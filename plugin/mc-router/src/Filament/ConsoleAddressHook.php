<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Filament;

/**
 * TEMPORARY stopgap (2026-10-01, user-approved option b): hide the `:port`
 * suffix on the server console "Address" block for routed servers only.
 *
 * Why it exists: core `Allocation::address` always appends the port and the
 * console widget (`App\Filament\Server\Widgets\ServerOverview`) is core-owned;
 * Pelican beta38 has no way to hide it from a plugin. Upstream PR
 * pelican/panel#2511 ("Add allocation port visibility option") was merged into
 * main on 2026-08-22 — AFTER the v1.0.0-beta38 release (2026-08-16, the newest
 * published release) — so a native per-allocation "Show port" toggle will ship
 * in the next Panel release. When the user's Panel is upgraded past that,
 * REMOVE THIS ENTIRE FILE plus the `registerConsoleAddressHook()` call in
 * `Providers/McRouterProvider::boot()` and let the native option take over
 * (the plugin can then set `show_port = false` on routed allocations).
 *
 * Fragility, documented: it matches the beta38 markup `.fi-small-stat-block`
 * and rewrites the value span's text. It self-heals on Livewire morphs via a
 * synchronous MutationObserver (no debounce: the widget polls every second and
 * each morph restores the port; running `clean()` in the observer microtask
 * fixes the text before paint, so the user never sees the port flash). If
 * Pelican changes the markup the hook silently does nothing (fails open, never
 * breaks the page). The block's copy text is baked into the inline click
 * handler by core (`@js($value)` in
 * `filament/components/server-small-data-block.blade.php`), so DOM edits can't
 * change it; instead the hook wraps `navigator.clipboard.writeText` once and
 * strips the port from any copy of a routed `hostname:port` value.
 *
 * Disable instantly without code changes: MC_ROUTER_HIDE_CONSOLE_PORT=false.
 */
final class ConsoleAddressHook
{
    /** @param list<string> $hostnames routed join hostnames */
    public static function render(array $hostnames): string
    {
        $routed = json_encode(array_values(array_filter(array_unique($hostnames))), JSON_UNESCAPED_SLASHES);
        if ($routed === false || $routed === '[]') { return ''; }

        $js = <<<'JS'
(function () {
    if (window.__mcRouterConsoleAddressHook) { return; }
    window.__mcRouterConsoleAddressHook = true;
    var routed = ROUTED_JSON;
    function routedHostOf(text) {
        text = (text || '').trim();
        for (var i = 0; i < routed.length; i++) {
            var host = routed[i];
            if (text.slice(0, host.length + 1) === host + ':') { return host; }
        }
        return null;
    }
    function clean() {
        var blocks = document.querySelectorAll('.fi-small-stat-block');
        for (var b = 0; b < blocks.length; b++) {
            var spans = blocks[b].querySelectorAll('span');
            var value = spans[spans.length - 1];
            if (!value) { continue; }
            var host = routedHostOf(value.textContent);
            if (host !== null && value.textContent !== host) { value.textContent = host; }
        }
    }
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        var write = navigator.clipboard.writeText.bind(navigator.clipboard);
        navigator.clipboard.writeText = function (text) {
            var host = routedHostOf(text);
            return write(host === null ? text : host);
        };
    }
    clean();
    new MutationObserver(clean).observe(document.body, { childList: true, subtree: true });
})();
JS;

        return '<script data-mc-router="console-address-hook">' . str_replace('ROUTED_JSON', $routed, $js) . '</script>';
    }

    /** Render hook entry point: emits the script on every page when routed servers exist. */
    public static function hook(): string
    {
        if (!config('mc-router.hide_console_port', true)) { return ''; }
        try {
            $hostnames = cache()->remember('mc-router:routed-hostnames', 30,
                fn () => \Kynzo\McRouter\Models\RouteRecord::query()->where('enabled', true)->pluck('hostname')->all());
        } catch (\Throwable) {
            return '';
        }

        return self::render($hostnames);
    }
}
