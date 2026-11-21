{$meta_title = 'CoreSync — синхронизация сателлита' scope=global}

{if $message_error}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="boxed boxed_warning">
                <div class="heading_box">{$message_error|escape}</div>
            </div>
        </div>
    </div>
{/if}

{if $message_success}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="boxed boxed_success">
                <div class="heading_box">{$message_success|escape}</div>
            </div>
        </div>
    </div>
{/if}

<div class="row">
    <div class="col-lg-12">
        <div class="heading_page">CoreSync — синхронизация сателлита</div>
    </div>
</div>
<div class="row">
    <div class="col-lg-12">
        <div class="boxed fn_toggle_wrap tabs">
            <div class="heading_tabs">
                <div class="tab_navigation">
                    <a href="#coresync-sync-tab" class="heading_box tab_navigation_link">Синхронизация</a>
                    <a href="#coresync-settings-tab" class="heading_box tab_navigation_link">Настройки</a>
                    <a href="#coresync-service-tab" class="heading_box tab_navigation_link">Обслуживание</a>
                </div>
            </div>
            <div class="toggle_body_wrap on fn_card">
                <div class="tab_container">
                    <div id="coresync-sync-tab" class="tab">
<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="wrap_heading">
            <div class="box_heading heading_page">Обмен с ядром</div>
            <div class="box_btn_heading">
                <button type="button" class="btn btn_small btn-info" id="coresync_check">
                    <span>Проверить связь</span>
                </button>
                <button type="button" class="btn btn_small btn-success" id="coresync_run">
                    <span>Запустить сейчас</span>
                </button>
                <button type="button" class="btn btn_small btn-warning" id="coresync_cancel" style="display:none">
                    <span>Отменить</span>
                </button>
            </div>
        </div>
    </div>
</div>

{* B. Панель состояния: серверный первый показ и AJAX-поллер рисуются ОДНОЙ JS-функцией
   (renderPanel) над одинаковой формой данных (panelPayload() в контроллере) — иначе первый показ
   и обновление поллером расходятся. Разметку строит JS; здесь только контейнер + посев данных. *}
<div class="row">
    <div class="col-lg-12">
        <div class="boxed" id="coresync_panel"></div>
    </div>
</div>
<div id="coresync_check_result" role="status" aria-live="polite"></div>

{* C. Чек-лист готовности: последствие незакрытого пункта видно ДО того, как оператор упрётся в
   отказ (пустая церемония подключения, fail-closed на применении и т.п.). *}
{if $readiness}
    <div class="row">
        <div class="col-lg-12">
            <div class="boxed boxed_warning">
                <div class="heading_box">Подключение настроено не полностью</div>
                <ul style="margin:8px 0 0 18px">
                    {foreach $readiness as $item}
                        <li>{$item.message|escape}</li>
                    {/foreach}
                </ul>
            </div>
        </div>
    </div>
{else}
    <div class="row">
        <div class="col-lg-12">
            <div class="boxed boxed_success">
                <div class="heading_box">Подключение настроено</div>
            </div>
        </div>
    </div>
{/if}

                    </div>
                    <div id="coresync-settings-tab" class="tab">
{* Настройки модуля *}
<form method="post" action="">
    <input type="hidden" name="session_id" value="{$smarty.session.id}">
    <div class="row">
        <div class="col-lg-8">
            <div class="form-group">
                <label>Адрес ядра (core URL)</label>
                <input type="text" class="form-control" name="settings[core_url]" value="{$coresync.core_url|escape}" placeholder="https://core.example">
            </div>
            <div class="form-group">
                <label>ID канала в ядре</label>
                <input type="text" class="form-control" name="settings[channel_code]" value="{$coresync.channel_code|escape}" placeholder="42" inputmode="numeric" pattern="[0-9]+">
                <details class="text-muted">
                    <summary>Как найти ID канала</summary>
                    <strong>Числовой</strong> идентификатор канала в ядре, а не словесный код: он уезжает в адрес
                    <code>{literal}{адрес ядра}/api/satellite/{ID}/manifest.json{/literal}</code>, где ядро принимает только цифры.
                    Посмотреть его можно в адресной строке карточки канала в ядре — например в
                    <code>/channels/42</code> это <code>42</code>.
                </details>
            </div>
            <div class="form-group">
                <label>Идентификатор сателлита (source_instance)</label>
                <input type="text" class="form-control" name="settings[source_instance]" value="{$coresync.source_instance|escape}" placeholder="grundfos" pattern="[a-z0-9][a-z0-9_-]{literal}{0,63}{/literal}">
                <details class="text-muted">
                    <summary>Требования к идентификатору</summary>
                    Стабильный safe slug источника из OkaySat, например <code>grundfos</code>. Для snapshot v2
                    обязателен и должен точно совпадать с <code>source_identity.instance</code> категории.
                </details>
            </div>
            <div class="form-group">
                <label>Адрес витрины (для церемонии подключения)</label>
                <input type="text" class="form-control" name="settings[storefront_base_url]" value="{$coresync.storefront_base_url|escape}" placeholder="{$storefront_base_url_hint|escape}">
                <details class="text-muted">
                    <summary>Для чего нужен адрес витрины</summary>
                    Абсолютный адрес этой витрины — со схемой и без пути, например <code>{$storefront_base_url_hint|escape}</code>.
                    Ядро спрашивает его церемонией подключения и подставляет в ссылки товаров в своих фидах, поэтому
                    адрес берётся отсюда, а не из заголовков запроса. Пока поле пустое, модуль на церемонию не отвечает.
                </details>
            </div>
            <div class="form-group">
                <label>Токен {if $coresync.has_token}<small>(сохранён: {$coresync.token_masked|escape} — оставьте пустым, чтобы не менять)</small>{/if}</label>
                <input type="password" class="form-control" name="settings[token]" value="" autocomplete="new-password" placeholder="{if $coresync.has_token}{$coresync.token_masked|escape}{else}введите токен{/if}">
            </div>
            <div class="form-group">
                <label>Язык-приёмник</label>
                <select class="form-control" name="settings[lang_id]">
                    {foreach $langs as $l}
                        <option value="{$l->id}" {if $coresync.lang_id == $l->id}selected{/if}>{$l->name|escape}</option>
                    {/foreach}
                </select>
            </div>
            <div class="form-group">
                <label>Конкурентность загрузки картинок</label>
                <input type="number" min="1" max="16" class="form-control" name="settings[image_concurrency]" value="{$coresync.image_concurrency}">
            </div>
            <div class="form-group">
                <label for="coresync_documents_max_mib">Документы из ядра: максимум за один обмен, МиБ</label>
                <input type="number" min="1" max="65536" class="form-control" id="coresync_documents_max_mib" name="settings[documents_max_mib]" value="{$coresync.documents_max_mib|escape}">
                <div class="text-muted">Файлы сверх лимита скачаются в следующих обменах, до этого у товара остаются прежние документы.</div>
            </div>
            <div class="form-group">
                <div class="okay_switch clearfix">
                    <label class="switch_label" for="coresync_enabled">Модуль включён</label>
                    <label class="switch switch-default">
                        <input class="switch-input" type="checkbox" id="coresync_enabled" name="settings[enabled]" value="1" {if $coresync.enabled}checked{/if}>
                        <span class="switch-label"></span><span class="switch-handle"></span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label for="coresync_run_trigger">Запуск обмена</label>
                <select class="form-control" id="coresync_run_trigger" name="settings[run_trigger]">
                    <option value="cron" {if $coresync.run_trigger != 'core'}selected{/if}>По крону витрины (раз в 30 минут)</option>
                    <option value="core" {if $coresync.run_trigger == 'core'}selected{/if} {if !$background_run.available && $coresync.run_trigger != 'core'}disabled{/if}>Только по запросу ядра</option>
                </select>
                <div class="text-muted">Только по запросу ядра: витрина не опрашивает ядро сама, обмен, обновление модуля и уборку запускает пинок ядра.</div>
                {if $background_run.available}
                    <div class="text-muted">Фоновый запуск доступен, PHP CLI: <code>{$background_run.php_cli|escape}</code></div>
                {else}
                    <p class="text-danger"><small>Режим "Только по запросу ядра" недоступен: {$background_run.reason|escape}</small></p>
                {/if}
            </div>
            <div class="form-group">
                <label for="coresync_php_cli_path">Путь к PHP CLI для фонового обмена</label>
                <input type="text" class="form-control" id="coresync_php_cli_path" name="settings[php_cli_path]" value="{$coresync.php_cli_path|escape}" placeholder="пусто: определить автоматически, например /opt/php74/bin/php">
                <div class="text-muted">Нужен только в режиме "Только по запросу ядра". Путь проверяется командой <code>-v</code>: это должен быть PHP CLI той же версии, что у витрины.</div>
            </div>
            <div class="form-group">
                <input type="hidden" name="settings[sync_prices]" value="0">
                <div class="okay_switch clearfix">
                    <label class="switch_label" for="coresync_sync_prices">{$btr->format_coresync__sync_prices|escape}</label>
                    <label class="switch switch-default">
                        <input class="switch-input" type="checkbox" id="coresync_sync_prices" name="settings[sync_prices]" value="1" {if $coresync.sync_prices}checked{/if}>
                        <span class="switch-label"></span><span class="switch-handle"></span>
                    </label>
                </div>
                <details class="text-muted"><summary>Как сохраняются цены</summary>{$btr->format_coresync__sync_prices_hint|escape}</details>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="form-group">
                <label>Соответствие валют</label>
                <p class="text-muted">Для каждой валюты витрины укажите код из манифеста ядра.</p>
                {foreach $readiness as $item}
                    {if $item.field == 'currency_map'}
                        <p class="text-danger"><small>{$item.message|escape}</small></p>
                    {/if}
                {/foreach}
                {foreach $currencies as $c}
                    <div class="form-group">
                        <label>{$c->name|default:$c->code|escape} (id {$c->id})</label>
                        <input type="text" class="form-control" name="settings[currency_map][{$c->id}]" value="{if isset($coresync.currency_map_by_id[$c->id])}{$coresync.currency_map_by_id[$c->id]|escape}{/if}" placeholder="код валюты в манифесте, напр. UAH">
                    </div>
                {/foreach}
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <button type="submit" class="btn btn-success">Сохранить настройки</button>
        </div>
    </div>
