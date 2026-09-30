<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Kynzo\McRouter\Services\Synchronizer;

final class SyncRoute implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $routeId) {}
    public function backoff(): array { return [10, 30, 120]; }

    public function handle(Synchronizer $synchronizer): void
    {
        if (!$synchronizer->sync($this->routeId)) {
            throw new \RuntimeException('MC Router synchronization failed. See the server admin MC Router tab.');
        }
    }
}
