<?php
/**
 * Every setting this module has, and the only place that knows their names.
 *
 * PrestaShop keeps module settings in ps_configuration, which is a plain
 * table. That has one consequence worth stating in the open: the API key is
 * encrypted here when the shop offers the means, and is never rendered back
 * into the browser either way, but a database dump is a database dump.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaSettings
{
    const ENABLED = 'NJIWA_ENABLED';
    const API_KEY = 'NJIWA_API_KEY';
    const BASE_URL = 'NJIWA_BASE_URL';
    const FROM = 'NJIWA_FROM';
    const ADMIN_NUMBERS = 'NJIWA_ADMIN_NUMBERS';
    const CRON_TOKEN = 'NJIWA_CRON_TOKEN';
    const ADMIN_LINK = 'NJIWA_ADMIN_LINK';
    const TEST_SENT_AT = 'NJIWA_TEST_SENT_AT';

    const DEFAULT_BASE_URL = 'https://njiwa.upeo.ai';

    /**
     * Encrypted values are stored with this in front of them, so a key saved
     * before the shop had a working encryption engine, or by hand, is still
     * read correctly instead of being decrypted into nonsense.
     */
    const ENCRYPTED_PREFIX = 'njiwa:enc:';

    /**
     * The six moments, in the order they happen to an order. Five of them are
     * messages to the customer; "alert" is the one to the shop.
     *
     * @return array<int,string>
     */
    public static function events()
    {
        return array('awaiting', 'paid', 'shipped', 'cancelled', 'refunded', 'alert');
    }

    /**
     * @return array<int,string>
     */
    public static function customerEvents()
    {
        return array('awaiting', 'paid', 'shipped', 'cancelled', 'refunded');
    }

    public static function eventKey($event)
    {
        return 'NJIWA_EVENT_' . strtoupper($event);
    }

    public static function templateKey($event)
    {
        return 'NJIWA_TPL_' . strtoupper($event);
    }

    /**
     * The master switch. Off keeps every other setting and sends nothing.
     *
     * @return bool
     */
    public static function isEnabled()
    {
        return (bool) Configuration::get(self::ENABLED);
    }

    /**
     * @return bool
     */
    public static function isConfigured()
    {
        return self::apiKey() !== '';
    }

    /**
     * @return string
     */
    public static function apiKey()
    {
        return self::reveal((string) Configuration::get(self::API_KEY));
    }

    /**
     * A key was saved, but this shop can no longer read it.
     *
     * That happens when a shop is moved and its cookie key is regenerated:
     * the stored text is still there and is still ciphertext, but the key that
     * would open it has gone. The merchant needs telling to paste the key
     * again, because everything else looks exactly like a shop that works.
     *
     * @return bool
     */
    public static function isApiKeyUnreadable()
    {
        $stored = (string) Configuration::get(self::API_KEY);

        return $stored !== '' && self::reveal($stored) === '';
    }

    /**
     * The last four characters of the saved key, so the settings page can say
     * which key is in there without printing it.
     *
     * @return string
     */
    public static function apiKeyHint()
    {
        $key = self::apiKey();

        return $key === '' ? '' : Tools::substr($key, -4);
    }

    /**
     * @return bool
     */
    public static function isTestKey()
    {
        return strpos(self::apiKey(), 'sk_test_') === 0;
    }

    /**
     * @return string
     */
    public static function baseUrl()
    {
        $url = trim((string) Configuration::get(self::BASE_URL));
        if ($url === '') {
            $url = self::DEFAULT_BASE_URL;
        }

        return rtrim($url, '/');
    }

    /**
     * @return string Digits only, or '' to let Njiwa use the account default.
     */
    public static function from()
    {
        return preg_replace('/\D/', '', (string) Configuration::get(self::FROM));
    }

    /**
     * @return array<int,string>
     */
    public static function adminNumbers()
    {
        return NjiwaNumbers::parseList((string) Configuration::get(self::ADMIN_NUMBERS));
    }

    /**
     * @return bool
     */
    public static function isEventOn($event)
    {
        return (bool) Configuration::get(self::eventKey($event));
    }

    /**
     * The wording for one message.
     *
     * The default matters: a shop that ticked an event but never opened the
     * template box must still have something sensible to send, and the worker
     * that does the sending never loads the settings page.
     *
     * @return string
     */
    public static function template($event)
    {
        $key = self::templateKey($event);
        if (!Configuration::hasKey($key)) {
            return NjiwaTemplates::defaultFor($event);
        }

        return (string) Configuration::get($key);
    }

    /**
     * The secret in the cron URL. Without it the endpoint answers nothing, so
     * a stranger cannot make the shop empty its own queue.
     *
     * @return string
     */
    public static function cronToken()
    {
        $token = (string) Configuration::get(self::CRON_TOKEN);
        if ($token === '') {
            $token = Tools::passwdGen(32, 'ALPHANUMERIC');
            Configuration::updateValue(self::CRON_TOKEN, $token);
        }

        return $token;
    }

    /**
     * @return string
     */
    public static function cronUrl()
    {
        $context = Context::getContext();

        $base = '';
        if (isset($context->shop) && Validate::isLoadedObject($context->shop)) {
            $base = $context->shop->getBaseURL(true, true);
        }
        if (!$base) {
            $base = Tools::getShopDomainSsl(true) . __PS_BASE_URI__;
        }

        // The long form rather than a friendly URL, because it works whether
        // or not URL rewriting is on and whichever way it is configured.
        return $base . 'index.php?fc=module&module=njiwa&controller=cron&token=' . urlencode(self::cronToken());
    }

    /**
     * Where {admin_url} comes from.
     *
     * An admin link cannot be built outside the back office, because the
     * constant that names the admin folder is only defined on admin requests.
     * So the address is recorded when the settings page is opened, which is by
     * definition the back office. Until somebody has opened it there is no
     * link, and {admin_url} resolves to nothing rather than to something
     * broken.
     *
     * What is recorded is the front door of the back office and nothing more.
     * A link straight to one order needs a security token, and PrestaShop
     * builds that token from the id of the employee who is signed in, so a
     * link to an order is one employee's token being WhatsApped out to
     * everybody on the list, and it does not work for any of the others
     * anyway.
     */
    public static function rememberAdminLink($link)
    {
        $link = (string) $link;
        if ($link !== '' && $link !== (string) Configuration::get(self::ADMIN_LINK)) {
            Configuration::updateValue(self::ADMIN_LINK, $link, true);
        }
    }

    /**
     * @return string
     */
    public static function adminLink()
    {
        return (string) Configuration::get(self::ADMIN_LINK);
    }

    /**
     * Store a secret. Returns the text to write into ps_configuration.
     *
     * @param string $plain
     *
     * @return string
     */
    public static function protect($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }

        $engine = self::crypto();
        if ($engine === null) {
            return $plain;
        }

        try {
            $cipher = $engine->encrypt($plain);
        } catch (Throwable $e) {
            // Storing the key in the clear is worse than encrypting it and
            // better than losing it, which is what refusing to save would
            // amount to for a merchant who has just pasted one in.
            return $plain;
        }

        return is_string($cipher) && $cipher !== '' ? self::ENCRYPTED_PREFIX . $cipher : $plain;
    }

    /**
     * @param string $stored
     *
     * @return string '' when the stored text cannot be read back.
     */
    public static function reveal($stored)
    {
        $stored = (string) $stored;
        if (strpos($stored, self::ENCRYPTED_PREFIX) !== 0) {
            return trim($stored);
        }

        $engine = self::crypto();
        if ($engine === null) {
            return '';
        }

        try {
            $plain = $engine->decrypt(Tools::substr($stored, Tools::strlen(self::ENCRYPTED_PREFIX)));
        } catch (Throwable $e) {
            return '';
        }

        return is_string($plain) ? trim($plain) : '';
    }

    /**
     * PrestaShop's own encryption, the one it uses for cookies.
     *
     * Every part of it is checked before it is used. A shop where it is not
     * available keeps working, with the key stored as typed.
     *
     * @return PhpEncryption|null
     */
    private static function crypto()
    {
        if (!class_exists('PhpEncryption') || !defined('_NEW_COOKIE_KEY_') || !_NEW_COOKIE_KEY_) {
            return null;
        }

        try {
            return new PhpEncryption(_NEW_COOKIE_KEY_);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * What a fresh install looks like: switched on, connected to nothing, and
     * every event off. Installing this module must never cause a message to
     * be sent.
     *
     * @return array<string,string>
     */
    public static function defaults()
    {
        $values = array(
            self::ENABLED => '1',
            self::API_KEY => '',
            self::BASE_URL => self::DEFAULT_BASE_URL,
            self::FROM => '',
            self::ADMIN_NUMBERS => '',
        );

        foreach (self::events() as $event) {
            $values[self::eventKey($event)] = '0';
            $values[self::templateKey($event)] = NjiwaTemplates::defaultFor($event);
        }

        return $values;
    }

    /**
     * @return array<int,string>
     */
    public static function allKeys()
    {
        $keys = array_keys(self::defaults());
        $keys[] = self::CRON_TOKEN;
        $keys[] = self::ADMIN_LINK;
        $keys[] = self::TEST_SENT_AT;

        return $keys;
    }

    /**
     * Write one setting.
     *
     * The third argument to updateValue is not optional here. Without it
     * PrestaShop runs the value through nl2br() and strip_tags() on its way
     * into the database, and a message template comes back out as one long
     * line with every paragraph break gone.
     */
    public static function put($key, $value)
    {
        Configuration::updateValue($key, $value, true);
    }
}