</form>

                    </div>
                    <div id="coresync-service-tab" class="tab">
{* F. Версия модуля с диска + исход последнего самообновления. *}
<div class="row">
    <div class="col-lg-12">
        <div class="boxed">
            <div>
                <div class="heading_box">Установленная версия</div>
                <p><strong>Версия модуля:</strong> {$module_version|default:'—'|escape}</p>
                <div class="heading_box">История обновлений</div>
                <div>
                    <strong>Последнее самообновление:</strong>
                    {if $update_status}
                        {if $update_status.status == 'updated'}
                            <span class="text-success">обновлён {$update_status.from|escape} → {$update_status.to|escape}</span>
                        {else}
                            <span class="text-danger">не применилось ({$update_status.status|escape}, {$update_status.from|escape} → {$update_status.to|escape}){if $update_status.error} — {$update_status.error|escape}{/if}</span>
                        {/if}
                        <small class="text-muted"> · {$update_status.at|escape}</small>
                    {else}
                        <span class="text-muted">обновлений не применялось</span>
                    {/if}
                </div>
            </div>
        </div>
    </div>
</div>

{* One-off, operator-confirmed adoption of an already-installed legacy gallery. This is deliberately
   separate from SyncRunner and available only while the module is explicitly disabled. *}
<div class="row">
    <div class="col-lg-12">
        <div class="boxed">
            <div>
                <div class="box_heading" style="margin-bottom:10px">Галерея: принятие существующих файлов</div>
                <p class="text-muted">
                    Проверка плана ничего не меняет. Применение добавляет владение CoreSync к существующей галерее,
                    не меняя изображения и их порядок. Нужен приватный план из ядра; на время операции выключите модуль.
                </p>
                {if $coresync.enabled}
                    <p class="text-danger">Сначала выключите модуль и сохраните настройки.</p>
                {/if}
                <div class="form-group">
                    <label for="coresync_gallery_plan">Приватный план (.ndjson.gz)</label>
                    <input type="file" id="coresync_gallery_plan" class="form-control" accept=".gz,application/gzip" {if $coresync.enabled}disabled{/if}>
                </div>
                <button type="button" class="btn btn_small btn-info" id="coresync_gallery_preview" {if $coresync.enabled}disabled{/if}>
                    <span>Проверить план</span>
                </button>
                <div id="coresync_gallery_result" style="margin-top:10px"></div>
                <div id="coresync_gallery_apply_box" style="display:none;margin-top:12px">
                    <label for="coresync_gallery_confirmation">Для применения введите <code>ADOPT_EXISTING_GALLERY</code></label>
                    <input type="text" id="coresync_gallery_confirmation" class="form-control" autocomplete="off" spellcheck="false" maxlength="64">
                    <button type="button" class="btn btn_small btn-warning" id="coresync_gallery_apply" style="margin-top:8px">
                        <span>Применить точный план</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{* Retention status is a cached journal read. A deletion is possible only from a fresh server preview. *}
<div class="row">
    <div class="col-lg-12">
        <div class="boxed">
            <div class="heading_box">Резервные копии CoreSync</div>
            <p class="text-muted">Проверка покажет, что можно удалить и что останется для восстановления. Она не меняет файлы.</p>
            <div id="coresync_retention_status" role="status" aria-live="polite">Загружаю состояние очистки…</div>
            <button type="button" class="btn btn_small btn-info" id="coresync_retention_preview">Проверить очистку</button>
            <div id="coresync_retention_result" role="status" aria-live="polite"></div>
            <div id="coresync_retention_apply_result" role="status" aria-live="polite"></div>
            <div id="coresync_retention_items" hidden>
                <div class="heading_box">План очистки</div>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Тип</th><th>Версия / операция</th><th>Дата</th><th>Размер</th><th>Решение</th><th>Причина</th></tr></thead>
                        <tbody id="coresync_retention_rows"></tbody>
                    </table>
                </div>
            </div>
            <div id="coresync_retention_apply_box" hidden>
                <p id="coresync_retention_confirmation" class="text-warning"></p>
                <button type="button" class="btn btn_small btn-warning" id="coresync_retention_apply">Удалить устаревшие копии</button>
            </div>
        </div>
    </div>
</div>

