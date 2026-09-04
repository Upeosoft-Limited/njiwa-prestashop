<?php
/**
 * Njiwa for PrestaShop.
 *
 * WhatsApp your customers when their order is paid, sent or cancelled, and get
 * a message yourself when one comes in.
 *
 * PHP 7.4 is the floor on purpose, and PrestaShop 1.7 with it. Plenty of shops
 * that would benefit from this are on hosting that has not moved, and a module
 * they cannot install is worth nothing to them.
 *
 * @author    UPEO.AI
 * @copyright 2026 UPEO.AI
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

define('NJIWA_VERSION', '0.2.0');

require_once dirname(__FILE__) . '/classes/NjiwaException.php';
require_once dirname(__FILE__) . '/classes/NjiwaLog.php';
require_once dirname(__FILE__) . '/classes/NjiwaSettings.php';
require_once dirname(__FILE__) . '/classes/NjiwaNumbers.php';
require_once dirname(__FILE__) . '/classes/NjiwaTemplates.php';
require_once dirname(__FILE__) . '/classes/NjiwaClient.php';
require_once dirname(__FILE__) . '/classes/NjiwaQueue.php';
require_once dirname(__FILE__) . '/classes/NjiwaNotifier.php';

class Njiwa extends Module
{
    /** Long enough that nobody can hammer the button, short enough to be usable. */
    const TEST_MESSAGE_INTERVAL = 30;

    public function __construct()
    {
        $this->name = 'njiwa';
        $this->tab = 'administration';
        $this->version = NJIWA_VERSION;
        $this->author = 'UPEO.AI';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Njiwa WhatsApp');
        $this->description = $this->l(
            'Sends your customers a WhatsApp message when their order is paid, sent, cancelled or refunded,'
            . ' and sends you one when an order comes in. Every message is off until you turn it on.'
        );
        $this->confirmUninstall = $this->l(
            'This removes the Njiwa settings, including the API key, and the record of which messages have'
            . ' already been sent. Notes already written on your orders stay where they are.'
        );
    }

    /**
     * @return bool
     */
    public function install()
    {
        // Settings are written for every shop, so that a multistore install
        // does not end up with the module switched on in one shop and unknown
        // in the next.
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()) {
            return false;
        }

        // The one hook this module needs. It fires when an order's status
        // genuinely changes, which is the only moment worth telling anybody
        // about.
        if (!$this->registerHook('actionOrderStatusPostUpdate')) {
            return false;
        }

        if (!NjiwaQueue::createTable()) {
            return false;
        }

        foreach (NjiwaSettings::defaults() as $key => $value) {
            NjiwaSettings::put($key, $value);
        }

        // Made now rather than the first time somebody looks at the settings,
        // so the cron address is the same from the beginning.
        NjiwaSettings::cronToken();

        return true;
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        // The key goes with the module. A live API key sitting in the
        // configuration table of a shop that no longer has the module is a key
        // nobody is looking after any more.
        foreach (NjiwaSettings::allKeys() as $key) {
            Configuration::deleteByName($key);
        }

        NjiwaQueue::dropTable();

        return parent::uninstall();
    }

    /**
     * An order's status genuinely changed.
     *
     * PrestaShop fires this after the change has been written, from the
     * checkout, from the order page, from a bulk update and from every payment
     * module, which is what makes it the right hook: it is not "the order was
     * saved", so editing an address does not message anybody.
     *
     * It fires again if the same status is set a second time, which happens
     * more often than people expect. Sending twice is prevented in the queue,
     * where it belongs, rather than here.
     *
     * @param array $params
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        try {
            $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
            $state = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;

            // Most callers pass the state object. A few pass its id, so both
            // are accepted rather than quietly doing nothing for those shops.
            if (!($state instanceof OrderState) && is_numeric($state)) {
                $state = new OrderState((int) $state);
            }

            if ($idOrder <= 0 || !($state instanceof OrderState) || !Validate::isLoadedObject($state)) {
                return;
            }

            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order)) {
                return;
            }

            NjiwaNotifier::onOrderStatus($order, $state);
        } catch (Throwable $e) {
            // An order must never fail to change status because a WhatsApp
            // message could not be arranged. This is the last line of that
            // promise; everything inside has its own.
            NjiwaLog::write('Could not arrange a message: ' . $e->getMessage());
        }
    }

    /**
     * The configuration page.
     *
     * @return string
     */
    public function getContent()
    {
        // This is the back office, which is the only place an admin link can
        // be built, so the shape of one is recorded while we are standing
        // here. NjiwaSettings::rememberAdminLink explains why.
        NjiwaSettings::rememberAdminLink($this->adminLinkBase());

        $output = '';

        // POST only. Tools::isSubmit is happy with a query string, and a
        // link that sends a WhatsApp message is a link somebody can be made
        // to click.
        $posted = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';

        if ($posted && (Tools::isSubmit('submitNjiwa')
            || Tools::isSubmit('submitNjiwaTest')
            || Tools::isSubmit('submitNjiwaSendTest')
            || Tools::isSubmit('submitNjiwaRetry'))) {
            $output .= $this->saveSettings();

            if (Tools::isSubmit('submitNjiwaTest')) {
                $output .= $this->testConnection();
            } elseif (Tools::isSubmit('submitNjiwaSendTest')) {
                $output .= $this->sendTestMessage();
            } elseif (Tools::isSubmit('submitNjiwaRetry')) {
                $output .= $this->resendFailed();
            }
        }

        $output .= $this->healthNotices();

        return $output . $this->renderForm();
    }

    /**
     * Save everything the form posted.
     *
     * The tests save first and check afterwards, so what is tested is what the
     * shop will actually use, and nothing anybody typed is thrown away by
     * pressing the wrong button.
     *
     * @return string Whatever the merchant needs telling.
     */
    private function saveSettings()
    {
        $notices = '';

        // Only what this page actually posted is written. PrestaShop renders
        // a form helper as one form per panel on some versions and as a single
        // form on others, so saving a field that was not on screen would quietly
        // wipe whichever panel the merchant was not looking at.
        if (Tools::getIsset(NjiwaSettings::ENABLED)) {
            NjiwaSettings::put(NjiwaSettings::ENABLED, Tools::getValue(NjiwaSettings::ENABLED) ? 1 : 0);
        }

        // An empty box means "keep the key I already saved", because the box
        // is never filled in with the key that is already there. Clearing a
        // key is done by pasting a new one, or by removing the module.
        $key = trim((string) Tools::getValue(NjiwaSettings::API_KEY));
        if ($key !== '') {
            NjiwaSettings::put(NjiwaSettings::API_KEY, NjiwaSettings::protect($key));

            if (strpos($key, 'sk_test_') !== 0 && strpos($key, 'sk_live_') !== 0) {
                $notices .= $this->alert(
                    'warning',
                    $this->l('That key does not begin sk_test_ or sk_live_. It has been saved, but check you pasted the whole thing.')
                );
            }
        }

        if (Tools::getIsset(NjiwaSettings::BASE_URL)) {
            $url = trim((string) Tools::getValue(NjiwaSettings::BASE_URL));
            if ($url === '') {
                $url = NjiwaSettings::DEFAULT_BASE_URL;
            }

            if (!preg_match('#^https?://[^\s/]+#i', $url)) {
                $notices .= $this->alert(
                    'danger',
                    $this->l('The Njiwa address has to start with https:// and name a host, so it was left as it was.')
                );
            } else {
                NjiwaSettings::put(NjiwaSettings::BASE_URL, rtrim($url, '/'));
            }
        }

        if (Tools::getIsset(NjiwaSettings::FROM)) {
            $from = preg_replace('/\D/', '', (string) Tools::getValue(NjiwaSettings::FROM));
            NjiwaSettings::put(NjiwaSettings::FROM, $from);

            if ($from !== '' && strpos($from, '0') === 0) {
                // A recipient is read against the sending number's country, so
                // a leading zero on one of those is fine. On the sending number
                // there is no country to read it against, and Njiwa will refuse
                // it.
                $notices .= $this->alert(
                    'warning',
                    $this->l('Send from starts with a zero. It needs the full international form, such as 254712345678.')
                );
            }
        }

        if (Tools::getIsset(NjiwaSettings::ADMIN_NUMBERS)) {
            $rawNumbers = (string) Tools::getValue(NjiwaSettings::ADMIN_NUMBERS);
            NjiwaSettings::put(NjiwaSettings::ADMIN_NUMBERS, $rawNumbers);
            $notices .= $this->numberWarnings($rawNumbers);
        }

        foreach (NjiwaSettings::events() as $event) {
            if (Tools::getIsset(NjiwaSettings::eventKey($event))) {
                NjiwaSettings::put(
                    NjiwaSettings::eventKey($event),
                    Tools::getValue(NjiwaSettings::eventKey($event)) ? 1 : 0
                );
            }

            if (Tools::getIsset(NjiwaSettings::templateKey($event))) {
                $template = (string) Tools::getValue(NjiwaSettings::templateKey($event));
                NjiwaSettings::put(NjiwaSettings::templateKey($event), $template);
                $notices .= $this->templateWarnings($event, $template);
            }
        }

        return $this->alert('success', $this->l('Settings saved.')) . $notices;
    }

    /**
     * Anything typed into "Your WhatsApp numbers" that will not be messaged.
     *
     * @return string
     */
    private function numberWarnings($raw)
    {
        $pieces = array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw)));
        $usable = NjiwaNumbers::parseList($raw);

        if (count($pieces) <= count($usable)) {
            return '';
        }

        return $this->alert(
            'warning',
            $this->l('Some of your WhatsApp numbers were not usable and will not be messaged. A number needs at least seven digits, and a WhatsApp group address is never accepted.')
            . ' ' . sprintf(
                $this->l('Messages will go to: %s'),
                $usable ? '+' . implode(', +', $usable) : $this->l('nobody')
            )
        );
    }

    /**
     * A placeholder that does not exist is removed before sending, which is
     * the right thing to do to a customer and no help at all to the merchant
     * unless somebody says so at the moment they typed it.
     *
     * @return string
     */
    private function templateWarnings($event, $template)
    {
        if (!preg_match_all('/\{[a-z_]+\}/', (string) $template, $found)) {
            return '';
        }

        $known = array_keys(NjiwaTemplates::placeholders());
        $unknown = array_values(array_unique(array_diff($found[0], $known)));

        if (empty($unknown)) {
            return '';
        }

        return $this->alert(
            'warning',
            sprintf(
                $this->l('The wording for "%1$s" uses %2$s, which is not a placeholder this module knows. It will be removed before the message is sent.'),
                htmlspecialchars($this->eventLabel($event), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(implode(', ', $unknown), ENT_QUOTES, 'UTF-8')
            )
        );
    }

    /**
     * Test connection. Lists the numbers this key really has. Sends nothing.
     *
     * @return string
     */
    private function testConnection()
    {
        try {
            $numbers = NjiwaClient::instances();
        } catch (NjiwaException $e) {
            return $this->alert('danger', $this->escape($e->getMessage()));
        }

        $lines = array();

        if (NjiwaSettings::isTestKey()) {
            $lines[] = '<strong>' . $this->l('This is a test key.') . '</strong> '
                . $this->l('Every message is checked and stored, and nothing reaches WhatsApp. Swap it for a key beginning sk_live_ when you are ready.');
        }

        if (empty($numbers)) {
            $lines[] = $this->l('The key works, but this account has no numbers yet. Add one in the Njiwa console under Numbers and link it.');
        } else {
            $listed = array();
            foreach ($numbers as $number) {
                $listed[] = $this->escape(isset($number['label']) ? $number['label'] : '')
                    . ' &mdash; ' . $this->escape(!empty($number['msisdn']) ? '+' . $number['msisdn'] : $this->l('not linked yet'))
                    . ' (' . $this->escape(isset($number['status']) ? $number['status'] : '') . ')';
            }
            $lines[] = $this->l('Connected. This key can send from:') . '<br>' . implode('<br>', $listed);
        }

        $from = NjiwaSettings::from();
        if ($from !== '') {
            $known = array();
            foreach ($numbers as $number) {
                if (!empty($number['msisdn'])) {
                    $known[] = preg_replace('/\D/', '', $number['msisdn']);
                }
            }
            if (!in_array($from, $known, true)) {
                $lines[] = '<strong>' . $this->l('Send from does not match any number on this account, so every message will be refused.') . '</strong> '
                    . $this->l('Correct it, or clear it to use the number marked default in the console.');
            }
        }

        return $this->alert('info', implode('<br><br>', $lines));
    }

    /**
     * Send test message. One fixed message, to one number the operator types.
     *
     * The wording is fixed in the code on purpose: the operator supplies the
     * recipient and nothing else, so this button cannot be turned into a way
     * of sending arbitrary text from the shop's WhatsApp number.
     *
     * @return string
     */
    private function sendTestMessage()
    {
        $last = (int) Configuration::get(NjiwaSettings::TEST_SENT_AT);
        $wait = self::TEST_MESSAGE_INTERVAL - (time() - $last);
        if ($last > 0 && $wait > 0) {
            return $this->alert(
                'warning',
                sprintf($this->l('A test message was sent a moment ago. Try again in %d seconds.'), $wait)
            );
        }

        $typed = trim((string) Tools::getValue('NJIWA_TEST_TO'));
        $number = NjiwaNumbers::toMsisdn($typed);

        if ($number === '' && $typed === '') {
            $own = NjiwaSettings::adminNumbers();
            $number = $own ? $own[0] : '';
        }

        if ($number === '') {
            return $this->alert(
                'danger',
                $this->l('Put a WhatsApp number in the test box, in full international form such as 254712345678. The test goes there and nowhere else, never to a customer.')
            );
        }

        Configuration::updateValue(NjiwaSettings::TEST_SENT_AT, time());

        try {
            $answer = NjiwaClient::sendText(
                $number,
                sprintf(
                    $this->l('Test message from %s. If you can read this, PrestaShop can reach your customers on WhatsApp.'),
                    Configuration::get('PS_SHOP_NAME')
                )
            );
        } catch (NjiwaException $e) {
            return $this->alert('danger', $this->escape($e->getMessage()));
        }

        $message = sprintf(
            $this->l('Sent to +%1$s (%2$s).'),
            $this->escape($number),
            $this->escape(isset($answer['id']) ? $answer['id'] : '?')
        );

        if (NjiwaSettings::isTestKey()) {
            $message .= ' <strong>' . $this->l('This is a test key, so nothing actually reached the phone.') . '</strong>';
        }

        return $this->alert('success', $message);
    }

    /**
     * Put the messages this module gave up on back in the queue.
     *
     * Everything revived here was refused by Njiwa or ran out of attempts, and
     * both of those mean the message was never accepted, so this is not a way
     * of sending anything a second time. A send that was interrupted and can
     * no longer be checked against Njiwa's idempotency window is deliberately
     * not included, because that one might already have arrived.
     *
     * @return string
     */
    private function resendFailed()
    {
        $count = NjiwaQueue::reviveFailed();

        if ($count < 1) {
            return $this->alert('info', $this->l('There was nothing waiting to be sent again.'));
        }

        NjiwaNotifier::scheduleDrain();

        return $this->alert(
            'success',
            sprintf(
                $this->l('%d message(s) are back in the queue. Anything that fails for the same reason ends up back here.'),
                $count
            )
        );
    }

    /**
     * The things that are wrong right now and would otherwise only be found
     * out when a customer did not get a message.
     *
     * @return string
     */
    private function healthNotices()
    {
        $notices = '';

        if (NjiwaSettings::isApiKeyUnreadable()) {
            $notices .= $this->alert(
                'danger',
                $this->l('The saved API key cannot be read by this shop any more. That happens when a shop is moved or its cookie key is regenerated. Paste the key in again and save.')
            );
        }

        if (NjiwaSettings::isEnabled() && !NjiwaSettings::isConfigured()) {
            $notices .= $this->alert(
                'warning',
                $this->l('There is no API key saved yet, so nothing can be sent. Paste one in and press Test connection.')
            );
        }

        if (!NjiwaSettings::isEnabled()) {
            $notices .= $this->alert(
                'warning',
                $this->l('Send WhatsApp messages is off. Every setting here is kept and nothing at all is sent.')
            );
        }

        $failed = NjiwaQueue::countFailed();
        if ($failed > 0) {
            $notices .= $this->alert(
                'warning',
                sprintf(
                    $this->l('%d message(s) were not sent and nothing will try them again on its own. The reason for each one is written on the order it belongs to. The button under The message to you puts them all back in the queue.'),
                    $failed
                )
            );
        }

        $abandoned = NjiwaQueue::countAbandoned();
        if ($abandoned > 0) {
            $notices .= $this->alert(
                'warning',
                sprintf(
                    $this->l('%d send(s) were interrupted more than a day ago, and this module cannot tell whether they arrived. They are not tried again, because a second copy to a customer is worse than none at all. The Njiwa console shows what was really sent.'),
                    $abandoned
                )
            );
        }

        $waiting = NjiwaQueue::countWaiting();
        if ($waiting > 0) {
            $notices .= $this->alert(
                'info',
                sprintf(
                    $this->l('%d message(s) are waiting to go out. They are normally sent within seconds of an order changing status. A number that stays here means nothing is emptying the queue, and the cron address below is the fix.'),
                    $waiting
                )
            );
        }

        return $notices;
    }

    /**
     * @return string
     */
    private function renderForm()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitNjiwa';
        $helper->show_toolbar = false;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->tpl_vars = array(
            'fields_value' => $this->fieldsValue(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        );

        return $helper->generateForm($this->formFields());
    }

    /**
     * @return array
     */
    private function fieldsValue()
    {
        $values = array(
            NjiwaSettings::ENABLED => (int) NjiwaSettings::isEnabled(),
            // Never filled in. The key is not sent back to the browser, and an
            // empty box means "keep the one that is saved".
            NjiwaSettings::API_KEY => '',
            NjiwaSettings::BASE_URL => NjiwaSettings::baseUrl(),
            NjiwaSettings::FROM => Configuration::get(NjiwaSettings::FROM),
            NjiwaSettings::ADMIN_NUMBERS => Configuration::get(NjiwaSettings::ADMIN_NUMBERS),
            'NJIWA_TEST_TO' => '',
        );

        foreach (NjiwaSettings::events() as $event) {
            $values[NjiwaSettings::eventKey($event)] = (int) NjiwaSettings::isEventOn($event);
            $values[NjiwaSettings::templateKey($event)] = NjiwaSettings::template($event);
        }

        return $values;
    }

    /**
     * The form.
     *
     * Every field carries its own description. A setting whose meaning has to
     * be looked up somewhere else is a setting people get wrong.
     *
     * @return array
     */
    private function formFields()
    {
        $forms = array();

        $forms[] = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Connection'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Send WhatsApp messages'),
                        'name' => NjiwaSettings::ENABLED,
                        'is_bool' => true,
                        'desc' => $this->l('The master switch. Turn it off and this module stops sending anything at all, without losing your key, your numbers or your wording. Orders carry on exactly as before.'),
                        'values' => $this->switchValues(NjiwaSettings::ENABLED),
                    ),
                    array(
                        'type' => 'password',
                        'label' => $this->l('API key'),
                        'name' => NjiwaSettings::API_KEY,
                        'desc' => $this->apiKeyDescription(),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Njiwa address'),
                        'name' => NjiwaSettings::BASE_URL,
                        'desc' => $this->l('Leave this exactly as it is. It exists for shops that have been given their own Njiwa address, and changing it otherwise stops messages reaching anybody.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Send from'),
                        'name' => NjiwaSettings::FROM,
                        'placeholder' => '254712345678',
                        'desc' => $this->l('Which of your linked WhatsApp numbers these messages come from. Digits only, in full international form, such as 254712345678. Leave it empty to use the number marked default in the console, which is the right answer if you have one number.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Test message to'),
                        'name' => 'NJIWA_TEST_TO',
                        'placeholder' => '254712345678',
                        'desc' => $this->l('Only used by the Send test message button. It is not saved, and the test never goes to a customer. Left empty, the test goes to the first of your own numbers below.'),
                    ),
                    array(
                        'type' => 'html',
                        'label' => $this->l('Check it works'),
                        'name' => 'NJIWA_BUTTONS',
                        'html_content' => $this->testButtons(),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ),
            ),
        );

        $customer = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Messages to your customers'),
                    'icon' => 'icon-comments',
                ),
                'description' => $this->placeholderHelp() . '<br><br>' . $this->stateSummary(),
                'input' => array(),
            ),
        );

        foreach (NjiwaSettings::customerEvents() as $event) {
            $customer['form']['input'][] = array(
                'type' => 'switch',
                'label' => $this->eventLabel($event),
                'name' => NjiwaSettings::eventKey($event),
                'is_bool' => true,
                'desc' => $this->eventHelp($event),
                'values' => $this->switchValues(NjiwaSettings::eventKey($event)),
            );
            $customer['form']['input'][] = array(
                'type' => 'textarea',
                'label' => $this->l('Wording'),
                'name' => NjiwaSettings::templateKey($event),
                'rows' => 5,
                'cols' => 60,
                'desc' => sprintf(
                    $this->l('What that message says. Leave it empty and nothing is sent for %s, whatever the switch above says.'),
                    htmlspecialchars($this->eventLabel($event), ENT_QUOTES, 'UTF-8')
                ),
            );
        }

        $customer['form']['submit'] = array(
            'title' => $this->l('Save'),
            'class' => 'btn btn-default pull-right',
        );

        $forms[] = $customer;

        $forms[] = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('The message to you'),
                    'icon' => 'icon-bell',
                ),
                'description' => $this->l('One message when an order becomes real. It is sent on the first status that means money is on the way, not the moment somebody reaches the payment page, so an abandoned checkout never wakes you up.'),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Tell me about new orders'),
                        'name' => NjiwaSettings::eventKey('alert'),
                        'is_bool' => true,
                        'desc' => $this->l('Send me a WhatsApp message when an order comes in. Once per order, whichever of the statuses below it reaches first.'),
                        'values' => $this->switchValues(NjiwaSettings::eventKey('alert')),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Your WhatsApp numbers'),
                        'name' => NjiwaSettings::ADMIN_NUMBERS,
                        'placeholder' => '254712345678, 254733000111',
                        'desc' => $this->l('Where that message goes. Digits only, in full international form, separated by commas if there are several. Everybody listed gets their own copy.'),
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Wording'),
                        'name' => NjiwaSettings::templateKey('alert'),
                        'rows' => 5,
                        'cols' => 60,
                        'desc' => $this->l('What that message says. {admin_url} adds a link to your back office. It stops at the front door rather than opening the order, because a link that opens one order carries the sign-in token of whoever last opened this page.'),
                    ),
                    array(
                        'type' => 'html',
                        'label' => $this->l('If messages sit in the queue'),
                        'name' => 'NJIWA_CRON',
                        'html_content' => $this->cronHelp(),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ),
            ),
        );

        return $forms;
    }

    /**
     * @return array
     */
    private function switchValues($name)
    {
        return array(
            array('id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')),
            array('id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')),
        );
    }

    /**
     * @return string
     */
    private function apiKeyDescription()
    {
        $description = $this->l('A key beginning sk_test_ checks and stores every message and delivers nothing, which is what you want while you set this up. A key beginning sk_live_ sends to real phones and costs money. The console shows a key once and keeps only its fingerprint, so a lost key is replaced rather than recovered.');

        $hint = NjiwaSettings::apiKeyHint();
        if ($hint !== '') {
            $description .= '<br><br><strong>' . sprintf(
                $this->l('A key ending %s is saved.'),
                htmlspecialchars($hint, ENT_QUOTES, 'UTF-8')
            ) . '</strong> ' . $this->l('It is never shown again. Leave this box empty to keep it, or paste a new key over it.');
        }

        return $description;
    }

    /**
     * The two buttons. They are written by hand rather than left to the form
     * helper so that the markup is the same on every PrestaShop this module
     * supports, and they submit the form they are standing in, so anything
     * typed is saved before it is tested.
     *
     * @return string
     */
    private function testButtons()
    {
        return '<button type="submit" name="submitNjiwaTest" class="btn btn-default">'
            . '<i class="icon-exchange"></i> ' . $this->l('Test connection') . '</button> '
            . '<button type="submit" name="submitNjiwaSendTest" class="btn btn-default">'
            . '<i class="icon-paper-plane"></i> ' . $this->l('Send test message') . '</button>'
            . '<p class="help-block">'
            . $this->l('Test connection lists the WhatsApp numbers your account really has and sends nothing. Send test message sends one fixed message to the number in the box above. Both save this page first, so what you test is what your shop will use.')
            . '</p>';
    }

    /**
     * @return string
     */
    private function cronHelp()
    {
        return '<p class="help-block">'
            . $this->l('Messages are sent just after the request that queued them, so on nearly every shop there is nothing to set up and the checkout never waits. Where your hosting cannot do that at all, the last resort is that the page which changed the order status sends up to three of them itself and leaves the rest in the queue. A cron job every five minutes on this address is the real answer:')
            . '</p><p><code style="word-break:break-all">' . $this->escape(NjiwaSettings::cronUrl()) . '</code></p>'
            . '<p class="help-block">'
            . $this->l('Keep that address private. It is what stops a stranger emptying your queue, and it is the only thing that does.')
            . '</p><p class="help-block">'
            . $this->l('A message that cannot go out is tried three times, waiting five minutes and then twenty between attempts, because most of what stops a message is a network that comes back. After the third attempt it is left alone and the reason is written on the order. Nothing tries it again by itself, and this is where you ask for it.')
            . '</p>'
            . $this->resendButton();
    }

    /**
     * The way back for a message that ran out of attempts. Without it, a
     * network that was down for half an hour costs a customer their message
     * for good, because the module will not claim that order, event and number
     * again.
     *
     * @return string
     */
    private function resendButton()
    {
        $failed = NjiwaQueue::countFailed();

        if ($failed < 1) {
            return '<p class="help-block">' . $this->l('No message has been given up on.') . '</p>';
        }

        return '<button type="submit" name="submitNjiwaRetry" class="btn btn-default">'
            . '<i class="icon-repeat"></i> '
            . sprintf($this->l('Send the %d message(s) that failed again'), $failed)
            . '</button>';
    }

    /**
     * The placeholder list, built from the code that does the replacing, so
     * the two cannot drift apart.
     *
     * @return string
     */
    private function placeholderHelp()
    {
        $rows = array();
        foreach (NjiwaTemplates::placeholders() as $token => $meaning) {
            $rows[] = '<code>' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '</code> &mdash; '
                . htmlspecialchars($meaning, ENT_QUOTES, 'UTF-8');
        }

        return $this->l('Each message is plain text. Anything in braces is filled in from the order:')
            . '<br>' . implode('<br>', $rows);
    }

    /**
     * Which of this shop's own order statuses each message is attached to.
     *
     * Worth printing, because the answer is different in every shop and a
     * merchant who has renamed a status, or whose payment module added its
     * own, needs to see what this module thinks it is looking at.
     *
     * @return string
     */
    private function stateSummary()
    {
        $lines = array();

        foreach (NjiwaNotifier::stateMap() as $event => $ids) {
            if ($event === 'alert') {
                continue;
            }

            $names = array();
            foreach ($ids as $id) {
                $state = new OrderState((int) $id, (int) $this->context->language->id);
                if (Validate::isLoadedObject($state)) {
                    $names[] = htmlspecialchars($state->name, ENT_QUOTES, 'UTF-8');
                }
            }

            $lines[] = '<strong>' . htmlspecialchars($this->eventLabel($event), ENT_QUOTES, 'UTF-8') . '</strong> &mdash; '
                . ($names ? implode(', ', $names) : $this->l('no status in this shop, so this one never fires'));
        }

        return $this->l('In this shop, each message is sent when an order reaches one of these statuses:')
            . '<br>' . implode('<br>', $lines) . '<br>'
            . $this->l('Any other status counts too if it is ticked "Set the order as shipped" or "Consider the associated order as validated", which is how a payment or shipping module adds its own.');
    }

    /**
     * @return string
     */
    private function eventLabel($event)
    {
        $labels = array(
            'awaiting' => $this->l('Order placed, payment not in yet'),
            'paid' => $this->l('Payment received'),
            'shipped' => $this->l('Shipped'),
            'cancelled' => $this->l('Cancelled'),
            'refunded' => $this->l('Refunded'),
            'alert' => $this->l('New order, to you'),
        );

        return isset($labels[$event]) ? $labels[$event] : $event;
    }

    /**
     * @return string
     */
    private function eventHelp($event)
    {
        $help = array(
            'awaiting' => $this->l('For bank transfer, cheque, cash on delivery and anything else where the order is placed before the money arrives. Tell them you have it and that you are waiting.'),
            'paid' => $this->l('The one most shops want. Payment has landed and you are getting the order ready.'),
            'shipped' => $this->l('It has left you. PrestaShop sends its own email at the same moment; this arrives where people actually look.'),
            'cancelled' => $this->l('Worth sending. A cancellation nobody explained is what turns into a phone call.'),
            'refunded' => $this->l('Money is on its way back. Saying so stops the "where is my refund" message before it is sent. It goes out when you move the order to Refunded, not when you write a credit slip.'),
        );

        return isset($help[$event]) ? $help[$event] : '';
    }

    /**
     * Where {admin_url} points.
     *
     * The address of the back office cannot be worked out from the front of
     * the shop, so it is built here, in the back office, and kept. It is the
     * front door and nothing more.
     *
     * A link that opens one order needs a security token, and PrestaShop
     * builds that token from the id of the employee who is signed in. Sending
     * one would put whichever employee last opened this page into a WhatsApp
     * message going to every number on the list, and it would not work for any
     * of the others in any case.
     *
     * @return string
     */
    private function adminLinkBase()
    {
        if (!defined('_PS_ADMIN_DIR_')) {
            return '';
        }

        try {
            return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . basename(_PS_ADMIN_DIR_) . '/';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @return string
     */
    private function alert($type, $html)
    {
        return '<div class="alert alert-' . $type . '">' . $html . '</div>';
    }

    /**
     * Anything that came from Njiwa, from the network or from a customer is
     * printed through here. An error message is not a place to render HTML
     * somebody else wrote.
     *
     * @return string
     */
    private function escape($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
