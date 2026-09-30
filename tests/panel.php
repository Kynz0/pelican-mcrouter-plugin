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
Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(App\Models\Role::getRootAdmin());
    Illuminate\Support\Facades\Auth::login($admin);
    $node = App\Models\Node::factory()->create();
    $free = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => 25566]);
    $other = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => 25567]);
    Illuminate\Support\Facades\DB::table('mc_router_settings')->where('id', 1)->delete();
    config(['mc-router.pools' => [$node->id => [$free->id, $other->id]], 'mc-router.preview_only' => true]);
    // Fail-closed default: without the egg feature the step is not offered at all.
    $mcEgg = App\Models\Egg::factory()->create(['name' => 'Fabric', 'tags' => ['minecraft']]);
    $mcEgg->features = [Kynzo\McRouter\Filament\RoutingFields::EGG_FEATURE];
    $mcEgg->save();
    $wizard = Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\CreateServer::class)
        ->assertDontSee('MC Router')
        ->set('data.egg_id', $mcEgg->id)
        ->assertSee('MC Router')
        ->assertSet('data.mc_router_enabled', false)
        ->set('data.allocation_id', $free->id)
        ->set('data.mc_router_enabled', true);
    $wizard->assertSet('data.allocation_id', $free->id)
        ->assertSet('data.mc_router_allocation_id', $free->id)
        ->assertSee('Primary allocation')
        ->assertDontSee('Optional allocation override')
        ->set('data.name', 'My Minecraft Server')
        ->assertSet('data.mc_router_slug', 'my-minecraft-server')
        ->set('data.mc_router_slug', 'custom')
        ->set('data.name', 'Another name')
        ->assertSet('data.mc_router_slug', 'custom')
        ->set('data.mc_router_allocation_id', $other->id);
    $wizard->assertSet('data.allocation_id', $other->id)
        ->set('data.allocation_id', $free->id)->assertSet('data.mc_router_allocation_id', $free->id)
        ->set('data.mc_router_enabled', false);
    $wizard->assertSet('data.allocation_id', $free->id)
        ->set('data.mc_router_enabled', true)->set('data.mc_router_allocation_id', $other->id);
    $state = ['data' => $wizard->instance()->data];
    $wizard->instance()->form->dehydrateState($state);
    if (($state['data']['mc_router_slug'] ?? null) !== 'custom' || isset($state['data']['mc_router_normal_node'])) {
        throw new RuntimeException('Plugin payload dehydration failed.');
    }
    $server = App\Models\Server::factory()->create(['node_id' => $node->id, 'allocation_id' => $other->id, 'egg_id' => $mcEgg->id]);
    $other->update(['server_id' => $server->id]);
    $edit = Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $server->id])
        ->assertSee('MC Router');
    $edit->set('data.mc_router_enabled', true)
        ->assertSet('data.mc_router_allocation_id', $other->id)
        ->assertDontSee('I would allow');
    $edit->set('data.mc_router_allocation_id', $free->id)->assertSee('Confirm this primary allocation change');
    $schemaAction = function ($page, string $name) {
        foreach ($page->instance()->form->getFlatComponents(true, true, true) as $component) {
            if ($component instanceof Filament\Actions\Action && $component->getName() === $name) {
                return Filament\Actions\Testing\TestAction::make($name)->schemaComponent($component->getSchemaComponent()->getKey(), schema: '');
            }
        }
        throw new RuntimeException('Missing schema action: ' . $name);
    };
    $edit->callAction($schemaAction($edit, 'mc_router_confirm_change'))
        ->assertSet('data.mc_router_confirmed_allocation', $free->id);
    // Routing is applied by the page's own "Save changes" button through the pinned call hook.
    $edit->call('save');
    $savedStatus = Kynzo\McRouter\Models\RouteRecord::where('server_uuid', $server->uuid)->value('status');
    if ($savedStatus !== 'dry-run') {
        throw new RuntimeException('The actual edit-tab save action did not persist dry-run settings (status: ' . var_export($savedStatus, true) . ').');
    }
    if ($server->refresh()->allocation_id !== $other->id || $free->refresh()->server_id !== null) { throw new RuntimeException('Unsaved preview mutated an allocation.'); }
    config(['mc-router.pools' => [$node->id => [$free->id]]]);
    $legacy = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => 26100]);
    $legacyServer = App\Models\Server::factory()->create(['node_id' => $node->id, 'allocation_id' => $legacy->id, 'egg_id' => $mcEgg->id]);
    $legacy->update(['server_id' => $legacyServer->id]);
    $replacement = Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $legacyServer->id])
        ->set('data.mc_router_enabled', true)
        ->assertSet('data.mc_router_allocation_id', null)
        ->assertSee('Automatically select eligible replacement');
    $replacement->callAction($schemaAction($replacement, 'mc_router_select_replacement'))
        ->assertSet('data.mc_router_allocation_id', $free->id)
        ->assertSet('data.mc_router_confirmed_allocation', $free->id);
    $plugin = new Kynzo\McRouter\McRouterPlugin();
    $plugin->saveSettings(['mode' => 'dry-run', 'suffix' => 'play.example.org', 'pool_rows' => [['node_id' => $node->id, 'allocation_ids' => [$free->id]]]]);
    if (app(Kynzo\McRouter\Services\Settings::class)->get()['suffix'] !== 'play.example.org') { throw new RuntimeException('Plugin settings were not persisted.'); }
    $settingsPage = Livewire\Livewire::test(App\Filament\Admin\Resources\Plugins\Pages\ListPlugins::class)
        ->mountAction(Filament\Actions\Testing\TestAction::make('exclude_settings')->table('mc-router'));
    $settingsPage->assertSchemaStateSet(['mode' => 'dry-run', 'suffix' => 'play.example.org'])
        ->set('mountedActions.0.data.suffix', 'saved.example.org')->assertSet('mountedActions.0.data.suffix', 'saved.example.org')
        ->callMountedAction()->assertHasNoFormErrors();
    if (app(Kynzo\McRouter\Services\Settings::class)->get()['suffix'] !== 'saved.example.org') { throw new RuntimeException('Native settings action did not save.'); }
    $settingsPage->mountAction(Filament\Actions\Testing\TestAction::make('exclude_settings')->table('mc-router'))
        ->set('mountedActions.0.data.suffix', 'bad..example.org')->callMountedAction()->assertHasFormErrors(['suffix']);
    if (app(Kynzo\McRouter\Services\Settings::class)->get()['suffix'] !== 'saved.example.org') { throw new RuntimeException('Invalid settings were saved.'); }
    $values = app(Kynzo\McRouter\Services\Settings::class)->get();
    $otherEgg = App\Models\Egg::factory()->create(['name' => 'Rust']);
    $plugin->saveSettings([...$values, 'eggs' => [$mcEgg->id],
        'pool_rows' => collect($values['pools'])->map(fn ($ids, $nodeId) => ['node_id' => (int) $nodeId, 'allocation_ids' => $ids])->values()->all()]);
    unset($values['pools']);
    if (!in_array(Kynzo\McRouter\Filament\RoutingFields::EGG_FEATURE, $mcEgg->refresh()->features ?? [], true)) {
        throw new RuntimeException('Saving settings did not apply the mc-router egg feature.');
    }
    // The step label only appears in the stepper once a feature-gated egg is selected.
    Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\CreateServer::class)
        ->assertDontSee('MC Router')
        ->set('data.egg_id', $otherEgg->id)->assertDontSee('MC Router')
        ->set('data.egg_id', $mcEgg->id)->assertSee('MC Router');
    try {
        app(App\Services\Servers\ServerCreationService::class)->handle(['name' => 'Gated', 'node_id' => $node->id, 'owner_id' => $admin->id,
            'egg_id' => 999999, 'allocation_id' => $other->id, 'memory' => 128, 'swap' => 0, 'disk' => 128, 'cpu' => 0, 'io' => 500,
            'environment' => [], 'mc_router_enabled' => true, 'mc_router_slug' => 'gated', 'mc_router_allocation_id' => $other->id]);
        throw new RuntimeException('Gated creation unexpectedly completed.');
    } catch (Illuminate\Database\Eloquent\ModelNotFoundException) {
        if (Kynzo\McRouter\Models\RouteRecord::where('hostname', 'like', 'gated%')->exists()) { throw new RuntimeException('Gated egg was routed anyway.'); }
    }
    // The edit tab is egg-gated too, but stays available for servers that already route.
    $nonMcServer = App\Models\Server::factory()->create(['owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $free->id, 'egg_id' => $otherEgg->id]);
    Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $nonMcServer->id])
        ->assertDontSee('Use mc-router');
    $mcServer = App\Models\Server::factory()->create(['owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $free->id, 'egg_id' => $mcEgg->id]);
    $free->update(['server_id' => $mcServer->id]);
    Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $mcServer->id])
        ->assertSee('Use mc-router');
    $fresh = app(Kynzo\McRouter\Services\Settings::class)->get();
    $plugin->saveSettings([...$fresh, 'eggs' => [],
        'pool_rows' => collect($fresh['pools'])->map(fn ($ids, $nodeId) => ['node_id' => (int) $nodeId, 'allocation_ids' => $ids])->values()->all()]);
    if (in_array(Kynzo\McRouter\Filament\RoutingFields::EGG_FEATURE, $mcEgg->refresh()->features ?? [], true)) {
        throw new RuntimeException('Empty egg list did not remove the mc-router feature.');
    }
    // A fully disabled, unclaimed record must not pin the tab; an enabled one must.
    $staleServer = App\Models\Server::factory()->create(['owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $free->id, 'egg_id' => $otherEgg->id]);
    $free->update(['server_id' => $staleServer->id]);
    Kynzo\McRouter\Models\RouteRecord::create(['server_uuid' => $staleServer->uuid, 'hostname' => 'stale.mc.example.org', 'desired_allocation_id' => $free->id,
        'enabled' => false, 'provisioned' => true, 'status' => 'disabled', 'applied' => [], 'attempted' => []]);
    Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $staleServer->id])
        ->assertDontSee('Use mc-router');
    $staleServer2 = App\Models\Server::factory()->create(['owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $free->id, 'egg_id' => $otherEgg->id]);
    $free->update(['server_id' => $staleServer2->id]);
    Kynzo\McRouter\Models\RouteRecord::create(['server_uuid' => $staleServer2->uuid, 'hostname' => 'claimed.mc.example.org', 'desired_allocation_id' => $free->id,
        'enabled' => true, 'provisioned' => true, 'status' => 'error', 'last_error' => 'test', 'applied' => ['claimed.mc.example.org' => '192.0.2.10:25566'], 'attempted' => []]);
    Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $staleServer2->id])
        ->assertSee('Use mc-router')
        ->assertSee('still exist for it');
    // Re-add via the settings helper button that collects eggs tagged minecraft.
    $mountedSchema = $settingsPage->instance()->{'mountedActionSchema0'};
    $nestedKey = null;
    foreach ($mountedSchema->getFlatComponents(true, true, true) as $component) {
        if ($component instanceof Filament\Actions\Action && $component->getName() === 'mc_router_add_minecraft_tagged') {
            $nestedKey = $component->getSchemaComponent()->getKey();
        }
    }
    if ($nestedKey === null) { throw new RuntimeException('Settings helper button missing.'); }
    $settingsPage->callAction(Filament\Actions\Testing\TestAction::make('mc_router_add_minecraft_tagged')
        ->schemaComponent($nestedKey, schema: ''))
        ;
    $actualEggs = $settingsPage->instance()->{'mountedActionSchema0'}->getComponentByStatePath('mountedActionSchema0.mc-router-settings-actions', withHidden: true) ? null : null;
    $got = data_get($settingsPage->instance(), 'mountedActions.0.data.eggs');
    $ordinary = App\Models\User::factory()->create();
    Illuminate\Support\Facades\Auth::login($ordinary);
    Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\CreateServer::class)->assertForbidden();
    try {
        app(Kynzo\McRouter\Services\RouteManager::class)->save($server, true, 'forbidden', $other->id, 0);
        throw new RuntimeException('Non-admin routing write was permitted.');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        if ($e->getStatusCode() !== 403) { throw $e; }
    }
    echo "OK: native creation step/edit tab, actual populated primary selector, slug hydration, specific confirmation, tagged-minecraft helper button, stale-record tab pinning, read-only info entries, egg-gated step/tab visibility, gated-egg creation bypass, non-admin denial\n";
} catch (PHPUnit\Framework\ExpectationFailedException $e) {
    echo $e->getComparisonFailure()?->getDiff();
    throw $e;
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'Configuration') || get_class($e) === 'PHPUnit\Event\RuntimeException') {
        foreach (array_slice($e->getTrace(), 0, 12) as $f) { echo ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . ' @ ' . basename($f['file'] ?? '') . ':' . ($f['line'] ?? '') . PHP_EOL; }
    }
    throw $e;
} finally { Illuminate\Support\Facades\DB::rollBack(); }
