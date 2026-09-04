<?php
/**
 * Where a merchant finds out what happened.
 *
 * Two places, because they answer two different questions. The shop log, under
 * Advanced Parameters, answers "is this module working at all". A note on the
 * order answers "what happened to this customer", which is the question
 * somebody actually has when they are looking at an order.
 *
 * Nothing in here throws. Losing the record of a message is not a reason to
 * lose the message, and it is certainly not a reason to break an order.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaLog
{
    const INFO = 1;
    const WARNING = 2;
    const ERROR = 3;

    /**
     * @param string $message
     * @param int    $severity
     * @param int    $idOrder  The order this is about, when there is one.
     */
    public static function write($message, $severity = self::ERROR, $idOrder = 0)
    {
        $message = 'Njiwa: ' . trim((string) $message);
        $idOrder = (int) $idOrder;

        try {
            if ($idOrder > 0) {
                PrestaShopLogger::addLog($message, (int) $severity, null, 'Order', $idOrder);
            } else {
                PrestaShopLogger::addLog($message, (int) $severity);
            }
        } catch (Throwable $e) {
            // A shop whose log table is unwell is still a shop that should
            // send its messages.
        }
    }

    /**
     * A note on the order, the way an employee sees it in the Messages block.
     *
     * It is private, so it is never shown to the customer and never emailed to
     * them: it is a record of what this module did, written for the shop.
     *
     * @param int    $idOrder
     * @param string $text
     */
    public static function onOrder($idOrder, $text)
    {
        $idOrder = (int) $idOrder;
        $text = 'Njiwa: ' . trim((string) $text);

        // PrestaShop validates the body of a message and refuses anything it
        // considers unclean HTML, which includes the angle brackets that turn
        // up in an error from a proxy. Plain text is all this ever needs.
        $text = strip_tags($text);
        $text = Tools::substr($text, 0, 900);

        try {
            $message = new Message();
            $message->id_order = $idOrder;
            $message->message = $text;
            $message->private = 1;

            if (!Validate::isCleanHtml($message->message) || !$message->add()) {
                self::write('Could not write a note on order ' . $idOrder . ': ' . $text, self::WARNING, $idOrder);
            }
        } catch (Throwable $e) {
            self::write('Could not write a note on order ' . $idOrder . ': ' . $e->getMessage(), self::WARNING, $idOrder);
        }
    }
}
