<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Seed only the disposable Panel with documentation-range backends and a test admin.
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
Illuminate\Support\Facades\Notification::fake();
config(['panel.email.send_account_created_notification' => false]);
$user = App\Models\User::where('email', 'preview@example.test')->first();
$newUser = !$user;
if (!$user) {
    $user = app(App\Services\Users\UserCreationService::class)->handle([
        'email' => 'preview@example.test', 'username' => 'preview', 'root_admin' => true,
        'password' => Illuminate\Support\Str::random(64),
    ]);
}
$user->assignRole(App\Models\Role::getRootAdmin());
$pools = [];
foreach (['Preview HDD' => '192.0.2.10', 'Preview SSD' => '192.0.2.20'] as $name => $ip) {
    $node = App\Models\Node::where('name', $name)->first()
        ?? App\Models\Node::factory()->create(['name' => $name, 'fqdn' => strtolower(str_replace(' ', '-', $name)) . '.example.test']);
    $allocations = [];
    foreach ([25565, 25566, 25567, 26100] as $port) {
        $allocations[$port] = App\Models\Allocation::firstOrCreate(['node_id' => $node->id, 'ip' => $ip, 'port' => $port]);
    }
    $pools[$node->id] = array_values(array_map(fn ($a) => $a->id, array_slice($allocations, 0, 3, true)));
    if ($name === 'Preview HDD') {
        foreach (['Eligible existing server' => 25565, 'Ineligible existing server' => 26100] as $serverName => $port) {
            $server = App\Models\Server::where('name', $serverName)->first()
                ?? App\Models\Server::factory()->create(['name' => $serverName, 'owner_id' => $user->id, 'node_id' => $node->id, 'allocation_id' => $allocations[$port]->id]);
            $allocations[$port]->update(['server_id' => $server->id]);
        }
    }
}
$env = file_get_contents($panel . '/.env');
foreach (['MC_ROUTER_SUFFIX' => 'mc.example.org', 'MC_ROUTER_POOLS' => "'" . json_encode($pools, JSON_THROW_ON_ERROR) . "'", 'APP_INSTALLED' => 'true', 'MC_ROUTER_PREVIEW_ONLY' => 'true', 'TRUSTED_PROXIES' => '127.0.0.1,::1'] as $key => $value) {
    $env = preg_replace('/^' . $key . '=.*\R?/m', '', $env);
    $env = rtrim($env) . "\n$key=$value\n";
}
file_put_contents($panel . '/.env', $env);
// Reset plugin settings to a known ungated demo state unless --keep-settings.
app(Kynzo\McRouter\Services\EggFeatureSync::class)->sync([]);
Illuminate\Support\Facades\DB::table('mc_router_settings')->updateOrInsert(['id' => 1],
    ['values' => json_encode(['mode' => 'dry-run', 'suffix' => 'mc.example.org', 'eggs' => [], 'pools' => $pools], JSON_THROW_ON_ERROR)]);
if (!$newUser && !in_array('--reset-access', $argv, true)) {
    echo "Disposable preview fixtures ready; plugin settings reset, existing test admin password preserved.\n";
    return;
}
$origin = trim(file_get_contents($root . '/.dev/public-url'));
config(['app.url' => $origin]);
Illuminate\Support\Facades\URL::useOrigin($origin);
Illuminate\Support\Facades\URL::forceHttps(true);
$authPanel = Filament\Facades\Filament::getPanel('app');
$token = Illuminate\Support\Facades\Password::broker($authPanel->getAuthPasswordBroker())->createToken($user);
$setupUrl = $authPanel->getResetPasswordUrl($token, $user);
echo "Test admin: preview@example.test\nPassword setup link: $setupUrl\n";
