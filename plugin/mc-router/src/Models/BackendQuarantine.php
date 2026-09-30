<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kynzo\McRouter\Domain\AllocationSelector;

/** Exclude unassigned ports still reachable through an old/uncertain owned route. */
final class BackendQuarantine implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!Schema::hasTable('mc_router_routes')) { return; }
        $claims = [];
        foreach (RouteRecord::get() as $route) {
            foreach ([...array_values($route->applied), ...array_values($route->attempted ?? [])] as $backend) { $claims[$backend] = true; }
        }
        if (!$claims) { return; }
        $blocked = [];
        // Raw query avoids recursive invocation of this Allocation scope.
        foreach (DB::table('allocations')->whereNull('server_id')->get(['id', 'ip', 'port']) as $allocation) {
            try { $backend = AllocationSelector::backend((array) $allocation); }
            catch (\InvalidArgumentException) { continue; }
            if (isset($claims[$backend])) { $blocked[] = $allocation->id; }
        }
        if ($blocked) { $builder->whereNotIn($model->qualifyColumn('id'), $blocked); }
    }
}
