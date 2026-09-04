<?php
/**
 * The message itself.
 *
 * A template is plain text with placeholders in braces. Every placeholder a
 * shop can use is listed in placeholders() below, and that same list is what
 * the settings page prints, so the documentation cannot drift from the code.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaTemplates
{
    /** WhatsApp takes 4096 characters. Stopping short leaves room for a footer. */
    const MAX_LENGTH = 4000;

    /** How many order lines {items} prints before it starts counting instead. */
    const MAX_ITEMS = 10;

    /**
     * Placeholder => what it is replaced with, in the shop's own words.
     *
     * @return array<string,string>
     */
    public static function placeholders()
    {
        return array(
            '{first_name}' => 'The first name on the invoice address, or "there" if the order has none.',
            '{last_name}' => 'The last name on the invoice address.',
            '{customer_name}' => 'Both names together.',
            '{order_number}' => 'The order reference the customer sees, such as XKBKNABJK.',
            '{order_total}' => 'The total paid, in the currency of the order.',
            '{order_date}' => 'The date the order was placed, in your shop format.',
            '{order_status}' => 'The status the order has just moved to, in your shop language.',
            '{payment_method}' => 'How they paid, as shown on the order.',
            '{items}' => 'One line per product, as "2 x Blue shirt".',
            '{item_count}' => 'How many items in total.',
            '{shop_name}' => 'Your shop name.',
            '{order_url}' => 'A link the customer can open to see their own order.',
            '{admin_url}' => 'A link to your back office. It does not open the order itself, because a link that does carries the security token of whoever last opened the Njiwa settings page. Only put this in the message to yourself.',
        );
    }

    /**
     * What each message says before anybody edits it.
     *
     * They live here rather than on the settings page because the worker that
     * sends a message never loads the settings page, and a shop that has saved
     * the configuration exactly zero times must still send something sensible.
     *
     * They are deliberately short. A WhatsApp message that reads like an email
     * gets read like an email, which is to say not at all.
     *
     * @return string
     */
    public static function defaultFor($event)
    {
        $defaults = array(
            'awaiting' => "Hi {first_name}, we have your order {order_number} for {order_total}. We will let you know the moment your payment comes through.\n\n{shop_name}",
            'paid' => "Hi {first_name}, thank you. Your payment for order {order_number} came through and we are getting it ready.\n\n{items}\n\nTotal {order_total}\n{shop_name}",
            'shipped' => "Hi {first_name}, order {order_number} is on its way to you. Thank you for shopping with {shop_name}.",
            'cancelled' => "Hi {first_name}, order {order_number} has been cancelled and you have not been charged. If that was not you, reply to this message and we will look into it.\n\n{shop_name}",
            'refunded' => "Hi {first_name}, we have refunded {order_total} for order {order_number}. Banks take a few days to show it.\n\n{shop_name}",
            'alert' => "New order {order_number} on {shop_name}.\n\n{customer_name}\n{item_count} item(s), {order_total}\nPaid by {payment_method}",
        );

        return isset($defaults[$event]) ? $defaults[$event] : '';
    }

    /**
     * @param string $template Raw template text.
     * @param Order  $order
     * @param int    $idOrderState The state the order has just reached.
     *
     * @return string The message, or '' when the template is empty.
     */
    public static function render($template, Order $order, $idOrderState = 0)
    {
        $template = trim((string) $template);
        if ($template === '') {
            return '';
        }

        $message = strtr($template, self::values($order, $idOrderState));

        // Anything still in braces is a placeholder that does not exist,
        // usually a typo. Sending "{order_no}" to a customer looks broken, so
        // it comes out and the shop is told where to look.
        if (preg_match_all('/\{[a-z_]+\}/', $message, $found)) {
            NjiwaLog::write(
                'Unknown placeholder ' . implode(', ', array_unique($found[0]))
                . ' in a message template. It was removed before sending.',
                NjiwaLog::WARNING
            );
            $message = preg_replace('/\{[a-z_]+\}/', '', $message);
        }

        $message = trim(preg_replace('/\n{3,}/', "\n\n", $message));

        if (Tools::strlen($message) > self::MAX_LENGTH) {
            $message = Tools::substr($message, 0, self::MAX_LENGTH - 1) . '…';
        }

        return $message;
    }

    /**
     * @return array<string,string>
     */
    private static function values(Order $order, $idOrderState)
    {
        $idLang = (int) $order->id_lang ? (int) $order->id_lang : (int) Configuration::get('PS_LANG_DEFAULT');

        $address = new Address((int) $order->id_address_invoice);
        $firstName = Validate::isLoadedObject($address) ? trim($address->firstname) : '';
        $lastName = Validate::isLoadedObject($address) ? trim($address->lastname) : '';

        if ($firstName === '' && $lastName === '') {
            $customer = new Customer((int) $order->id_customer);
            if (Validate::isLoadedObject($customer)) {
                $firstName = trim($customer->firstname);
                $lastName = trim($customer->lastname);
            }
        }

        return array(
            '{first_name}' => $firstName !== '' ? $firstName : 'there',
            '{last_name}' => $lastName,
            '{customer_name}' => trim($firstName . ' ' . $lastName),
            '{order_number}' => $order->reference ? $order->reference : (string) $order->id,
            '{order_total}' => self::money((float) $order->total_paid, (int) $order->id_currency),
            '{order_date}' => $order->date_add ? Tools::displayDate($order->date_add) : '',
            '{order_status}' => self::stateName($idOrderState, $idLang),
            '{payment_method}' => (string) $order->payment,
            '{items}' => self::items($order),
            '{item_count}' => (string) self::itemCount($order),
            '{shop_name}' => (string) Configuration::get('PS_SHOP_NAME'),
            '{order_url}' => self::orderUrl($order, $idLang),
            '{admin_url}' => NjiwaSettings::adminLink(),
        );
    }

    /**
     * The total, written the way the shop writes prices.
     *
     * PrestaShop has moved this twice. The locale service is the current way
     * and is asked first; Tools::displayPrice is the older one and is only
     * called when it is really there. If a shop has neither, the amount and
     * the currency code are still better than nothing at all.
     *
     * @return string
     */
    private static function money($amount, $idCurrency)
    {
        $currency = new Currency((int) $idCurrency);
        $iso = Validate::isLoadedObject($currency) ? $currency->iso_code : '';

        try {
            $context = Context::getContext();
            if (method_exists($context, 'getCurrentLocale')) {
                $locale = $context->getCurrentLocale();
                if ($locale) {
                    return $locale->formatPrice($amount, $iso);
                }
            }
        } catch (Throwable $e) {
            // Fall through to the older ways below rather than lose the
            // message over the formatting of one number.
        }

        if (method_exists('Tools', 'displayPrice') && Validate::isLoadedObject($currency)) {
            return Tools::displayPrice($amount, $currency);
        }

        return trim($iso . ' ' . number_format($amount, 2, '.', ','));
    }

    /**
     * @return string
     */
    private static function stateName($idOrderState, $idLang)
    {
        $idOrderState = (int) $idOrderState;
        if ($idOrderState <= 0) {
            return '';
        }

        $state = new OrderState($idOrderState, (int) $idLang);

        return Validate::isLoadedObject($state) ? (string) $state->name : '';
    }

    /**
     * @return string
     */
    private static function items(Order $order)
    {
        $lines = array();
        $more = 0;

        foreach ($order->getProducts() as $product) {
            if (count($lines) >= self::MAX_ITEMS) {
                ++$more;
                continue;
            }
            $lines[] = sprintf('%d x %s', (int) $product['product_quantity'], strip_tags($product['product_name']));
        }

        if ($more > 0) {
            $lines[] = sprintf('and %d more', $more);
        }

        return implode("\n", $lines);
    }

    /**
     * @return int
     */
    private static function itemCount(Order $order)
    {
        $count = 0;
        foreach ($order->getProducts() as $product) {
            $count += (int) $product['product_quantity'];
        }

        return $count;
    }

    /**
     * The customer's own view of the order.
     *
     * It is built with the order's language rather than whoever's language the
     * request happens to be in, because the worker that sends the message runs
     * outside anybody's session.
     *
     * @return string
     */
    private static function orderUrl(Order $order, $idLang)
    {
        try {
            $link = new Link();

            return $link->getPageLink(
                'order-detail',
                true,
                (int) $idLang,
                array('id_order' => (int) $order->id),
                false,
                (int) $order->id_shop
            );
        } catch (Throwable $e) {
            return '';
        }
    }
}
