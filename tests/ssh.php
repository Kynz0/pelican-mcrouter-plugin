<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
$root = dirname(__DIR__);
$panel = $root . '/.dev/panel';
chdir($panel);
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || realpath(base_path()) !== realpath($panel)) { throw new RuntimeException('Refusing non-disposable Panel.'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\DB::beginTransaction();
try {
    config(['mc-router.preview_only' => false]);
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(App\Models\Role::getRootAdmin());
    Illuminate\Support\Facades\Auth::login($admin);
    Illuminate\Support\Facades\DB::table('mc_router_settings')->where('id', 1)->delete();
    $settings = app(Kynzo\McRouter\Services\Settings::class);
    $settings->save([
        'mode' => 'ssh', 'ssh_host' => '127.0.0.1', 'ssh_port' => (int) getenv('TEST_SSH_PORT'),
        'ssh_user' => getenv('TEST_SSH_USER'), 'ssh_api_port' => (int) getenv('TEST_API_PORT'),
        'ssh_key_path' => getenv('TEST_SSH_KEY_PATH'), 'ssh_known_hosts_path' => getenv('TEST_SSH_KNOWN_HOSTS'),
    ]);
    $client = app(Kynzo\McRouter\Services\RouterClient::class);
    $client->apply('ssh-test.mc.example.org', '192.0.2.10:25565');
    if (($client->routes()['ssh-test.mc.example.org']['backend'] ?? null) !== '192.0.2.10:25565') { throw new RuntimeException('SSH API forwarding failed.'); }
    // Force a response large enough for Go's chunked HTTP framing.
    for ($i = 0; $i < 30; $i++) { $client->apply('ssh-framing-' . $i . '.mc.example.org', '192.0.2.10:25565'); }
    if (count($client->routes()) !== 31) { throw new RuntimeException('Large SSH route readback failed.'); }
    $client->remove('ssh-test.mc.example.org');
    if (isset($client->routes()['ssh-test.mc.example.org'])) { throw new RuntimeException('SSH route removal failed.'); }
    // Empty host-key trust must fail closed; no TOFU/accept-new fallback.
    $settings->save(['ssh_known_hosts_path' => getenv('TEST_SSH_EMPTY_KNOWN_HOSTS')]);
    try { $client->routes(); }
    catch (Kynzo\McRouter\Exceptions\RouterException) {
        echo "OK: real restricted SSH -W transport apply/read/delete, remote shell denied, pinned host-key rejection\n";
        return;
    }
    throw new RuntimeException('SSH accepted an untrusted host key.');
} finally { Illuminate\Support\Facades\DB::rollBack(); }
