<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Runs exclusively against the disposable checkout, with rolled-back fixtures.
$root = dirname(__DIR__);
$panel = $root . '/.dev/panel';
if (!is_dir($panel . '/vendor')) {
    throw new RuntimeException('Prepare .dev/panel with scripts/setup-panel.sh first.');
}
chdir($panel);
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || realpath(base_path()) !== realpath($panel)) {
    throw new RuntimeException('Refusing non-disposable Panel.');
}
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
    config(['mc-router.pools' => [$node->id => [$free->id, $other->id]]]);
    $page = Livewire\Livewire::test(Kynzo\McRouter\Filament\Admin\Pages\RoutingPreview::class)
        ->assertSee('This prototype only previews routing')
        ->set('data.node_id', $node->id)
        ->set('data.name', 'My Minecraft Server')
        ->assertSet('data.slug', 'my-minecraft-server')
        ->assertSee('my-minecraft-server.mc.example.org')
        ->assertSee('192.0.2.10:25566')
        ->set('data.slug', 'custom')
        ->set('data.name', 'Another name')
        ->assertSet('data.slug', 'custom')
        ->set('data.allocation_id', $other->id)
        ->assertSee('192.0.2.10:25567');
    $server = App\Models\Server::factory()->create(['node_id' => $node->id, 'allocation_id' => $other->id]);
    $other->update(['server_id' => $server->id]);
    $page->set('data.server_id', $server->id)->assertSee('192.0.2.10:25567');
    config(['mc-router.pools' => [$node->id => [$free->id]]]);
    $page->set('data.slug', 'existing')->assertSee('Confirmation required')
        ->set('data.confirm_reallocation', true)->assertSee('proposed route');
    if ($server->refresh()->allocation_id !== $other->id || $free->refresh()->server_id !== null) {
        throw new RuntimeException('Preview changed an allocation!');
    }
    // Spike only: register a whole step in this test process, not in the shipped prototype.
    App\Filament\Admin\Resources\Servers\Pages\CreateServer::registerCustomSteps(
        App\Enums\StepPosition::Before,
        Filament\Schemas\Components\Wizard\Step::make('MC Router spike')->schema([
            Filament\Forms\Components\Toggle::make('mc_router_enabled')->default(false)->live(),
            Filament\Forms\Components\TextInput::make('mc_router_slug')->live(),
        ]),
    );
    $wizard = Livewire\Livewire::test(App\Filament\Admin\Resources\Servers\Pages\CreateServer::class)
        ->assertSee('MC Router spike')
        ->set('data.mc_router_enabled', true)
        ->set('data.mc_router_slug', 'wizard-spike')
        ->assertSet('data.mc_router_slug', 'wizard-spike');
    $state = ['data' => $wizard->instance()->data];
    $wizard->instance()->form->dehydrateState($state);
    if (($state['data']['mc_router_slug'] ?? null) !== 'wizard-spike' || ($state['data']['mc_router_enabled'] ?? null) !== true) {
        throw new RuntimeException('Custom wizard state did not survive dehydration.');
    }
    $ordinary = App\Models\User::factory()->create();
    Illuminate\Support\Facades\Auth::login($ordinary);
    Livewire\Livewire::test(Kynzo\McRouter\Filament\Admin\Pages\RoutingPreview::class)->assertForbidden();
    echo "OK: beta38 Livewire rendering/hydration, suggestion/edit, auto-pick/override, existing-server confirmation, read-only preview, non-admin denial, custom wizard step hydration/dehydration\n";
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
