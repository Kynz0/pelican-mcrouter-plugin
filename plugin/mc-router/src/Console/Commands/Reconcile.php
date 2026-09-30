<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Console\Commands;

use Illuminate\Console\Command;
use Kynzo\McRouter\Jobs\SyncRoute;
use Kynzo\McRouter\Models\RouteRecord;

final class Reconcile extends Command
{
    protected $signature = 'mc-router:reconcile';
    protected $description = 'Queue reconciliation of plugin-owned routes (including deleted servers)';

    public function handle(): int
    {
        RouteRecord::orderBy('id')->chunkById(100, function ($routes) {
            foreach ($routes as $route) { SyncRoute::dispatch($route->id); }
        });
        $this->info('MC Router reconciliation queued.');
        return self::SUCCESS;
    }
}
