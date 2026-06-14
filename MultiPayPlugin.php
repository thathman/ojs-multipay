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

class MultiPayPlugin extends PaymethodPlugin
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

        // NOTE (bug fix): we deliberately no longer strip the other gateways'
        // setting groups or hide the core paymentPluginName selector. Keeping
        // them lets staff (a) edit each gateway's API keys directly and
        // (b) switch the journal back to a single gateway via the native
        // selector — which is the genuine "turn MultiPay off" path. MultiPay
        // simply adds its own group alongside the others.

        // Adapter-backed gateways (Paystack/Flutterwave/PayPal) expose the
        // currencies they can settle; staff tick which of those to support. The
        // union of ticked currencies replaces the old free-text "Allowed
        // Currencies" box, and the per-gateway map replaces the routing JSON.
        $adapterGateways = $this->getAdapterBackedGateways($context->getId());
        $fallbackOptions = array_merge(
            [['value' => '', 'label' => __('plugins.paymethod.multipay.settings.fallbackGateway.none')]],
            $gatewayOptions
        );

        $form->addGroup([
            'id' => 'multipay',
            'label' => __('plugins.paymethod.multipay.displayName'),
            'showWhen' => 'paymentsEnabled',
        ]);

        $form->addField(new FieldHTML('multipayActiveNotice', [
                'label' => __('plugins.paymethod.multipay.settings.pluginMode'),
                'description' => '<div class="pkpNotification pkpNotification--success"><p style="margin:0;">' . __('plugins.paymethod.multipay.settings.pluginMode.value') . '</p></div>',
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldOptions('testMode', [
                'label' => __('plugins.paymethod.multipay.settings.testMode'),
                'options' => [
                    ['value' => true, 'label' => __('common.enable')]
                ],
                'value' => (bool) $this->getSetting($context->getId(), 'testMode'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldOptions('enabledGateways', [
                'label' => __('plugins.paymethod.multipay.settings.enabledGateways'),
                'options' => $gatewayOptions,
                'value' => $enabledGateways,
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldHTML('multipayCurrencyHeading', [
                'label' => __('plugins.paymethod.multipay.settings.gatewayCurrencies'),
                'description' => '<p style="margin:0;color:#555;">' . __('plugins.paymethod.multipay.settings.gatewayCurrencies.description') . '</p>',
                'groupId' => 'multipay',
            ]));

        // One checkbox list per ENABLED adapter-backed gateway: the currencies it
        // supports, with the ticked subset MultiPay will accept for it. (Only
        // enabled gateways are shown — currencies are irrelevant for a gateway
        // that is not in service.)
        foreach ($adapterGateways as $gw) {
            if (!in_array($gw['id'], $enabledGateways, true)) {
                continue;
            }
            $form->addField(new FieldOptions('currencies_' . $gw['id'], [
                'label' => $gw['label'],
                'options' => array_map(fn($c) => ['value' => $c, 'label' => $c], $gw['currencies']),
                'value' => $this->getSelectedCurrencies($context->getId(), $gw['id'], $gw['currencies']),
                'groupId' => 'multipay',
            ]));
        }

        $form->addField(new FieldSelect('fallbackGateway', [
                'label' => __('plugins.paymethod.multipay.settings.fallbackGateway'),
                'description' => __('plugins.paymethod.multipay.settings.fallbackGateway.description'),
                'options' => $fallbackOptions,
                'value' => $fallbackGateway,
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
            ->addField(new FieldHTML('multipayRevertHint', [
                'label' => __('plugins.paymethod.multipay.settings.revert'),
                'description' => '<div class="pkpNotification pkpNotification--notice"><p style="margin:0;">' . __('plugins.paymethod.multipay.settings.revert.description') . '</p></div>',
                'groupId' => 'multipay',
            ]));

        // Inline gateway credentials (test + live, switched by Test Mode), plus
        // each gateway's webhook URL to paste into its dashboard. These write to
        // MultiPay's own settings, which getAdapter() reads with priority over
        // each sibling plugin's keys. Secrets are write-only (blank = keep).
        $this->addCredentialFields($form, $context->getId(), $request, $adapterGateways);

        // Display-only currency conversion (FX). Advisory estimate only; the
        // gateway is always charged the journal currency/amount.
        $form->addField(new FieldHTML('multipayFxHeading', [
                'label' => __('plugins.paymethod.multipay.settings.fx'),
                'description' => '<p style="margin:0;color:#555;">' . __('plugins.paymethod.multipay.settings.fx.description') . '</p>',
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldOptions('fxEnabled', [
                'label' => __('plugins.paymethod.multipay.settings.fxEnabled'),
                'options' => [['value' => true, 'label' => __('common.enable')]],
                'value' => $this->getSetting($context->getId(), 'fxEnabled') === null ? true : (bool) $this->getSetting($context->getId(), 'fxEnabled'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldSelect('fxProvider', [
                'label' => __('plugins.paymethod.multipay.settings.fxProvider'),
                'options' => [
                    ['value' => 'yahoo', 'label' => __('plugins.paymethod.multipay.settings.fxProvider.yahoo')],
                    ['value' => 'custom', 'label' => __('plugins.paymethod.multipay.settings.fxProvider.custom')],
                ],
                'value' => (string) ($this->getSetting($context->getId(), 'fxProvider') ?: 'yahoo'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldText('fxProviderUrl', [
                'label' => __('plugins.paymethod.multipay.settings.fxProviderUrl'),
                'description' => __('plugins.paymethod.multipay.settings.fxProviderUrl.description'),
                'value' => (string) $this->getSetting($context->getId(), 'fxProviderUrl'),
                'groupId' => 'multipay',
                'showWhen' => ['fxProvider', 'custom'],
            ]))
            ->addField(new FieldText('fxProviderKey', [
                'label' => __('plugins.paymethod.multipay.settings.fxProviderKey'),
                'value' => (string) $this->getSetting($context->getId(), 'fxProviderKey'),
                'groupId' => 'multipay',
                'showWhen' => ['fxProvider', 'custom'],
            ]))
            ->addField(new FieldText('fxRatePath', [
                'label' => __('plugins.paymethod.multipay.settings.fxRatePath'),
                'value' => (string) ($this->getSetting($context->getId(), 'fxRatePath') ?: 'rate'),
                'groupId' => 'multipay',
                'showWhen' => ['fxProvider', 'custom'],
            ]))
            ->addField(new FieldText('fxMarkupPercent', [
                'label' => __('plugins.paymethod.multipay.settings.fxMarkupPercent'),
                'description' => __('plugins.paymethod.multipay.settings.fxMarkupPercent.description'),
                'value' => (string) ($this->getSetting($context->getId(), 'fxMarkupPercent') ?: '0'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldText('fxCacheTtl', [
                'label' => __('plugins.paymethod.multipay.settings.fxCacheTtl'),
                'description' => __('plugins.paymethod.multipay.settings.fxCacheTtl.description'),
                'value' => (string) ($this->getSetting($context->getId(), 'fxCacheTtl') ?: '43200'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldTextarea('fxDisclaimer', [
                'label' => __('plugins.paymethod.multipay.settings.fxDisclaimer'),
                'value' => (string) ($this->getSetting($context->getId(), 'fxDisclaimer') ?: __('plugins.paymethod.multipay.checkout.fxDisclaimer.default')),
                'groupId' => 'multipay',
            ]))
            // Location / currency detection (best-effort) and gateway suggestion.
            ->addField(new FieldHTML('multipayGeoHeading', [
                'label' => __('plugins.paymethod.multipay.settings.geo'),
                'description' => '<p style="margin:0;color:#555;">' . __('plugins.paymethod.multipay.settings.geo.description') . '</p>',
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldOptions('geoDetectEnabled', [
                'label' => __('plugins.paymethod.multipay.settings.geoDetectEnabled'),
                'options' => [['value' => true, 'label' => __('common.enable')]],
                'value' => $this->getSetting($context->getId(), 'geoDetectEnabled') === null ? true : (bool) $this->getSetting($context->getId(), 'geoDetectEnabled'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldOptions('suggestGatewayNotice', [
                'label' => __('plugins.paymethod.multipay.settings.suggestGatewayNotice'),
                'options' => [['value' => true, 'label' => __('common.enable')]],
                'value' => $this->getSetting($context->getId(), 'suggestGatewayNotice') === null ? true : (bool) $this->getSetting($context->getId(), 'suggestGatewayNotice'),
                'groupId' => 'multipay',
            ]))
            ->addField(new FieldTextarea('geoCountryCurrencyMap', [
                'label' => __('plugins.paymethod.multipay.settings.geoCountryCurrencyMap'),
                'description' => __('plugins.paymethod.multipay.settings.geoCountryCurrencyMap.description'),
                'value' => (string) $this->getSetting($context->getId(), 'geoCountryCurrencyMap'),
                'groupId' => 'multipay',
            ]));

        return true;
    }

    /**
     * Setting keys that hold secrets. They are rendered write-only (never echoed
     * back to the browser) and only overwritten when a non-empty value is
     * submitted. See addCredentialFields() and saveSettings().
     *
     * @return string[]
     */
    protected function secretSettingKeys(): array
    {
        return [
            'paystackTestSecretKey', 'paystackLiveSecretKey',
            'flutterwaveTestSecretKey', 'flutterwaveLiveSecretKey', 'flutterwaveWebhookSecret',
            'paypalTestSecret', 'paypalLiveSecret',
            'fxProviderKey',
        ];
    }

    /**
     * Render inline gateway credentials as Test + Live pairs (selected at
     * runtime by the MultiPay Test Mode toggle) plus the gateway's webhook URL.
     * Secrets are write-only: shown blank, kept unless a new value is entered.
     *
     * @param array<int,array{id:string,label:string,currencies:string[]}> $adapterGateways
     */
    protected function addCredentialFields($form, int $contextId, $request, array $adapterGateways): void
    {
        $form->addField(new FieldHTML('multipayCredHeading', [
            'label' => __('plugins.paymethod.multipay.settings.credentials'),
            'description' => '<p style="margin:0;color:#555;">' . __('plugins.paymethod.multipay.settings.credentials.description') . '</p>',
            'groupId' => 'multipay',
        ]));

        $specs = [
            'paystackplugin' => [
                ['paystackTestPublicKey', 'publicTest', false],
                ['paystackTestSecretKey', 'secretTest', true],
                ['paystackLivePublicKey', 'publicLive', false],
                ['paystackLiveSecretKey', 'secretLive', true],
            ],
            'flutterwaveplugin' => [
                ['flutterwaveTestPublicKey', 'publicTest', false],
                ['flutterwaveTestSecretKey', 'secretTest', true],
                ['flutterwaveLivePublicKey', 'publicLive', false],
                ['flutterwaveLiveSecretKey', 'secretLive', true],
                ['flutterwaveWebhookSecret', 'webhookSecret', true],
            ],
            'paypalpayment' => [
                ['paypalTestClientId', 'clientTest', false],
                ['paypalTestSecret', 'secretTest', true],
                ['paypalLiveClientId', 'clientLive', false],
                ['paypalLiveSecret', 'secretLive', true],
            ],
        ];

        foreach ($adapterGateways as $gw) {
            $id = strtolower($this->normalizeGatewayId((string) $gw['id']));
            if (!isset($specs[$id])) {
                continue;
            }
            $form->addField(new FieldHTML('multipayCredGw_' . $gw['id'], [
                'label' => '',
                'description' => '<h4 style="margin:1rem 0 .25rem;">' . htmlspecialchars((string) $gw['label'], ENT_QUOTES, 'UTF-8') . '</h4>',
                'groupId' => 'multipay',
            ]));
            foreach ($specs[$id] as [$name, $kind, $isSecret]) {
                $label = (string) $gw['label'] . ' — ' . __('plugins.paymethod.multipay.settings.cred.' . $kind);
                if ($isSecret) {
                    $set = trim((string) $this->getSetting($contextId, $name)) !== '';
                    $form->addField(new FieldText($name, [
                        'label' => $label,
                        'value' => '',
                        'description' => $set
                            ? __('plugins.paymethod.multipay.settings.cred.secretSet')
                            : __('plugins.paymethod.multipay.settings.cred.secretUnset'),
                        'groupId' => 'multipay',
                    ]));
                } else {
                    $form->addField(new FieldText($name, [
                        'label' => $label,
                        'value' => (string) $this->getSetting($contextId, $name),
                        'groupId' => 'multipay',
                    ]));
                }
            }
            $webhookUrl = $request->url(null, 'payment', 'plugin', [$this->getName(), 'webhook', $gw['id']]);
            $form->addField(new FieldText('webhookUrl_' . $gw['id'], [
                'label' => (string) $gw['label'] . ' — ' . __('plugins.paymethod.multipay.settings.webhookUrl'),
                'description' => __('plugins.paymethod.multipay.settings.webhookUrl.description'),
                'value' => $webhookUrl,
                'groupId' => 'multipay',
                'isInert' => true,
            ]));
        }
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

        // Only merge while MultiPay is the active orchestrator.
        $context = Application::get()->getRequest()->getContext();
        if (!$context || strtolower((string) $context->getData('paymentPluginName')) !== strtolower((string) $this->getName())) {
            return;
        }

        // Merge the gateways whose credentials/currencies MultiPay now manages
        // inline (Paystack/Flutterwave/PayPal) into the MultiPay group, removing
        // their duplicate sibling groups. The native paymentPluginName selector
        // and any gateway MultiPay does not absorb (e.g. Manual Payment) stay
        // visible so staff can still edit them and revert to a single gateway.
        // Settings group ids used by each sibling gateway plugin (these are the
        // groups' own ids, which differ from the plugins' getName() values).
        $absorbed = ['paystackpayment', 'flutterwavepayment', 'paypalpayment'];
        if (!empty($config['groups']) && is_array($config['groups'])) {
            $config['groups'] = array_values(array_filter(
                $config['groups'],
                fn($group) => !in_array(strtolower((string) ($group['id'] ?? '')), $absorbed, true)
            ));
        }
        if (!empty($config['fields']) && is_array($config['fields'])) {
            $config['fields'] = array_values(array_filter(
                $config['fields'],
                fn($field) => !in_array(strtolower((string) ($field['groupId'] ?? '')), $absorbed, true)
            ));
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

        $contextId = $request->getContext()->getId();
        $allParams = $illuminateRequest->input();
        $saveParams = [];
        $currencySelections = [];
        foreach ($allParams as $param => $val) {
            // Per-gateway currency checkbox lists (currencies_<gatewayId>).
            if (strpos($param, 'currencies_') === 0) {
                $gatewayId = substr($param, strlen('currencies_'));
                $codes = array_values(array_unique(array_map(
                    fn($c) => strtoupper(trim((string) $c)),
                    (array) $val
                )));
                $codes = array_filter($codes);
                $currencySelections[$gatewayId] = $codes;
                $saveParams[$param] = $codes;
                continue;
            }
            // Secrets are write-only: only overwrite when a non-empty value is
            // submitted, so a blank field keeps the stored secret.
            if (in_array($param, $this->secretSettingKeys(), true)) {
                if (trim((string) $val) !== '') {
                    $saveParams[$param] = (string) $val;
                }
                continue;
            }
            switch ($param) {
                case 'fallbackGateway':
                case 'paystackTestPublicKey':
                case 'paystackLivePublicKey':
                case 'flutterwaveTestPublicKey':
                case 'flutterwaveLivePublicKey':
                case 'paypalTestClientId':
                case 'paypalLiveClientId':
                case 'fxProvider':
                case 'fxProviderUrl':
                case 'fxRatePath':
                case 'fxDisclaimer':
                    $saveParams[$param] = (string) $val;
                    break;
                // Numeric settings — clamped to safe ranges (B/UX hardening).
                case 'amountTolerance':
                    $saveParams[$param] = (string) max(0.0, min(1000.0, (float) $val));
                    break;
                case 'fxMarkupPercent':
                    $saveParams[$param] = (string) max(-100.0, min(100.0, (float) $val));
                    break;
                case 'fxCacheTtl':
                    $saveParams[$param] = (string) max(60, min(604800, (int) $val));
                    break;
                case 'geoCountryCurrencyMap':
                    // Only store valid JSON objects; otherwise keep the previous
                    // value rather than silently storing garbage.
                    $raw = trim((string) $val);
                    if ($raw === '') {
                        $saveParams[$param] = '';
                    } elseif (is_array(json_decode($raw, true))) {
                        $saveParams[$param] = $raw;
                    }
                    break;
                case 'testMode':
                case 'fxEnabled':
                case 'geoDetectEnabled':
                case 'suggestGatewayNotice':
                    $saveParams[$param] = $val === 'true';
                    break;
                case 'enabledGateways':
                    $saveParams[$param] = $this->normalizeGatewayList((array) $val);
                    break;
            }
        }
        if (isset($saveParams['fallbackGateway'])) {
            $saveParams['fallbackGateway'] = $this->normalizeGatewayId((string) $saveParams['fallbackGateway']);
        }

        // Derive the legacy routing inputs from the per-gateway currency matrix
        // so the existing routing/eligibility code keeps working unchanged:
        //  - allowedCurrencies = the union of every ticked currency.
        //  - gatewayRoutingJson = currency -> first gateway that supports it,
        //    in enabled-gateway order (a sensible default route per currency).
        if (!empty($currencySelections)) {
            $enabledOrder = $this->normalizeGatewayList((array) ($saveParams['enabledGateways'] ?? $this->getSetting($contextId, 'enabledGateways')));
            $orderedGateways = array_values(array_unique(array_merge(
                $enabledOrder,
                array_keys($currencySelections)
            )));
            $allowed = [];
            $routingMap = [];
            foreach ($orderedGateways as $gatewayId) {
                foreach (($currencySelections[$gatewayId] ?? []) as $code) {
                    $allowed[$code] = true;
                    if (!isset($routingMap[$code])) {
                        $routingMap[$code] = $gatewayId;
                    }
                }
            }
            $saveParams['allowedCurrencies'] = implode(', ', array_keys($allowed));
            $saveParams['gatewayRoutingJson'] = $routingMap ? json_encode($routingMap) : '';
        }
        $this->updateSetting($contextId, 'paymentPluginName', $this->getName());
        // Note: an empty enabledGateways selection is honoured as-is (no silent
        // re-enable of every installed gateway). With none enabled, MultiPay is
        // effectively inactive at checkout (isConfigured() returns false).
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
     * Presentation metadata for a gateway: official brand colour, on-brand text
     * colour, a short tagline, and the consumer-facing payment instruments the
     * gateway exposes (card, transfer, USSD, wallets, …). Drives the branded
     * gateway cards on the checkout selector so payers can tell at a glance which
     * gateway to use and what they can pay with.
     *
     * Keyed by a loose match on the gateway id (plugin name) so it survives the
     * paystackplugin / flutterwaveplugin / paypalpayment / manualpayment naming.
     *
     * @return array{brand:string,onBrand:string,tagline:string,methods:string[]}
     */
    public function getGatewayPresentation(string $gatewayId): array
    {
        $id = strtolower($gatewayId);
        $catalog = [
            'paystack' => [
                'brand' => '#011B33',
                'onBrand' => '#ffffff',
                'accent' => '#00C3F7',
                'tagline' => __('plugins.paymethod.multipay.gateway.paystack.tagline'),
                'methods' => ['card', 'transfer', 'ussd', 'opay', 'mobilemoney', 'applepay'],
            ],
            'flutterwave' => [
                'brand' => '#F5A623',
                'onBrand' => '#1a1208',
                'accent' => '#F5A623',
                'tagline' => __('plugins.paymethod.multipay.gateway.flutterwave.tagline'),
                'methods' => ['card', 'transfer', 'ussd', 'mobilemoney', 'mpesa', 'barter'],
            ],
            'paypal' => [
                'brand' => '#003087',
                'onBrand' => '#ffffff',
                'accent' => '#009CDE',
                'tagline' => __('plugins.paymethod.multipay.gateway.paypal.tagline'),
                'methods' => ['paypalbalance', 'card', 'paylater'],
            ],
            'manual' => [
                'brand' => '#475569',
                'onBrand' => '#ffffff',
                'accent' => '#475569',
                'tagline' => __('plugins.paymethod.multipay.gateway.manual.tagline'),
                'methods' => ['offline'],
            ],
        ];

        $meta = [
            'brand' => '#1a1a1a',
            'onBrand' => '#ffffff',
            'accent' => 'var(--teal, #0f766e)',
            'tagline' => '',
            'methods' => [],
        ];
        foreach ($catalog as $key => $data) {
            if (strpos($id, $key) !== false) {
                $meta = $data;
                break;
            }
        }

        // Resolve method codes to localised labels for display.
        $meta['methods'] = array_map(
            fn($code) => __('plugins.paymethod.multipay.method.' . $code),
            $meta['methods']
        );
        return $meta;
    }

    /**
     * Enabled gateways that can actually SETTLE the journal currency. A gateway
     * that cannot charge the journal currency is excluded entirely (not merely
     * "not suggested"), because MultiPay always charges the journal currency.
     *
     * @return array<int,array{id:string,label:string}>
     */
    public function getEligibleGatewayChoices(int $contextId, string $journalCurrency): array
    {
        $choices = $this->getEnabledGatewayChoices($contextId);
        $currency = strtoupper($journalCurrency);
        if ($currency === '') {
            return $choices;
        }
        $eligible = [];
        foreach ($choices as $choice) {
            $adapter = $this->getAdapter($choice['id'], $contextId);
            // Keep gateways with no adapter (e.g. manual/delegated) — they handle
            // their own currency rules downstream; only filter ones we can test.
            if (!$adapter) {
                $eligible[] = $choice;
                continue;
            }
            // Drop gateways that are enabled but not yet configured with keys —
            // otherwise checkout would offer them and fail at initiation.
            if (!$this->gatewayIsConfigured($choice['id'], $contextId)) {
                continue;
            }
            // A gateway is eligible only if the journal currency is both
            // supported by the gateway and ticked in its currency selection.
            $selected = $this->getSelectedCurrencies($contextId, $choice['id'], $adapter->getSupportedCurrencies());
            if (in_array($currency, $selected, true)) {
                $eligible[] = $choice;
            }
        }
        return $eligible;
    }

    /**
     * Suggest the best eligible gateway for the payer's detected currency.
     *
     * @param array<int,array{id:string,label:string}> $eligible
     * @return array{id:string,label:string,country:string,currency:string}|null
     */
    public function suggestGateway($request, int $contextId, array $eligible): ?array
    {
        if (count($eligible) < 2) {
            return null;
        }
        if (!(bool) ($this->getSetting($contextId, 'geoDetectEnabled') ?? true)) {
            return null;
        }
        require_once(dirname(__FILE__) . '/classes/services/LocaleCurrencyService.php');
        $locale = new \APP\plugins\paymethod\multipay\classes\services\LocaleCurrencyService(
            (string) $this->getSetting($contextId, 'geoCountryCurrencyMap')
        );
        $country = $locale->detectCountry($request);
        $currency = $locale->detectCurrency($request);
        if ($currency === '') {
            return null;
        }
        foreach ($eligible as $choice) {
            $adapter = $this->getAdapter($choice['id'], $contextId);
            if ($adapter && $adapter->supportsCurrency($currency)) {
                return [
                    'id' => $choice['id'],
                    'label' => $choice['label'],
                    'country' => $locale->countryName($country),
                    'currency' => $currency,
                ];
            }
        }
        return null;
    }

    /**
     * Handle incoming requests (initiate, callback, webhook)
     */
    public function handle($args, $request)
    {
        $op = isset($args[0]) ? $args[0] : 'default';

        // Webhooks must answer the gateway with a plain status code, never an
        // HTML error page; they handle their own response/exceptions.
        if ($op === 'webhook') {
            $this->handleWebhook($request, isset($args[1]) ? $args[1] : null);
            return;
        }

        try {
            switch ($op) {
                case 'initiate':
                    $this->handleInitiate($request);
                    break;
                case 'return': // Callback from gateway
                    $this->handleReturn($request);
                    break;
                case 'manage':
                    $this->handleManage($request);
                    break;
                default:
                    parent::handle($args, $request);
            }
        } catch (\Throwable $e) {
            // Never surface a bare 500 to a payer. Log the detail and show a
            // friendly, theme-safe message page instead.
            error_log('[MultiPay] handle(' . $op . ') failed: ' . $e->getMessage());
            $this->renderMessage(
                $request,
                'plugins.paymethod.multipay.error.generic',
                true
            );
        }
    }

    /**
     * Render a theme-safe message page (HTTP 200) instead of throwing. Uses the
     * plugin's own {include}-pattern template so it renders on include-style
     * themes (the core message.tpl is {extends}-based and blanks on those).
     *
     * @param string $message    Locale key (when $isKey) or literal text.
     * @param bool   $isKey       Treat $message as a translate key.
     * @param string $backUrl     Optional "back" link target.
     */
    protected function renderMessage($request, string $message, bool $isKey = true, string $backUrl = ''): void
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'message' => $message,
            'messageIsKey' => $isKey,
            'backUrl' => $backUrl,
        ]);
        $templateMgr->display($this->getTemplateResource('message.tpl'));
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
            $this->requestRefund(
                (int) $context->getId(),
                (string) $request->getUserVar('gateway'),
                (string) $request->getUserVar('reference'),
                (string) $request->getUserVar('providerTxId'),
                (float) $request->getUserVar('amount'),
                (string) $request->getUserVar('currency')
            );
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
                $this->renderMessage(
                    $request,
                    'plugins.paymethod.multipay.error.initiationFailed',
                    true,
                    $cancelUrl
                );
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
            $this->renderMessage(
                $request,
                'plugins.paymethod.multipay.error.verificationFailed',
                true,
                isset($queuedPayment) && $queuedPayment ? $queuedPayment->getRequestUrl() : ''
            );
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

        // Dispute / chargeback auto-flag. Dispute events pass the SAME signature
        // gate as payment events (above). Record/update the dispute row; tolerate
        // out-of-order resolve-before-create via updateOrInsert.
        if (stripos($eventType, 'dispute') !== false || stripos($eventType, 'chargeback') !== false) {
            $this->recordDisputeFromWebhook((int) $journal->getId(), $gatewayName, $eventType, $reference ? (string) $reference : '', $normalized, $decoded);
            http_response_code(200);
            exit();
        }

        // Refund confirmation. The manager submits refunds as async requests;
        // the gateway confirms the final state via a refund.* webhook.
        if (stripos($eventType, 'refund') !== false && $reference) {
            $this->updateRefundFromWebhook((int) $journal->getId(), $gatewayName, (string) $reference, $normalized);
            http_response_code(200);
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

    /**
     * Submit a refund request (idempotent, amount-capped). Refunds are modelled
     * as asynchronous: the row is recorded with the adapter's reported status
     * (commonly "pending"); the final state arrives via the gateway's refund
     * webhook. Shared by the legacy manage console and the payment manager.
     *
     * @return array{status:string}
     * @throws \Exception on validation failure or duplicate submission.
     */
    public function requestRefund(int $contextId, string $gateway, string $reference, string $providerTxId, float $amount, string $currency): array
    {
        $gateway = $this->normalizeGatewayId($gateway);
        $adapter = $this->getAdapter($gateway, $contextId);
        if (!$adapter || !$adapter->supportsRefunds()) {
            throw new \Exception('This gateway does not support refunds.');
        }
        if ($amount <= 0) {
            throw new \Exception('Refund amount must be positive.');
        }

        // B2 — over-refund / replay guard.
        $tx = \Illuminate\Support\Facades\DB::table('multipay_transactions')
            ->where('context_id', $contextId)
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->first();
        $captured = $tx ? (float) $tx->amount : 0.0;
        $alreadyRefunded = (float) \Illuminate\Support\Facades\DB::table('multipay_refunds')
            ->where('context_id', $contextId)
            ->where('reference', $reference)
            ->whereIn('status', ['success', 'pending'])
            ->sum('amount');
        if ($captured > 0 && ($alreadyRefunded + $amount) > $captured + 0.001) {
            throw new \Exception('Refund exceeds the remaining refundable amount.');
        }

        require_once(dirname(__FILE__) . '/classes/services/IdempotencyService.php');
        $idempotency = new \APP\plugins\paymethod\multipay\classes\services\IdempotencyService();
        $refundKey = 'refund:' . $reference . ':' . number_format($amount, 4, '.', '');
        if (!$idempotency->claim($contextId, $gateway, $refundKey)) {
            throw new \Exception('Duplicate refund request ignored.');
        }

        $refundResult = $adapter->refund($providerTxId, $amount, $currency);
        $status = (string) ($refundResult['status'] ?? 'pending');
        \Illuminate\Support\Facades\DB::table('multipay_refunds')->insert([
            'context_id' => $contextId,
            'gateway' => $gateway,
            'reference' => $reference,
            'provider_tx_id' => $providerTxId,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'status' => $status,
            'response_payload' => json_encode($refundResult['raw'] ?? []),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['status' => $status];
    }

    /**
     * Create or update a dispute row from a verified gateway webhook.
     */
    protected function recordDisputeFromWebhook(int $contextId, string $gateway, string $eventType, string $reference, array $normalized, array $decoded): void
    {
        if ($reference === '') {
            // Best-effort: try to dig a reference out of the raw payload.
            $reference = (string) ($decoded['data']['reference'] ?? $decoded['data']['tx_ref'] ?? '');
        }
        if ($reference === '') {
            return;
        }
        $resolved = stripos($eventType, 'resolve') !== false || stripos($eventType, 'close') !== false;
        $status = $resolved ? 'resolved' : 'open';
        $reason = (string) ($decoded['data']['reason'] ?? $decoded['data']['category'] ?? '');
        $amount = (float) ($normalized['amount'] ?? ($decoded['data']['amount'] ?? 0));
        $currency = strtoupper((string) ($normalized['currency'] ?? ($decoded['data']['currency'] ?? '')));

        $existing = \Illuminate\Support\Facades\DB::table('multipay_disputes')
            ->where('context_id', $contextId)->where('gateway', $gateway)->where('reference', $reference)->first();
        $history = [];
        if ($existing && $existing->history_json) {
            $decodedHist = json_decode((string) $existing->history_json, true);
            $history = is_array($decodedHist) ? $decodedHist : [];
        }
        $history[] = ['at' => date('c'), 'by' => 'webhook', 'status' => $status, 'event' => $eventType];

        $values = [
            'status' => $status,
            'reason' => $reason ?: ($existing->reason ?? null),
            'amount' => $amount ?: ($existing->amount ?? 0),
            'currency' => $currency ?: ($existing->currency ?? null),
            'history_json' => json_encode($history),
            'resolved_at' => $resolved ? now() : ($existing->resolved_at ?? null),
            'updated_at' => now(),
        ];
        if ($existing) {
            \Illuminate\Support\Facades\DB::table('multipay_disputes')->where('id', $existing->id)->update($values);
        } else {
            $values = array_merge($values, [
                'context_id' => $contextId,
                'gateway' => $gateway,
                'reference' => $reference,
                'opened_at' => now(),
                'created_at' => now(),
            ]);
            \Illuminate\Support\Facades\DB::table('multipay_disputes')->insert($values);
        }
    }

    /**
     * Update a refund row's status from a verified refund.* webhook.
     */
    protected function updateRefundFromWebhook(int $contextId, string $gateway, string $reference, array $normalized): void
    {
        $status = strtolower((string) ($normalized['status'] ?? ''));
        $final = in_array($status, ['success', 'successful', 'completed', 'refunded'], true) ? 'success'
            : (in_array($status, ['failed', 'declined'], true) ? 'failed' : 'pending');
        \Illuminate\Support\Facades\DB::table('multipay_refunds')
            ->where('context_id', $contextId)
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->where('status', 'pending')
            ->update(['status' => $final, 'updated_at' => now()]);
    }

    /**
     * Resolve effective credentials for a gateway. The MultiPay Test Mode toggle
     * selects the test or live key set; each MultiPay inline key falls back to
     * the sibling plugin's matching key, and live keys also fall back to the
     * pre-1.1 single-pair settings for backward compatibility.
     *
     * @return array<string,mixed> e.g. ['public'=>,'secret'=>,'webhook'=>,'testMode'=>]
     *         or for PayPal ['client'=>,'secret'=>,'testMode'=>]; [] if unknown.
     */
    protected function resolveGatewayCredentials(string $gatewayId, int $contextId): array
    {
        $gatewayId = strtolower($this->normalizeGatewayId($gatewayId));
        $testMode = (bool) $this->getSetting($contextId, 'testMode');

        $pick = function (string $mpTest, string $mpLive, ?string $legacyLive, string $sibKey, $sibling) use ($contextId, $testMode) {
            if ($testMode) {
                $sibVal = $sibling ? (string) $sibling->getSetting($contextId, 'test' . ucfirst($sibKey)) : '';
                return (string) ($this->getSetting($contextId, $mpTest) ?: $sibVal);
            }
            $sibVal = $sibling ? (string) $sibling->getSetting($contextId, 'live' . ucfirst($sibKey)) : '';
            $legacy = $legacyLive ? (string) $this->getSetting($contextId, $legacyLive) : '';
            return (string) ($this->getSetting($contextId, $mpLive) ?: $legacy ?: $sibVal);
        };

        if (in_array($gatewayId, ['paystack', 'paystackplugin'], true)) {
            $sib = $this->getPaymethodPlugin('paystackplugin');
            return [
                'public' => $pick('paystackTestPublicKey', 'paystackLivePublicKey', 'paystackPublicKey', 'PublicKey', $sib),
                'secret' => $pick('paystackTestSecretKey', 'paystackLiveSecretKey', 'paystackSecretKey', 'SecretKey', $sib),
                'testMode' => $testMode,
            ];
        }
        if (in_array($gatewayId, ['flutterwave', 'flutterwaveplugin'], true)) {
            $sib = $this->getPaymethodPlugin('flutterwaveplugin');
            $webhook = (string) ($this->getSetting($contextId, 'flutterwaveWebhookSecret')
                ?: ($sib ? (string) $sib->getSetting($contextId, 'webhookHash') : ''));
            return [
                'public' => $pick('flutterwaveTestPublicKey', 'flutterwaveLivePublicKey', 'flutterwavePublicKey', 'PublicKey', $sib),
                'secret' => $pick('flutterwaveTestSecretKey', 'flutterwaveLiveSecretKey', 'flutterwaveSecretKey', 'SecretKey', $sib),
                'webhook' => $webhook,
                'testMode' => $testMode,
            ];
        }
        if (in_array($gatewayId, ['paypalpayment', 'paypal'], true)) {
            $sib = $this->getPaymethodPlugin('paypalpayment');
            if (!$sib) {
                return [];
            }
            $client = $testMode
                ? (string) ($this->getSetting($contextId, 'paypalTestClientId') ?: $sib->getSetting($contextId, 'clientId'))
                : (string) ($this->getSetting($contextId, 'paypalLiveClientId') ?: $this->getSetting($contextId, 'paypalClientId') ?: $sib->getSetting($contextId, 'clientId'));
            $secret = $testMode
                ? (string) ($this->getSetting($contextId, 'paypalTestSecret') ?: $sib->getSetting($contextId, 'secret'))
                : (string) ($this->getSetting($contextId, 'paypalLiveSecret') ?: $this->getSetting($contextId, 'paypalSecret') ?: $sib->getSetting($contextId, 'secret'));
            return ['client' => $client, 'secret' => $secret, 'testMode' => $testMode];
        }
        return [];
    }

    /**
     * Whether a gateway has the credentials it needs to operate. Gateways with
     * no adapter (e.g. Manual Payment) are considered configured (they handle
     * their own setup downstream).
     */
    public function gatewayIsConfigured(string $gatewayId, int $contextId): bool
    {
        $normalized = strtolower($this->normalizeGatewayId($gatewayId));
        if (!in_array($normalized, ['paystack', 'paystackplugin', 'flutterwave', 'flutterwaveplugin', 'paypalpayment', 'paypal'], true)) {
            return true;
        }
        $creds = $this->resolveGatewayCredentials($gatewayId, $contextId);
        if (empty($creds)) {
            return false;
        }
        if (isset($creds['client'])) {
            return trim((string) $creds['client']) !== '' && trim((string) $creds['secret']) !== '';
        }
        return trim((string) ($creds['public'] ?? '')) !== '' && trim((string) ($creds['secret'] ?? '')) !== '';
    }

    protected function getAdapter($gatewayName, $contextId)
    {
        require_once(dirname(__FILE__) . '/classes/GatewayAdapterInterface.php');
        require_once(dirname(__FILE__) . '/classes/HttpClient.php');
        require_once(dirname(__FILE__) . '/classes/Money.php');

        $gatewayName = strtolower($this->normalizeGatewayId((string) $gatewayName));
        $creds = $this->resolveGatewayCredentials($gatewayName, $contextId);

        if (in_array($gatewayName, ['paystack', 'paystackplugin'], true)) {
            require_once(dirname(__FILE__) . '/classes/PaystackAdapter.php');
            return new \APP\plugins\paymethod\multipay\classes\PaystackAdapter(
                (string) ($creds['public'] ?? ''),
                (string) ($creds['secret'] ?? ''),
                new \APP\plugins\paymethod\multipay\classes\HttpClient()
            );
        } elseif (in_array($gatewayName, ['flutterwave', 'flutterwaveplugin'], true)) {
            require_once(dirname(__FILE__) . '/classes/FlutterwaveAdapter.php');
            return new \APP\plugins\paymethod\multipay\classes\FlutterwaveAdapter(
                (string) ($creds['public'] ?? ''),
                (string) ($creds['secret'] ?? ''),
                (string) ($creds['webhook'] ?? ''),
                new \APP\plugins\paymethod\multipay\classes\HttpClient()
            );
        } elseif (in_array($gatewayName, ['paypalpayment', 'paypal'], true)) {
            if (empty($creds)) {
                return null;
            }
            require_once(dirname(__FILE__) . '/classes/PaypalAdapter.php');
            return new \APP\plugins\paymethod\multipay\classes\PaypalAdapter(
                (string) ($creds['client'] ?? ''),
                (string) ($creds['secret'] ?? ''),
                (bool) ($creds['testMode'] ?? false)
            );
        }
        return null;
    }

    /**
     * Whether display-only FX conversion is enabled for a context.
     */
    public function isFxEnabled(int $contextId): bool
    {
        $val = $this->getSetting($contextId, 'fxEnabled');
        return $val === null ? true : (bool) $val;
    }

    /**
     * Build a configured ExchangeRateService for a context, or null if FX is off.
     */
    public function buildExchangeRateService(int $contextId)
    {
        if (!$this->isFxEnabled($contextId)) {
            return null;
        }
        require_once(dirname(__FILE__) . '/classes/HttpClient.php');
        require_once(dirname(__FILE__) . '/classes/Money.php');
        require_once(dirname(__FILE__) . '/classes/services/fx/RateProvider.php');
        require_once(dirname(__FILE__) . '/classes/services/fx/YahooRateProvider.php');
        require_once(dirname(__FILE__) . '/classes/services/fx/ConfigurableRateProvider.php');
        require_once(dirname(__FILE__) . '/classes/services/ExchangeRateService.php');

        $providerId = (string) ($this->getSetting($contextId, 'fxProvider') ?: 'yahoo');
        if ($providerId === 'custom') {
            $provider = new \APP\plugins\paymethod\multipay\classes\services\fx\ConfigurableRateProvider(
                (string) $this->getSetting($contextId, 'fxProviderUrl'),
                (string) $this->getSetting($contextId, 'fxProviderKey'),
                (string) ($this->getSetting($contextId, 'fxRatePath') ?: 'rate')
            );
        } else {
            $provider = new \APP\plugins\paymethod\multipay\classes\services\fx\YahooRateProvider();
        }

        $markup = (float) ($this->getSetting($contextId, 'fxMarkupPercent') ?: 0);
        $ttl = (int) ($this->getSetting($contextId, 'fxCacheTtl') ?: 43200);
        return new \APP\plugins\paymethod\multipay\classes\services\ExchangeRateService($provider, $markup, $ttl);
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

    /**
     * Installed gateways that expose a MultiPay adapter, with the ISO-4217
     * currencies each can settle. Drives the per-gateway currency checkbox
     * matrix in settings and the eligibility filter.
     *
     * @return array<int,array{id:string,label:string,currencies:string[]}>
     */
    public function getAdapterBackedGateways(int $contextId): array
    {
        $out = [];
        foreach ($this->getInstalledGatewayOptions() as $option) {
            $adapter = $this->getAdapter($option['value'], $contextId);
            if (!$adapter) {
                continue;
            }
            $out[] = [
                'id' => $option['value'],
                'label' => $option['label'],
                'currencies' => $adapter->getSupportedCurrencies(),
            ];
        }
        return $out;
    }

    /**
     * The currencies staff have ticked for a gateway. Defaults to every
     * supported currency when no selection has been saved yet; an explicit
     * empty selection is honoured (gateway accepts nothing).
     *
     * @param string[] $supported
     * @return string[]
     */
    public function getSelectedCurrencies(int $contextId, string $gatewayId, array $supported): array
    {
        $stored = $this->getSetting($contextId, 'currencies_' . $gatewayId);
        if (!is_array($stored)) {
            return $supported;
        }
        return array_values(array_intersect(
            array_map('strtoupper', $stored),
            array_map('strtoupper', $supported)
        ));
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


