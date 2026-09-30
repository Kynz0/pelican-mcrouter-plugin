<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use App\Models\Egg;
use Kynzo\McRouter\Filament\RoutingFields;

/**
 * Keeps the "mc-router" egg feature in sync with the configured egg list, so
 * admins can also grant/revoke routing eligibility per egg from the egg editor.
 * An empty list means "offer routing for every egg" and touches nothing.
 */
final class EggFeatureSync
{
    public function sync(array $eggs): void
    {
        $feature = RoutingFields::EGG_FEATURE;
        $eggs = array_map('intval', array_unique($eggs));
        // An empty list means "offer routing for every egg": eligibility no longer
        // depends on the feature, so it is removed from all eggs to avoid stale flags.
        Egg::query()->get(['id', 'features'])->each(function (Egg $egg) use ($eggs, $feature) {
            $features = $egg->features ?? [];
            $has = in_array($feature, $features, true);
            $should = in_array((int) $egg->id, $eggs, true);
            if ($has === $should) { return; }
            $egg->features = $should ? [...$features, $feature] : array_values(array_diff($features, [$feature]));
            $egg->save();
        });
    }
}
