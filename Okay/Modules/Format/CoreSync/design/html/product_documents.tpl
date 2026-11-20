{* CoreSync: документы товара из ядра. Шаблон по умолчанию, без своих стилей.
   Переопределение в теме: design/<тема>/modules/Format/CoreSync/html/product_documents.tpl *}
{if $coresync_product_documents}
<div class="coresync-documents">
    <ul class="coresync-documents__list">
        {foreach $coresync_product_documents as $document}
            <li class="coresync-documents__item">
                <a class="coresync-documents__link" href="{$rootUrl}/{$document->path|escape}" download="{$document->download_name|escape}">{$document->title|escape}</a>
                <span class="coresync-documents__size">{$document->size_value|escape} {if $document->size_unit == 'mb'}МБ{else}КБ{/if}</span>
            </li>
        {/foreach}
    </ul>
</div>
{/if}
