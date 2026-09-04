<?php
/**
 * Turning what a customer typed into a number WhatsApp can reach.
 *
 * People write their number the way they say it: 0712 345 678, (071) 234-5678,
 * +254 712 345 678. WhatsApp needs one form. The country on the order's
 * address is what makes a local number unambiguous, which is why nothing here
 * guesses: PrestaShop already stores an international dialling code against
 * every country, and that is where the answer comes from.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaNumbers
{
    /**
     * Short enough to accept a national number anywhere, long enough that a
     * house number or an order reference typed into the phone field is not
     * mistaken for one.
     */
    const MINIMUM_DIGITS = 7;

    /**
     * @param string $phone      As the customer typed it.
     * @param int    $callPrefix The dialling code of the country on the order,
     *                           from ps_country.call_prefix. 0 when there is
     *                           no country to reason with.
     *
     * @return string Digits only, in full international form, or '' when there
     *                is nothing usable. A customer with no phone number is
     *                normal, so '' is an answer rather than an error.
     */
    public static function toMsisdn($phone, $callPrefix = 0)
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return '';
        }

        // A WhatsApp group is addressed as something like 120363042@g.us, and
        // Njiwa will post to it. One saved settings page could then message
        // every person in a group from the shop's own number, so anything
        // carrying an @ is refused outright rather than having its digits
        // picked out and sent somewhere nobody intended.
        if (strpos($raw, '@') !== false) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return '';
        }

        // A leading + or 00 is the customer saying "this is the whole number".
        // Believe them, and stop before the country on the order gets a say:
        // somebody living abroad who buys with a card billed at home would
        // otherwise have their own country code treated as a local number and
        // a second one stuck in front of it.
        $alreadyInternational = strpos($raw, '+') === 0 || strpos($digits, '00') === 0;

        // 00 is how much of the world dials out.
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < self::MINIMUM_DIGITS) {
            return '';
        }

        if ($alreadyInternational) {
            return $digits;
        }

        $prefix = (string) (int) $callPrefix;
        if ($prefix === '0') {
            // No country to reason with. Send it as written and let Njiwa
            // resolve it against the sending number's own country.
            return $digits;
        }

        // Already international. The length test is what stops a national
        // number that happens to open with its own country's digits being
        // mistaken for one, which is a real hazard in +1 countries.
        if (strpos($digits, $prefix) === 0 && strlen($digits) >= strlen($prefix) + 7) {
            return $digits;
        }

        // The trunk prefix: the 0 you dial at home and never abroad.
        return $prefix . ltrim($digits, '0');
    }

    /**
     * A list typed by the shop owner: separated by commas, semicolons or
     * newlines, because people use all three and none of them is wrong.
     *
     * These are the shop's own numbers rather than a customer's, so there is
     * no country to read them against and they are taken as written. The
     * settings page says to put them in full international form.
     *
     * @param string $raw
     *
     * @return array<int,string>
     */
    public static function parseList($raw)
    {
        $numbers = array();

        $pieces = preg_split('/[\s,;]+/', (string) $raw);
        if (!is_array($pieces)) {
            return $numbers;
        }

        foreach ($pieces as $piece) {
            $number = self::toMsisdn($piece);
            if ($number !== '') {
                $numbers[] = $number;
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * The dialling code PrestaShop holds for a country, such as 254 for Kenya.
     *
     * @param int $idCountry
     *
     * @return int 0 when the country is unknown or has no code recorded.
     */
    public static function callPrefixFor($idCountry)
    {
        $idCountry = (int) $idCountry;
        if ($idCountry <= 0) {
            return 0;
        }

        $country = new Country($idCountry);
        if (!Validate::isLoadedObject($country) || !isset($country->call_prefix)) {
            return 0;
        }

        return (int) $country->call_prefix;
    }
}
