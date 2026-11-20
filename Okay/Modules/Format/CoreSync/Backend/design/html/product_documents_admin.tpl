{* CoreSync: документы товара из ядра в карточке товара админки. Только просмотр: загрузка,
   удаление и правка документов из ядра на витрине не предусмотрены. *}
{if $coresync_product_documents}
<div class="col-lg-12 col-md-12">
    <div class="boxed fn_toggle_wrap coresync-product-documents">
        <div class="heading_box">
            Документы из ядра
            <span class="text-muted">управляется ядром</span>
        </div>
        <ul class="coresync-product-documents__list">
            {foreach $coresync_product_documents as $document}
                <li>
                    <a href="{$rootUrl}/{$document->path|escape}" download="{$document->download_name|escape}" target="_blank" rel="noopener noreferrer">{$document->title|escape}</a>
                    <span class="text-muted">{$document->size_value|escape} {if $document->size_unit == 'mb'}МБ{else}КБ{/if}</span>
                </li>
            {/foreach}
        </ul>
    </div>
</div>
{/if}
