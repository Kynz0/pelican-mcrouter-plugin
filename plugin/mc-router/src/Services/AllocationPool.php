<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use App\Models\Allocation;
use App\Models\Node;
use App\Services\Allocations\AssignmentService;
use Kynzo\McRouter\Domain\PortRanges;

/** Helpers behind the settings pool actions: range selection, allocation creation, uncommon auto-generation. */
final class AllocationPool
{
    /** @return array{ids: list<int>, skipped: int} allocations of the node inside the port range */
    public function inRange(int $nodeId, int $start, int $end): array
    {
        $existing = Allocation::withoutGlobalScopes()
            ->where('node_id', $nodeId)->whereBetween('port', [min($start, $end), max($start, $end)])
            ->orderBy('port')->pluck('id')->all();

        return ['ids' => array_values($existing), 'skipped' => 0];
    }

    /** @return array{created: list<int>, skipped: int} create new allocations on the node, skipping ports that already exist */
    public function createRange(int $nodeId, string $ip, int $start, int $count): array
    {
        $node = Node::findOrFail($nodeId);
        $count = max(1, min(1000, $count));
        $taken = Allocation::withoutGlobalScopes()->where('node_id', $nodeId)->where('ip', $ip)
            ->whereBetween('port', [$start, $start + $count - 1])->pluck('port')->all();
        $ports = array_values(array_diff(range($start, $start + $count - 1), $taken));
        $created = [];
        if ($ports !== []) {
            try {
                $created = app(AssignmentService::class)->handle($node, ['allocation_ip' => $ip, 'allocation_ports' => $ports]);
            } catch (\Throwable $e) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pool_rows' => 'Allocation creation failed: ' . $e->getMessage()]);
            }
        }

        return ['created' => $created, 'skipped' => count($taken)];
    }

    /** Pick a random uncommon start port whose range is entirely free on the node; null when nothing was found. */
    public function findUncommonStart(int $nodeId, int $size): ?int
    {
        $taken = Allocation::withoutGlobalScopes()->where('node_id', $nodeId)->pluck('port')->all();
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $start = random_int(1024, 65535 - $size);
            $ports = range($start, $start + $size - 1);
            $clean = array_filter($ports, fn (int $p) => !PortRanges::isCommon($p) && !in_array($p, $taken, true));
            if (count($clean) === $size) { return $start; }
        }

        return null;
    }

    public function firstAllocationIp(int $nodeId): ?string
    {
        return Allocation::withoutGlobalScopes()->where('node_id', $nodeId)->orderBy('id')->value('ip');
    }

    /** @return list<string> distinct IPs already present on the node */
    public function allocationIps(int $nodeId): array
    {
        return Allocation::withoutGlobalScopes()->where('node_id', $nodeId)->distinct()->orderBy('ip')->pluck('ip')->all();
    }
}
