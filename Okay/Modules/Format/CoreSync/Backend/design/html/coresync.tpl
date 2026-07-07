{$meta_title = 'CoreSync — синхронизация сателлита' scope=global}

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
                <label>Код канала</label>
                <input type="text" class="form-control" name="settings[channel_code]" value="{$coresync.channel_code|escape}" placeholder="site-a">
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
    document.getElementById('coresync_run').addEventListener('click', function () {
        post(urlRun).then(function (res) { renderJob(res.job); startPolling(); });
    });
    document.getElementById('coresync_cancel').addEventListener('click', function () {
        post(urlCancel).then(function () { startPolling(); });
    });

    {if $last_job && $last_job->status == 'running'}startPolling();{/if}
})();
</script>
