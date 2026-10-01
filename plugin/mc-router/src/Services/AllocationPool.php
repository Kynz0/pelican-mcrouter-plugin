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
    /** Parse '25565' or '25565-25574' into [start, end]; null when malformed. */
    public static function parsePortRange(string $entry): ?array
    {
        $entry = trim($entry);
        if (!preg_match('/^(\d{1,5})(?:\s*-\s*(\d{1,5}))?$/', $entry, $m)) { return null; }
        $start = (int) $m[1];
        $end = isset($m[2]) ? (int) $m[2] : $start;

        return $start <= $end ? [$start, $end] : [$end, $start];
    }

    /** @return array{ids: list<int>, skipped: int} allocations of the node inside the port range */
    public function inRange(int $nodeId, int $start, int $end): array
    {
        $existing = Allocation::withoutGlobalScopes()
            ->where('node_id', $nodeId)->whereBetween('port', [min($start, $end), max($start, $end)])
            ->orderBy('port')->pluck('id')->all();

        return ['ids' => array_values($existing), 'skipped' => 0];
    }

    /**
     * Create new allocations on the node for the given port entries ('25565', '25565-25570'),
     * skipping ports that already exist. Mirrors Pelican's own allocation creator semantics.
     *
     * @param  list<string|int>  $entries
     * @return array{created: list<int>, skipped: int, requested: int}
     */
    public function createPorts(int $nodeId, string $ip, array $entries): array
    {
        $node = Node::findOrFail($nodeId);
        $ports = [];
        foreach ($entries as $entry) {
            $range = self::parsePortRange((string) $entry);
            if ($range === null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pool_rows' => 'Invalid port entry: ' . $entry]);
            }
            foreach (range($range[0], $range[1]) as $port) {
                if ($port < AssignmentService::PORT_FLOOR || $port > AssignmentService::PORT_CEIL) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['pool_rows' => "Port $port is outside the allowed 1024–65535 range."]);
                }
                $ports[$port] = true;
            }
        }
        $ports = array_keys($ports);
        if (count($ports) > AssignmentService::PORT_RANGE_LIMIT) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pool_rows' => 'More than 1000 ports requested — split it up.']);
        }
        $taken = Allocation::withoutGlobalScopes()->where('node_id', $nodeId)->where('ip', $ip)
            ->whereIn('port', $ports)->pluck('port')->all();
        $remaining = array_values(array_map('strval', array_diff($ports, $taken)));
        $created = [];
        if ($remaining !== []) {
            try {
                $created = app(AssignmentService::class)->handle($node, ['allocation_ip' => $ip, 'allocation_ports' => $remaining]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pool_rows' => 'Allocation creation failed: ' . $e->getMessage()]);
            }
        }

        return ['created' => $created, 'skipped' => count($taken), 'requested' => count($ports)];
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

    /** The IP most allocations of this node already use — the sensible default for new allocations. */
    public function commonIp(int $nodeId): ?string
    {
        return Allocation::withoutGlobalScopes()->where('node_id', $nodeId)
            ->selectRaw('ip, count(*) as c')->groupBy('ip')->orderByDesc('c')->orderBy('ip')->value('ip');
    }

    /** @return list<string> distinct IPs already present on the node */
    public function allocationIps(int $nodeId): array
    {
        return Allocation::withoutGlobalScopes()->where('node_id', $nodeId)->distinct()->orderBy('ip')->pluck('ip')->all();
    }
}
