<?php
/**
 * The address a real cron job can call to empty the queue.
 *
 * Most of the time nothing calls this: the shop empties its own queue at the
 * end of the request that filled it. This exists for the two occasions when
 * that is not enough. The first is a shop on hosting where PHP cannot hand
 * back the response and carry on, where the shop knocks on this door for
 * itself. The second is anything that went wrong: a request that died half
 * way, or a network that was down for an hour. A cron every five minutes
 * means a message that could not go out at the time still goes out.
 *
 * It answers nothing at all without the token from the settings page, so
 * knowing the shop's address is not enough to make it work.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaCronModuleFrontController extends ModuleFrontController
{
    /** No customer session is involved in sending a message. */
    public $auth = false;

    /**
     * The settings page hands out an https address on a shop that has SSL, so
     * this matches it and nothing is redirected.
     */
    public $ssl = true;

    /**
     * How many batches one call will work through before it stops and leaves
     * the rest for the next one. A cron that runs for ever is a cron that gets
     * killed in the middle of something.
     */
    const MAX_BATCHES = 5;

    /**
     * How long one call may spend sending before it stops and leaves the rest
     * for the next run. PHP's own time limit does not count time spent waiting
     * on a socket, so a queue full of messages to an unreachable Njiwa would
     * otherwise hold this request open far longer than the limit above
     * suggests.
     */
    const MAX_SECONDS = 60;

    public function initContent()
    {
        parent::initContent();

        $given = (string) Tools::getValue('token');
        $expected = NjiwaSettings::cronToken();

        if ($given === '' || !hash_equals($expected, $given)) {
            // Deliberately terse. A wrong token learns nothing from the answer
            // about whether the module is even installed.
            $this->answer('Not found.', 404);
        }

        if (function_exists('ignore_user_abort')) {
            // The shop knocks on this door and hangs up immediately. That is
            // the whole point, and it must not stop the work.
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $startedAt = time();
        $sent = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; ++$batch) {
            $left = self::MAX_SECONDS - (time() - $startedAt);
            if ($left <= 0) {
                break;
            }

            // A message that fails in a way worth retrying is put back with a
            // time before which nobody may touch it, so the next batch here
            // picks up the rest of the queue rather than spending this
            // message's remaining attempts on the same outage.
            $done = NjiwaNotifier::drain(NjiwaNotifier::BATCH, $left);
            $sent += $done;

            if ($done === 0) {
                break;
            }
        }

        $this->answer(
            'Njiwa: ' . (int) $sent . ' message(s) dealt with, '
            . NjiwaQueue::countWaiting() . ' waiting, '
            . NjiwaQueue::countFailed() . ' given up on.'
        );
    }

    /**
     * Plain text, and nothing else. Nobody reads this in a browser except a
     * merchant checking that their cron job is pointed at the right place.
     *
     * @param string $text
     * @param int    $status
     */
    private function answer($text, $status = 200)
    {
        if ($status !== 200) {
            header('HTTP/1.1 ' . (int) $status);
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');

        echo $text . "\n";
        exit;
    }
}
