<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mc_router_routes', function (Blueprint $table) { $table->json('attempted')->nullable(); });
    }
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('mc_router_routes')->get()->contains(fn ($r) => count(json_decode($r->applied, true) ?? []) > 0 || count(json_decode($r->attempted ?? '{}', true) ?? []) > 0)) {
            throw new RuntimeException('Disable routing and reconcile all MC Router claims before uninstalling.');
        }
        Schema::table('mc_router_routes', function (Blueprint $table) { $table->dropColumn('attempted'); });
    }
};
