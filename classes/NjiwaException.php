<?php
/**
 * Anything Njiwa refused, or could not be asked.
 *
 * getErrorCode() is the stable, machine readable reason and is the thing to
 * branch on. The wording of the message can change; the code does not.
 *
 * It extends the plain PHP exception rather than PrestaShopException on
 * purpose: PrestaShopException renders a fatal error page and stops the
 * request, which is exactly what must never happen to an order because a
 * WhatsApp message could not be sent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaException extends Exception
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $status;

    /** @var string|null */
    private $docs;

    public function __construct($message, $errorCode = 'unknown', $status = 0, $docs = null)
    {
        parent::__construct((string) $message);

        $this->errorCode = (string) $errorCode;
        $this->status = (int) $status;
        $this->docs = $docs;
    }

    /**
     * @return string
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string|null
     */
    public function getDocs()
    {
        return $this->docs;
    }

    /**
     * A network failure means the message was never accepted, so trying again
     * later is safe. A refusal means Njiwa read the request and said no, and
     * asking again would only be told the same thing.
     *
     * @return bool
     */
    public function isWorthRetrying()
    {
        return in_array($this->errorCode, array('connection_failed', 'rate_limited'), true)
            || $this->status >= 500;
    }
}
