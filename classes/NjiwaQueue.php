<?php
/**
 * The list of messages this module has decided to send, and the thing that
 * stops any of them going twice.
 *
 * PrestaShop has no general queue and no job runner, so the module keeps its
 * own list. A row is written while the order is being saved, which is one
 * INSERT and nothing else; the sending happens afterwards, off the request.
 *
 * The unique key is the whole point of the table. A row is claimed before a
 * message is attempted, and a second attempt at the same order, event and
 * number collides with it and stops there. That covers an order that arrives
 * at the same status twice, a merchant who bulk-updates the same orders again,
 * and two workers racing each other.
 *
 * The row outlives the send. Njiwa's Idempotency-Key covers twenty-four hours;
 * this covers the order.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NjiwaQueue
{
    const TABLE = 'njiwa_message';

    const STATUS_QUEUED = 'queued';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    /**
     * The send was interrupted and this module cannot tell whether it landed.
     * Kept apart from "failed" because a failed message is safe to send again
     * and this one is not.
     */
    const STATUS_ABANDONED = 'abandoned';

    /**
     * How many times one message is attempted before it is left alone.
     *
     * Retrying is only safe because every attempt carries the same
     * Idempotency-Key, so an attempt that reached Njiwa but died on the way
     * back is replayed rather than sent again.
     */
    const MAX_TRIES = 3;

    /**
     * How long a message waits before the attempt after this one, in minutes,
     * counted by attempts already made.
     *
     * Attempts back to back are not retries, they are the same failure three
     * times: a name server that is not answering is not answering a second
     * later either. The waits widen because the failures worth retrying are
     * outages, and the longer one has lasted the longer it tends to last.
     */
    const RETRY_MINUTES = array(1 => 5, 2 => 20);

    /**
     * How long a row may sit in "sending" before this module stops hoping.
     *
     * It is Njiwa's idempotency window. Inside it, repeating an interrupted
     * send is safe, because Njiwa replays its first answer instead of sending
     * again. Outside it, the same request would be a second message to the
     * customer.
     */
    const ABANDON_HOURS = 24;

    /**
     * A row that has been in "sending" this long belongs to a request that
     * died. Long enough that a slow send is never taken away from the worker
     * that is still doing it.
     */
    const STALE_MINUTES = 15;

    public static function tableName()
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    /**
     * @return bool
     */
    public static function createTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::tableName() . '` (
            `id_njiwa_message` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `id_shop` int(10) unsigned NOT NULL DEFAULT 1,
            `id_order` int(10) unsigned NOT NULL,
            `id_order_state` int(10) unsigned NOT NULL DEFAULT 0,
            `event` varchar(32) NOT NULL,
            `recipient` varchar(32) NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT \'queued\',
            `tries` tinyint(3) unsigned NOT NULL DEFAULT 0,
            `njiwa_id` varchar(64) DEFAULT NULL,
            `detail` varchar(255) DEFAULT NULL,
            `date_add` datetime NOT NULL,
            `date_upd` datetime NOT NULL,
            `date_next` datetime NOT NULL,
            PRIMARY KEY (`id_njiwa_message`),
            UNIQUE KEY `njiwa_once` (`id_shop`,`id_order`,`event`,`recipient`),
            KEY `njiwa_pending` (`status`,`id_njiwa_message`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

        try {
            return (bool) Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            NjiwaLog::write('Could not create the message table: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return bool
     */
    public static function dropTable()
    {
        try {
            return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::tableName() . '`');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Claim one send.
     *
     * @return int|null The row id when this caller may go ahead, or null when
     *                  this message has already been claimed and must not be
     *                  sent again.
     */
    public static function claim($idShop, $idOrder, $event, $recipient, $idOrderState = 0)
    {
        $sql = 'INSERT IGNORE INTO `' . self::tableName() . '`
            (`id_shop`, `id_order`, `id_order_state`, `event`, `recipient`, `status`, `tries`,
                `date_add`, `date_upd`, `date_next`)
            VALUES (' . (int) $idShop . ', ' . (int) $idOrder . ', ' . (int) $idOrderState . ',
                \'' . pSQL($event) . '\', \'' . pSQL($recipient) . '\',
                \'' . self::STATUS_QUEUED . '\', 0, NOW(), NOW(), NOW())';

        try {
            Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            // The database being unwell is not a reason to send anyway.
            // Refusing is the safe answer: a message that goes out twice
            // cannot be taken back, and one that does not go out leaves this
            // line behind.
            NjiwaLog::write(
                'Could not claim the "' . $event . '" message for order ' . (int) $idOrder . ': ' . $e->getMessage(),
                NjiwaLog::ERROR,
                (int) $idOrder
            );

            return null;
        }

        // INSERT IGNORE turns the duplicate-key collision into no rows
        // written, which is the ordinary outcome of an event that fires twice
        // and is not worth a line in the log.
        if ((int) Db::getInstance()->Affected_Rows() < 1) {
            return null;
        }

        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Messages waiting to go out, and due to be tried now.
     *
     * `date_next` is what keeps a retry a retry. Without it, a cron that
     * drains the queue five times in one call would spend all three attempts
     * on the same connection error inside a second and then give up for good.
     *
     * Rows left in "sending" by a request that died are picked up again once
     * they are old enough, because the alternative is a message that silently
     * never arrives. Rows older than Njiwa's idempotency window are not, and
     * expireStale closes them instead.
     *
     * @return array<int,array>
     */
    public static function pending($limit = 20)
    {
        $sql = 'SELECT * FROM `' . self::tableName() . '`
            WHERE `tries` < ' . (int) self::MAX_TRIES . '
            AND `date_next` <= NOW()
            AND (`status` = \'' . self::STATUS_QUEUED . '\'
                OR (`status` = \'' . self::STATUS_SENDING . '\'
                    AND `date_upd` < DATE_SUB(NOW(), INTERVAL ' . (int) self::STALE_MINUTES . ' MINUTE)
                    AND `date_upd` > DATE_SUB(NOW(), INTERVAL ' . (int) self::ABANDON_HOURS . ' HOUR)))
            ORDER BY `id_njiwa_message` ASC
            LIMIT ' . (int) $limit;

        try {
            $rows = Db::getInstance()->executeS($sql);
        } catch (Throwable $e) {
            NjiwaLog::write('Could not read the message queue: ' . $e->getMessage());

            return array();
        }

        return is_array($rows) ? $rows : array();
    }

    /**
     * Take one row for this worker.
     *
     * The status and the attempt count the row was read with are both part of
     * the condition, so if another worker took it in the meantime this one is
     * told no and moves on.
     *
     * The next attempt is pushed out before the send rather than after it, so
     * that a worker killed mid-send leaves a row nobody touches again for the
     * length of the wait instead of one the next cron picks up a second later.
     *
     * @return bool
     */
    public static function take(array $row)
    {
        $tries = (int) $row['tries'] + 1;

        $sql = 'UPDATE `' . self::tableName() . '`
            SET `status` = \'' . self::STATUS_SENDING . '\',
                `tries` = ' . $tries . ',
                `date_upd` = NOW(),
                `date_next` = ' . self::nextAttemptSql($tries) . '
            WHERE `id_njiwa_message` = ' . (int) $row['id_njiwa_message'] . '
            AND `status` = \'' . pSQL($row['status']) . '\'
            AND `tries` = ' . (int) $row['tries'];

        try {
            Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            return false;
        }

        return (int) Db::getInstance()->Affected_Rows() === 1;
    }

    public static function markSent($id, $njiwaId)
    {
        self::finish($id, self::STATUS_SENT, null, $njiwaId);
    }

    /**
     * Njiwa refused it, or it has been tried as often as it is going to be.
     * The row stays, so this is never tried again on its own; the reason is
     * written on the order as well, because that is where the merchant is
     * looking. Nothing here is lost for ever: reviveFailed puts these back
     * when the merchant asks for it on the settings page.
     */
    public static function markFailed($id, $reason)
    {
        self::finish($id, self::STATUS_FAILED, $reason);
    }

    /**
     * Nothing was sent and nothing was wrong: the wording for this event is
     * empty, which is how a merchant turns one message off.
     */
    public static function markSkipped($id, $reason)
    {
        self::finish($id, self::STATUS_SKIPPED, $reason);
    }

    /**
     * Put it back for another go. Only ever called for a failure that means
     * the message was never accepted.
     *
     * @param int $triesUsed How many attempts have been spent, which decides
     *                       how long the next one waits.
     */
    public static function requeue($id, $reason, $triesUsed)
    {
        self::finish($id, self::STATUS_QUEUED, $reason, null, self::nextAttemptSql((int) $triesUsed));
    }

    /**
     * Give up on rows whose worker died and never came back.
     *
     * A row still in "sending" a day later belongs to a request that was
     * killed. It may have reached Njiwa before it died, and past the
     * idempotency window there is no way to ask: sending it again would be a
     * second message to a customer who may already have had the first. So it
     * is closed, and the merchant is told, rather than sent.
     *
     * @return int How many were closed.
     */
    public static function expireStale()
    {
        $sql = 'UPDATE `' . self::tableName() . '`
            SET `status` = \'' . self::STATUS_ABANDONED . '\',
                `detail` = \'Interrupted, and too old to repeat safely.\',
                `date_upd` = NOW()
            WHERE `status` = \'' . self::STATUS_SENDING . '\'
            AND `date_upd` < DATE_SUB(NOW(), INTERVAL ' . (int) self::ABANDON_HOURS . ' HOUR)';

        try {
            Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            return 0;
        }

        $closed = (int) Db::getInstance()->Affected_Rows();
        if ($closed > 0) {
            NjiwaLog::write(
                $closed . ' message(s) were interrupted more than ' . (int) self::ABANDON_HOURS
                . ' hours ago and have been left unsent, because repeating them could message a'
                . ' customer twice.',
                NjiwaLog::WARNING
            );
        }

        return $closed;
    }

    /**
     * Put every failed message back in the queue, because a merchant asked.
     *
     * Only rows that were refused or that ran out of attempts come back, and
     * both of those mean Njiwa never accepted the message, so this is not a
     * way of sending anything twice. Rows this module abandoned are left where
     * they are, because for those it genuinely does not know.
     *
     * @return int How many were put back.
     */
    public static function reviveFailed()
    {
        $sql = 'UPDATE `' . self::tableName() . '`
            SET `status` = \'' . self::STATUS_QUEUED . '\',
                `tries` = 0,
                `date_upd` = NOW(),
                `date_next` = NOW()
            WHERE `status` = \'' . self::STATUS_FAILED . '\'';

        try {
            Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            NjiwaLog::write('Could not put the failed messages back: ' . $e->getMessage(), NjiwaLog::ERROR);

            return 0;
        }

        return (int) Db::getInstance()->Affected_Rows();
    }

    /**
     * When a row may next be touched, as a piece of SQL.
     *
     * @param int $triesUsed
     *
     * @return string
     */
    private static function nextAttemptSql($triesUsed)
    {
        $minutes = isset(self::RETRY_MINUTES[$triesUsed]) ? (int) self::RETRY_MINUTES[$triesUsed] : 0;

        return $minutes > 0 ? 'DATE_ADD(NOW(), INTERVAL ' . $minutes . ' MINUTE)' : 'NOW()';
    }

    private static function finish($id, $status, $reason = null, $njiwaId = null, $nextSql = null)
    {
        $sets = array(
            '`status` = \'' . pSQL($status) . '\'',
            '`date_upd` = NOW()',
        );

        if ($reason !== null) {
            $sets[] = '`detail` = \'' . pSQL(Tools::substr((string) $reason, 0, 250)) . '\'';
        }
        if ($njiwaId !== null) {
            $sets[] = '`njiwa_id` = \'' . pSQL((string) $njiwaId) . '\'';
        }
        if ($nextSql !== null) {
            // Built here rather than passed in as a value, because it is a
            // MySQL expression and not something a caller ever supplies.
            $sets[] = '`date_next` = ' . $nextSql;
        }

        try {
            Db::getInstance()->execute(
                'UPDATE `' . self::tableName() . '` SET ' . implode(', ', $sets)
                . ' WHERE `id_njiwa_message` = ' . (int) $id
            );
        } catch (Throwable $e) {
            // The message has already been sent or refused by now. Losing the
            // note of it is not worth throwing over.
            NjiwaLog::write('Could not record the outcome of message ' . (int) $id . ': ' . $e->getMessage());
        }
    }

    /**
     * How many messages are waiting, for the settings page to show. A number
     * that never comes down is how a merchant finds out that nothing is
     * draining the queue.
     *
     * @return int
     */
    public static function countWaiting()
    {
        try {
            return (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . self::tableName() . '`
                WHERE `status` IN (\'' . self::STATUS_QUEUED . '\', \'' . self::STATUS_SENDING . '\')'
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * How many messages this module gave up on, for the settings page to offer
     * to send again. Without this number a spent message is invisible, and the
     * merchant only finds out when a customer says nothing ever arrived.
     *
     * @return int
     */
    public static function countFailed()
    {
        return self::countWithStatus(self::STATUS_FAILED);
    }

    /**
     * How many sends were interrupted and are now too old to repeat. They are
     * shown separately from the failures because they are the one kind this
     * module will not offer to send again.
     *
     * @return int
     */
    public static function countAbandoned()
    {
        return self::countWithStatus(self::STATUS_ABANDONED);
    }

    /**
     * @return int
     */
    private static function countWithStatus($status)
    {
        try {
            return (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . self::tableName() . '`
                WHERE `status` = \'' . pSQL($status) . '\''
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Add anything a table made by an older version of this module is missing.
     *
     * Called from the upgrade script. CREATE TABLE IF NOT EXISTS leaves an
     * existing table exactly as it was, so a column added later has to be
     * asked for by name.
     *
     * @return bool
     */
    public static function addNextAttemptColumn()
    {
        try {
            $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . self::tableName() . '` LIKE \'date_next\'');
            if (!empty($columns)) {
                return true;
            }

            return (bool) Db::getInstance()->execute(
                'ALTER TABLE `' . self::tableName() . '`
                ADD COLUMN `date_next` datetime NOT NULL DEFAULT \'2000-01-01 00:00:00\' AFTER `date_upd`'
            );
        } catch (Throwable $e) {
            NjiwaLog::write('Could not add date_next to the message table: ' . $e->getMessage(), NjiwaLog::ERROR);

            return false;
        }
    }
}
