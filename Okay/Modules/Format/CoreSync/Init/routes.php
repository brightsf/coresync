<?php

namespace Okay\Modules\Format\CoreSync;

/**
 * Фронтовый (без сессии/CSRF) роут-приёмник HMAC-пинка ядра (SAT-RT §0.2, контракт SAT-B «Пинок»).
 * Автоподхватывается Module::getRoutes() — регистрация в Init.php не нужна. always_active: приёмник
 * должен отвечать и при выключенной витрине (webhook сервер-сервер, не зависит от site_work).
 */
return [
    'Format.CoreSync.ping' => [
        'slug'          => 'coresync/ping',
        'always_active' => true,
        'params'        => [
            'controller' => __NAMESPACE__ . '\Controllers\PingController',
            'method'     => 'ping',
        ],
    ],
];
