<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use App\Models\Allocation;
use App\Models\Server;
use App\Services\Servers\BuildModificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kynzo\McRouter\Domain\AllocationSelector;
use Kynzo\McRouter\Domain\Hostname;
use Kynzo\McRouter\Jobs\SyncRoute;
use Kynzo\McRouter\Models\RouteRecord;

final class RouteManager
{
    /** Apply the edit-tab form state; runs after the page's own "Save changes" succeeds. */
    public function saveFromForm(Server $server, array $data): void
    {
        if (!array_key_exists('mc_router_enabled', $data)) { return; } // Tab hidden (e.g. egg not eligible): leave routing untouched.
        $this->save($server, (bool) ($data['mc_router_enabled'] ?? false), (string) ($data['mc_router_slug'] ?? ''),
            (int) ($data['mc_router_allocation_id'] ?? 0), (int) ($data['mc_router_confirmed_allocation'] ?? 0));
    }

    public function hostname(string $slug): string
    {
        try { return Hostname::make($slug, app(Settings::class)->get()['suffix'], config('mc-router.reserved', [])); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['mc_router_slug' => $e->getMessage()]); }
    }

    public function allocation(int $id, int $nodeId, ?int $serverId = null): Allocation
    {
        $a = Allocation::whereKey($id)->lockForUpdate()->first();
        $selector = new AllocationSelector(app(Settings::class)->get()['pools']);
        if (!$a || !$selector->eligible($a->toArray(), $nodeId) || ($a->server_id !== null && $a->server_id !== $serverId)) {
            throw ValidationException::withMessages(['mc_router_allocation_id' => 'The primary allocation is not eligible or available. Please choose again.']);
        }
        try { AllocationSelector::backend($a->toArray()); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['mc_router_allocation_id' => $e->getMessage()]); }
        return $a;
    }

    public function guardBackend(Allocation $allocation, string $serverUuid): void
    {
        try { $backend = AllocationSelector::backend($allocation->toArray()); }
        catch (\InvalidArgumentException) { return; } // Non-router wildcard allocations retain normal Panel behavior.
        foreach (RouteRecord::where('server_uuid', '!=', $serverUuid)->get() as $route) {
            if (in_array($backend, [...array_values($route->applied), ...array_values($route->attempted ?? [])], true)) {
                throw ValidationException::withMessages(['mc_router_allocation_id' => 'This backend is still claimed by a previous route. Reconcile its removal before reusing the port.']);
            }
        }
    }

    public function reserve(RouteRecord $route, string $hostname): void
    {
        $owner = DB::table('mc_router_hostnames')->where('hostname', $hostname)->lockForUpdate()->value('route_id');
        if ($owner !== null && (int) $owner !== $route->id) {
            throw ValidationException::withMessages(['mc_router_slug' => 'This hostname is already reserved by another server.']);
        }
        if ($owner === null) {
            try { DB::table('mc_router_hostnames')->insert(['hostname' => $hostname, 'route_id' => $route->id]); }
            catch (\Illuminate\Database\QueryException) { throw ValidationException::withMessages(['mc_router_slug' => 'This hostname was reserved concurrently. Please choose another.']); }
        }
    }

    public function save(Server $server, bool $enabled, string $slug, int $allocationId, int $confirmedId): void
    {
        abort_unless(user()?->isRootAdmin() && user()->can('update server', $server), 403);
        $route = Cache::lock('mc-router:mutation', 180)->block(10, function () use ($server, $enabled, $slug, $allocationId, $confirmedId) {
            $server = $server->fresh();
            $settings = app(Settings::class)->get();
            if ($enabled && $allocationId !== $server->allocation_id && $confirmedId !== $allocationId) {
                throw ValidationException::withMessages(['mc_router_allocation_id' => 'Confirm this specific primary allocation change before saving.']);
            }
            $hostname = $enabled ? $this->hostname($slug) : null;
            $route = DB::transaction(function () use ($server, $enabled, $hostname, $allocationId) {
                if ($enabled) { $this->guardBackend($this->allocation($allocationId, $server->node_id, $server->id), $server->uuid); }
                $route = RouteRecord::firstOrNew(['server_uuid' => $server->uuid]);
                if (!$enabled && !$route->exists) { return null; }
                $route->fill([
                    'hostname' => $hostname ?? $route->hostname, 'enabled' => $enabled,
                    'desired_allocation_id' => $enabled ? $allocationId : $route->desired_allocation_id,
                    'provisioned' => true, 'revision' => ($route->revision ?? 0) + 1,
                    'status' => $enabled ? 'pending' : 'removing', 'last_error' => null,
                    'applied' => $route->applied ?? [],
                ])->save();
                if ($enabled) { $this->reserve($route, $hostname); }
                return $route;
            });
            // No database transaction is held across Wings calls.
            if ($enabled && $settings['mode'] !== 'dry-run' && $allocationId !== $server->allocation_id) {
                try {
                    app(BuildModificationService::class)->handle($server, [
                        'add_allocations' => [$allocationId], 'allocation_id' => $allocationId,
                        'database_limit' => $server->database_limit, 'allocation_limit' => $server->allocation_limit,
                        'backup_limit' => $server->backup_limit,
                    ]);
                } catch (\Throwable) {
                    $route->update(['status' => 'error', 'last_error' => 'Primary allocation change failed. Recheck availability and save again.']);
                    throw ValidationException::withMessages(['mc_router_allocation_id' => 'The primary allocation could not be changed. No new route was activated.']);
                }
            }
            return $route;
        });
        if ($route) { $this->queue($route); }
    }

    public function queue(RouteRecord $route): void
    {
        try { SyncRoute::dispatch($route->id); }
        catch (\Throwable) {
            $route->refresh();
            if ($route->status !== 'error') {
                $route->update(['status' => 'error', 'last_error' => 'Synchronization could not be queued. Periodic reconciliation will retry.']);
            }
        }
    }
}
