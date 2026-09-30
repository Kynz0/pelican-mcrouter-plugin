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
    $wizard = Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\CreateServer::class)
        ->assertSee('MC Router')
        ->assertSet('data.mc_router_enabled', false)
        ->set('data.allocation_id', $free->id)
        ->set('data.mc_router_enabled', true)
        ->assertSet('data.allocation_id', $free->id)
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
        ->set('data.mc_router_enabled', false)->assertSet('data.allocation_id', $free->id)
        ->set('data.mc_router_enabled', true)->set('data.mc_router_allocation_id', $other->id);
    $state = ['data' => $wizard->instance()->data];
    $wizard->instance()->form->dehydrateState($state);
    if (($state['data']['mc_router_slug'] ?? null) !== 'custom' || isset($state['data']['mc_router_normal_node'])) {
        throw new RuntimeException('Plugin payload dehydration failed.');
    }
    $server = App\Models\Server::factory()->create(['node_id' => $node->id, 'allocation_id' => $other->id]);
    $other->update(['server_id' => $server->id]);
    $edit = Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $server->id])
        ->assertSee('MC Router')
        ->set('data.mc_router_enabled', true)
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
        ->assertSet('data.mc_router_confirmed_allocation', $free->id)
        ->callAction($schemaAction($edit, 'mc_router_save'));
    if (Kynzo\McRouter\Models\RouteRecord::where('server_uuid', $server->uuid)->value('status') !== 'dry-run') {
        throw new RuntimeException('The actual edit-tab save action did not persist dry-run settings.');
    }
    if ($server->refresh()->allocation_id !== $other->id || $free->refresh()->server_id !== null) { throw new RuntimeException('Unsaved preview mutated an allocation.'); }
    config(['mc-router.pools' => [$node->id => [$free->id]]]);
    $legacy = App\Models\Allocation::factory()->create(['node_id' => $node->id, 'ip' => '192.0.2.10', 'port' => 26100]);
    $legacyServer = App\Models\Server::factory()->create(['node_id' => $node->id, 'allocation_id' => $legacy->id]);
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
    $ordinary = App\Models\User::factory()->create();
    Illuminate\Support\Facades\Auth::login($ordinary);
    Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\CreateServer::class)->assertForbidden();
    try {
        app(Kynzo\McRouter\Services\RouteManager::class)->save($server, true, 'forbidden', $other->id, 0);
        throw new RuntimeException('Non-admin routing write was permitted.');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        if ($e->getStatusCode() !== 403) { throw $e; }
    }
    echo "OK: native creation step/edit tab, actual populated primary selector, slug hydration, specific confirmation, read-only unsaved fields, persisted settings, non-admin denial\n";
} catch (PHPUnit\Framework\ExpectationFailedException $e) {
    echo $e->getComparisonFailure()?->getDiff();
    throw $e;
} finally { Illuminate\Support\Facades\DB::rollBack(); }
