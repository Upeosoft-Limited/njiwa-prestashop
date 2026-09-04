<?php
/**
 * Talking to Njiwa. Transport only, and the only file in this module that
 * makes an HTTP request.
 *
 * It uses cURL directly rather than the HTTP client PrestaShop happens to
 * bundle, because that client is a different version in 1.7 than it is in 8
 * and a module that picks the wrong one fails at the worst possible moment.
 * cURL is on every host that can run a shop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaClient
{
    /** Long enough for a slow line, short enough that nothing holds a request. */
    const TIMEOUT = 20;

    /** A host that will not answer at all should not take twenty seconds to say so. */
    const CONNECT_TIMEOUT = 10;

    /**
     * Send one text message.
     *
     * @param string $to             Recipient, digits only, in full international form.
     * @param string $text           The message.
     * @param string $idempotencyKey Njiwa honours it for 24 hours, so a job
     *                               that runs twice replays the first answer
     *                               instead of messaging the customer again.
     *
     * @return array Njiwa's answer, including the message id.
     *
     * @throws NjiwaException
     */
    public static function sendText($to, $text, $idempotencyKey = '')
    {
        $headers = array();
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = array(
            'to' => (string) $to,
            'text' => (string) $text,
        );

        // Only when the shop named a number. Left out, Njiwa uses the account's
        // default, which is the right answer for the shops that have one number
        // and never think about this again.
        $from = NjiwaSettings::from();
        if ($from !== '') {
            $body['from'] = $from;
        }

        return self::request('POST', '/v1/messages', $body, $headers);
    }

    /**
     * The WhatsApp numbers on this account, linked or not.
     *
     * @return array<int,array>
     *
     * @throws NjiwaException
     */
    public static function instances()
    {
        $answer = self::request('GET', '/v1/instances');

        return isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : array();
    }

    /**
     * @param array|null $body
     * @param array      $headers
     *
     * @return array
     *
     * @throws NjiwaException
     */
    private static function request($method, $path, $body = null, array $headers = array())
    {
        // The master switch has to fail here rather than shrug. Somebody who
        // turned it off and forgot needs to find a line in a log saying so,
        // not silence that looks exactly like a working shop.
        if (!NjiwaSettings::isEnabled()) {
            throw new NjiwaException(
                'Send WhatsApp messages is switched off in the Njiwa settings, so nothing was sent.',
                'disabled'
            );
        }

        if (NjiwaSettings::isApiKeyUnreadable()) {
            throw new NjiwaException(
                'The saved Njiwa API key cannot be read by this shop any more, which happens when a shop is'
                . ' moved or its cookie key is regenerated. Paste the key into the Njiwa settings again.',
                'key_unreadable'
            );
        }

        $key = NjiwaSettings::apiKey();
        if ($key === '') {
            throw new NjiwaException('There is no Njiwa API key saved, so nothing can be sent.', 'not_configured');
        }

        if (!function_exists('curl_init')) {
            throw new NjiwaException(
                'This server has no cURL, so it cannot reach Njiwa. Ask your host to enable the PHP cURL extension.',
                'no_http_client'
            );
        }

        $url = NjiwaSettings::baseUrl() . $path;

        $headers = array_merge(
            array(
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
                'User-Agent' => 'njiwa-prestashop/' . (defined('NJIWA_VERSION') ? NJIWA_VERSION : 'dev'),
            ),
            $headers
        );

        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body);
        }

        $lines = array();
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $lines);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        // Redirects are not followed on purpose. A redirect would carry the
        // Authorization header to wherever it points, and the only reason this
        // request would be redirected is that the Njiwa address is wrong.
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);

        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        $answer = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $failure = curl_error($curl);
        curl_close($curl);

        if ($answer === false) {
            // A network failure is not a send failure: the message was never
            // accepted, so trying again later is safe.
            throw new NjiwaException(
                'Could not reach Njiwa at ' . NjiwaSettings::baseUrl() . '. ' . $failure,
                'connection_failed'
            );
        }

        if ($status >= 300 && $status < 400) {
            throw new NjiwaException(
                'Njiwa answered with a redirect (HTTP ' . $status . '). Check the Njiwa address in the settings:'
                . ' it should be ' . NjiwaSettings::DEFAULT_BASE_URL . ' unless you were given your own.',
                'unexpected_redirect',
                $status
            );
        }

        $decoded = json_decode((string) $answer, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        if ($status >= 400) {
            $error = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : array();

            throw new NjiwaException(
                isset($error['message']) ? $error['message'] : 'Njiwa answered with HTTP ' . $status . '.',
                isset($error['code']) ? $error['code'] : 'unknown',
                $status,
                isset($error['docs']) ? $error['docs'] : null
            );
        }

        return $decoded;
    }
}
