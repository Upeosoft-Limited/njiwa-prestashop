<?php
/**
 * From 0.1.0 to 0.2.0, for a shop that already has this module installed.
 *
 * Two things changed underneath it.
 *
 * The message table gained a "not before" time. Without it every attempt at a
 * message was spent inside a single cron call, so a network that was down for
 * half a minute cost the customer their message for good. CREATE TABLE IF NOT
 * EXISTS leaves an existing table exactly as it was, so the column has to be
 * asked for by name.
 *
 * And {admin_url} stopped being a link to one order, because the token in such
 * a link is built from the id of the employee who was signed in when the
 * settings page was last opened. The stored link is cleared here so that the
 * token in it is not WhatsApped out again; the settings page records the new,
 * tokenless one the next time somebody opens it.
 *
 * @author    UPEO.AI
 * @copyright 2026 UPEO.AI
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Njiwa $module
 *
 * @return bool
 */
function upgrade_module_0_2_0($module)
{
    Configuration::deleteByName(NjiwaSettings::ADMIN_LINK);

    return NjiwaQueue::addNextAttemptColumn();
}
