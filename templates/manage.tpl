{extends file="frontend/components/header.tpl"}

{block name="content"}
<div class="pkp_structure_page_main pkp_structure_content">
    <div class="pkp_page_content">
        <h2>{translate key="plugins.paymethod.multipay.displayName"}</h2>

        {if $op === 'transactions'}
            <h3>{translate key="plugins.paymethod.multipay.manage.transactions"}</h3>
            <table class="pkpTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>{translate key="plugins.paymethod.multipay.table.gateway"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.reference"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.amountCurrency"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.status"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.actions"}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$transactions item=tx}
                        <tr>
                            <td>{$tx->id|escape}</td>
                            <td>{$tx->gateway|escape}</td>
                            <td>{$tx->reference|escape}</td>
                            <td>{$tx->amount|escape} {$tx->currency|escape}</td>
                            <td>{$tx->status|escape}</td>
                            <td>
                                {if $tx->provider_tx_id && $tx->canRefund}
                                <form method="post" action="{$manageUrl|escape}">
                                    {csrf}
                                    <input type="hidden" name="op" value="refunds">
                                    <input type="hidden" name="action" value="refund">
                                    <input type="hidden" name="gateway" value="{$tx->gateway|escape}">
                                    <input type="hidden" name="providerTxId" value="{$tx->provider_tx_id|escape}">
                                    <input type="hidden" name="reference" value="{$tx->reference|escape}">
                                    <input type="hidden" name="amount" value="{$tx->amount|escape}">
                                    <input type="hidden" name="currency" value="{$tx->currency|escape}">
                                    <button type="submit">{translate key="plugins.paymethod.multipay.manage.refundAction"}</button>
                                </form>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}

        {if $op === 'webhooks'}
            <h3>{translate key="plugins.paymethod.multipay.manage.webhooks"}</h3>
            <table class="pkpTable">
                <thead>
                    <tr>
                        <th>{translate key="plugins.paymethod.multipay.table.gateway"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.event"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.reference"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.verified"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.timestamp"}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$webhooks item=wh}
                        <tr>
                            <td>{$wh->gateway|escape}</td>
                            <td>{$wh->event_type|escape}</td>
                            <td>{$wh->reference|escape}</td>
                            <td>{if $wh->verified}true{else}false{/if}</td>
                            <td>{$wh->created_at|escape}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}

        {if $op === 'routing'}
            <h3>{translate key="plugins.paymethod.multipay.manage.routing"}</h3>
            <table class="pkpTable">
                <thead>
                    <tr>
                        <th>{translate key="plugins.paymethod.multipay.table.currency"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.gateway"}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$routingPreview item=row}
                        <tr>
                            <td>{$row.currency|escape}</td>
                            <td>{$row.gateway|escape}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}

        {if $op === 'refunds'}
            <h3>{translate key="plugins.paymethod.multipay.manage.refunds"}</h3>
            <table class="pkpTable">
                <thead>
                    <tr>
                        <th>{translate key="plugins.paymethod.multipay.table.gateway"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.reference"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.amountCurrency"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.status"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.timestamp"}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$refunds item=rf}
                        <tr>
                            <td>{$rf->gateway|escape}</td>
                            <td>{$rf->reference|escape}</td>
                            <td>{$rf->amount|escape} {$rf->currency|escape}</td>
                            <td>{$rf->status|escape}</td>
                            <td>{$rf->created_at|escape}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}

        {if $op === 'reconciliation'}
            <h3>{translate key="plugins.paymethod.multipay.manage.reconciliation"}</h3>
            <form method="post" action="{$manageUrl|escape}">
                {csrf}
                <input type="hidden" name="op" value="reconciliation">
                <input type="hidden" name="action" value="settlementReport">
                <label>{translate key="plugins.paymethod.multipay.manage.periodStart"}</label>
                <input type="date" name="periodStart" value="">
                <label>{translate key="plugins.paymethod.multipay.manage.periodEnd"}</label>
                <input type="date" name="periodEnd" value="">
                <button type="submit">{translate key="plugins.paymethod.multipay.manage.generateReport"}</button>
            </form>
            <table class="pkpTable">
                <thead>
                    <tr>
                        <th>{translate key="plugins.paymethod.multipay.table.gateway"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.currency"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.status"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.count"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.total"}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$reconciliation item=row}
                        <tr>
                            <td>{$row->gateway|escape}</td>
                            <td>{$row->currency|escape}</td>
                            <td>{$row->status|escape}</td>
                            <td>{$row->total_count|escape}</td>
                            <td>{$row->total_amount|escape}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
            <h4>{translate key="plugins.paymethod.multipay.manage.settlementReports"}</h4>
            <table class="pkpTable">
                <thead><tr><th>ID</th><th>{translate key="plugins.paymethod.multipay.manage.periodStart"}</th><th>{translate key="plugins.paymethod.multipay.manage.periodEnd"}</th><th>{translate key="plugins.paymethod.multipay.table.timestamp"}</th></tr></thead>
                <tbody>
                    {foreach from=$reports item=rp}
                        <tr><td>{$rp->id|escape}</td><td>{$rp->period_start|escape}</td><td>{$rp->period_end|escape}</td><td>{$rp->created_at|escape}</td></tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}

        {if $op === 'recurring'}
            <h3>{translate key="plugins.paymethod.multipay.manage.recurring"}</h3>
            <table class="pkpTable">
                <thead>
                    <tr>
                        <th>{translate key="plugins.paymethod.multipay.table.gateway"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.reference"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.amountCurrency"}</th>
                        <th>{translate key="plugins.paymethod.multipay.table.timestamp"}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$recurringCandidates item=rc}
                        <tr>
                            <td>{$rc->gateway|escape}</td>
                            <td>{$rc->reference|escape}</td>
                            <td>{$rc->amount|escape} {$rc->currency|escape}</td>
                            <td>{$rc->created_at|escape}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}
    </div>
</div>
{/block}

{include file="frontend/components/footer.tpl"}

