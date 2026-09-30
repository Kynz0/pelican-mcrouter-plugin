<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Models;

use Illuminate\Database\Eloquent\Model;

final class RouteRecord extends Model
{
    protected $table = 'mc_router_routes';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'provisioned' => 'boolean', 'applied' => 'array', 'attempted' => 'array', 'desired_allocation_id' => 'integer', 'revision' => 'integer'];
    }
}