{* Exchange package retention is independent of code-copy retention above. *}
<div class="row">
    <div class="col-lg-12">
        <div class="boxed">
            <div class="heading_box">Пакеты обмена</div>
            <p class="text-muted">Хранение применённых и неудачных пакетов настраивается отдельно. Проверка ничего не удаляет; защищённые пакеты остаются.</p>
            <div id="coresync_exchange_status" role="status" aria-live="polite">Загружаю состояние…</div>
            <form id="coresync_exchange_policy" method="post">
                <input type="hidden" name="session_id" value="{$smarty.session.id}">
                <div class="okay_switch clearfix">
                    <label class="switch_label" for="coresync_exchange_enabled">Периодическая очистка</label>
                    <label class="switch switch-default">
                        <input class="switch-input" type="checkbox" id="coresync_exchange_enabled" name="enabled" value="1">
                        <span class="switch-label"></span><span class="switch-handle"></span>
                    </label>
                </div>
                <p class="text-muted">По умолчанию выключена. Ручная проверка доступна независимо от неё.</p>
                <div class="row">
                    <div class="col-lg-6 col-md-6 col-sm-12">
                        <div class="heading_box">Применённые</div>
                        <div class="form-group"><label for="coresync_exchange_applied_age">Возраст, суток</label><input class="form-control" id="coresync_exchange_applied_age" name="applied_max_age_days" type="number" min="1" step="1"></div>
                        <div class="form-group"><label for="coresync_exchange_applied_count">Количество</label><input class="form-control" id="coresync_exchange_applied_count" name="applied_max_count" type="number" min="1" step="1"></div>
                        <div class="form-group"><label for="coresync_exchange_applied_mib">Объём, МиБ</label><input class="form-control" id="coresync_exchange_applied_mib" type="number" min="1" step="1"></div>
                    </div>
                    <div class="col-lg-6 col-md-6 col-sm-12">
                        <div class="heading_box">Неудачные</div>
                        <div class="form-group"><label for="coresync_exchange_failed_age">Возраст, суток</label><input class="form-control" id="coresync_exchange_failed_age" name="failed_max_age_days" type="number" min="1" step="1"></div>
                        <div class="form-group"><label for="coresync_exchange_failed_count">Количество</label><input class="form-control" id="coresync_exchange_failed_count" name="failed_max_count" type="number" min="1" step="1"></div>
                        <div class="form-group"><label for="coresync_exchange_failed_mib">Объём, МиБ</label><input class="form-control" id="coresync_exchange_failed_mib" type="number" min="1" step="1"></div>
                    </div>
                </div>
                <p id="coresync_exchange_policy_note" class="text-muted"></p>
                <button type="submit" class="btn btn_small btn-success" id="coresync_exchange_save">Сохранить правило</button>
                <span id="coresync_exchange_policy_result" role="status" aria-live="polite"></span>
            </form>
            <hr>
            <button type="button" class="btn btn_small btn-info" id="coresync_exchange_preview">Проверить пакеты</button>
            <button type="button" class="btn btn_small btn-default" id="coresync_exchange_next" hidden>Следующая часть</button>
            <p id="coresync_exchange_preview_result" role="status" aria-live="polite"></p>
            <div id="coresync_exchange_items" hidden>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Версия</th><th>Состояние</th><th>Дата</th><th>Размер</th><th>Файлы</th><th>Решение</th><th>Причина и сведения</th><th>Защита</th></tr></thead>
                        <tbody id="coresync_exchange_rows"></tbody>
                    </table>
                </div>
            </div>
            <div id="coresync_exchange_apply_box" hidden>
                <p id="coresync_exchange_confirmation" class="text-warning"></p>
                <button type="button" class="btn btn_small btn-warning" id="coresync_exchange_apply">Удалить выбранную часть</button>
            </div>
            <p id="coresync_exchange_apply_result" role="status" aria-live="polite"></p>
        </div>
    </div>
</div>

{* E. Обслуживание: деструктивные церемонии отдельно от повседневных кнопок шапки, с текстом
   последствий рядом с кнопкой (не только внутри confirm()). *}
