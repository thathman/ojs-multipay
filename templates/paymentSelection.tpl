{extends file="frontend/components/header.tpl"}

{block name="content"}
<div class="pkp_structure_page_main pkp_structure_content">
    <div class="pkp_page_content">
        <h2>{translate key="plugins.paymethod.multipay.paymentSelection"}</h2>
        
        <p>{translate key="plugins.paymethod.multipay.paymentDescription" amount=$amount currency=$currency}</p>
        
        <form method="post" action="{$initiateUrl}">
            {csrf}
            <input type="hidden" name="queuedPaymentId" value="{$queuedPaymentId|escape}" />
            
            <fieldset class="pkp_form_section">
                <legend>{translate key="plugins.paymethod.multipay.chooseGateway"}</legend>
                
                {foreach from=$gateways item=gateway}
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="gateway" id="gateway_{$gateway.id|escape}" value="{$gateway.id|escape}" {if $gateway@first}checked{/if}>
                        <label class="form-check-label" for="gateway_{$gateway.id|escape}">
                            {$gateway.label|escape}
                        </label>
                    </div>
                {/foreach}
            </fieldset>
            
            <div class="section form_buttons">
                <button class="pkp_button submit" type="submit">{translate key="common.continue"}</button>
            </div>
        </form>
    </div>
</div>
{/block}

{include file="frontend/components/footer.tpl"}
