<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
$root = dirname(__DIR__);
$panel = $root . '/.dev/panel';
chdir($panel);
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || realpath(base_path()) !== realpath($panel)) { throw new RuntimeException('Refusing non-disposable Panel.'); }
$port = (int) getenv('MC_ROUTER_TEST_API_PORT');
if ($port < 1 || $port > 65535) { throw new RuntimeException('Missing disposable router port.'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::allowStrayRequests(['http://127.0.0.1:' . $port . '/*']);
Illuminate\Support\Facades\DB::beginTransaction();
try {
    config(['mc-router.preview_only' => false]);
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(App\Models\Role::getRootAdmin());
    Illuminate\Support\Facades\Auth::login($admin);
    Illuminate\Support\Facades\DB::table('mc_router_settings')->where('id', 1)->delete();
    $settings = app(Kynzo\McRouter\Services\Settings::class);
    $client = app(Kynzo\McRouter\Services\RouterClient::class);
    $invalid = function (array $data, string $label): void {
        try { app(Kynzo\McRouter\Services\Settings::class)->save($data); }
        catch (Illuminate\Validation\ValidationException) { echo "OK: rejects $label\n"; return; }
        throw new RuntimeException("Expected rejection: $label");
    };
    // The plain-HTTP transport is an explicit opt-in; without it, saving must fail closed.
    $invalid(['mode' => 'http', 'http_host' => '127.0.0.1', 'http_port' => $port], 'unopted plain HTTP mode');
    config(['mc-router.allow_insecure_http' => true]);
    $invalid(['mode' => 'http', 'http_host' => 'router host', 'http_port' => $port], 'HTTP host with whitespace');
    $invalid(['mode' => 'http', 'http_host' => 'http://127.0.0.1/path', 'http_port' => $port], 'scheme/path in HTTP host');
    $invalid(['mode' => 'http', 'http_host' => '127.0.0.1', 'http_port' => 0], 'HTTP port out of range');
    $settings->save(['mode' => 'http', 'http_host' => '127.0.0.1', 'http_port' => $port]);
    $client->apply('http-test.mc.example.org', '192.0.2.10:25565');
    if (($client->routes()['http-test.mc.example.org']['backend'] ?? null) !== '192.0.2.10:25565') { throw new RuntimeException('Plain HTTP apply failed.'); }
    if (($client->routes()['manual.example.org']['backend'] ?? null) !== '192.0.2.99:25565') { throw new RuntimeException('Foreign route changed over HTTP.'); }
    $client->remove('http-test.mc.example.org');
    if (isset($client->routes()['http-test.mc.example.org'])) { throw new RuntimeException('Plain HTTP route removal failed.'); }
    // A dead endpoint must produce a visible RouterException, not an unhandled error.
    $settings->save(['mode' => 'http', 'http_host' => '127.0.0.1', 'http_port' => 1]);
    try { $client->routes(); }
    catch (Kynzo\McRouter\Exceptions\RouterException) { }
    $settings->save(['mode' => 'http', 'http_host' => '127.0.0.1', 'http_port' => $port]);
    $client->routes();
    echo "OK: opt-in plain HTTP transport apply/read/delete, foreign preservation, opt-out gating, dead-endpoint error\n";
} finally { Illuminate\Support\Facades\DB::rollBack(); }
