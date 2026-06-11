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
        $enabledGatewayChoices = $this->plugin->getEnabledGatewayChoices((int) $journal->getId());
        $currency = strtoupper($this->queuedPayment->getCurrencyCode());

        if (empty($enabledGatewayChoices)) {
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.multipay.error.noGateways');
            $templateMgr->display('frontend/pages/message.tpl');
            return;
        }

        if (count($enabledGatewayChoices) > 1) {
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign([
                'gateways' => $enabledGatewayChoices,
                'queuedPaymentId' => $this->queuedPayment->getId(),
                'pluginName' => $this->plugin->getName(),
                'amount' => $this->queuedPayment->getAmount(),
                'currency' => $currency,
                'initiateUrl' => $request->url(null, 'payment', 'plugin', [$this->plugin->getName(), 'initiate']),
            ]);
            $templateMgr->display($this->plugin->getTemplateResource('paymentSelection.tpl'));
        } else {
            $gateway = reset($enabledGatewayChoices);
            $gatewayKey = is_array($gateway) ? ($gateway['id'] ?? '') : (string) $gateway;
            $request->redirectUrl($request->url(null, 'payment', 'plugin', [$this->plugin->getName(), 'initiate'], [
                'queuedPaymentId' => $this->queuedPayment->getId(),
                'gateway' => $gatewayKey,
            ]));
        }
    }
}
