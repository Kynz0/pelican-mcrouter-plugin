<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Providers;

use App\Models\Server;
use App\Services\Servers\ServerCreationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Kynzo\McRouter\Models\RouteRecord;
use Kynzo\McRouter\Services\CreationCapture;
use Kynzo\McRouter\Services\RoutedCreationService;

final class McRouterProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreationCapture::class);
        $this->app->extend(ServerCreationService::class, fn ($service, $app) => new RoutedCreationService($service, $app->make(CreationCapture::class)));
    }

    public function boot(): void
    {
        \App\Models\Allocation::addGlobalScope(new \Kynzo\McRouter\Models\BackendQuarantine());
        \App\Models\Allocation::saving(function ($allocation) {
            if ($allocation->server_id !== null && $allocation->isDirty(['server_id', 'ip', 'port'])) {
                $uuid = Server::whereKey($allocation->server_id)->value('uuid') ?? '';
                $this->app->make(\Kynzo\McRouter\Services\RouteManager::class)->guardBackend($allocation, $uuid);
            }
        });
        // Pinned Livewire update hook: run after the native name state is assigned.
        \Livewire\Livewire::listen('update', function ($component, $path, $value) {
            if ($component instanceof \App\Filament\Admin\Resources\Servers\Pages\CreateServer) {
                return function () use ($component, $path, $value) {
                    if (!($component->data['mc_router_enabled'] ?? false)) { return; }
                    if ($path === 'data.name' && !($component->data['mc_router_slug_edited'] ?? false)) {
                        $component->data['mc_router_slug'] = \Kynzo\McRouter\Domain\Hostname::suggest((string) $value);
                    }
                    if ($path === 'data.mc_router_allocation_id') {
                        $component->data['allocation_id'] = $value;
                        $component->data['allocation_additional'] = null;
                    }
                    if ($path === 'data.allocation_id') {
                        $eligible = collect(\Kynzo\McRouter\Filament\RoutingFields::selector()->options(\Kynzo\McRouter\Filament\RoutingFields::allocations(), (int) ($component->data['node_id'] ?? 0)))->firstWhere('id', (int) $value);
                        if ($eligible) { $component->data['mc_router_allocation_id'] = (int) $value; }
                    }
                    if ($path === 'data.node_id') {
                        $component->node = \App\Models\Node::find($value);
                        $chosen = \Kynzo\McRouter\Filament\RoutingFields::autoAllocation((int) $value, \Kynzo\McRouter\Filament\RoutingFields::allocations());
                        $component->data['allocation_id'] = $component->data['mc_router_allocation_id'] = $chosen['id'] ?? null;
                        $component->data['allocation_additional'] = null;
                    }
                };
            }
        });
        Server::creating(fn (Server $server) => $this->app->make(CreationCapture::class)->creating($server));
        Server::created(fn (Server $server) => $this->app->make(CreationCapture::class)->created($server));
        Server::updating(function (Server $server) {
            if ($server->isDirty('allocation_id') && $server->allocation_id) {
                $a = \App\Models\Allocation::withoutGlobalScopes()->find($server->allocation_id);
                if ($a) { $this->app->make(\Kynzo\McRouter\Services\RouteManager::class)->guardBackend($a, $server->uuid); }
            }
        });
        Server::updated(function (Server $server) {
            if (!$server->wasChanged(['allocation_id', 'node_id'])) { return; }
            $route = RouteRecord::where('server_uuid', $server->uuid)->first();
            if ($route) {
                $route->update(['desired_allocation_id' => $server->allocation_id, 'revision' => $route->revision + 1, 'status' => 'pending']);
            }
        });
        Server::deleted(function (Server $server) {
            RouteRecord::where('server_uuid', $server->uuid)->update(['enabled' => false, 'status' => 'removing']);
        });
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('mc-router:reconcile')->everyMinute()->withoutOverlapping();
        });
    }
}
