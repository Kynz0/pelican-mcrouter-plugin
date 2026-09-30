<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mc_router_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->json('values');
        });
        Schema::create('mc_router_routes', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36)->unique();
            $table->string('hostname', 253);
            $table->unsignedBigInteger('desired_allocation_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('provisioned')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->json('applied');
            $table->timestamps();
        });
        Schema::create('mc_router_hostnames', function (Blueprint $table) {
            $table->string('hostname', 253)->primary();
            $table->foreignId('route_id')->constrained('mc_router_routes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('mc_router_routes')->get()->contains(fn ($r) => count(json_decode($r->applied, true) ?? []) > 0)) {
            throw new RuntimeException('Disable routing and reconcile all managed routes before uninstalling MC Router.');
        }
        Schema::dropIfExists('mc_router_hostnames');
        Schema::dropIfExists('mc_router_routes');
        Schema::dropIfExists('mc_router_settings');
    }
};
