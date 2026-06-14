{include file="frontend/components/header.tpl" pageTitle="plugins.paymethod.multipay.displayName"}
<div class="pkp_structure_page_main pkp_structure_content">
    <div class="pkp_page_content">
        <h2>{translate key="plugins.paymethod.multipay.displayName"}</h2>
        <p class="multipay-message" style="margin:1rem 0;padding:1rem 1.25rem;border:1px solid #c2cdd6;border-left:4px solid #006798;border-radius:4px;background:#f3f7fa;">
            {if $messageIsKey}{translate key=$message}{else}{$message|escape}{/if}
        </p>
        {if $backUrl}
            <p><a class="pkp_button" href="{$backUrl|escape}" style="display:inline-block;padding:.5rem 1rem;background:#006798;color:#fff;text-decoration:none;border-radius:3px;font-weight:600;">{translate key="common.back"}</a></p>
        {/if}
    </div>
</div>
{include file="frontend/components/footer.tpl"}
