<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use App\Models\Objects\DeploymentObject;
use App\Models\Server;
use App\Services\Servers\ServerCreationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Kynzo\McRouter\Models\RouteRecord;

/** Delegates normal creation unchanged; no copied Panel page/service implementation. */
final class RoutedCreationService extends ServerCreationService
{
    public function __construct(private ServerCreationService $inner, private CreationCapture $capture) {}

    public function handle(array $data, ?DeploymentObject $deployment = null): Server
    {
        $enabled = (bool) ($data['mc_router_enabled'] ?? false);
        $eggs = app(Settings::class)->get()['eggs'] ?? [];
        if ($enabled && $eggs && !in_array((int) ($data['egg_id'] ?? 0), array_map('intval', $eggs), true)) {
            // Step is hidden for this egg; do not route silently from stale toggle state.
            $enabled = false;
        }
        $slug = (string) ($data['mc_router_slug'] ?? '');
        $chosen = (int) ($data['mc_router_allocation_id'] ?? 0);
        foreach (array_keys($data) as $key) {
            if (str_starts_with((string) $key, 'mc_router_')) { unset($data[$key]); }
        }
        if (!$enabled) { return $this->inner->handle($data, $deployment); }
        abort_unless(user()?->isRootAdmin(), 403);
        if ($deployment !== null || !$chosen || $chosen !== (int) ($data['allocation_id'] ?? 0)) {
            throw ValidationException::withMessages(['mc_router_allocation_id' => 'Return to the MC Router step and select the final eligible primary allocation.']);
        }
        $server = Cache::lock('mc-router:mutation', 180)->block(10, function () use ($data, $chosen, $slug) {
            $this->capture->desired = [
                'hostname' => app(RouteManager::class)->hostname($slug),
                'node_id' => (int) ($data['node_id'] ?? 0), 'allocation_id' => $chosen,
            ];
            try { return $this->inner->handle($data); }
            catch (\Throwable $e) {
                // Core may have removed a failed Wings creation; release only unapplied claims.
                $pending = RouteRecord::where('hostname', $this->capture->desired['hostname'])->where('provisioned', false)->first();
                if ($pending && !$pending->applied && !$pending->attempted && !Server::where('uuid', $pending->server_uuid)->exists()) {
                    $pending->delete();
                }
                throw $e;
            } finally { $this->capture->desired = null; }
        });
        $route = RouteRecord::where('server_uuid', $server->uuid)->firstOrFail();
        $route->update(['provisioned' => true]);
        // Dispatch only after Wings creation succeeds, never at the core transaction commit.
        app(RouteManager::class)->queue($route);
        return $server;
    }
}
