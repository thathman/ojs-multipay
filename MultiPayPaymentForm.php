<?php

/**
 * @file plugins/paymethod/multipay/MultiPayPaymentForm.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MultiPayPaymentForm
 *
 * @brief Form for MultiPay gateway selection and initiation.
 */

namespace APP\plugins\paymethod\multipay;

use APP\core\Application;
use APP\core\Request;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\payment\QueuedPayment;

class MultiPayPaymentForm extends Form
{
    /** @var MultiPayPlugin */
    public $plugin;

    /** @var QueuedPayment */
    public $queuedPayment;

    /**
     * @param MultiPayPlugin $plugin
     * @param QueuedPayment $queuedPayment
     */
    public function __construct($plugin, $queuedPayment)
    {
        $this->plugin = $plugin;
        $this->queuedPayment = $queuedPayment;
        parent::__construct(null);
    }

    /**
     * @copydoc Form::display()
     */
    public function display($request = null, $template = null)
    {
        $journal = $request->getJournal();
        $contextId = (int) $journal->getId();
        $amount = (float) $this->queuedPayment->getAmount();
        $currency = strtoupper($this->queuedPayment->getCurrencyCode());

        // Only gateways that can settle the JOURNAL currency are selectable —
        // MultiPay always charges the journal currency/amount.
        $gatewayChoices = $this->plugin->getEligibleGatewayChoices($contextId, $currency);

        $templateMgr = TemplateManager::getManager($request);

        if (empty($gatewayChoices)) {
            $templateMgr->assign('message', 'plugins.paymethod.multipay.error.noGateways');
            $templateMgr->display('frontend/pages/message.tpl');
            return;
        }

        // Best-gateway suggestion (advisory notice).
        $suggestion = null;
        if ((bool) ($this->plugin->getSetting($contextId, 'suggestGatewayNotice') ?? true)) {
            $suggestion = $this->plugin->suggestGateway($request, $contextId, $gatewayChoices);
        }

        // Display-only FX estimate in the payer's local currency, if detectable.
        $fx = null;
        $service = $this->plugin->buildExchangeRateService($contextId);
        if ($service) {
            require_once(dirname(__FILE__) . '/classes/services/LocaleCurrencyService.php');
            $localeCurrency = new \APP\plugins\paymethod\multipay\classes\services\LocaleCurrencyService(
                (string) $this->plugin->getSetting($contextId, 'geoCountryCurrencyMap')
            );
            $localCurrency = $localeCurrency->detectCurrency($request);
            if ($localCurrency !== '' && $localCurrency !== $currency) {
                $converted = $service->convertForDisplay($amount, $currency, $localCurrency);
                if ($converted) {
                    $fx = [
                        'localCurrency' => $localCurrency,
                        'localFormatted' => $converted['formatted'],
                        'disclaimer' => (string) ($this->plugin->getSetting($contextId, 'fxDisclaimer')
                            ?: __('plugins.paymethod.multipay.checkout.fxDisclaimer.default')),
                    ];
                }
            }
        }

        require_once(dirname(__FILE__) . '/classes/Money.php');
        $journalFormatted = \APP\plugins\paymethod\multipay\classes\Money::format($amount, $currency);

        // Always render the POST selection form (with {csrf}), even for a single
        // gateway. The previous single-gateway shortcut issued a GET redirect to
        // ?op=initiate with no csrfToken, which handleInitiate()'s checkCSRF()
        // then rejected (bug B1).
        $templateMgr->assign([
            'gateways' => $gatewayChoices,
            'queuedPaymentId' => $this->queuedPayment->getId(),
            'pluginName' => $this->plugin->getName(),
            'amount' => $amount,
            'currency' => $currency,
            'journalFormatted' => $journalFormatted,
            'suggestion' => $suggestion,
            'fx' => $fx,
            'initiateUrl' => $request->url(null, 'payment', 'plugin', [$this->plugin->getName(), 'initiate']),
        ]);
        $templateMgr->display($this->plugin->getTemplateResource('paymentSelection.tpl'));
    }
}
