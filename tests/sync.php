<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
use Kynzo\McRouter\Models\RouteRecord;
use Kynzo\McRouter\Services\RouteManager;
use Kynzo\McRouter\Services\RouterClient;
use Kynzo\McRouter\Services\Settings;
use Kynzo\McRouter\Services\Synchronizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__);
$panel = $root . '/.dev/panel';
chdir($panel);
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || realpath(base_path()) !== realpath($panel)) { throw new RuntimeException('Refusing non-disposable Panel.'); }
$port = (int) getenv('MC_ROUTER_TEST_API_PORT');
if ($port < 1 || $port > 65535) { throw new RuntimeException('Missing disposable router port.'); }
Http::preventStrayRequests();
Http::allowStrayRequests(['http://127.0.0.1:' . $port . '/*']);
Illuminate\Support\Facades\Queue::fake();
Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function invalid(Closure $call, string $label = '?'): void
{
    try { $call(); } catch (ValidationException) { check(true, 'Expected rejection'); return; }
    throw new RuntimeException('Expected validation rejection: ' . $label);
}
DB::beginTransaction();
try {
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(App\Models\Role::getRootAdmin());
    Illuminate\Support\Facades\Auth::login($admin);
    $node = App\Models\Node::factory()->create();
    $primary = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => 25565]);
    $free = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => 25566]);
    $another = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => 25567]);
    $server = App\Models\Server::factory()->create(['owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $primary->id]);
    $primary->update(['server_id' => $server->id]);
    config(['mc-router.preview_only' => false]);
    DB::table('mc_router_settings')->where('id', 1)->delete();
    $settings = app(Settings::class);
    $settings->save(['mode' => 'local', 'suffix' => 'mc.example.org', 'local_port' => $port, 'pools' => [$node->id => [$primary->id, $free->id, $another->id]]]);
    $manager = app(RouteManager::class);
    $sync = app(Synchronizer::class);
    $client = app(RouterClient::class);
    $manager->save($server, true, 'test', $primary->id, 0);
    $route = RouteRecord::where('server_uuid', $server->uuid)->firstOrFail();
    check($route->status === 'pending', 'Save must not claim active before apply.');
    check($sync->sync($route->id), 'Initial apply failed.');
    check($route->fresh()->status === 'active', 'Active state requires readback.');
    check($client->routes()['test.mc.example.org']['backend'] === '192.0.2.10:25565', 'Wrong backend.');
    check($client->routes()['manual.example.org']['backend'] === '192.0.2.99:25565', 'Foreign route changed.');
    invalid(fn () => $settings->save(['suffix' => 'other.example.org']), 'connection change with live claims');
    $rollback = require $root . '/plugin/mc-router/database/migrations/2026_09_30_000002_add_mc_router_attempts.php';
    try { $rollback->down(); throw new RuntimeException('Live ownership migration rollback was allowed.'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'before uninstalling'), 'Wrong uninstall guard result.'); }
    check(Illuminate\Support\Facades\Schema::hasColumn('mc_router_routes', 'attempted'), 'Uninstall guard partially removed schema.');
    invalid(fn () => $manager->save($server, true, 'test', $free->id, 0), 'unconfirmed primary change');
    check($server->refresh()->allocation_id === $primary->id, 'Unconfirmed change mutated primary.');
    $manager->save($server, true, 'renamed', $primary->id, 0);
    check(DB::table('mc_router_hostnames')->where('hostname', 'test.mc.example.org')->exists(), 'Old name must remain reserved until retired.');
    check($sync->sync($route->id), 'Rename failed.');
    check(!isset($client->routes()['test.mc.example.org']), 'Old renamed route remains.');
    check(!DB::table('mc_router_hostnames')->where('hostname', 'test.mc.example.org')->exists(), 'Old slug was not released.');
    $outage = Mockery::mock();
    $outage->shouldReceive('routes')->andThrow(new Kynzo\McRouter\Exceptions\RouterException('Disposable router outage.'));
    $app->instance(RouterClient::class, $outage);
    check(!$sync->sync($route->id) && $route->fresh()->status === 'error', 'Router outage was not a visible retryable error.');
    $app->instance(RouterClient::class, $client);
    check($sync->sync($route->id), 'Router outage did not recover.');
    $client->remove('renamed.mc.example.org');
    check($sync->sync($route->id), 'Missing route drift did not heal.');
    check(isset($client->routes()['renamed.mc.example.org']), 'Desired route was not restored.');
    // Simulate a core primary-allocation change (no Wings call in this fixture).
    $free->update(['server_id' => $server->id]);
    $server->update(['allocation_id' => $free->id]);
    check($route->fresh()->desired_allocation_id === $free->id, 'Core allocation event was missed.');
    $lostResponse = Mockery::mock();
    $lostResponse->shouldReceive('routes')->andReturnUsing(fn () => $client->routes());
    $lostResponse->shouldReceive('apply')->andReturnUsing(function ($hostname, $backend) use ($client) {
        $client->apply($hostname, $backend);
        throw new Kynzo\McRouter\Exceptions\RouterException('Disposable lost response after successful POST.');
    });
    $app->instance(RouterClient::class, $lostResponse);
    check(!$sync->sync($route->id), 'Lost response should not claim active.');
    check(($route->fresh()->attempted ?? [])['renamed.mc.example.org'] === '192.0.2.10:25566', 'Uncertain write intent was lost.');
    $app->instance(RouterClient::class, $client);
    check($sync->sync($route->id), 'Allocation follow/lost-response retry failed.');
    check($client->routes()['renamed.mc.example.org']['backend'] === '192.0.2.10:25566', 'Route did not follow primary.');
    check($free->refresh()->ip_alias === 'renamed.mc.example.org', 'Primary allocation display alias was not applied.');
    // An uncertain retry may observe either the previous or attempted backend.
    $route->refresh()->update(['applied' => ['renamed.mc.example.org' => '192.0.2.10:25565'], 'attempted' => ['renamed.mc.example.org' => '192.0.2.10:25566']]);
    check($sync->sync($route->id), 'Post-timeout readback recovery failed.');
    check($route->fresh()->attempted === [], 'Uncertain write was not resolved.');
    $client->apply('renamed.mc.example.org', '192.0.2.10:25565');
    $route->refresh()->update(['applied' => ['renamed.mc.example.org' => '192.0.2.10:25565'], 'attempted' => ['renamed.mc.example.org' => '192.0.2.10:25566']]);
    check($sync->sync($route->id), 'Retry after pre-write failure lost previous ownership.');
    // Out-of-band ownership conflict must be preserved, including during opt-out.
    $client->apply('renamed.mc.example.org', '192.0.2.99:29999');
    check(!$sync->sync($route->id), 'External overwrite should be a conflict.');
    check($route->fresh()->status === 'error', 'Conflict was not visible.');
    $manager->save($server, false, '', 0, 0);
    check(!$sync->sync($route->id), 'Opt-out deleted an externally changed route.');
    check($client->routes()['renamed.mc.example.org']['backend'] === '192.0.2.99:29999', 'Foreign overwrite was removed.');
    $client->apply('renamed.mc.example.org', '192.0.2.10:25566');
    check($sync->sync($route->id), 'Owned route cleanup failed.');
    check(!isset($client->routes()['renamed.mc.example.org']), 'Opt-out route remains.');
    check(!DB::table('mc_router_hostnames')->where('route_id', $route->id)->exists(), 'Opt-out reservation remains.');
    check($free->refresh()->ip_alias === null, 'Opt-out did not clear the display alias.');
    $client->apply('collision.mc.example.org', '192.0.2.10:25566');
    $manager->save($server, true, 'collision', $free->id, 0);
    check(!$sync->sync($route->id), 'Same-backend foreign route was silently adopted.');
    $manager->save($server, false, '', 0, 0);
    check($sync->sync($route->id), 'Unapplied collision reservation was not released.');
    check(isset($client->routes()['collision.mc.example.org']), 'Foreign colliding route was deleted.');
    $client->remove('collision.mc.example.org');
    $manager->save($server, true, 'final', $free->id, 0);
    check($sync->sync($route->id), 'Re-enable failed.');
    check($free->refresh()->ip_alias === 'final.mc.example.org', 'Primary allocation display alias was not applied.');
    $free->update(['ip_alias' => 'legacy.example.org']);
    check($sync->sync($route->id), 'Alias protection sync failed.');
    check($free->refresh()->ip_alias === 'legacy.example.org', 'Pre-existing custom alias was overwritten.');
    $free->update(['ip_alias' => null]);
    check($sync->sync($route->id), 'Alias adoption sync failed.');
    check($free->refresh()->ip_alias === 'final.mc.example.org', 'Alias was not adopted after custom alias cleared.');
    $otherServer = App\Models\Server::factory()->create(['owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $another->id]);
    $another->update(['server_id' => $otherServer->id]);
    invalid(fn () => $manager->save($otherServer, true, 'FINAL', $another->id, 0), 'case-insensitive hostname collision');
    $server->delete();
    $free->update(['server_id' => null]);
    check(!collect(Kynzo\McRouter\Filament\RoutingFields::allocations())->contains('id', $free->id), 'Unremoved old route backend was offered for reuse.');
    invalid(fn () => App\Models\Server::factory()->create(['owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $free->id]), 'quarantined backend reuse');
    check($sync->sync($route->id), 'Deletion cleanup failed.');
    check(RouteRecord::find($route->id) === null, 'Deleted-server ledger remains.');
    check(!isset($client->routes()['final.mc.example.org']), 'Deleted-server route remains.');
    check(collect(Kynzo\McRouter\Filament\RoutingFields::allocations())->contains('id', $free->id), 'Cleaned backend did not become available again.');
    check(count($client->routes()) === 1 && isset($client->routes()['manual.example.org']), 'Unrelated route preservation failed.');
    echo "OK: $checks real-router lifecycle checks (apply/readback, rename, core allocation follow, outage/lost-response recovery, drift repair, uncertain retries, conflicts, opt-out, re-enable, uniqueness, quarantined reuse, guarded uninstall, deletion)\n";
} finally {
    DB::rollBack();
    Mockery::close();
}
