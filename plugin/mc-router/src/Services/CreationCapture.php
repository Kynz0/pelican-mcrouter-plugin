<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use App\Models\Server;
use Illuminate\Validation\ValidationException;
use Kynzo\McRouter\Models\RouteRecord;

/** Request-local context; model callbacks execute inside Pelican's own creation transaction. */
final class CreationCapture
{
    public ?array $desired = null;

    public function creating(Server $server): void
    {
        if ($this->desired === null) { return; }
        if ($server->allocation_id !== $this->desired['allocation_id'] || $server->node_id !== $this->desired['node_id']) {
            throw ValidationException::withMessages(['mc_router_allocation_id' => 'The final primary/node selection does not match the MC Router step.']);
        }
        app(RouteManager::class)->allocation($server->allocation_id, $server->node_id);
    }

    public function created(Server $server): void
    {
        if ($this->desired === null) { return; }
        $route = RouteRecord::create([
            'server_uuid' => $server->uuid, 'hostname' => $this->desired['hostname'],
            'desired_allocation_id' => $server->allocation_id, 'enabled' => true,
            'provisioned' => false, 'status' => 'pending', 'applied' => [],
        ]);
        app(RouteManager::class)->reserve($route, $route->hostname);
    }
}
