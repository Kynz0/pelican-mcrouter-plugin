<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kynzo\McRouter\Domain\AllocationSelector;
use Kynzo\McRouter\Domain\RoutePlanner;
use Kynzo\McRouter\Models\RouteRecord;
use Kynzo\McRouter\Exceptions\RouterException as RuntimeException;

final class Synchronizer
{
    public function sync(int $id): bool
    {
        return Cache::lock('mc-router:mutation', 180)->block(10, function () use ($id) {
            $route = RouteRecord::find($id);
            if (!$route) { return true; }
            try {
                $server = Server::where('uuid', $route->server_uuid)->first();
                if (!$server) { $route->update(['enabled' => false, 'status' => 'removing']); }
                if (!$route->enabled && !$route->applied && !$route->attempted) {
                    $this->release($route, null);
                    if (!$server) { $route->delete(); }
                    else { $route->update(['status' => 'disabled', 'last_error' => null]); }
                    return true;
                }
                if ($route->enabled && !$route->provisioned) { return true; }
                $settings = app(Settings::class)->get();
                if ($settings['mode'] === 'dry-run') {
                    if ($route->applied || $route->attempted) { throw new RuntimeException('Previously managed live routes require their original transport for cleanup.'); }
                    $this->release($route, $route->enabled ? $route->hostname : null);
                    $route->update(['status' => $route->enabled ? 'dry-run' : 'disabled', 'last_error' => null]);
                    return true;
                }
                $backend = null;
                $invalid = null;
                if ($route->enabled) {
                    $a = $server?->allocation;
                    if (!$a || $a->server_id !== $server->id || !(new AllocationSelector($settings['pools']))->eligible($a->toArray(), $server->node_id)) {
                        $invalid = 'Current primary allocation is not owned and eligible. Approve an eligible replacement in the MC Router tab.';
                    } elseif ($route->desired_allocation_id !== $server->allocation_id) {
                        $invalid = 'The proposed primary differs from the actual primary. Save routing again to confirm the allocation change.';
                    } else {
                        try { $backend = AllocationSelector::backend($a->toArray()); }
                        catch (\InvalidArgumentException) { $invalid = 'The current allocation does not have a valid unicast Wings backend address.'; }
                    }
                }
                $client = app(RouterClient::class);
                $observed = $client->routes();
                $applied = $route->applied;
                $attempted = $route->attempted ?? [];
                // Keep both previous confirmed and uncertain new backends across timeout/retry.
                foreach (array_replace($attempted, $applied) as $hostname => $oldBackend) {
                    if ($backend !== null && $hostname === $route->hostname) { continue; }
                    $existing = $observed[$hostname]['backend'] ?? null;
                    $ownedBackend = $existing !== null && in_array($existing, [$applied[$hostname] ?? null, $attempted[$hostname] ?? null], true) ? $existing : $oldBackend;
                    $plan = RoutePlanner::remove($hostname, $observed, $ownedBackend);
                    if ($plan['action'] === 'conflict') { throw new RuntimeException($plan['reason']); }
                    if ($plan['action'] === 'remove') {
                        $client->remove($hostname);
                        $observed = $client->routes();
                        if (isset($observed[$hostname])) { throw new RuntimeException('Router did not confirm route removal.'); }
                    }
                    unset($applied[$hostname], $attempted[$hostname]);
                    $route->update(['applied' => $applied, 'attempted' => $attempted]);
                }
                if ($invalid !== null) { throw new RuntimeException($invalid); }
                if ($backend !== null) {
                    if ((int) DB::table('mc_router_hostnames')->where('hostname', $route->hostname)->value('route_id') !== $route->id) {
                        throw new RuntimeException('Hostname reservation is missing; save routing again.');
                    }
                    $existing = $observed[$route->hostname]['backend'] ?? null;
                    $ownedBackend = $existing !== null && in_array($existing, [$applied[$route->hostname] ?? null, $attempted[$route->hostname] ?? null], true)
                        ? $existing : ($applied[$route->hostname] ?? $attempted[$route->hostname] ?? null);
                    $plan = RoutePlanner::apply($route->hostname, $backend, $observed, $ownedBackend);
                    if ($plan['action'] === 'conflict') { throw new RuntimeException($plan['reason']); }
                    if ($plan['action'] === 'apply') {
                        // Durable intent before the request makes timeouts/retries recoverable.
                        // This requires a single writer for the plugin-managed namespace (API has no CAS).
                        $attempted[$route->hostname] = $backend;
                        $route->update(['attempted' => $attempted]);
                        $client->apply($route->hostname, $backend);
                        $observed = $client->routes();
                    }
                    if (($observed[$route->hostname]['backend'] ?? null) !== $backend) {
                        throw new RuntimeException('Router readback does not match the desired backend.');
                    }
                    $applied[$route->hostname] = $backend;
                    unset($attempted[$route->hostname]);
                    $route->update(['applied' => $applied, 'attempted' => $attempted, 'status' => 'active', 'last_error' => null]);
                    $this->release($route, $route->hostname);
                } else {
                    $this->release($route, null);
                    if (!$server) { $route->delete(); }
                    else { $route->update(['status' => 'disabled', 'last_error' => null]); }
                }
                return true;
            } catch (\Throwable $e) {
                // Only our controlled messages are exposed; no transport stderr, URLs, keys or credentials.
                $message = $e instanceof RuntimeException ? $e->getMessage() : 'Synchronization failed. Check configuration and retry.';
                $route->update(['status' => 'error', 'last_error' => $message]);
                return false;
            }
        });
    }

    private function release(RouteRecord $route, ?string $keep): void
    {
        $query = DB::table('mc_router_hostnames')->where('route_id', $route->id);
        $retained = array_unique([...array_keys($route->applied), ...array_keys($route->attempted ?? [])]);
        if ($keep !== null) { $retained[] = $keep; }
        if ($retained) { $query->whereNotIn('hostname', $retained); }
        $query->delete();
    }
}
