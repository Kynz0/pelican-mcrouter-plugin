<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
$root = dirname(__DIR__);
$panel = $root . '/.dev/panel';
chdir($panel);
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || realpath(base_path()) !== realpath($panel)) {
    throw new RuntimeException('Refusing non-disposable Panel.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
$manifest = plugin_path('mc-router', 'plugin.json');
$installedManifest = file_get_contents($manifest);
try {
    $version = json_decode(file_get_contents($root . '/plugin/mc-router/plugin.json'), true)['version'];
    $file = new Illuminate\Http\UploadedFile($root . '/dist/mc-router-' . $version . '.zip', 'renamed-prototype.zip', 'application/zip', test: true);
    $id = app(App\Services\Helpers\PluginService::class)->downloadPluginFromFile($file, 'mc-router');
    if ($id !== 'mc-router' || !file_exists(plugin_path($id, 'src/McRouterPlugin.php')) || !file_exists(plugin_path($id, 'LICENSE'))) {
        throw new RuntimeException('Archive did not import as expected.');
    }
    echo "OK: actual beta38 ZIP importer accepts renamed archive and extracts plugin/manifest/license\n";
} finally {
    // Import strips runtime status; keep the local harness installed for subsequent runs.
    file_put_contents($manifest, $installedManifest);
}