<div class="row">
    <div class="col-lg-12">
        <div class="boxed">
            <div>
                <div class="box_heading" style="margin-bottom:10px">Повторные операции</div>
                <div class="form-group" style="display:flex;align-items:center;gap:12px">
                    <button type="button" class="btn btn_small btn-default" id="coresync_reapply">
                        <span>Полное перепринятие</span>
                    </button>
                    <small class="text-muted">
                        Сбрасывает применённые хэши и переприменяет весь снапшот текущей версией заново.
                        Каталог вне карты sync не затрагивается. Используйте только при расхождении данных.
                    </small>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:12px;margin-bottom:0">
                    <button type="button" class="btn btn_small btn-default" id="coresync_rebind">
                        <span>Связать заново</span>
                    </button>
                    <small class="text-muted">
                        Сбрасывает связывание товаров/вариантов с каталогом ядра и связывает заново по SKU.
                        Товары витрины без совпадающего SKU в каталоге ядра приедут следующим прогоном как
                        НОВЫЕ (могут задвоиться с уже существующими).
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var sessionId = '{$smarty.session.id}';
    var urlCheck = '{url controller="Format.CoreSync.CoreSyncAdmin@checkConnection"}';
    var urlRun = '{url controller="Format.CoreSync.CoreSyncAdmin@runNow"}';
    var urlStatus = '{url controller="Format.CoreSync.CoreSyncAdmin@status"}';
    var urlCancel = '{url controller="Format.CoreSync.CoreSyncAdmin@cancel"}';
    var urlReapply = '{url controller="Format.CoreSync.CoreSyncAdmin@reapply"}';
    var urlRebind = '{url controller="Format.CoreSync.CoreSyncAdmin@rebind"}';
    var urlGalleryPreview = '{url controller="Format.CoreSync.CoreSyncAdmin@previewGalleryAdoption"}';
    var urlGalleryApply = '{url controller="Format.CoreSync.CoreSyncAdmin@applyGalleryAdoption"}';

    // Посев первого показа — та же форма данных, что отдаёт status()/runNow()/cancel() (panelPayload
    // в контроллере). renderPanel() ниже — ЕДИНСТВЕННОЕ место, которое умеет рисовать панель:
    // первый показ и обновление поллером не могут разъехаться, потому что читают один код.
    var PANEL_INITIAL = {$panel_json};

    var RU_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    var BUSY_STATUSES = ['created', 'running', 'downloaded', 'applying'];
    var STATUS_META = {
        created: { label: 'создан', cls: 'text-info' },
        running: { label: 'идёт', cls: 'text-info' },
        downloaded: { label: 'скачан', cls: 'text-info' },
        applying: { label: 'применяется', cls: 'text-info' },
        applied: { label: 'применён', cls: 'text-success' },
        held: { label: 'частично применён (held)', cls: 'text-warning' },
        bound: { label: 'связан (bind)', cls: 'text-info' },
        failed: { label: 'ошибка', cls: 'text-danger' },
        cancelled: { label: 'отменён', cls: 'text-warning' }
    };

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[ch];
        });
    }

    function parseTs(s) {
        if (!s) { return null; }
        var d = new Date(String(s).replace(' ', 'T'));

        return isNaN(d.getTime()) ? null : d;
    }

    function formatHuman(s) {
        var d = parseTs(s);
        if (!d) { return ''; }
        var hh = ('0' + d.getHours()).slice(-2);
        var mm = ('0' + d.getMinutes()).slice(-2);

        return d.getDate() + ' ' + RU_MONTHS[d.getMonth()] + ', ' + hh + ':' + mm;
    }

    function formatDuration(startMs, endMs) {
        var totalSec = Math.max(0, Math.round((endMs - startMs) / 1000));
        var h = Math.floor(totalSec / 3600);
        var m = Math.floor((totalSec % 3600) / 60);
        var parts = [];
        if (h) { parts.push(h + ' ч'); }
        parts.push(m + ' мин');

        return parts.join(' ');
    }

    var isRunning = false;
    var runPending = false;
    var runButton = document.getElementById('coresync_run');

    function syncRunButton() {
        runButton.disabled = isRunning || runPending;
    }

    function renderPanel(data) {
        var job = data.job;
        var html = '';

        if (!job) {
            html += '<p>Прогонов ещё не было.</p>';
        } else {
            var meta = STATUS_META[job.status] || { label: job.status, cls: '' };
            html += '<p><strong class="' + meta.cls + '">' + escapeHtml(meta.label) + '</strong>';
            if (job.snapshot_version) { html += ' — версия ' + job.snapshot_version; }
            html += '</p>';

            if (BUSY_STATUSES.indexOf(job.status) !== -1) {
                var started = parseTs(job.started_at);
                if (started) {
                    var minutes = Math.max(0, Math.round((Date.now() - started.getTime()) / 60000));
                    html += '<p>Идёт ' + minutes + ' мин.</p>';
                }
                var total = job.files_total || 0;
                var done = job.files_done || 0;
                var pct = total > 0 ? Math.round(done / total * 100) : 0;
                html += '<div class="progress" style="height:18px;margin-bottom:8px">'
                    + '<div class="progress-bar" role="progressbar" style="width:' + pct + '%">' + done + '/' + total + '</div></div>';
                if (job.phase) { html += '<p>Фаза: ' + escapeHtml(job.phase) + '</p>'; }
            } else if (job.started_at) {
                var finished = parseTs(job.finished_at);
                var startedDate = parseTs(job.started_at);
                html += '<p>Последний прогон: ' + formatHuman(job.started_at);
                if (startedDate && finished) {
                    html += ' (заняло ' + formatDuration(startedDate.getTime(), finished.getTime()) + ')';
                }
                html += '</p>';
            }

            if (job.status === 'failed' && job.error_message) {
                html += '<p class="text-danger">' + escapeHtml(job.error_message) + '</p>';
            }
        }

        var own = data.ownership || {};
        html += '<p><strong>Под управлением обмена:</strong> товаров ' + (own.product || 0)
            + ', вариантов ' + (own.variant || 0) + ', категорий ' + (own.category || 0)
            + ', брендов ' + (own.brand || 0) + '</p>';

        var img = data.images || {};
        html += '<p><strong>Картинки:</strong> скачано ' + (img.done || 0)
            + ', в очереди ' + (img.pending || 0) + ', с ошибкой ' + (img.failed || 0) + '</p>';

        html += '<details><summary>Параметры webhook</summary>'
            + '<p>URL приёмника: <code id="coresync_ping_url">' + escapeHtml(data.ping_url) + '</code> '
            + '<button type="button" class="btn btn-xs btn-default" id="coresync_copy_ping">Копировать</button></p>'
            + '<p>Укажите этот адрес как <em>satellite_url</em> канала в ядре.</p>';
        if (data.channel_link) {
            html += '<p><a href="' + escapeHtml(data.channel_link) + '" target="_blank" rel="noopener">Карточка канала в ядре</a></p>';
        }
        html += '</details>';

        document.getElementById('coresync_panel').innerHTML = html;

        var copyBtn = document.getElementById('coresync_copy_ping');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var text = document.getElementById('coresync_ping_url').textContent;
                if (navigator.clipboard) { navigator.clipboard.writeText(text); }
            });
        }

        isRunning = !!job && BUSY_STATUSES.indexOf(job.status) !== -1;
        syncRunButton();
        var cancelBtn = document.getElementById('coresync_cancel');
        if (cancelBtn) { cancelBtn.style.display = (job && job.status === 'running') ? '' : 'none'; }

        return isRunning;
    }

    var poller = null;
    function stopPolling() {
        if (poller) { clearInterval(poller); poller = null; }
    }
    function pollOnce() {
        fetch(urlStatus, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
            if (!renderPanel(res)) { stopPolling(); }
        });
    }
    function startPolling() {
        if (poller) { return; }
        poller = setInterval(pollOnce, 2000);
    }

    // Поллер стоит на скрытой вкладке и возобновляется при возврате — не жжёт запросы фоном.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else if (isRunning) {
            pollOnce();
            startPolling();
        }
    });

    document.getElementById('coresync_check').addEventListener('click', function () {
        var box = document.getElementById('coresync_check_result');
        box.textContent = 'Проверяю…';
        fetch(urlCheck, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { box.innerHTML = '<span class="text-danger"></span>'; box.firstChild.textContent = res.error; return; }
            var s = res.summary;
            var text = 'Версия ' + s.snapshot_version + ' · сгенерирован ' + s.generated_at
                + ' · товаров ' + (s.counts.products || 0) + ', вариантов ' + (s.counts.variants || 0)
                + ', категорий ' + (s.counts.categories || 0);
            // D. Список валютных кодов манифеста — только если валидатор его когда-нибудь станет
            // отдавать (сейчас summary() несёт единственную валюту манифеста, не список; расширение
            // ManifestValidator вне scope этого этапа). Молча пропускаем, если поля нет.
            if (Array.isArray(s.currencies) && s.currencies.length) {
                text += ' · валюты манифеста: ' + s.currencies.join(', ');
            }
            box.innerHTML = '<span class="text-success"></span>';
            box.firstChild.textContent = text;
        });
    });

    function post(url) {
        var fd = new FormData();
        fd.append('session_id', sessionId);

        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    var galleryPlanSha = '';
    var galleryFile = document.getElementById('coresync_gallery_plan');
    var galleryResult = document.getElementById('coresync_gallery_result');
    var galleryApplyBox = document.getElementById('coresync_gallery_apply_box');

    function resetGalleryPreview() {
        galleryPlanSha = '';
        galleryApplyBox.style.display = 'none';
        document.getElementById('coresync_gallery_confirmation').value = '';
    }

    function galleryPost(url, apply) {
        if (!galleryFile.files || !galleryFile.files[0]) {
            return Promise.resolve({ success: false, error: 'Выберите приватный файл плана.' });
        }
        var fd = new FormData();
        fd.append('session_id', sessionId);
        fd.append('gallery_adoption_plan', galleryFile.files[0]);
        if (apply) {
            fd.append('expected_plan_sha256', galleryPlanSha);
            fd.append('confirm', document.getElementById('coresync_gallery_confirmation').value.trim());
        }

        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    galleryFile.addEventListener('change', function () {
        resetGalleryPreview();
        galleryResult.textContent = '';
    });
    document.getElementById('coresync_gallery_preview').addEventListener('click', function () {
        resetGalleryPreview();
        galleryResult.textContent = 'Проверяю план…';
        galleryPost(urlGalleryPreview, false).then(function (res) {
            if (!res.success) {
                galleryResult.innerHTML = '<span class="text-danger"></span>';
                galleryResult.firstChild.textContent = res.error || 'План отклонён';
                return;
            }
            galleryPlanSha = res.plan_sha256;
            var writes = res.writes || {};
            galleryResult.innerHTML = '<span class="text-success"></span>';
            galleryResult.firstChild.textContent = 'План ' + galleryPlanSha + ' · строк ' + res.rows
                + ' · durable add ' + (writes.durable_adds || 0)
                + ', durable update ' + (writes.durable_updates || 0)
                + ', coarse marker ' + (writes.map_updates || 0) + '. Файл будет проверен повторно при применении.';
            galleryApplyBox.style.display = '';
        });
    });
    document.getElementById('coresync_gallery_apply').addEventListener('click', function () {
        galleryResult.textContent = 'Повторно проверяю и применяю точный план…';
        galleryPost(urlGalleryApply, true).then(function (res) {
            if (!res.success) {
                galleryResult.innerHTML = '<span class="text-danger"></span>';
                galleryResult.firstChild.textContent = res.error || 'План не применён';
                return;
            }
            galleryResult.innerHTML = '<span class="text-success"></span>';
            galleryResult.firstChild.textContent = 'Применено: ' + res.rows + ' строк, записей ' + res.writes
                + ', SHA-256 ' + res.plan_sha256 + '.';
            galleryApplyBox.style.display = 'none';
        });
    });

    // Отказ «Запустить сейчас» (напр. модуль выключен) обязан быть ВИДЕН оператору.
    function runNow() {
        if (isRunning || runPending) { return Promise.resolve(); }
        runPending = true;
        syncRunButton();
        return post(urlRun).then(function (res) {
            var box = document.getElementById('coresync_check_result');
            if (!res.success) {
                box.innerHTML = '<span class="text-danger"></span>';
                box.firstChild.textContent = res.error || 'Прогон не запущен';
                return;
            }
            box.textContent = '';
            if (renderPanel(res)) { startPolling(); }
        }, function () {
            var box = document.getElementById('coresync_check_result');
            box.innerHTML = '<span class="text-danger">Не удалось отправить запрос. Проверьте соединение.</span>';
        }).then(function () {
            runPending = false;
            syncRunButton();
        });
    }

    document.getElementById('coresync_run').addEventListener('click', function () {
        runNow();
    });
    document.getElementById('coresync_cancel').addEventListener('click', function () {
        post(urlCancel).then(function (res) {
            if (renderPanel(res)) { startPolling(); }
        });
    });
    document.getElementById('coresync_reapply').addEventListener('click', function () {
        if (!confirm('Сбросить применённые хэши и переприменить весь снапшот заново той же версией? Каталог вне карты sync не затрагивается.')) { return; }
        post(urlReapply).then(function () {
            // после сброса — сразу запускаем прогон (обойдёт VersionGate по force-флагу)
            runNow();
        });
    });
    // «Связать заново»: честный текст о последствиях — сброс связывания товаров; товары, не
    // найденные по SKU в каталоге ядра, приедут как НОВЫЕ (могут задвоиться). Отказ (модуль
    // выключен) обязан быть виден оператору — как и в runNow.
    document.getElementById('coresync_rebind').addEventListener('click', function () {
        if (!confirm('Сбросить текущее связывание товаров/вариантов с каталогом ядра и связать заново по SKU?\n\n'
            + 'Товары витрины, для которых нет совпадающего SKU в каталоге ядра, приедут следующим прогоном как НОВЫЕ '
            + '(могут задвоиться с уже существующими). Связывание категорий, брендов и свойств не затрагивается.')) { return; }
        post(urlRebind).then(function (res) {
            if (res && res.success === false) {
                var box = document.getElementById('coresync_check_result');
                box.innerHTML = '<span class="text-danger"></span>';
                box.firstChild.textContent = res.error || 'Связывание не сброшено';
                return;
            }
            // после сброса — сразу запускаем прогон (уйдёт в BIND и свяжет по SKU)
            runNow();
        });
    });

    // RETENTION_UI_START — cached status, exact server preview and bounded apply.
    var retentionStatusUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@retentionStatus"}';
    var retentionPreviewUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@previewRetention"}';
    var retentionApplyUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@applyRetention"}';
    var retentionStatusBox = document.getElementById('coresync_retention_status');
    var retentionResult = document.getElementById('coresync_retention_result');
    var retentionApplyResult = document.getElementById('coresync_retention_apply_result');
    var retentionPreviewButton = document.getElementById('coresync_retention_preview');
    var retentionApplyButton = document.getElementById('coresync_retention_apply');
    var retentionApplyBox = document.getElementById('coresync_retention_apply_box');
    var retentionItemsBox = document.getElementById('coresync_retention_items');
    var retentionRows = document.getElementById('coresync_retention_rows');
    var retentionConfirmation = document.getElementById('coresync_retention_confirmation');
    var retentionPlan = null;
    var retentionBusy = false;

    function retentionBytes(raw) {
        var bytes = Number(raw);
        if (raw === null || raw === undefined || !isFinite(bytes) || bytes < 0) { return '—'; }
        if (bytes < 1024) { return Math.round(bytes) + ' Б'; }
        if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' КБ'; }
        if (bytes < 1073741824) { return (bytes / 1048576).toFixed(1) + ' МБ'; }
        return (bytes / 1073741824).toFixed(1) + ' ГБ';
    }

    function retentionText(value) {
        return value === null || value === undefined || value === '' ? '—' : String(value);
    }

    function retentionSetBusy(busy) {
        retentionBusy = busy;
        retentionPreviewButton.disabled = busy;
        retentionApplyButton.disabled = busy || !retentionPlan;
    }

    function retentionError(code, fallback) {
        return {
            invalid_session: 'Сессия истекла. Обновите страницу.',
            shared_lock_unavailable: 'Синхронизация, обновление или другая проверка уже выполняется.',
            stale_preview: 'Список изменился. Проверьте его заново.',
            confirmation_required: 'Подтвердите удаление после проверки.',
            retention_inventory_unavailable: 'Проверка хранилища не завершилась.',
            retention_apply_unavailable: 'Очистка не завершилась. Проверьте список и журнал.'
        }[code] || fallback;
    }

    function retentionPost(url, fields) {
        var fd = new FormData();
        fd.append('session_id', sessionId);
        Object.keys(fields).forEach(function (key) { fd.append(key, fields[key]); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function retentionType(type) {
        return { rollback: 'Копия кода', failed: 'Неудачное обновление', work: 'Временные файлы', download: 'Пакет загрузки', staging: 'Данные импорта' }[type] || retentionText(type);
    }

    function retentionDecision(decision) {
        return { delete: 'Удалить', keep: 'Сохранить', protected: 'Защищено' }[decision] || retentionText(decision);
    }

    function retentionReason(reason) {
        return {
            unknown_or_legacy: 'Нет проверенных метаданных',
            unverified_contents: 'Содержимое не прошло проверку',
            scan_budget: 'За пределами лимита этой проверки',
            apply_budget: 'Оставлено для следующего плана очистки',
            rollback_chain_unverified: 'Две более новые точки отката ещё не подтверждены',
            investigation_hold: 'Сохранено для расследования',
            immediate_rollback: 'Копия для отката последнего обновления',
            last_two_rollbacks: 'Одна из последних двух копий',
            latest_diagnostic: 'Последнее неудачное обновление',
            work_24h: 'Временным файлам меньше 24 часов',
            orphan_work_24h: 'Временные файлы старше 24 часов без активной операции',
            failed_7d: 'Неудачному обновлению меньше 7 дней',
            schema_uncertain: 'Совместимость со схемой БД не подтверждена',
            older_compatible_rollback: 'Есть две более новые совместимые копии',
            old_failed: 'Есть более свежая диагностика, этой больше 7 дней'
        }[reason] || 'Сохранено до дополнительной проверки';
    }

    function retentionRenderItems(items) {
        retentionRows.textContent = '';
        retentionItemsBox.hidden = !Array.isArray(items) || items.length === 0;
        if (retentionItemsBox.hidden) { return; }
        items.forEach(function (item) {
            var row = document.createElement('tr');
            var values = [
                retentionType(item.type), retentionText(item.version || (item.type ? item.id : null)), item.created_at ? formatHuman(item.created_at) : '—',
                retentionBytes(item.size_bytes || null), retentionDecision(item.decision), retentionReason(item.reason)
            ];
            values.forEach(function (value) {
                var cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });
            retentionRows.appendChild(row);
        });
    }

    function retentionRenderSummary(summary) {
        var state = summary || {};
        var rule = state.rule === 'two_compatible_rollbacks_latest_failed_7d_work_24h'
            ? 'Две совместимые копии кода. Последняя ошибка и диагностика за 7 дней. Временные файлы без активной операции — 24 часа.'
            : 'Правило хранения не указано';
        retentionStatusBox.textContent = 'Правило: ' + rule
            + ' · Последняя очистка: ' + retentionText(state.last_cleanup_at)
            + ' · Освобождено: ' + retentionBytes(state.bytes_freed)
            + (state.last_error ? ' · Ошибка: ' + retentionText(state.last_error) : '');
    }

    function retentionStatus() {
        return fetch(retentionStatusUrl, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { throw new Error(retentionError(res.error, 'Состояние очистки недоступно')); }
            retentionRenderSummary(res.summary);
            if (Array.isArray(res.items) && !retentionPlan) { retentionRenderItems(res.items); }
        }).catch(function (err) {
            retentionStatusBox.textContent = 'Не удалось загрузить состояние очистки: ' + retentionText(err.message);
        });
    }

    function retentionPreview() {
        if (retentionBusy) { return Promise.resolve(); }
        return retentionPreviewNow();
    }

    function retentionPreviewNow() {
        retentionPlan = null;
        retentionApplyBox.hidden = true;
        retentionResult.textContent = 'Проверяю копии без удаления…';
        retentionSetBusy(true);
        return retentionPost(retentionPreviewUrl, {}).then(function (res) {
            if (!res.success) { throw new Error(retentionError(res.error, 'План очистки недоступен')); }
            if (typeof res.plan_sha256 !== 'string' || !res.plan_sha256 || !res.totals) {
                throw new Error('Ответ плана неполный');
            }
            var count = Number(res.totals.deletable_count);
            var bytes = Number(res.totals.deletable_bytes);
            var protectedCount = Number(res.totals.protected_count);
            if (!Number.isSafeInteger(count) || count < 0 || !Number.isSafeInteger(bytes) || bytes < 0
                || !Number.isSafeInteger(protectedCount) || protectedCount < 0) {
                throw new Error('Ответ плана неполный');
            }
            retentionPlan = { sha: res.plan_sha256, count: count, bytes: bytes };
            retentionRenderItems(res.items);
            retentionResult.textContent = 'К удалению: ' + count + ' (' + retentionBytes(bytes)
                + '). Защищено: ' + protectedCount + '. Проверенный объём: ' + retentionBytes(res.totals.known_bytes)
                + (Number(res.totals.unmeasured_count) > 0 ? ' · Без замера: ' + res.totals.unmeasured_count : '');
            if (count > 0) {
                retentionConfirmation.textContent = 'План очистки: к удалению ' + count
                    + '; объём ' + retentionBytes(bytes) + ' (' + bytes + ' байт). Защищённые копии останутся.';
                retentionApplyBox.hidden = false;
            }
        }).catch(function (err) {
            retentionPlan = null;
            retentionRenderItems([]);
            retentionResult.textContent = 'Проверка не выполнена: ' + retentionText(err.message);
        }).then(function () { retentionSetBusy(false); });
    }

    retentionPreviewButton.addEventListener('click', retentionPreview);
    retentionApplyButton.addEventListener('click', function () {
        if (retentionBusy || !retentionPlan || retentionPlan.count === 0) { return; }
        var plan = retentionPlan;
        if (!confirm('Удалить устаревшие копии: ' + plan.count + ' (' + retentionBytes(plan.bytes)
            + ', ' + plan.bytes + ' байт)? Сохранённые для восстановления копии останутся.')) { return; }
        retentionPlan = null;
        retentionApplyBox.hidden = true;
        retentionSetBusy(true);
        retentionApplyResult.textContent = 'Применяю точный план…';
        retentionPost(retentionApplyUrl, {
            expected_plan_sha256: plan.sha,
            confirm: 'DELETE_OBSOLETE_COPIES'
        }).then(function (res) {
            if (!res.success) { throw new Error(retentionError(res.error, 'Очистка не применена')); }
            var errors = Array.isArray(res.errors) ? res.errors.length : Number(res.errors || 0);
            retentionApplyResult.textContent = 'Удалено: ' + retentionText(res.deleted_count)
                + ' · Освобождено: ' + retentionBytes(res.bytes_freed)
                + (errors > 0 ? ' · Ошибок: ' + errors + '. Проверьте оставшиеся копии.' : '');
            if (res.summary) { retentionRenderSummary(res.summary); }
        }).catch(function (err) {
            retentionApplyResult.textContent = 'Очистка не подтверждена: ' + retentionText(err.message);
        }).then(function () {
            // The server recalculates the exact cohort. Never reuse a stale hash after any apply outcome.
            return retentionStatus().then(retentionPreviewNow);
        });
    });
    retentionStatus();
    // RETENTION_UI_END

    // EXCHANGE_RETENTION_UI_START — independent of code-copy retention.
    var exchangeStatusUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@exchangeRetentionStatus"}';
    var exchangePreviewUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@previewExchangeRetention"}';
    var exchangeApplyUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@applyExchangeRetention"}';
    var exchangePolicyUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@saveExchangeRetentionPolicy"}';
    var exchangeProtectionUrl = '{url controller="Format.CoreSync.CoreSyncAdmin@setExchangeRetentionProtection"}';
    var exchangeStatusBox = document.getElementById('coresync_exchange_status');
    var exchangePolicyForm = document.getElementById('coresync_exchange_policy');
    var exchangePolicyResult = document.getElementById('coresync_exchange_policy_result');
    var exchangePolicyNote = document.getElementById('coresync_exchange_policy_note');
    var exchangePreviewResult = document.getElementById('coresync_exchange_preview_result');
    var exchangeApplyResult = document.getElementById('coresync_exchange_apply_result');
    var exchangeItemsBox = document.getElementById('coresync_exchange_items');
    var exchangeRows = document.getElementById('coresync_exchange_rows');
    var exchangeApplyBox = document.getElementById('coresync_exchange_apply_box');
    var exchangeConfirmation = document.getElementById('coresync_exchange_confirmation');
    var exchangePreviewButton = document.getElementById('coresync_exchange_preview');
    var exchangeNextButton = document.getElementById('coresync_exchange_next');
    var exchangeApplyButton = document.getElementById('coresync_exchange_apply');
    var exchangeSaveButton = document.getElementById('coresync_exchange_save');
    var exchangeEnabled = document.getElementById('coresync_exchange_enabled');
    var exchangeInputs = {
        applied_max_age_days: document.getElementById('coresync_exchange_applied_age'),
        applied_max_count: document.getElementById('coresync_exchange_applied_count'),
        applied_max_bytes: document.getElementById('coresync_exchange_applied_mib'),
        failed_max_age_days: document.getElementById('coresync_exchange_failed_age'),
        failed_max_count: document.getElementById('coresync_exchange_failed_count'),
        failed_max_bytes: document.getElementById('coresync_exchange_failed_mib')
    };
    var exchangeMiB = 1048576;
    var exchangeBusy = false;
    var exchangePolicyLoaded = false;
    var exchangePlan = null;
    var exchangeProtectionButtons = [];

    function exchangeError(code, fallback) {
        return {
            invalid_session: 'Сессия истекла. Обновите страницу.',
            shared_lock_unavailable: 'Идёт другая операция CoreSync. Повторите проверку позднее.',
            stale_preview: 'Пакеты изменились. Проверьте план заново.',
            stale_identity: 'Пакет изменился. Обновите список.',
            confirmation_required: 'Подтвердите удаление после проверки.',
            invalid_cursor: 'Продолжение списка устарело. Начните проверку сначала.'
        }[code] || fallback;
    }

    function exchangeFlag(value) {
        return value === true || value === 1 || value === '1';
    }

    function exchangeIdentityReady(item) {
        return item && /^[a-f0-9]{64}$/i.test(String(item.identity_sha256 || ''))
            && item.version !== null && item.version !== undefined && item.version !== '';
    }

    function exchangePositiveInt(value) {
        var number = Number(value);
        return Number.isSafeInteger(number) && number > 0 ? number : null;
    }

    function exchangeSetBusy(busy) {
        exchangeBusy = busy;
        exchangeEnabled.disabled = busy;
        Object.keys(exchangeInputs).forEach(function (key) { exchangeInputs[key].disabled = busy; });
        exchangeSaveButton.disabled = busy || !exchangePolicyLoaded;
        exchangePreviewButton.disabled = busy;
        exchangeNextButton.disabled = busy || !exchangePlan || !exchangePlan.nextCursor;
        exchangeApplyButton.disabled = busy || !exchangePlan || exchangePlan.count < 1;
        exchangeProtectionButtons.forEach(function (button) { button.disabled = busy; });
    }

    function exchangeClearPlan() {
        exchangePlan = null;
        exchangeApplyBox.hidden = true;
        exchangeNextButton.hidden = true;
        exchangeSetBusy(exchangeBusy);
    }

    function exchangeRenderStatus(summary) {
        var data = summary || {};
        exchangeStatusBox.textContent = 'Последняя очистка: ' + retentionText(data.last_cleanup_at)
            + ' · Удалено: ' + retentionText(data.deleted_count)
            + ' · Освобождено: ' + retentionBytes(data.bytes_freed)
            + (data.last_error ? ' · Ошибка: ' + retentionText(data.last_error) : '');
    }

    function exchangePolicyMib(bytes, input, warnings, label) {
        var value = Number(bytes);
        if (Number.isSafeInteger(value) && value > 0 && value % exchangeMiB === 0) {
            input.value = String(value / exchangeMiB);
        } else {
            input.value = '';
            warnings.push(label + ': текущий лимит не кратен целым МиБ. Задайте новое значение явно.');
        }
    }

    function exchangeRenderPolicy(policy) {
        if (!policy || !policy.applied || !policy.failed) { throw new Error('Правило хранения неполное'); }
        var warnings = [];
        exchangeEnabled.checked = policy.enabled === true || policy.enabled === 1 || policy.enabled === '1';
        exchangeInputs.applied_max_age_days.value = retentionText(policy.applied.max_age_days) === '—' ? '' : String(policy.applied.max_age_days);
        exchangeInputs.applied_max_count.value = retentionText(policy.applied.max_count) === '—' ? '' : String(policy.applied.max_count);
        exchangeInputs.failed_max_age_days.value = retentionText(policy.failed.max_age_days) === '—' ? '' : String(policy.failed.max_age_days);
        exchangeInputs.failed_max_count.value = retentionText(policy.failed.max_count) === '—' ? '' : String(policy.failed.max_count);
        exchangePolicyMib(policy.applied.max_bytes, exchangeInputs.applied_max_bytes, warnings, 'Применённые');
        exchangePolicyMib(policy.failed.max_bytes, exchangeInputs.failed_max_bytes, warnings, 'Неудачные');
        var diagnosticDays = exchangePositiveInt(policy.diagnostic_days);
        exchangePolicyNote.textContent = (diagnosticDays ? 'Диагностика хранится ' + diagnosticDays + ' суток. ' : '')
            + '1 ГиБ = 1024 МиБ. ' + warnings.join(' ');
        exchangePolicyLoaded = true;
        exchangeSetBusy(exchangeBusy);
    }

    function exchangeStatus() {
        return fetch(exchangeStatusUrl, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { throw new Error(exchangeError(res.error, 'Состояние пакетов недоступно')); }
            exchangeRenderStatus(res.summary);
            exchangeRenderPolicy(res.policy);
        }).catch(function (err) {
            exchangePolicyLoaded = false;
            exchangeStatusBox.textContent = 'Не удалось загрузить состояние пакетов: ' + retentionText(err.message);
            exchangeSetBusy(exchangeBusy);
        });
    }

    function exchangeReason(reason, decision) {
        var known = {
            current_applied: 'Текущий применённый пакет',
            previous_applied: 'Предыдущий применённый пакет',
            current_manifest_failed: 'Последний пакет обмена с ошибкой',
            pending_replay: 'Ожидает повторной обработки',
            manual_hold: 'Удержан вручную', manual_pin: 'Закреплён вручную',
            within_policy: 'В пределах лимитов хранения', count_limit: 'Превышен лимит количества',
            age_limit: 'Превышен срок хранения', byte_limit: 'Превышен лимит объёма',
            failure_representative: 'Сохранён для диагностики повторяющейся ошибки',
            legacy_unproven: 'Для старого пакета недостаточно данных',
            mixed_or_unknown: 'Статус обработки требует проверки',
            linked_staging_document: 'Опубликованный документ ссылается на этот пакет',
            published_document_unverified: 'Копию опубликованного документа не удалось проверить',
            unsafe_checkpoint: 'Сведения о проверке файлов неполные',
            checkpoint_mismatch: 'Файлы разошлись с контрольными суммами',
            verification_budget: 'Достигнут лимит проверки файлов',
            apply_budget: 'Отложен до следующей партии'
        };
        if (Object.prototype.hasOwnProperty.call(known, reason)) { return known[reason]; }
        if (typeof reason === 'string' && /[А-Яа-яЁё\s]/.test(reason)) { return reason; }
        return decision === 'delete' ? 'Подлежит удалению по правилу хранения' : 'Сохранён по правилу защиты';
    }

    function exchangeCell(row, text) {
        var cell = document.createElement('td');
        cell.textContent = retentionText(text);
        row.appendChild(cell);
        return cell;
    }

    function exchangeProtection(item, field) {
        if (exchangeBusy || !exchangeIdentityReady(item)) { return; }
        var cursor = exchangePlan ? exchangePlan.cursor : '';
        exchangeClearPlan();
        exchangeSetBusy(true);
        exchangeApplyResult.textContent = 'Обновляю защиту пакета…';
        var hold = field === 'hold' ? !exchangeFlag(item.hold) : exchangeFlag(item.hold);
        var pin = field === 'pin' ? !exchangeFlag(item.pin) : exchangeFlag(item.pin);
        retentionPost(exchangeProtectionUrl, {
            version: String(item.version), hold: hold ? '1' : '0', pin: pin ? '1' : '0',
            expected_identity_sha256: String(item.identity_sha256)
        }).then(function (res) {
            if (!res.success) { throw new Error(exchangeError(res.error, 'Защита не изменена')); }
            exchangeApplyResult.textContent = 'Защита пакета обновлена. Проверяю новый план…';
        }).catch(function (err) {
            exchangeApplyResult.textContent = 'Результат защиты не подтверждён: ' + retentionText(err.message);
        }).then(function () { return exchangeStatus().then(function () { return exchangePreviewAt(cursor); }); });
    }

    function exchangeProtectionButton(item, field, label, cell) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn_small btn-default';
        button.textContent = label;
        button.disabled = exchangeBusy;
        button.addEventListener('click', function () { exchangeProtection(item, field); });
        cell.appendChild(button);
        exchangeProtectionButtons.push(button);
    }

    function exchangeRenderItems(items) {
        exchangeRows.textContent = '';
        exchangeProtectionButtons = [];
        exchangeItemsBox.hidden = !Array.isArray(items) || items.length === 0;
        if (exchangeItemsBox.hidden) { return; }
        items.forEach(function (item) {
            var row = document.createElement('tr');
            exchangeCell(row, item.version);
            exchangeCell(row, { applied: 'Применён', failed: 'Ошибка' }[item.status] || item.status);
            exchangeCell(row, item.created_at ? formatHuman(item.created_at) : '—');
            exchangeCell(row, retentionBytes(item.size_bytes));
            exchangeCell(row, item.files_count);
            exchangeCell(row, { delete: 'К удалению', keep: 'Сохранить', protected: 'Защищён' }[item.decision] || item.decision);
            var reasonCell = exchangeCell(row, exchangeReason(item.reason, item.decision));
            var details = document.createElement('details');
            var summary = document.createElement('summary');
            summary.textContent = 'Сведения';
            details.appendChild(summary);
            var diagnostic = document.createElement('div');
            diagnostic.textContent = 'Причина: ' + retentionText(item.reason)
                + ' · Путь: ' + retentionText(item.relative_path)
                + ' · Идентичность: ' + retentionText(item.identity_sha256);
            details.appendChild(diagnostic);
            reasonCell.appendChild(details);
            var protectionCell = exchangeCell(row, (exchangeFlag(item.hold) ? 'Удержан. ' : '')
                + (exchangeFlag(item.pin) ? 'Закреплён.' : ''));
            if (exchangeIdentityReady(item)) {
                exchangeProtectionButton(item, 'hold', exchangeFlag(item.hold) ? 'Снять удержание' : 'Удержать', protectionCell);
                exchangeProtectionButton(item, 'pin', exchangeFlag(item.pin) ? 'Снять закрепление' : 'Закрепить', protectionCell);
            } else {
                var unavailable = document.createElement('span');
                unavailable.textContent = 'Нет проверенной идентичности';
                protectionCell.appendChild(unavailable);
            }
            exchangeRows.appendChild(row);
        });
        exchangeSetBusy(exchangeBusy);
    }

    function exchangePreviewAt(cursor) {
        exchangeClearPlan();
        exchangeSetBusy(true);
        exchangePreviewResult.textContent = 'Проверяю пакеты без удаления…';
        return retentionPost(exchangePreviewUrl, { cursor: cursor }).then(function (res) {
            if (!res.success) { throw new Error(exchangeError(res.error, 'План пакетов недоступен')); }
            if (typeof res.plan_sha256 !== 'string' || !res.plan_sha256 || !res.totals || !Array.isArray(res.items)) {
                throw new Error('Ответ плана неполный');
            }
            var count = Number(res.totals.deletable_count);
            var bytes = Number(res.totals.deletable_bytes);
            var protectedCount = Number(res.totals.protected_count);
            var unmeasuredCount = Number(res.totals.unmeasured_count);
            if (![count, bytes, protectedCount, unmeasuredCount].every(function (n) { return Number.isSafeInteger(n) && n >= 0; })) {
                throw new Error('Счётчики плана неполные');
            }
            var nextCursor = res.incomplete && typeof res.next_cursor === 'string' && res.next_cursor !== cursor
                ? res.next_cursor : '';
            exchangePlan = { sha: res.plan_sha256, cursor: cursor, count: count, bytes: bytes, nextCursor: nextCursor };
            exchangeRenderItems(res.items);
            exchangePreviewResult.textContent = 'К удалению в этой части: ' + count + ' (' + retentionBytes(bytes)
                + ', ' + bytes + ' байт). Защищено: ' + protectedCount + '. Без замера: ' + unmeasuredCount
                + (res.incomplete
                    ? (nextCursor ? ' · Список неполный: продолжите проверку по курсору.'
                        : ' · Список неполный, курсор недоступен. Начните проверку заново.')
                    : ' · Список завершён.');
            exchangeNextButton.hidden = !nextCursor;
            if (count > 0) {
                exchangeConfirmation.textContent = 'Точная часть плана: ' + count + ' пакетов, ' + retentionBytes(bytes)
                    + ' (' + bytes + ' байт). Защищённые пакеты останутся.';
                exchangeApplyBox.hidden = false;
            }
        }).catch(function (err) {
            exchangeRenderItems([]);
            exchangePreviewResult.textContent = 'Проверка не выполнена: ' + retentionText(err.message);
        }).then(function () { exchangeSetBusy(false); });
    }

    exchangePreviewButton.addEventListener('click', function () {
        if (!exchangeBusy) { exchangePreviewAt(''); }
    });
    exchangeNextButton.addEventListener('click', function () {
        if (!exchangeBusy && exchangePlan && exchangePlan.nextCursor) { exchangePreviewAt(exchangePlan.nextCursor); }
    });

    exchangePolicyForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (exchangeBusy || !exchangePolicyLoaded) { return; }
        var fields = { enabled: exchangeEnabled.checked ? '1' : '0' };
        var invalid = false;
        Object.keys(exchangeInputs).forEach(function (key) {
            var value = exchangePositiveInt(exchangeInputs[key].value);
            if (value === null) { invalid = true; return; }
            if (key.slice(-6) === '_bytes') { value *= exchangeMiB; }
            if (!Number.isSafeInteger(value)) { invalid = true; return; }
            fields[key] = String(value);
        });
        if (invalid) {
            exchangePolicyResult.textContent = 'Укажите положительные целые значения; объём — в целых МиБ.';
            return;
        }
        exchangeClearPlan();
        exchangeSetBusy(true);
        exchangePolicyResult.textContent = 'Сохраняю правило…';
        retentionPost(exchangePolicyUrl, fields).then(function (res) {
            if (!res.success) { throw new Error(exchangeError(res.error, 'Правило не сохранено')); }
            exchangePolicyResult.textContent = 'Правило сохранено. Проверьте план очистки заново.';
        }).catch(function (err) {
            exchangePolicyResult.textContent = 'Результат сохранения не подтверждён: ' + retentionText(err.message);
        }).then(function () { return exchangeStatus().then(function () { exchangeSetBusy(false); }); });
    });

    exchangeApplyButton.addEventListener('click', function () {
        if (exchangeBusy || !exchangePlan || exchangePlan.count < 1) { return; }
        var plan = exchangePlan;
        if (!confirm('Удалить пакеты только из этой части: ' + plan.count + ' (' + retentionBytes(plan.bytes)
            + ', ' + plan.bytes + ' байт)? Защищённые пакеты останутся.')) { return; }
        exchangeClearPlan();
        exchangeSetBusy(true);
        exchangeApplyResult.textContent = 'Применяю точный план…';
        var readbackCursor = plan.cursor;
        retentionPost(exchangeApplyUrl, {
            cursor: plan.cursor, expected_plan_sha256: plan.sha, confirm: 'DELETE_EXCHANGE_PACKAGES'
        }).then(function (res) {
            if (!res.success) { throw new Error(exchangeError(res.error, 'Очистка не подтверждена')); }
            var errors = Array.isArray(res.errors) ? res.errors.length : Number(res.errors || 0);
            if (typeof res.next_cursor === 'string') { readbackCursor = res.next_cursor; }
            exchangeApplyResult.textContent = 'Удалено: ' + retentionText(res.deleted_count)
                + ' · Освобождено: ' + retentionBytes(res.bytes_freed)
                + (errors > 0 ? ' · Ошибок: ' + errors + '. Проверьте оставшиеся пакеты.' : '');
            if (res.summary) { exchangeRenderStatus(res.summary); }
        }).catch(function (err) {
            exchangeApplyResult.textContent = 'Результат очистки не подтверждён: ' + retentionText(err.message);
        }).then(function () { return exchangeStatus().then(function () { return exchangePreviewAt(readbackCursor); }); });
    });

    exchangeSetBusy(true);
    exchangeStatus().then(function () { exchangeSetBusy(false); });
    // EXCHANGE_RETENTION_UI_END

    if (renderPanel(PANEL_INITIAL)) { startPolling(); }
})();
</script>
