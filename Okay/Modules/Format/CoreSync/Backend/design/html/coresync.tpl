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
                <button type="button" class="btn btn_small btn-default" id="coresync_reapply">
                    <span>Полное перепринятие</span>
                </button>
                <button type="button" class="btn btn_small btn-default" id="coresync_rebind">
                    <span>Связать заново</span>
                </button>
            </div>
        </div>
    </div>
</div>

{* Статус последнего/текущего прогона (reconnect по этой панели) *}
<div class="row">
    <div class="col-lg-12">
        <div class="card mb-2">
            <div class="card-body">
                <strong>Последний прогон:</strong>
                <span id="coresync_status">
                    {if $last_job}
                        #{$last_job->id} — {$last_job->status|escape}
                        {if $last_job->snapshot_version} (версия {$last_job->snapshot_version}){/if}
                        — файлов {$last_job->files_done}/{$last_job->files_total}
                        {if $last_job->phase} · фаза {$last_job->phase|escape}{/if}
                        {if $last_job->error_message} · <span style="color:#c00">{$last_job->error_message|escape}</span>{/if}
                    {else}
                        прогонов ещё не было
                    {/if}
                </span>
                <div id="coresync_check_result" style="margin-top:8px"></div>
                <div style="margin-top:8px">
                    <strong>URL приёмника пинка:</strong>
                    <code>{$ping_url|escape}</code>
                    <small>— пропишите его как <em>satellite_url</em> канала в ядре (webhook публикации).</small>
                </div>
            </div>
        </div>
    </div>
</div>

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
                <label>Конкурентность картинок (задел M3)</label>
                <input type="number" min="1" max="16" class="form-control" name="settings[image_concurrency]" value="{$coresync.image_concurrency}">
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="settings[enabled]" value="1" {if $coresync.enabled}checked{/if}> Модуль включён</label>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="form-group">
                <label>Соответствие валют (код манифеста → локальная валюта)</label>
                <p><small>Отсутствие соответствия для валюты манифеста = fail-closed на этапе применения (M2).</small></p>
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

<script>
(function () {
    var sessionId = '{$smarty.session.id}';
    var urlCheck = '{url controller="Format.CoreSync.CoreSyncAdmin@checkConnection"}';
    var urlRun = '{url controller="Format.CoreSync.CoreSyncAdmin@runNow"}';
    var urlStatus = '{url controller="Format.CoreSync.CoreSyncAdmin@status"}';
    var urlCancel = '{url controller="Format.CoreSync.CoreSyncAdmin@cancel"}';
    var urlReapply = '{url controller="Format.CoreSync.CoreSyncAdmin@reapply"}';
    var urlRebind = '{url controller="Format.CoreSync.CoreSyncAdmin@rebind"}';

    function post(url) {
        var fd = new FormData();
        fd.append('session_id', sessionId);
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function renderJob(job) {
        var el = document.getElementById('coresync_status');
        if (!job) { el.textContent = 'прогонов ещё не было'; return null; }
        var txt = '#' + job.id + ' — ' + job.status;
        if (job.snapshot_version) { txt += ' (версия ' + job.snapshot_version + ')'; }
        txt += ' — файлов ' + job.files_done + '/' + job.files_total;
        if (job.phase) { txt += ' · фаза ' + job.phase; }
        if (job.error_message) { txt += ' · ' + job.error_message; }
        el.textContent = txt;
        document.getElementById('coresync_cancel').style.display = (job.status === 'running') ? '' : 'none';
        return job.status;
    }
    var poller = null;
    function startPolling() {
        if (poller) { return; }
        poller = setInterval(function () {
            fetch(urlStatus, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
                var st = renderJob(res.job);
                if (st !== 'running') { clearInterval(poller); poller = null; }
            });
        }, 2000);
    }

    document.getElementById('coresync_check').addEventListener('click', function () {
        var box = document.getElementById('coresync_check_result');
        box.textContent = 'Проверяю…';
        fetch(urlCheck, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { box.innerHTML = '<span style="color:#c00">' + res.error + '</span>'; return; }
            var s = res.summary;
            box.innerHTML = '<span style="color:#080">Версия ' + s.snapshot_version + ' · сгенерирован ' + s.generated_at +
                ' · товаров ' + (s.counts.products || 0) + ', вариантов ' + (s.counts.variants || 0) +
                ', категорий ' + (s.counts.categories || 0) + '</span>';
        });
    });
    // Отказ «Запустить сейчас» (напр. модуль выключен) обязан быть ВИДЕН оператору: без этой ветки
    // res.job === undefined отрисовался бы как «прогонов ещё не было» — то есть кнопка молча стирала
    // бы статус вместо того, чтобы назвать причину.
    function runNow() {
        return post(urlRun).then(function (res) {
            var box = document.getElementById('coresync_check_result');
            if (!res.success) {
                box.innerHTML = '<span style="color:#c00"></span>';
                box.firstChild.textContent = res.error || 'Прогон не запущен';
                return;
            }
            box.textContent = '';
            renderJob(res.job);
            startPolling();
        });
    }

    document.getElementById('coresync_run').addEventListener('click', function () {
        runNow();
    });
    document.getElementById('coresync_cancel').addEventListener('click', function () {
        post(urlCancel).then(function () { startPolling(); });
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
                box.innerHTML = '<span style="color:#c00"></span>';
                box.firstChild.textContent = res.error || 'Связывание не сброшено';
                return;
            }
            // после сброса — сразу запускаем прогон (уйдёт в BIND и свяжет по SKU)
            runNow();
        });
    });

    {if $last_job && $last_job->status == 'running'}startPolling();{/if}
})();
</script>
