{if $coresync_variant_inventory}
    {literal}
    <style>
        .coresync-inventory { flex: 1 1 0; min-width: 0; margin: .5rem 0 .75rem; }
        .coresync-inventory__scroll { overflow-x: auto; border: 1px solid #dce2e7; border-radius: 6px; }
        .coresync-inventory__table { width: 100%; min-width: 590px; border-collapse: collapse; }
        .coresync-inventory__table caption { caption-side: top; padding: .65rem .85rem; color: #263443; font-weight: 600; text-align: left; }
        .coresync-inventory__table th, .coresync-inventory__table td { padding: .45rem .85rem; text-align: left; vertical-align: middle; }
        .coresync-inventory__table th { background: #f2f5f7; color: #425469; font-size: .875em; }
        .coresync-inventory__table td { border-top: 1px solid #e8edf1; background: #fff; }
        .coresync-inventory__table .coresync-inventory__qty { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .coresync-inventory__table .coresync-inventory__date { white-space: nowrap; color: #546475; font-variant-numeric: tabular-nums; }
        .coresync-inventory__table a { color: #245995; text-decoration: underline; white-space: nowrap; }
    </style>
    {/literal}
    <div class="coresync-inventory">
        <div class="coresync-inventory__scroll">
            <table class="coresync-inventory__table">
                <caption>Остатки по поставщикам и складам</caption>
                <thead>
                    <tr>
                        <th scope="col">Поставщик или источник</th>
                        <th scope="col">Склад</th>
                        <th scope="col" class="coresync-inventory__qty">Доступно</th>
                        <th scope="col">Обновлено (UTC)</th>
                        {if $coresync_inventory_origin}<th scope="col">b2bCRM</th>{/if}
                    </tr>
                </thead>
                <tbody>
                    {foreach $coresync_variant_inventory as $row}
                        <tr>
                            <td>{$row.source|escape}</td>
                            <td>{if $row.warehouse !== null}{$row.warehouse|escape}{else}По поставщику{/if}</td>
                            <td class="coresync-inventory__qty">{if $row.quantity !== null}{$row.quantity|escape}{else}—{/if}</td>
                            <td class="coresync-inventory__date">
                                {if $row.observed_at !== null}
                                    <time datetime="{$row.observed_at|escape}">{$row.observed_label|escape}</time>
                                {else}—{/if}
                            </td>
                            {if $coresync_inventory_origin}
                                <td><a href="{$coresync_inventory_origin|escape}{$row.core_path|escape}" target="_blank" rel="noopener noreferrer">В b2bCRM</a></td>
                            {/if}
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
{/if}
