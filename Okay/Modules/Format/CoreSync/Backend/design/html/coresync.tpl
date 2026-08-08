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
    <div class="col-lg-12 col-md-12">
        <div class="wrap_heading">
            <div class="box_heading heading_page">Синхронизация сателлита (CoreSync)</div>
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
        <div class="card mb-2">
            <div class="card-body" id="coresync_panel"></div>
        </div>
    </div>
</div>
<div id="coresync_check_result" style="margin:0 0 15px"></div>

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
                <small class="form-text text-muted">
                    <strong>Числовой</strong> идентификатор канала в ядре, а не словесный код: он уезжает в адрес
                    <code>{literal}{адрес ядра}/api/satellite/{ID}/manifest.json{/literal}</code>, где ядро принимает только цифры.
                    Посмотреть его можно в адресной строке карточки канала в ядре — например в
                    <code>/channels/42</code> это <code>42</code>.
                </small>
            </div>
            <div class="form-group">
                <label>Идентификатор сателлита (source_instance)</label>
                <input type="text" class="form-control" name="settings[source_instance]" value="{$coresync.source_instance|escape}" placeholder="grundfos" pattern="[a-z0-9][a-z0-9_-]{literal}{0,63}{/literal}">
                <small class="form-text text-muted">
                    Стабильный safe slug источника из OkaySat, например <code>grundfos</code>. Для snapshot v2
                    обязателен и должен точно совпадать с <code>source_identity.instance</code> категории.
                </small>
            </div>
            <div class="form-group">
                <label>Адрес витрины (для церемонии подключения)</label>
                <input type="text" class="form-control" name="settings[storefront_base_url]" value="{$coresync.storefront_base_url|escape}" placeholder="{$storefront_base_url_hint|escape}">
                <small class="form-text text-muted">
                    Абсолютный адрес этой витрины — со схемой и без пути, например <code>{$storefront_base_url_hint|escape}</code>.
                    Ядро спрашивает его церемонией подключения и подставляет в ссылки товаров в своих фидах, поэтому
                    адрес берётся отсюда, а не из заголовков запроса. Пока поле пустое, модуль на церемонию не отвечает.
                </small>
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
                <label><input type="checkbox" name="settings[enabled]" value="1" {if $coresync.enabled}checked{/if}> Модуль включён</label>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="form-group">
                <label>Соответствие валют (в поле — код валюты из манифеста ядра; подпись строки — локальная валюта витрины)</label>
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

{* F. Версия модуля с диска + исход последнего самообновления. *}
<div class="row">
    <div class="col-lg-12">
        <div class="card mb-2">
            <div class="card-body">
                <strong>Версия модуля:</strong> {$module_version|default:'—'|escape}
                <div style="margin-top:8px">
                    <strong>Самообновление:</strong>
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

{* E. Обслуживание: деструктивные церемонии отдельно от повседневных кнопок шапки, с текстом
   последствий рядом с кнопкой (не только внутри confirm()). *}
<div class="row">
    <div class="col-lg-12">
        <div class="card mb-2">
            <div class="card-body">
                <div class="box_heading" style="margin-bottom:10px">Обслуживание</div>
                <div class="form-group" style="display:flex;align-items:center;gap:12px">
                    <button type="button" class="btn btn_small btn-default" id="coresync_reapply">
                        <span>Полное перепринятие</span>
                    </button>
                    <small class="text-muted">
                        Сбрасывает применённые хэши и переприменяет весь снапшот текущей версией заново.
                        Каталог вне карты sync не затрагивается. Использовать при подозрении на дрифт данных.
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

<script>
(function () {
    var sessionId = '{$smarty.session.id}';
    var urlCheck = '{url controller="Format.CoreSync.CoreSyncAdmin@checkConnection"}';
    var urlRun = '{url controller="Format.CoreSync.CoreSyncAdmin@runNow"}';
    var urlStatus = '{url controller="Format.CoreSync.CoreSyncAdmin@status"}';
    var urlCancel = '{url controller="Format.CoreSync.CoreSyncAdmin@cancel"}';
    var urlReapply = '{url controller="Format.CoreSync.CoreSyncAdmin@reapply"}';
    var urlRebind = '{url controller="Format.CoreSync.CoreSyncAdmin@rebind"}';

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

        html += '<p><strong>URL приёмника пинка:</strong> <code id="coresync_ping_url">' + escapeHtml(data.ping_url) + '</code> '
            + '<button type="button" class="btn btn-xs btn-default" id="coresync_copy_ping">Копировать</button>'
            + ' — пропишите его как <em>satellite_url</em> канала в ядре (webhook публикации).</p>';

        if (data.channel_link) {
            html += '<p><a href="' + escapeHtml(data.channel_link) + '" target="_blank" rel="noopener">Карточка канала в ядре</a></p>';
        }

        document.getElementById('coresync_panel').innerHTML = html;

        var copyBtn = document.getElementById('coresync_copy_ping');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var text = document.getElementById('coresync_ping_url').textContent;
                if (navigator.clipboard) { navigator.clipboard.writeText(text); }
            });
        }

        isRunning = !!job && BUSY_STATUSES.indexOf(job.status) !== -1;
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

    // Отказ «Запустить сейчас» (напр. модуль выключен) обязан быть ВИДЕН оператору.
    function runNow() {
        return post(urlRun).then(function (res) {
            var box = document.getElementById('coresync_check_result');
            if (!res.success) {
                box.innerHTML = '<span class="text-danger"></span>';
                box.firstChild.textContent = res.error || 'Прогон не запущен';
                return;
            }
            box.textContent = '';
            if (renderPanel(res)) { startPolling(); }
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

    if (renderPanel(PANEL_INITIAL)) { startPolling(); }
})();
</script>
