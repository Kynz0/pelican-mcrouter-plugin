<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kynzo\McRouter\Models\RouteRecord;
use Kynzo\McRouter\Services\RouteManager;
use Kynzo\McRouter\Services\Settings;

$root = dirname(__DIR__);
$panel = $root . '/.dev/panel';
chdir($panel);
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || realpath(base_path()) !== realpath($panel)) { throw new RuntimeException('Refusing non-disposable Panel.'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
$count = 0;
function ensure(bool $condition, string $message): void {
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++;
}
DB::beginTransaction();
try {
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(App\Models\Role::getRootAdmin());
    Illuminate\Support\Facades\Auth::login($admin);
    $node = App\Models\Node::factory()->create();
    $egg = App\Models\Egg::factory()->create();
    $egg->features = [Kynzo\McRouter\Filament\RoutingFields::EGG_FEATURE];
    $egg->save();
    $allocations = [];
    for ($port = 25565; $port < 25570; $port++) {
        $allocations[] = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => $port]);
    }
    DB::table('mc_router_settings')->where('id', 1)->delete();
    config(['mc-router.preview_only' => false]);
    app(Settings::class)->save(['mode' => 'local', 'local_port' => 65530, 'suffix' => 'mc.example.org', 'pools' => [$node->id => array_map(fn ($a) => $a->id, $allocations)]]);
    $currentServer = null;
    $failCreation = false;
    $wingsCreates = 0;
    $daemon = Mockery::mock(App\Repositories\Daemon\DaemonServerRepository::class);
    $daemon->shouldReceive('setServer')->andReturnUsing(function ($server) use (&$currentServer, $daemon) { $currentServer = $server; return $daemon; });
    $daemon->shouldReceive('create')->andReturnUsing(function () use (&$currentServer, &$failCreation, &$wingsCreates) {
        $wingsCreates++;
        ensure(DB::transactionLevel() === 1, 'Plugin held an extra DB transaction over Wings.');
        $route = RouteRecord::where('server_uuid', $currentServer->uuid)->first();
        if ($route) { ensure(!$route->provisioned && !$route->applied, 'Route was enabled before Wings creation succeeded.'); }
        if ($failCreation) { throw new Illuminate\Http\Client\ConnectionException('Disposable Wings failure'); }
    });
    $daemon->shouldReceive('delete')->andReturnNull();
    $daemon->shouldReceive('sync')->andReturnUsing(fn () => ensure(DB::transactionLevel() === 1, 'Plugin held DB transaction over Wings sync.'));
    $app->instance(App\Repositories\Daemon\DaemonServerRepository::class, $daemon);
    $data = ['name' => 'Creation test', 'node_id' => $node->id, 'owner_id' => $admin->id, 'egg_id' => $egg->id,
        'memory' => 256, 'swap' => 0, 'disk' => 256, 'cpu' => 0, 'io' => 500, 'environment' => [],
        'allocation_limit' => 4, 'database_limit' => 3, 'backup_limit' => 2];
    $service = app(App\Services\Servers\ServerCreationService::class);
    ensure($service instanceof Kynzo\McRouter\Services\RoutedCreationService, 'Creation decorator not installed.');
    $normal = $service->handle($data + ['allocation_id' => $allocations[0]->id, 'mc_router_enabled' => false]);
    ensure(!RouteRecord::where('server_uuid', $normal->uuid)->exists(), 'Disabled flow created a route.');
    $routed = $service->handle($data + ['allocation_id' => $allocations[1]->id, 'mc_router_enabled' => true, 'mc_router_slug' => 'created', 'mc_router_allocation_id' => $allocations[1]->id]);
    $route = RouteRecord::where('server_uuid', $routed->uuid)->firstOrFail();
    ensure($route->provisioned && $route->status === 'pending', 'Successful creation did not persist pending desired state.');
    ensure($allocations[1]->fresh()->server_id === $routed->id, 'Creation did not assign chosen primary.');
    $beforeCount = App\Models\Server::count();
    $beforeWings = $wingsCreates;
    try {
        $service->handle($data + ['allocation_id' => $allocations[3]->id, 'mc_router_enabled' => true, 'mc_router_slug' => 'CREATED', 'mc_router_allocation_id' => $allocations[3]->id]);
        throw new RuntimeException('Duplicate hostname accepted.');
    } catch (ValidationException) { ensure(true, 'Duplicate rejected'); }
    ensure(App\Models\Server::count() === $beforeCount && $wingsCreates === $beforeWings, 'Duplicate reservation did not roll back core creation.');
    ensure($allocations[3]->fresh()->server_id === null, 'Duplicate creation consumed allocation.');
    $failCreation = true;
    try {
        $service->handle($data + ['allocation_id' => $allocations[2]->id, 'mc_router_enabled' => true, 'mc_router_slug' => 'failed', 'mc_router_allocation_id' => $allocations[2]->id]);
        throw new RuntimeException('Expected Wings failure.');
    } catch (Illuminate\Http\Client\ConnectionException) { ensure(true, 'Wings failure handled'); }
    ensure(!RouteRecord::where('hostname', 'failed.mc.example.org')->exists(), 'Failed creation orphaned route/slug.');
    ensure(!DB::table('mc_router_hostnames')->where('hostname', 'failed.mc.example.org')->exists(), 'Failed creation orphaned reservation.');
    ensure($allocations[2]->fresh()->server_id === null, 'Failed creation kept primary assigned.');
    ensure(app(Kynzo\McRouter\Services\CreationCapture::class)->desired === null, 'Creation context leaked.');
    $failCreation = false;
    $retried = $service->handle($data + ['allocation_id' => $allocations[2]->id, 'mc_router_enabled' => true, 'mc_router_slug' => 'failed', 'mc_router_allocation_id' => $allocations[2]->id]);
    ensure(RouteRecord::where('server_uuid', $retried->uuid)->exists(), 'Failed slug could not be reused.');
    try {
        $service->handle($data + ['allocation_id' => $allocations[3]->id, 'mc_router_enabled' => true, 'mc_router_slug' => 'mismatch', 'mc_router_allocation_id' => $allocations[4]->id]);
        throw new RuntimeException('Mismatched primary accepted.');
    } catch (ValidationException) { ensure(true, 'Mismatch rejected'); }
    app(RouteManager::class)->save($normal, true, 'existing', $allocations[4]->id, $allocations[4]->id);
    ensure($normal->refresh()->allocation_id === $allocations[4]->id, 'Confirmed existing primary change failed.');
    ensure($allocations[4]->fresh()->server_id === $normal->id, 'Replacement allocation was not assigned.');
    ensure($allocations[0]->fresh()->server_id === $normal->id, 'Old primary/additional allocation was silently released.');
    ensure($normal->allocation_limit === 4 && $normal->database_limit === 3 && $normal->backup_limit === 2, 'Primary change reset feature limits.');
    echo "OK: $count creation/lifecycle checks using real Panel services, mocked Wings and queued sync\n";
} finally { DB::rollBack(); Mockery::close(); }
