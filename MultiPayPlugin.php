<?php

/**
 * @file plugins/paymethod/multipay/MultiPayPlugin.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MultiPayPlugin
 *
 * @ingroup plugins_paymethod_multipay
 *
 * @brief Multi-gateway payment plugin class
 */

namespace APP\plugins\paymethod\multipay;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\Role;
use PKP\components\forms\context\PKPPaymentSettingsForm;
use PKP\components\forms\FieldHTML;
use PKP\db\DAORegistry;
use PKP\plugins\Hook;
use PKP\plugins\PaymethodPlugin;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldSelect;
use PKP\components\forms\FieldText;
use PKP\components\forms\FieldTextarea;
use PKP\plugins\PluginRegistry;

class MultipayPlugin extends PaymethodPlugin
{
    /**
     * @see Plugin::getName
     */
    public function getName()
    {
        return 'multipay';
    }

    /**
     * @see Plugin::getDisplayName
     */
    public function getDisplayName()
    {
        return __('plugins.paymethod.multipay.displayName');
    }

    /**
     * @see Plugin::getDescription
     */
    public function getDescription()
    {
        return __('plugins.paymethod.multipay.description');
    }

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        $this->addLocaleData();
        Hook::add('Form::config::before', [$this, 'addSettings'], Hook::SEQUENCE_LATE);
        Hook::add('Form::config::after', [$this, 'finalizePaymentSettingsConfig'], Hook::SEQUENCE_LATE);
        return true;
    }

    /**
     * Add settings to the payments form
     *
     * @param string $hookName
     * @param \PKP\components\forms\FormComponent $form
     */
    public function addSettings($hookName, $form)
    {
        $id = (is_object($form) && property_exists($form, 'id')) ? $form->id : 'unknown';
        if (!$form instanceof \PKP\components\forms\FormComponent || $form->id !== PKPPaymentSettingsForm::FORM_PAYMENT_SETTINGS) {
            return;
        }
        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return false;
        }

        // Only take over the payment settings form when MultiPay is the
        // journal's selected payment plugin. Otherwise leave the stock
        // selector and the other gateways' settings groups untouched.
        if (strtolower((string) $context->getData('paymentPluginName')) !== strtolower((string) $this->getName())) {
            return;
        }

        $request = Application::get()->getRequest();
        $callbackUrl = $request->url(null, 'payment', 'plugin', [$this->getName(), 'return']);
        $gatewayOptions = $this->getInstalledGatewayOptions();
        $gatewayOptionValues = array_map(fn($option) => $option['value'], $gatewayOptions);
        $enabledGateways = $this->normalizeGatewayList((array) $this->getSetting($context->getId(), 'enabledGateways'));
        $enabledGateways = array_values(array_intersect($enabledGateways, $gatewayOptionValues));
        $fallbackGateway = $this->normalizeGatewayId((string) $this->getSetting($context->getId(), 'fallbackGateway'));
        $currentPluginField = $form->getField('paymentPluginName');
        $currentPlugin = $currentPluginField ? (string) $currentPluginField->value : '';

        // The stock payment plugin selector is a core field added before hooks run.
        // Remove it from the form object here, then strip it again from final config
        // in finalizePaymentSettingsConfig() as a fallback against later mutations.
        $form->removeField('paymentPluginName');
        // Remove the per-gateway groups (and their fields) object-safely. The core
        // FormComponent::removeGroup() accesses fields as arrays ($field['groupId'])
        // while they are Field objects here, which fatals; filter directly instead.
        $removeGroups = ['manualPayment', 'paypalpayment', 'paystackpayment', 'flutterwavepayment', 'paystackpaymentgateway', 'flutterwavepaymentgateway'];
        $form->groups = array_values(array_filter(
            $form->groups,
            fn($group) => !in_array((string) ($group['id'] ?? ''), $removeGroups, true)
        ));
        $form->fields = array_values(array_filter(
            $form->fields,
            function ($field) use ($removeGroups) {
                $groupId = is_object($field) ? ($field->groupId ?? null) : ($field['groupId'] ?? null);
                return !in_array((string) $groupId, $removeGroups, true);
            }
        ));

        // paymentPluginName is persisted by the core _payments endpoint, not by plugin settings.
        $form->addHiddenField('paymentPluginName', $this->getName());

        $form->addGroup([
            'id' => 'multipay',
            'label' => __('plugins.paymethod.multipay.displayName'),
            'showWhen' => 'paymentsEnabled',
        ])
            ->addField(new FieldText('paymentPluginNameReadonly', [
                'label' => __('plugins.paymethod.multipay.settings.pluginMode'),
                'value' => __('plugins.paymethod.multipay.settings.pluginMode.value'),
                'groupId' => 'multipay',
                'isInert' => true,
            ]))
            ->addField(new FieldOptions('testMode', [
                'label' => __('plugins.paymethod.multipay.settings.testMode'),
                'options' => [
                    ['value' => true, 'label' => __('common.enable')]
                ],
                'value' => (bool) $this->getSetting($context->getId(), 'testMode'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldSelect('multipayGatewayTab', [
                'label' => __('plugins.paymethod.multipay.settings.gatewayTabs'),
                'options' => $gatewayOptions,
                'value' => !empty($gatewayOptionValues) ? $gatewayOptionValues[0] : '',
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldTextarea('allowedCurrencies', [
                'label' => __('plugins.paymethod.multipay.settings.allowedCurrencies'),
                'description' => __('plugins.paymethod.multipay.settings.allowedCurrencies.description'),
                'value' => $this->getSetting($context->getId(), 'allowedCurrencies'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldOptions('enabledGateways', [
                'label' => __('plugins.paymethod.multipay.settings.enabledGateways'),
                'options' => $gatewayOptions,
                'value' => $enabledGateways,
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldTextarea('gatewayRoutingJson', [
                'label' => __('plugins.paymethod.multipay.settings.gatewayRoutingJson'),
                'description' => __('plugins.paymethod.multipay.settings.gatewayRoutingJson.description'),
                'value' => $this->getSetting($context->getId(), 'gatewayRoutingJson'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldText('fallbackGateway', [
                'label' => __('plugins.paymethod.multipay.settings.fallbackGateway'),
                'value' => $fallbackGateway,
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldText('logLevel', [
                'label' => __('plugins.paymethod.multipay.settings.logLevel'),
                'value' => $this->getSetting($context->getId(), 'logLevel') ?: 'error',
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldText('amountTolerance', [
                'label' => __('plugins.paymethod.multipay.settings.amountTolerance'),
                'value' => (string) ($this->getSetting($context->getId(), 'amountTolerance') ?: '0.01'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldText('callbackUrl', [
                'label' => __('plugins.paymethod.multipay.settings.callbackUrl'),
                'value' => $callbackUrl,
                'groupId' => 'multipay',
                'isInert' => true,
            ]))
            ->addField(new FieldHTML('multipayGatewayHint', [
                'label' => '',
                'description' => '<div class="pkpNotification pkpNotification--notice"><p style="margin:0;">' . __('plugins.paymethod.multipay.settings.gatewayTabs.description') . '</p></div>',
                'groupId' => 'multipay',
            ]));

        foreach ($gatewayOptions as $option) {
            $mode = strtolower((string) $option['value']) === strtolower($currentPlugin) ? __('plugins.paymethod.multipay.settings.gatewayMode.active') : __('plugins.paymethod.multipay.settings.gatewayMode.available');
            $form->addField(new FieldHTML('multipayGatewayTabInfo_' . $option['value'], [
                'label' => '',
                'description' => '<div class="pkpNotification pkpNotification--default"><p style="margin:0;"><strong>' . htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8') . '</strong> — ' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '</p></div>',
                'groupId' => 'multipay',
                'showWhen' => ['multipayGatewayTab', $option['value']],
            ]));
        }

        return true;
    }

    /**
     * Finalize payment settings configuration after all plugin hooks run.
     * This avoids ordering issues where other payment groups are added after this plugin.
     */
    public function finalizePaymentSettingsConfig($hookName, $args)
    {
        $config = & $args[0];
        $form = $args[1];

        if ($form->id !== PKPPaymentSettingsForm::FORM_PAYMENT_SETTINGS) {
            return;
        }

        // Mirror the addSettings() gate: only strip other gateways' groups
        // when MultiPay is the journal's selected payment plugin.
        $context = Application::get()->getRequest()->getContext();
        if (!$context || strtolower((string) $context->getData('paymentPluginName')) !== strtolower((string) $this->getName())) {
            return;
        }

        $groupsToRemove = ['paystackpayment', 'flutterwavepayment', 'paypalpayment', 'manualPayment', 'paystackpaymentgateway', 'flutterwavepaymentgateway'];

        if (isset($config['groups']) && is_array($config['groups'])) {
            $config['groups'] = array_values(array_filter($config['groups'], function ($group) use ($groupsToRemove) {
                $id = is_array($group) ? ($group['id'] ?? null) : null;
                return !$id || !in_array($id, $groupsToRemove, true);
            }));
        }

        if (isset($config['fields']) && is_array($config['fields'])) {
            $config['fields'] = array_values(array_filter($config['fields'], function ($field) use ($groupsToRemove) {
                if (!is_array($field)) {
                    return true;
                }
                $name = $field['name'] ?? null;
                $groupId = $field['groupId'] ?? null;
                if ($name === 'paymentPluginName') {
                    return false;
                }
                return !$groupId || !in_array($groupId, $groupsToRemove, true);
            }));
        }
    }

    /**
     * @copydoc PaymethodPlugin::saveSettings
     */
    public function saveSettings(string $hookname, array $args)
    {
        $illuminateRequest = $args[0];
        $request = $args[1];
        $updatedSettings = $args[3];

        $allParams = $illuminateRequest->input();
        $saveParams = [];
        foreach ($allParams as $param => $val) {
            switch ($param) {
                case 'allowedCurrencies':
                case 'gatewayRoutingJson':
                case 'fallbackGateway':
                case 'logLevel':
                case 'amountTolerance':
                    $saveParams[$param] = (string) $val;
                    break;
                case 'testMode':
                    $saveParams[$param] = $val === 'true';
                    break;
                case 'enabledGateways':
                    $saveParams[$param] = $this->normalizeGatewayList((array) $val);
                    break;
            }
        }
        $contextId = $request->getContext()->getId();
        if (isset($saveParams['fallbackGateway'])) {
            $saveParams['fallbackGateway'] = $this->normalizeGatewayId((string) $saveParams['fallbackGateway']);
        }
        $this->updateSetting($contextId, 'paymentPluginName', $this->getName());
        if (empty($saveParams['enabledGateways'])) {
            $gatewayOptions = $this->getInstalledGatewayOptions();
            if (!empty($gatewayOptions)) {
                $saveParams['enabledGateways'] = array_column($gatewayOptions, 'value');
            }
        }
        foreach ($saveParams as $param => $val) {
            $this->updateSetting($contextId, $param, $val);
            $updatedSettings->put($param, $val);
        }
    }

    /**
     * @copydoc PaymethodPlugin::getPaymentForm()
     */
    public function getPaymentForm($context, $queuedPayment)
    {
        require_once(dirname(__FILE__) . '/MultiPayPaymentForm.php');
        return new MultiPayPaymentForm($this, $queuedPayment);
    }

    /**
     * @copydoc PaymethodPlugin::isConfigured
     */
    public function isConfigured($context)
    {
        if (!$context) {
            return false;
        }
        $enabledGateways = $this->normalizeGatewayList((array) $this->getSetting($context->getId(), 'enabledGateways'));
        return !empty($enabledGateways);
    }

    public function getEnabledGatewayChoices(int $contextId): array
    {
        $options = $this->getInstalledGatewayOptions();
        $enabled = $this->normalizeGatewayList((array) $this->getSetting($contextId, 'enabledGateways'));
        $labels = [];
        foreach ($options as $option) {
            $labels[$option['value']] = $option['label'];
        }
        $choices = [];
        foreach ($enabled as $gateway) {
            $choices[] = [
                'id' => $gateway,
                'label' => $labels[$gateway] ?? $gateway,
            ];
        }
        return $choices;
    }

    /**
     * Handle incoming requests (initiate, callback, webhook)
     */
    public function handle($args, $request)
    {
        $op = isset($args[0]) ? $args[0] : 'default';
        
        switch ($op) {
            case 'initiate':
                $this->handleInitiate($request);
                break;
            case 'return': // Callback from gateway
                $this->handleReturn($request);
                break;
            case 'webhook':
                $this->handleWebhook($request, isset($args[1]) ? $args[1] : null);
                break;
            case 'manage':
                $this->handleManage($request);
                break;
            default:
                parent::handle($args, $request);
        }
    }

    /**
     * Whether the current user may use the payment management console for this
     * context. Journal managers (for the context) and site administrators only.
     */
    protected function currentUserCanManage($request, $context): bool
    {
        $user = $request->getUser();
        if (!$user) {
            return false;
        }
        return $user->hasRole([Role::ROLE_ID_MANAGER], $context->getId())
            || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID);
    }

    protected function handleManage($request): void
    {
        $context = $request->getContext();
        if (!$context) {
            $request->redirect(null, 'index');
        }
        // AUTHORIZATION: the management console (transaction list, refunds,
        // settlement reports, recurring profiles) is restricted to journal
        // managers and site administrators. Without this, CSRF was the only
        // gate, so any authenticated user able to obtain a token could issue
        // refunds. Deny everyone else before any side effect runs.
        if (!$this->currentUserCanManage($request, $context)) {
            $request->getDispatcher()->handle404();
            return;
        }
        $op = $request->getUserVar('op') ?: 'transactions';
        $action = $request->getUserVar('action');
        if ($action && !$request->checkCSRF()) {
            throw new \Exception('Invalid CSRF token.');
        }
        require_once(dirname(__FILE__) . '/classes/services/MetadataService.php');
        require_once(dirname(__FILE__) . '/classes/services/WebhookLogService.php');
        require_once(dirname(__FILE__) . '/classes/services/RoutingService.php');
        require_once(dirname(__FILE__) . '/classes/services/ReconciliationService.php');
        require_once(dirname(__FILE__) . '/classes/services/RecurringService.php');
        $metadataService = new \APP\plugins\paymethod\multipay\classes\services\MetadataService();
        $webhookLogService = new \APP\plugins\paymethod\multipay\classes\services\WebhookLogService();
        $routingService = new \APP\plugins\paymethod\multipay\classes\services\RoutingService();
        $reconciliationService = new \APP\plugins\paymethod\multipay\classes\services\ReconciliationService();
        $recurringService = new \APP\plugins\paymethod\multipay\classes\services\RecurringService();

        $templateMgr = TemplateManager::getManager($request);
        if ($action === 'refund') {
            $gateway = (string) $request->getUserVar('gateway');
            $providerTxId = (string) $request->getUserVar('providerTxId');
            $amount = (float) $request->getUserVar('amount');
            $currency = (string) $request->getUserVar('currency');
            $reference = (string) $request->getUserVar('reference');
            $adapter = $this->getAdapter($gateway, $context->getId());
            if ($adapter && $adapter->supportsRefunds()) {
                $refundResult = $adapter->refund($providerTxId, $amount, $currency);
                \Illuminate\Support\Facades\DB::table('multipay_refunds')->insert([
                    'context_id' => (int) $context->getId(),
                    'gateway' => $gateway,
                    'reference' => $reference,
                    'provider_tx_id' => $providerTxId,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                    'status' => $refundResult['status'],
                    'response_payload' => json_encode($refundResult['raw'] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($action === 'settlementReport') {
            $start = $request->getUserVar('periodStart') ?: date('Y-m-01');
            $end = $request->getUserVar('periodEnd') ?: date('Y-m-d');
            $summary = $reconciliationService->snapshot((int) $context->getId());
            \Illuminate\Support\Facades\DB::table('multipay_settlement_reports')->insert([
                'context_id' => (int) $context->getId(),
                'period_start' => $start,
                'period_end' => $end,
                'summary_json' => json_encode($summary),
                'created_at' => now(),
            ]);
            \Illuminate\Support\Facades\DB::table('multipay_reconciliation_jobs')->insert([
                'context_id' => (int) $context->getId(),
                'job_type' => 'settlement',
                'status' => 'completed',
                'result_json' => json_encode($summary),
                'created_at' => now(),
            ]);
        }
        $templateMgr->assign([
            'pluginName' => $this->getName(),
            'manageUrl' => $request->url(null, 'payment', 'plugin', [$this->getName(), 'manage']),
            'op' => $op,
        ]);

        if ($op === 'transactions') {
            $transactions = $metadataService->listTransactions($context->getId());
            foreach ($transactions as $tx) {
                $tx->canRefund = $this->gatewaySupportsRefunds((string) ($tx->gateway ?? ''), (int) $context->getId())
                    && !empty($tx->provider_tx_id);
            }
            $templateMgr->assign('transactions', $transactions);
        } elseif ($op === 'webhooks') {
            $templateMgr->assign('webhooks', $webhookLogService->listWebhooks($context->getId()));
        } elseif ($op === 'routing') {
            $enabled = (array) $this->getSetting($context->getId(), 'enabledGateways');
            $allowed = $routingService->parseAllowedCurrencies((string) $this->getSetting($context->getId(), 'allowedCurrencies'));
            $map = $routingService->parseRoutingMap((string) $this->getSetting($context->getId(), 'gatewayRoutingJson'));
            $fallback = (string) $this->getSetting($context->getId(), 'fallbackGateway');
            $preview = [];
            foreach ($allowed as $currency) {
                $preview[] = [
                    'currency' => $currency,
                    'gateway' => $routingService->resolveGateway($enabled, $currency, null, $map, $fallback),
                ];
            }
            $templateMgr->assign('routingPreview', $preview);
        } elseif ($op === 'reconciliation') {
            $templateMgr->assign('reconciliation', $reconciliationService->snapshot($context->getId()));
            $templateMgr->assign('reports', \Illuminate\Support\Facades\DB::table('multipay_settlement_reports')->where('context_id', $context->getId())->orderByDesc('id')->limit(50)->get()->all());
        } elseif ($op === 'refunds') {
            $templateMgr->assign('refunds', \Illuminate\Support\Facades\DB::table('multipay_refunds')->where('context_id', $context->getId())->orderByDesc('id')->limit(200)->get()->all());
        } elseif ($op === 'recurring') {
            $templateMgr->assign('recurringCandidates', $recurringService->listRecurringCandidates($context->getId()));
            $templateMgr->assign('recurringProfiles', \Illuminate\Support\Facades\DB::table('multipay_recurring_profiles')->where('context_id', $context->getId())->orderByDesc('id')->limit(200)->get()->all());
        }
        $templateMgr->display($this->getTemplateResource('manage.tpl'));
        exit;
    }

    protected function handleInitiate($request)
    {
        if (!$request->checkCSRF()) {
            throw new \Exception('Invalid CSRF token.');
        }
        $journal = $request->getJournal();
        $queuedPaymentId = $request->getUserVar('queuedPaymentId');
        $userSelectedGateway = $request->getUserVar('gateway');

        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
        $queuedPayment = $queuedPaymentDao->getById($queuedPaymentId);
        
        if (!$queuedPayment) {
             throw new \Exception("Invalid queued payment ID!");
        }

        if ((int) $queuedPayment->getUserId() !== (int) $request->getUser()->getId()) {
            throw new \Exception('Queued payment does not belong to current user.');
        }

        require_once(dirname(__FILE__) . '/classes/services/RoutingService.php');
        require_once(dirname(__FILE__) . '/classes/services/ReferenceService.php');
        $routingService = new \APP\plugins\paymethod\multipay\classes\services\RoutingService();
        $referenceService = new \APP\plugins\paymethod\multipay\classes\services\ReferenceService();
        $enabled = (array) $this->getSetting($journal->getId(), 'enabledGateways');
        $allowedCurrencies = $routingService->parseAllowedCurrencies((string) $this->getSetting($journal->getId(), 'allowedCurrencies'));
        $currency = strtoupper($queuedPayment->getCurrencyCode());
        if (!empty($allowedCurrencies) && !in_array($currency, $allowedCurrencies, true)) {
            throw new \Exception('Currency not allowed.');
        }
        $routingMap = $routingService->parseRoutingMap((string) $this->getSetting($journal->getId(), 'gatewayRoutingJson'));
        $fallbackGateway = (string) $this->getSetting($journal->getId(), 'fallbackGateway');
        $gatewayName = $routingService->resolveGateway($enabled, $currency, $userSelectedGateway, $routingMap, $fallbackGateway);
        if (!$gatewayName) {
            throw new \Exception('No eligible gateway found.');
        }
        $gatewayName = $this->normalizeGatewayId((string) $gatewayName);
        $adapter = $this->getAdapter($gatewayName, $journal->getId());
        if ($adapter && $adapter->supportsCurrency($currency)) {
            $reference = $referenceService->generateReference((int) $queuedPaymentId);
            $traceId = $referenceService->generateTraceId();
            $testMode = (bool) $this->getSetting($journal->getId(), 'testMode');
            if (!$testMode && empty($_SERVER['HTTPS'])) {
                throw new \Exception('HTTPS is required outside test mode.');
            }

            $callbackUrl = $request->url(null, 'payment', 'plugin', [$this->getName(), 'return'], [
                'queuedPaymentId' => $queuedPaymentId,
                'gateway' => $gatewayName
            ]);
            $cancelUrl = $queuedPayment->getRequestUrl();

            $params = [
                'amount' => $queuedPayment->getAmount(),
                'currency' => $queuedPayment->getCurrencyCode(),
                'email' => $request->getUser()->getEmail(),
                'reference' => $reference,
                'callbackUrl' => $callbackUrl,
                'cancelUrl' => $cancelUrl,
                'metadata' => [
                    'queuedPaymentId' => $queuedPaymentId,
                    'contextId' => $journal->getId(),
                    'gateway' => $gatewayName,
                    'traceId' => $traceId,
                ]
            ];

            try {
                $result = $adapter->initializePayment($params);
                require_once(dirname(__FILE__) . '/classes/services/MetadataService.php');
                $metadataService = new \APP\plugins\paymethod\multipay\classes\services\MetadataService();
                $metadataService->createTransaction([
                    'context_id' => $journal->getId(),
                    'queued_payment_id' => (int) $queuedPaymentId,
                    'completed_payment_id' => null,
                    'gateway' => $gatewayName,
                    'reference' => (string) ($result['reference'] ?? $reference),
                    'provider_tx_id' => $result['provider_tx_id'] ?? null,
                    'status' => 'initiated',
                    'amount' => $queuedPayment->getAmount(),
                    'currency' => $currency,
                    'trace_id' => $traceId,
                ]);

                if (!empty($result['redirectUrl'])) {
                    $request->redirectUrl($result['redirectUrl']);
                }
                throw new \Exception("Gateway did not return a redirect URL.");
            } catch (\Exception $e) {
                error_log('MultiPay initiation error: ' . $e->getMessage());
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign('message', 'plugins.paymethod.multipay.error.initiationFailed');
                $templateMgr->display('frontend/pages/message.tpl');
            }
            return;
        }

        $delegatePlugin = $this->getPaymethodPlugin($gatewayName);
        if ($delegatePlugin && strtolower($delegatePlugin->getName()) !== strtolower($this->getName())) {
            require_once(dirname(__FILE__) . '/classes/services/MetadataService.php');
            $metadataService = new \APP\plugins\paymethod\multipay\classes\services\MetadataService();
            $metadataService->createTransaction([
                'context_id' => $journal->getId(),
                'queued_payment_id' => (int) $queuedPaymentId,
                'completed_payment_id' => null,
                'gateway' => $gatewayName,
                'reference' => 'delegated_' . time(),
                'provider_tx_id' => null,
                'status' => 'delegated',
                'amount' => $queuedPayment->getAmount(),
                'currency' => $currency,
                'trace_id' => null,
            ]);
            $paymentForm = $delegatePlugin->getPaymentForm($journal, $queuedPayment);
            if ($paymentForm) {
                $paymentForm->display($request);
                return;
            }
            throw new \Exception('Selected gateway cannot render payment form.');
        }
        throw new \Exception('Selected gateway does not support this currency.');
    }

    protected function handleReturn($request)
    {
        $journal = $request->getJournal();
        $queuedPaymentId = $request->getUserVar('queuedPaymentId');
        $gatewayName = $request->getUserVar('gateway'); // We passed this in callback URL
        
        // Some gateways might return reference in different params
        $reference = $request->getUserVar('reference') ?? $request->getUserVar('tx_ref') ?? $request->getUserVar('trxref') ?? $request->getUserVar('paymentId');
        if (strtolower((string) $gatewayName) === 'paypalpayment' && $request->getUserVar('paymentId') && $request->getUserVar('PayerID')) {
            $reference = $request->getUserVar('paymentId') . '|' . $request->getUserVar('PayerID');
        }

        if (!$reference) {
             // Try to get from session or other means if needed
             throw new \Exception("No reference found in callback.");
        }

        $adapter = $this->getAdapter($gatewayName, $journal->getId());
        if (!$adapter) {
             throw new \Exception("Invalid gateway in return handler.");
        }

        try {
            $verification = $adapter->verifyTransaction($reference);
            
            if (($verification['status'] ?? '') === 'success') {
                $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
                $queuedPayment = $queuedPaymentDao->getById($queuedPaymentId);

                if ($queuedPayment) {
                    if ((int) $queuedPayment->getContextId() !== (int) $journal->getId()) {
                        throw new \Exception('Context mismatch');
                    }
                    $expectedAmount = (float) $queuedPayment->getAmount();
                    $expectedCurrency = strtoupper($queuedPayment->getCurrencyCode());
                    $actualAmount = (float) ($verification['amount'] ?? 0);
                    $actualCurrency = strtoupper((string) ($verification['currency'] ?? ''));
                    $tolerance = (float) ($this->getSetting($journal->getId(), 'amountTolerance') ?: 0.01);
                    if ($actualCurrency !== $expectedCurrency) {
                        throw new \Exception('Currency mismatch');
                    }
                    if (abs($actualAmount - $expectedAmount) > $tolerance) {
                        throw new \Exception('Amount mismatch');
                    }
                    require_once(dirname(__FILE__) . '/classes/services/MetadataService.php');
                    require_once(dirname(__FILE__) . '/classes/services/IdempotencyService.php');
                    $metadataService = new \APP\plugins\paymethod\multipay\classes\services\MetadataService();
                    $metadataService->syncTransactionReferenceByQueuedPayment(
                        (int) $journal->getId(),
                        (int) $queuedPayment->getId(),
                        (string) $gatewayName,
                        (string) $reference,
                        isset($verification['provider_tx_id']) ? (string) $verification['provider_tx_id'] : null,
                        'verified'
                    );
                    if ($metadataService->hasCompletedQueuedPayment((int) $queuedPaymentId)) {
                        $request->redirectUrl($queuedPayment->getRequestUrl());
                    }
                    $idempotency = new \APP\plugins\paymethod\multipay\classes\services\IdempotencyService();
                    require_once(dirname(__FILE__) . '/classes/services/ReferenceService.php');
                    $referenceService = new \APP\plugins\paymethod\multipay\classes\services\ReferenceService();
                    $dedupeKey = $referenceService->callbackDedupeKey((string) $gatewayName, (string) $reference);
                    if (!$idempotency->claim((int) $journal->getId(), (string) $gatewayName, $dedupeKey)) {
                        $request->redirectUrl($queuedPayment->getRequestUrl());
                    }
                    $paymentManager = Application::get()->getPaymentManager($journal);
                    $paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());
                    $completedPaymentId = $this->lookupCompletedPaymentId($queuedPayment);
                    $metadataService->markCompletedByQueuedPayment(
                        (int) $journal->getId(),
                        (int) $queuedPayment->getId(),
                        (string) $gatewayName,
                        (string) $reference,
                        $completedPaymentId,
                        isset($verification['provider_tx_id']) ? (string) $verification['provider_tx_id'] : null
                    );
                    $request->redirectUrl($queuedPayment->getRequestUrl());
                } else {
                    error_log("Queued payment $queuedPaymentId not found during fulfillment.");
                }
            } else {
                throw new \Exception("Transaction verification failed: " . $verification['status']);
            }

        } catch (\Exception $e) {
            error_log('MultiPay return error: ' . $e->getMessage());
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.multipay.error.verificationFailed');
            $templateMgr->display('frontend/pages/message.tpl');
        }
    }

    protected function handleWebhook($request, $gatewayName)
    {
        $journal = $request->getJournal();
        if (!$journal) {
            http_response_code(400);
            exit();
        }
        $gatewayName = (string) $gatewayName;
        $adapter = $this->getAdapter($gatewayName, $journal->getId());
        if (!$adapter) {
            http_response_code(404);
            exit();
        }
        $payload = file_get_contents('php://input') ?: '';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($k, 5)));
                $headers[$name] = $v;
            }
        }
        $verified = $adapter->validateWebhook($payload, $headers);
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $eventType = (string) ($decoded['event'] ?? ($decoded['type'] ?? 'unknown'));
        $reference = null;
        $normalized = [];
        try {
            $normalized = $adapter->normalizeEvent($decoded);
            $reference = $normalized['reference'] ?? null;
        } catch (\Throwable $e) {
        }
        require_once(dirname(__FILE__) . '/classes/services/WebhookLogService.php');
        $webhookLogService = new \APP\plugins\paymethod\multipay\classes\services\WebhookLogService();
        $webhookLogService->log((int) $journal->getId(), $gatewayName, $eventType, $reference ? (string) $reference : null, $verified, $payload);
        if (!$verified) {
            http_response_code(400);
            exit();
        }
        if ($reference) {
            require_once(dirname(__FILE__) . '/classes/services/IdempotencyService.php');
            require_once(dirname(__FILE__) . '/classes/services/ReferenceService.php');
            $idempotency = new \APP\plugins\paymethod\multipay\classes\services\IdempotencyService();
            $referenceService = new \APP\plugins\paymethod\multipay\classes\services\ReferenceService();
            $dedupeKey = $referenceService->webhookDedupeKey($gatewayName, $eventType, (string) $reference);
            if (!$idempotency->claim((int) $journal->getId(), $gatewayName, $dedupeKey)) {
                http_response_code(200);
                exit();
            }
            require_once(dirname(__FILE__) . '/classes/services/MetadataService.php');
            $metadata = new \APP\plugins\paymethod\multipay\classes\services\MetadataService();
            $metadata->upsertTransactionStatus((int) $journal->getId(), 0, $gatewayName, (string) $reference, 'webhook_received', null);
            $tx = $metadata->findByReference($gatewayName, (string) $reference);
            $status = strtolower((string) ($normalized['status'] ?? ''));
            if ($tx && (int) $tx->queued_payment_id > 0 && in_array($status, ['success', 'successful', 'completed'], true)) {
                if (!$metadata->hasCompletedQueuedPayment((int) $tx->queued_payment_id)) {
                    $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
                    $queuedPayment = $queuedPaymentDao->getById((int) $tx->queued_payment_id);
                    if ($queuedPayment && (int) $queuedPayment->getContextId() === (int) $journal->getId()) {
                        $this->assertPaymentMatchesQueuedPayment(
                            $queuedPayment,
                            (float) ($normalized['amount'] ?? 0),
                            strtoupper((string) ($normalized['currency'] ?? '')),
                            (string) $reference,
                            (float) ($this->getSetting($journal->getId(), 'amountTolerance') ?: 0.01)
                        );
                        $paymentManager = Application::get()->getPaymentManager($journal);
                        $paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());
                        $completedPaymentId = $this->lookupCompletedPaymentId($queuedPayment);
                        $metadata->markCompletedByQueuedPayment(
                            (int) $journal->getId(),
                            (int) $queuedPayment->getId(),
                            (string) $gatewayName,
                            (string) $reference,
                            $completedPaymentId,
                            isset($normalized['provider_tx_id']) ? (string) $normalized['provider_tx_id'] : null
                        );
                    }
                }
            }
        }
        http_response_code(200);
        exit();
    }

    protected function getAdapter($gatewayName, $contextId)
    {
        require_once(dirname(__FILE__) . '/classes/GatewayAdapterInterface.php');
        require_once(dirname(__FILE__) . '/classes/HttpClient.php');

        $gatewayName = strtolower($this->normalizeGatewayId((string) $gatewayName));

        if (in_array($gatewayName, ['paystack', 'paystackplugin'], true)) {
            require_once(dirname(__FILE__) . '/classes/PaystackAdapter.php');
            $paystackPlugin = $this->getPaymethodPlugin('paystackplugin');
            $testMode = $paystackPlugin ? (bool) $paystackPlugin->getSetting($contextId, 'testMode') : false;
            $fallbackPublic = $paystackPlugin ? (string) $paystackPlugin->getSetting($contextId, $testMode ? 'testPublicKey' : 'livePublicKey') : '';
            $fallbackSecret = $paystackPlugin ? (string) $paystackPlugin->getSetting($contextId, $testMode ? 'testSecretKey' : 'liveSecretKey') : '';
            return new \APP\plugins\paymethod\multipay\classes\PaystackAdapter(
                (string) ($this->getSetting($contextId, 'paystackPublicKey') ?: $fallbackPublic),
                (string) ($this->getSetting($contextId, 'paystackSecretKey') ?: $fallbackSecret),
                new \APP\plugins\paymethod\multipay\classes\HttpClient()
            );
        } elseif (in_array($gatewayName, ['flutterwave', 'flutterwaveplugin'], true)) {
            require_once(dirname(__FILE__) . '/classes/FlutterwaveAdapter.php');
            $flutterwavePlugin = $this->getPaymethodPlugin('flutterwaveplugin');
            $testMode = $flutterwavePlugin ? (bool) $flutterwavePlugin->getSetting($contextId, 'testMode') : false;
            $fallbackPublic = $flutterwavePlugin ? (string) $flutterwavePlugin->getSetting($contextId, $testMode ? 'testPublicKey' : 'livePublicKey') : '';
            $fallbackSecret = $flutterwavePlugin ? (string) $flutterwavePlugin->getSetting($contextId, $testMode ? 'testSecretKey' : 'liveSecretKey') : '';
            $fallbackWebhook = $flutterwavePlugin ? (string) $flutterwavePlugin->getSetting($contextId, 'webhookHash') : '';
            return new \APP\plugins\paymethod\multipay\classes\FlutterwaveAdapter(
                (string) ($this->getSetting($contextId, 'flutterwavePublicKey') ?: $fallbackPublic),
                (string) ($this->getSetting($contextId, 'flutterwaveSecretKey') ?: $fallbackSecret),
                (string) ($this->getSetting($contextId, 'flutterwaveWebhookSecret') ?: $fallbackWebhook),
                new \APP\plugins\paymethod\multipay\classes\HttpClient()
            );
        } elseif (in_array($gatewayName, ['paypalpayment', 'paypal'], true)) {
            require_once(dirname(__FILE__) . '/classes/PaypalAdapter.php');
            $paypalPlugin = $this->getPaymethodPlugin('paypalpayment');
            if (!$paypalPlugin) {
                return null;
            }
            return new \APP\plugins\paymethod\multipay\classes\PaypalAdapter(
                (string) $paypalPlugin->getSetting($contextId, 'clientId'),
                (string) $paypalPlugin->getSetting($contextId, 'secret'),
                (bool) $paypalPlugin->getSetting($contextId, 'testMode')
            );
        }
        return null;
    }

    protected function getInstalledGatewayOptions(): array
    {
        $paymentPlugins = PluginRegistry::loadCategory('paymethod', true);
        $options = [];
        foreach ($paymentPlugins as $plugin) {
            $name = $this->normalizeGatewayId((string) $plugin->getName());
            if (strtolower($name) === strtolower($this->getName())) {
                continue;
            }
            $options[] = [
                'value' => $name,
                'label' => $plugin->getDisplayName(),
            ];
        }
        usort($options, function ($a, $b) {
            return strcmp((string) $a['label'], (string) $b['label']);
        });
        return $options;
    }

    protected function gatewaySupportsRefunds(string $gatewayName, int $contextId): bool
    {
        $adapter = $this->getAdapter($gatewayName, $contextId);
        return $adapter ? $adapter->supportsRefunds() : false;
    }

    protected function assertPaymentMatchesQueuedPayment($queuedPayment, float $actualAmount, string $actualCurrency, string $reference, float $tolerance): void
    {
        $expectedAmount = (float) $queuedPayment->getAmount();
        $expectedCurrency = strtoupper((string) $queuedPayment->getCurrencyCode());
        if ($reference === '') {
            throw new \Exception('Missing reference');
        }
        if ($actualCurrency !== $expectedCurrency) {
            throw new \Exception('Currency mismatch');
        }
        if (abs($actualAmount - $expectedAmount) > $tolerance) {
            throw new \Exception('Amount mismatch');
        }
    }

    protected function lookupCompletedPaymentId($queuedPayment): int
    {
        $completedPaymentDao = DAORegistry::getDAO('OJSCompletedPaymentDAO');
        $completedPayment = $completedPaymentDao
            ? $completedPaymentDao->getByAssoc($queuedPayment->getUserId(), $queuedPayment->getType(), $queuedPayment->getAssocId())
            : null;
        return $completedPayment ? (int) $completedPayment->getId() : 0;
    }

    protected function normalizeGatewayId(string $gateway): string
    {
        $gateway = trim($gateway);
        if ($gateway === '') {
            return $gateway;
        }
        $normalized = strtolower($gateway);
        $map = [
            'paystack' => 'paystackplugin',
            'flutterwave' => 'flutterwaveplugin',
            'paypal' => 'paypalpayment',
            'manual' => 'manualpayment',
        ];
        return $map[$normalized] ?? $gateway;
    }

    protected function normalizeGatewayList(array $gateways): array
    {
        $normalized = [];
        foreach ($gateways as $gateway) {
            $value = $this->normalizeGatewayId((string) $gateway);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return array_values(array_unique($normalized));
    }

    protected function getPaymethodPlugin(string $gatewayName)
    {
        $paymentPlugins = PluginRegistry::loadCategory('paymethod', true);
        $needle = strtolower($this->normalizeGatewayId($gatewayName));
        foreach ($paymentPlugins as $plugin) {
            if (strtolower($this->normalizeGatewayId((string) $plugin->getName())) === $needle) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        require_once(dirname(__FILE__) . '/migrations/MultiPayMigration.php');
        return new \APP\plugins\paymethod\multipay\migrations\MultiPayMigration();
    }
}


