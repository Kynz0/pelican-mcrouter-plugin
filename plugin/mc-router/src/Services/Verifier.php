<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use Kynzo\McRouter\Domain\AllocationSelector;
use Kynzo\McRouter\Models\RouteRecord;

/** End-to-end verification behind the edit-tab "Verify end-to-end" action. */
final class Verifier
{
    public const JOIN_PORT = 25565;

    /** @return array{ok: bool, message: string, warning: ?string} */
    public function check(RouteRecord $route): array
    {
        $settings = app(Settings::class)->get();
        $hostname = $route->hostname;
        try {
            $routes = app(RouterClient::class)->routes();
        } catch (\Kynzo\McRouter\Exceptions\RouterException $e) {
            return ['ok' => false, 'message' => 'Router API unreachable: ' . $e->getMessage(), 'warning' => null];
        }
        $applied = $routes[$hostname]['backend'] ?? null;
        if ($applied === null) {
            $uncertain = $route->attempted[$hostname] ?? null;
            return ['ok' => false, 'message' => 'The router has no mapping for ' . $hostname . ' yet'
                . ($uncertain ? ' (an uncertain write to ' . $uncertain . ' is recorded; retry pending)' : '')
                . '. Check the transport configuration and synchronization status.', 'warning' => null];
        }
        $desiredAllocation = $route->desired_allocation_id ? \App\Models\Allocation::withoutGlobalScopes()->find($route->desired_allocation_id) : null;
        try {
            $desired = $desiredAllocation ? AllocationSelector::backend($desiredAllocation->toArray()) : null;
        } catch (\InvalidArgumentException) { $desired = null; }
        if ($desired && $applied !== $desired && $route->enabled) {
            return ['ok' => false, 'message' => 'The router maps ' . $hostname . ' to ' . $applied . ', but the desired backend is ' . $desired . '. Synchronization is pending or conflicting.', 'warning' => null];
        }

        $resolved = @gethostbyname($hostname);
        if ($resolved === $hostname || !filter_var($resolved, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'message' => $applied . ' is applied, but the join address does not resolve. Create the wildcard DNS record (*.<suffix>) as a DNS-only record pointing at the router\'s public address.', 'warning' => null];
        }

        $join = @fsockopen($resolved, self::JOIN_PORT, $errno, $errstr, 3.0);
        if (!$join) {
            return ['ok' => false, 'message' => $applied . ' is applied and ' . $hostname . ' resolves to ' . $resolved
                . ', but TCP ' . self::JOIN_PORT . ' is unreachable from the Panel host. Check that public TCP ' . self::JOIN_PORT
                . ' is forwarded/published to the router (a LAN Panel host may fail here due to NAT hairpin even when external players can join).', 'warning' => null];
        }
        fclose($join);

        $warning = null;
        [$backendIp, $backendPort] = explode(':', $applied);
        $backend = @fsockopen($backendIp, (int) $backendPort, $errno2, $errstr2, 2.0);
        if (!$backend) {
            $warning = 'The backend ' . $applied . ' did not accept a TCP connection from the Panel host (it may simply be offline — clients still reach it via the router).';
        } else {
            fclose($backend);
        }

        return ['ok' => true, 'message' => $hostname . ' → ' . $applied . ' via ' . $resolved . ':' . self::JOIN_PORT . '.', 'warning' => $warning];
    }
}
