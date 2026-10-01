<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
return [
    'preview_only' => env('MC_ROUTER_PREVIEW_ONLY', false),
    // Plain-HTTP router transport is an opt-in escape hatch for setups where SSH
    // is impossible. The mc-router API has no authentication; anyone who can
    // reach the API port can rewrite all routes.
    'allow_insecure_http' => env('MC_ROUTER_ALLOW_INSECURE_HTTP', false),
    // TEMPORARY stopgap (see Filament/ConsoleAddressHook.php): hides the port
    // suffix in the console Address block for routed servers. Upstream
    // pelican/panel#2511 adds a native per-allocation "Show port" option that
    // ships after beta38; when that lands, remove the hook and this flag.
    'hide_console_port' => env('MC_ROUTER_HIDE_CONSOLE_PORT', true),
    'suffix' => env('MC_ROUTER_SUFFIX', 'mc.example.org'),
    'reserved' => ['admin', 'panel', 'router', 'www'],
    // JSON object: node ID -> approved allocation IDs. Empty by default (fail closed).
    'pools' => json_decode(env('MC_ROUTER_POOLS', '{}'), true, 512, JSON_THROW_ON_ERROR),
];
