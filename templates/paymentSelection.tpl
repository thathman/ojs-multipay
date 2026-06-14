{include file="frontend/components/header.tpl" pageTitle="plugins.paymethod.multipay.paymentSelection"}
<link rel="stylesheet" href="{$baseUrl}/plugins/paymethod/multipay/styles/checkout.css">

<div class="pkp_structure_page_main pkp_structure_content">
    <div class="pkp_page_content multipay-checkout">
        <h2 class="multipay-h">{translate key="plugins.paymethod.multipay.paymentSelection"}</h2>

        <div class="multipay-grid">
            <form method="post" action="{$initiateUrl}" class="multipay-form" id="multipay-form">
                {csrf}
                <input type="hidden" name="queuedPaymentId" value="{$queuedPaymentId|escape}" />
                <input type="hidden" name="gateway" id="multipay-gateway" value="{$gateways[0].id|escape}" />

                {if $suggestion}
                    <div class="multipay-notice" id="multipay-suggest-notice">
                        <button type="button" class="multipay-notice-x" aria-label="dismiss" onclick="this.parentNode.style.display='none'">&times;</button>
                        {if $suggestion.country}
                            {translate key="plugins.paymethod.multipay.checkout.suggestNotice" country=$suggestion.country|escape gateway=$suggestion.label|escape currency=$suggestion.currency|escape}
                        {else}
                            {translate key="plugins.paymethod.multipay.checkout.suggestNotice.noCountry" gateway=$suggestion.label|escape currency=$suggestion.currency|escape}
                        {/if}
                    </div>
                {/if}

                <h3 class="multipay-sub">{translate key="plugins.paymethod.multipay.chooseGateway"}</h3>
                <div class="multipay-cards" id="multipay-seg" role="radiogroup" aria-label="{translate key="plugins.paymethod.multipay.chooseGateway"}">
                    {foreach from=$gateways item=gateway name=gw}
                        <button type="button"
                            class="multipay-card{if $gateway@first} on{/if}{if $suggestion && $suggestion.id == $gateway.id} suggested{/if}"
                            data-gateway="{$gateway.id|escape}"
                            data-name="{$gateway.label|escape}"
                            style="--mp-brand:{$gateway.brand|escape};--mp-on-brand:{$gateway.onBrand|escape};--mp-accent:{$gateway.accent|escape};"
                            role="radio"
                            aria-checked="{if $gateway@first}true{else}false{/if}">
                            <span class="multipay-card-head">
                                <span class="multipay-badge" aria-hidden="true">{$gateway.label|truncate:1:"":true|escape}</span>
                                <span class="multipay-card-name">{$gateway.label|escape}</span>
                                {if $suggestion && $suggestion.id == $gateway.id}<span class="multipay-card-rec">{translate key="plugins.paymethod.multipay.checkout.recommended"}</span>{/if}
                                <span class="multipay-card-check" aria-hidden="true">&#10003;</span>
                            </span>
                            {if $gateway.tagline}<span class="multipay-card-tag">{$gateway.tagline|escape}</span>{/if}
                            {if $gateway.footer}
                                <span class="multipay-card-methods">
                                    {foreach from=$gateway.footer item=section}
                                        <span class="multipay-card-mrow">
                                            <span class="multipay-card-mlabel">{$section.label|escape}</span>
                                            <span class="multipay-card-mbadges">
                                                {foreach from=$section.items item=item}<span class="mp-net{if $item.tone} mp-{$item.tone|escape}{/if}">{$item.text|escape}</span>{/foreach}
                                            </span>
                                        </span>
                                    {/foreach}
                                </span>
                            {/if}
                        </button>
                    {/foreach}
                </div>

                <div class="multipay-pane">
                    <p class="multipay-pane-text">{translate key="plugins.paymethod.multipay.checkout.hostedNotice"}</p>
                </div>

                <div class="section form_buttons multipay-actions">
                    <button class="pkp_button submit multipay-pay" type="submit">{translate key="common.continue"}</button>
                </div>
            </form>

            <aside class="multipay-summary">
                <h6 class="multipay-summary-h">{translate key="plugins.paymethod.multipay.checkout.orderSummary"}</h6>

                {if $orderSummary.description}
                    <div class="multipay-order-type">{$orderSummary.description|escape}</div>
                {/if}

                {if $orderSummary.rows}
                    <dl class="multipay-order-meta">
                        {foreach from=$orderSummary.rows item=row}
                            <div class="multipay-order-row">
                                <dt>{$row.label|escape}</dt>
                                <dd>{$row.value|escape}</dd>
                            </div>
                        {/foreach}
                    </dl>
                {/if}

                <div class="multipay-li">
                    <span class="k">{translate key="plugins.paymethod.multipay.checkout.amount"}</span>
                    <span class="v" id="multipay-amount-display">{$journalFormatted|escape}</span>
                </div>
                {if $fx}
                    <div class="multipay-li multipay-fx" id="multipay-fx-line">
                        <span class="k">{translate key="plugins.paymethod.multipay.checkout.estimated"} ({$fx.localCurrency|escape})</span>
                        <span class="v">&asymp; {$fx.localFormatted|escape}</span>
                    </div>
                    <div class="multipay-toggle">
                        <button type="button" id="multipay-fx-toggle"
                            data-journal="{$journalFormatted|escape}"
                            data-local="&asymp; {$fx.localFormatted|escape}"
                            data-label-local="{translate key="plugins.paymethod.multipay.checkout.showLocal" currency=$fx.localCurrency|escape}"
                            data-label-journal="{translate key="plugins.paymethod.multipay.checkout.showJournal"}"
                            data-mode="journal">
                            {translate key="plugins.paymethod.multipay.checkout.showLocal" currency=$fx.localCurrency|escape}
                        </button>
                    </div>
                    <p class="multipay-disclaimer">{$fx.disclaimer|escape}</p>
                {/if}
            </aside>
        </div>
    </div>
</div>

<script>
(function () {
    var seg = document.getElementById('multipay-seg');
    var hidden = document.getElementById('multipay-gateway');
    if (seg) {
        seg.addEventListener('click', function (e) {
            var btn = e.target.closest('.multipay-card');
            if (!btn) return;
            seg.querySelectorAll('.multipay-card').forEach(function (b) {
                b.classList.remove('on');
                b.setAttribute('aria-checked', 'false');
            });
            btn.classList.add('on');
            btn.setAttribute('aria-checked', 'true');
            hidden.value = btn.getAttribute('data-gateway');
        });
    }
    var toggle = document.getElementById('multipay-fx-toggle');
    var amountDisplay = document.getElementById('multipay-amount-display');
    if (toggle && amountDisplay) {
        toggle.addEventListener('click', function () {
            if (toggle.getAttribute('data-mode') === 'journal') {
                amountDisplay.textContent = toggle.getAttribute('data-local');
                toggle.setAttribute('data-mode', 'local');
                toggle.textContent = toggle.getAttribute('data-label-journal');
            } else {
                amountDisplay.textContent = toggle.getAttribute('data-journal');
                toggle.setAttribute('data-mode', 'journal');
                toggle.textContent = toggle.getAttribute('data-label-local');
            }
        });
    }
})();
</script>

{include file="frontend/components/footer.tpl"}
