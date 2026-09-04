<?php
/**
 * When a message goes out, and to whom.
 *
 * One rule runs the whole module: an order reaching a status sends the message
 * for that status, once. Nothing is sent while the customer waits at the
 * checkout, and nothing that fails here is ever allowed to break an order.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaNotifier
{
    /**
     * How many messages one pass sends. A shop that has just bulk-updated two
     * hundred orders drains them a batch at a time rather than holding one
     * request open until it is killed half way through.
     */
    const BATCH = 20;

    /**
     * The last resort, where the queue is emptied inside the request that
     * filled it because the hosting can do neither of the other two things.
     *
     * Small on purpose. Twenty messages at twenty seconds each is a page that
     * hangs for the better part of seven minutes; three messages inside a ten
     * second budget is a page that is a little slow once. Whatever is left
     * goes out on the next order or the next cron, which is why the settings
     * page asks for a cron job.
     */
    const INLINE_BATCH = 3;

    /** How long the last resort may spend before it leaves the rest. */
    const INLINE_SECONDS = 10;

    /** @var bool One drain per request, however many messages it queued. */
    private static $drainScheduled = false;

    /**
     * PrestaShop's order states, mapped onto the moments this module knows
     * about.
     *
     * The ids are read from the shop's own configuration and never written
     * down here, because they differ from shop to shop: a shop that was
     * installed years ago, or migrated, or had a state deleted and remade, has
     * different numbers from a fresh one. PS_OS_PAYMENT is the question
     * "which state does this shop call payment accepted", and it is the only
     * honest way to ask.
     *
     * A shop that does not have one of these settings simply has no state
     * mapped to that moment, and that moment never fires. That is why every
     * lookup below is allowed to come back empty.
     *
     * @return array<string,array<int,int>>
     */
    public static function stateMap()
    {
        $wanted = array(
            // Placed, and the shop is waiting for the money. These are the
            // states PrestaShop ships for the payment methods where an order
            // exists before it is paid.
            'awaiting' => array('PS_OS_BANKWIRE', 'PS_OS_CHEQUE', 'PS_OS_COD_VALIDATION'),
            'paid' => array('PS_OS_PAYMENT', 'PS_OS_WS_PAYMENT', 'PS_OS_OUTOFSTOCK_PAID'),
            'shipped' => array('PS_OS_SHIPPING'),
            'cancelled' => array('PS_OS_CANCELED'),
            'refunded' => array('PS_OS_REFUND'),
        );

        $map = array();
        foreach ($wanted as $event => $keys) {
            $map[$event] = array();
            foreach ($keys as $key) {
                $id = (int) Configuration::getGlobalValue($key);
                if ($id <= 0) {
                    $id = (int) Configuration::get($key);
                }
                if ($id > 0) {
                    $map[$event][] = $id;
                }
            }
            $map[$event] = array_values(array_unique($map[$event]));
        }

        return $map;
    }

    /**
     * The moment an order has just reached, or '' when it is one this module
     * has nothing to say about.
     *
     * @return string
     */
    public static function eventForState(OrderState $state)
    {
        $id = (int) $state->id;
        if ($id <= 0) {
            return '';
        }

        foreach (self::stateMap() as $event => $ids) {
            if (in_array($id, $ids, true)) {
                return $event;
            }
        }

        // Nothing PrestaShop ships matched, so ask the state what it is.
        // Payment modules and shipping tools add their own states, and a state
        // ticked "Set the order as shipped" or "Consider the associated order
        // as validated/paid" means exactly what those words say. Without this,
        // a shop whose gateway installs its own "Payment accepted" state would
        // tick the payment event and never understand why nothing arrives.
        //
        // Shipped is asked about first because PrestaShop's Delivered state is
        // both shipped and paid, and by then the payment is old news.
        if (!empty($state->shipped)) {
            return 'shipped';
        }
        if (!empty($state->paid)) {
            return 'paid';
        }

        return '';
    }

    /**
     * The moments that mean an order genuinely exists.
     *
     * The alert to the shop goes on the first of these to arrive, once. Not
     * when the order row is created, which happens the moment somebody reaches
     * the payment page and usually means nothing.
     *
     * @return array<int,string>
     */
    public static function alertMoments()
    {
        return array('awaiting', 'paid', 'shipped');
    }

    /**
     * The hook, with the order already loaded.
     *
     * This runs inside the request that changed the status, which is often the
     * customer's own checkout, so it does as little as it possibly can: read
     * some settings, work out one phone number, write one row.
     */
    public static function onOrderStatus(Order $order, OrderState $state)
    {
        if (!NjiwaSettings::isEnabled() || !NjiwaSettings::isConfigured()) {
            return;
        }

        $event = self::eventForState($state);
        if ($event === '') {
            return;
        }

        $queued = self::tellTheCustomer($order, $state, $event);
        $queued = self::tellTheShop($order, $state, $event) || $queued;

        if ($queued) {
            self::scheduleDrain();
        }
    }

    /**
     * @return bool Whether anything was queued.
     */
    private static function tellTheCustomer(Order $order, OrderState $state, $event)
    {
        if (!in_array($event, NjiwaSettings::customerEvents(), true) || !NjiwaSettings::isEventOn($event)) {
            return false;
        }

        $number = self::customerNumber($order);
        if ($number === '') {
            // A customer without a phone number is normal, and it is not an
            // error. It is worth writing on the order, because "why did this
            // one not get a message" is a question somebody will ask.
            NjiwaLog::onOrder(
                (int) $order->id,
                'no WhatsApp message, because this order has no phone number on its address.'
            );

            return false;
        }

        return NjiwaQueue::claim((int) $order->id_shop, (int) $order->id, $event, $number, (int) $state->id) !== null;
    }

    /**
     * @return bool Whether anything was queued.
     */
    private static function tellTheShop(Order $order, OrderState $state, $event)
    {
        if (!NjiwaSettings::isEventOn('alert') || !in_array($event, self::alertMoments(), true)) {
            return false;
        }

        $numbers = NjiwaSettings::adminNumbers();
        if (empty($numbers)) {
            return false;
        }

        // The claim is keyed on the order, the word "alert" and the number, so
        // an order that goes awaiting, then paid, then shipped still wakes the
        // shop up once. Everybody listed gets their own copy, and their own
        // claim, because two people must not collapse into one another.
        $queued = false;
        foreach ($numbers as $number) {
            if (NjiwaQueue::claim((int) $order->id_shop, (int) $order->id, 'alert', $number, (int) $state->id) !== null) {
                $queued = true;
            }
        }

        return $queued;
    }

    /**
     * The customer's WhatsApp number, read against the country on the order.
     *
     * The mobile number is preferred over the other one: WhatsApp is a mobile
     * thing, and a landline in the phone field is a message nobody ever sees.
     *
     * @return string
     */
    public static function customerNumber(Order $order)
    {
        $address = new Address((int) $order->id_address_invoice);
        if (!Validate::isLoadedObject($address)) {
            $address = new Address((int) $order->id_address_delivery);
        }
        if (!Validate::isLoadedObject($address)) {
            return '';
        }

        $phone = trim((string) $address->phone_mobile);
        if ($phone === '') {
            $phone = trim((string) $address->phone);
        }

        return NjiwaNumbers::toMsisdn($phone, NjiwaNumbers::callPrefixFor((int) $address->id_country));
    }

    /**
     * Arrange for the queue to be emptied after this request has answered.
     *
     * PrestaShop has no queue and no job runner of its own, so this is the
     * honest best available, in the order it is preferred:
     *
     * 1. PHP-FPM can hand the finished response to the web server and carry on
     *    running. Where that exists, the customer already has their page and
     *    the sending costs them nothing. Most modern hosting is PHP-FPM.
     * 2. Otherwise the shop asks itself, over its own front door, to empty the
     *    queue: one socket opened, one line written, closed without waiting
     *    for the answer. The request that is serving a customer moves on.
     * 3. If neither works, a few messages are sent in this request, before
     *    the page finishes. That is the only case in which anybody waits. It
     *    is held to INLINE_BATCH messages and INLINE_SECONDS, so the page is
     *    slow rather than stuck, and anything past that is simply left in the
     *    queue. A shop in that position needs a real cron on the URL on the
     *    settings page; nothing else will empty the queue reliably.
     *
     * Nothing here can throw into the order. Everything it calls is wrapped.
     */
    public static function scheduleDrain()
    {
        if (self::$drainScheduled) {
            return;
        }
        self::$drainScheduled = true;

        // Built now, while there is a shop and a context to build it from. By
        // the time the shutdown function runs, a good deal of PrestaShop has
        // already been taken down.
        $url = NjiwaSettings::cronUrl();

        if (function_exists('register_shutdown_function')) {
            register_shutdown_function(array(__CLASS__, 'afterResponse'), $url);

            return;
        }

        self::afterResponse($url);
    }

    /**
     * @param string $url
     */
    public static function afterResponse($url)
    {
        if (function_exists('ignore_user_abort')) {
            // The customer closing the tab must not kill a message that is
            // already half sent.
            @ignore_user_abort(true);
        }

        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
                self::drain();

                return;
            }

            if (self::knock($url)) {
                return;
            }

            // Nothing on this server can carry on after the response, so the
            // customer's own page pays for the send. Kept short: the order is
            // already saved, so the worst this can do is make one page slow.
            self::drain(self::INLINE_BATCH, self::INLINE_SECONDS);
        } catch (Throwable $e) {
            NjiwaLog::write('The queue could not be emptied after the request: ' . $e->getMessage());
        }
    }

    /**
     * Ask the shop to empty its own queue, without waiting for the answer.
     *
     * @return bool Whether the knock was delivered.
     */
    private static function knock($url)
    {
        if (!function_exists('fsockopen')) {
            return false;
        }

        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $secure = isset($parts['scheme']) && $parts['scheme'] === 'https';
        $port = isset($parts['port']) ? (int) $parts['port'] : ($secure ? 443 : 80);
        $path = (isset($parts['path']) ? $parts['path'] : '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $errno = 0;
        $error = '';
        $socket = @fsockopen(($secure ? 'ssl://' : '') . $parts['host'], $port, $errno, $error, 3);
        if (!$socket) {
            return false;
        }

        $request = 'GET ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . $parts['host'] . "\r\n"
            . 'User-Agent: njiwa-prestashop' . "\r\n"
            . "Connection: Close\r\n\r\n";

        @stream_set_timeout($socket, 3);
        $written = @fwrite($socket, $request);
        @fclose($socket);

        return $written !== false && $written > 0;
    }

    /**
     * The worker. Runs after the customer has been sent on their way, or from
     * the cron URL.
     *
     * The master switch is deliberately not checked here. A message that was
     * queued while the module was switched on and is then found by a worker
     * after somebody switched it off should say so out loud, on the order and
     * in the log, rather than disappear.
     *
     * @param int $limit   How many messages at most.
     * @param int $seconds  How long this pass may spend, or 0 for no limit.
     *                      Checked between messages, so the true worst case is
     *                      this plus one client timeout.
     *
     * @return int How many messages were dealt with.
     */
    public static function drain($limit = self::BATCH, $seconds = 0)
    {
        $done = 0;
        $startedAt = time();

        // Rows whose worker died more than a day ago are closed before
        // anything is read, so that pending() never hands one back.
        NjiwaQueue::expireStale();

        foreach (NjiwaQueue::pending($limit) as $row) {
            if ($seconds > 0 && (time() - $startedAt) >= (int) $seconds) {
                // The rest keep. Every one of them is still queued, still
                // claimed, and still due, so the next pass takes them.
                break;
            }

            if (!NjiwaQueue::take($row)) {
                // Somebody else got to it first, which is exactly what the
                // claim is for.
                continue;
            }

            try {
                self::deliver($row);
            } catch (Throwable $e) {
                NjiwaQueue::markFailed((int) $row['id_njiwa_message'], $e->getMessage());
                NjiwaLog::write(
                    'Order ' . (int) $row['id_order'] . ', ' . $row['event'] . ': ' . $e->getMessage(),
                    NjiwaLog::ERROR,
                    (int) $row['id_order']
                );
            }

            ++$done;
        }

        return $done;
    }

    /**
     * One message.
     */
    private static function deliver(array $row)
    {
        $id = (int) $row['id_njiwa_message'];
        $idOrder = (int) $row['id_order'];
        $event = (string) $row['event'];
        $recipient = (string) $row['recipient'];

        // In a multistore shop the settings, the wording and even the API key
        // can differ per shop, and the worker may be running in the context of
        // a different one from the order. Everything below reads settings, so
        // the shop is put right first.
        self::useShopOf((int) $row['id_shop']);

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            NjiwaQueue::markFailed($id, 'The order no longer exists.');

            return;
        }

        $message = NjiwaTemplates::render(
            NjiwaSettings::template($event),
            $order,
            (int) $row['id_order_state']
        );

        if ($message === '') {
            // Clearing the wording is how a merchant stops one message without
            // touching the switch above it. It is not a failure.
            NjiwaQueue::markSkipped($id, 'The wording for this message is empty.');

            return;
        }

        try {
            $answer = NjiwaClient::sendText($recipient, $message, self::idempotencyKey($row));

            NjiwaQueue::markSent($id, isset($answer['id']) ? $answer['id'] : '');
            NjiwaLog::onOrder(
                $idOrder,
                'WhatsApp sent to +' . $recipient . ' (' . (isset($answer['id']) ? $answer['id'] : '?') . ').'
                . (NjiwaSettings::isTestKey() ? ' Test key, so nothing reached WhatsApp.' : '')
            );
        } catch (NjiwaException $e) {
            $tries = (int) $row['tries'] + 1;

            if ($e->isWorthRetrying() && $tries < NjiwaQueue::MAX_TRIES) {
                // The message was never accepted, so it is safe to ask again,
                // and the Idempotency-Key means a request that did arrive is
                // replayed rather than sent twice. The row is put back with a
                // time before which nobody may touch it, so the attempts are
                // spread over the next half hour instead of being spent on one
                // outage in the space of a second.
                NjiwaQueue::requeue($id, $e->getMessage(), $tries);
                NjiwaLog::write(
                    'Order ' . $idOrder . ', ' . $event . ': ' . $e->getMessage() . ' Will try again later.',
                    NjiwaLog::WARNING,
                    $idOrder
                );

                return;
            }

            NjiwaQueue::markFailed($id, $e->getMessage());
            NjiwaLog::onOrder(
                $idOrder,
                'could not WhatsApp +' . $recipient . '. ' . $e->getMessage()
                . ' Nothing more will be tried on its own; the Njiwa settings page can send it again.'
            );
            NjiwaLog::write(
                'Order ' . $idOrder . ', ' . $event . ': ' . $e->getMessage() . ' (' . $e->getErrorCode() . ')',
                NjiwaLog::ERROR,
                $idOrder
            );
        }
    }

    private static function useShopOf($idShop)
    {
        if ($idShop <= 0 || !Shop::isFeatureActive()) {
            return;
        }

        try {
            $context = Context::getContext();
            if (isset($context->shop) && (int) $context->shop->id === $idShop) {
                return;
            }

            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        } catch (Throwable $e) {
            NjiwaLog::write('Could not switch to shop ' . $idShop . ': ' . $e->getMessage(), NjiwaLog::WARNING);
        }
    }

    /**
     * One key per order, event and recipient.
     *
     * Njiwa honours it for 24 hours, so a worker that runs twice, or a send
     * that timed out on the way back, replays the first answer instead of
     * messaging the customer again. The recipient is part of the key because
     * one alert can go to several of your own numbers, and they must not
     * collapse into one another. The shop is part of it because two shops
     * sharing one Njiwa account have their own order numbering.
     *
     * @return string
     */
    public static function idempotencyKey(array $row)
    {
        $shop = md5((string) Configuration::get('PS_SHOP_DOMAIN') . '|' . (int) $row['id_shop']);

        return 'ps-' . Tools::substr($shop, 0, 8)
            . '-' . (int) $row['id_order']
            . '-' . $row['event']
            . '-' . Tools::substr(md5((string) $row['recipient']), 0, 6);
    }
}
