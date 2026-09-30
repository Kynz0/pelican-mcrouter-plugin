<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Filament;

use App\Enums\TablerIcon;
use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;
use Kynzo\McRouter\Domain\AllocationSelector;
use Kynzo\McRouter\Domain\Hostname;
use Kynzo\McRouter\Models\RouteRecord;
use Kynzo\McRouter\Services\Settings;

final class RoutingFields
{
    public const EGG_FEATURE = 'mc-router';

    public static function record(?Server $record): ?RouteRecord
    {
        return $record ? RouteRecord::where('server_uuid', $record->uuid)->first() : null;
    }

    /** The "mc-router" egg feature gates routing; an empty settings list leaves every egg eligible. */
    public static function eggEligible(?int $eggId): bool
    {
        if (empty(app(Settings::class)->get()['eggs'])) { return true; }
        if (!$eggId || !$egg = Egg::find($eggId)) { return false; }

        return in_array(self::EGG_FEATURE, $egg->features ?? [], true);
    }

    /** @return array<int, array<string, mixed>> allocations in approved pools, minus ports claimed by other servers' routes */
    public static function allocations(): array
    {
        $settings = app(Settings::class)->get();
        $allocations = Allocation::withoutGlobalScopes()
            ->whereIn('node_id', array_keys($settings['pools']))
            ->get(['id', 'node_id', 'server_id', 'ip', 'port'])->toArray();
        $owners = Server::whereIn('id', array_filter(array_column($allocations, 'server_id')))->pluck('uuid', 'id');
        $claims = [];
        foreach (RouteRecord::get() as $route) {
            foreach ([...array_values($route->applied), ...array_values($route->attempted ?? [])] as $backend) {
                $claims[$backend][] = $route->server_uuid;
            }
        }

        return array_values(array_filter($allocations, function (array $a) use ($claims, $owners) {
            try { $backend = AllocationSelector::backend($a); } catch (\InvalidArgumentException) { return false; }
            $owner = $owners[$a['server_id']] ?? null;
            foreach ($claims[$backend] ?? [] as $claimOwner) {
                if ($owner !== $claimOwner) { return false; }
            }

            return true;
        }));
    }

    public static function selector(): AllocationSelector
    {
        return new AllocationSelector(app(Settings::class)->get()['pools']);
    }

    /** @param array<int, array<string, mixed>> $allocations */
    public static function autoAllocation(int $nodeId, array $allocations, ?int $currentId = null, ?int $serverId = null): ?array
    {
        $options = collect(self::selector()->options($allocations, $nodeId, $serverId));
        if ($currentId !== null && ($kept = $options->firstWhere('id', $currentId)) !== null) {
            return $kept; // Keep the current primary when it is already eligible.
        }

        return $options->first(fn (array $a) => $a['server_id'] === null); // Automatic pick must be free.
    }

    public static function previewText(Get $get, ?Server $record, bool $creating): string
    {
        if (!$get('mc_router_enabled')) {
            return 'mc-router is disabled. Normal Pelican allocation behavior is unchanged.';
        }
        $route = self::record($record);
        $status = $route ? " Stored status: {$route->status}." . ($route->last_error ? ' ' . $route->last_error : '') : '';
        try {
            $hostname = Hostname::make((string) $get('mc_router_slug'), app(Settings::class)->get()['suffix'], config('mc-router.reserved', []));
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $allocation = Allocation::find($get('mc_router_allocation_id'));
        if (!$allocation) {
            return "Join address: $hostname. No eligible primary is selected yet; choose one below or change the node.";
        }
        try { $backend = AllocationSelector::backend($allocation->toArray()); } catch (\InvalidArgumentException) { $backend = 'invalid backend address'; }
        $mismatch = $creating && (int) $get('allocation_id') !== (int) $get('mc_router_allocation_id')
            ? ' The Information step’s primary differs; creation will reject mismatched choices.'
            : '';

        return "Join address: $hostname (public TCP 25565) → $backend.$mismatch$status";
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function fields(bool $creating): array
    {
        $fields = [
            Text::make(fn () => app(Settings::class)->get()['mode'] === 'dry-run'
                ? 'Dry-run: routing choices can be saved, but no existing server ports or mc-router routes will be changed.'
                : 'Routing uses mc-router. Active means the runtime mapping is accepted, not that Minecraft is online or persistence is guaranteed.')
                ->columnSpanFull(),
            Grid::make(2)->columnSpanFull()->schema([
                Toggle::make('mc_router_enabled')->label('Use mc-router')->live()->inline(false)
                    ->default(fn (?Server $record) => self::record($record)?->enabled ?? false)
                    ->dehydrated($creating)
                    ->afterStateUpdated(function ($state, Get $get, Set $set, ?Server $record, $livewire) use ($creating) {
                        $set('mc_router_confirmed_allocation', null);
                        if (!$creating) {
                            if ($state && $record) {
                                $route = self::record($record);
                                $preferred = $route?->enabled ? ($route->desired_allocation_id ?? $record->allocation_id) : $record->allocation_id;
                                // Keep an eligible current primary; an ineligible one stays empty
                                // until the admin explicitly approves automatic replacement.
                                $chosen = collect(self::selector()->options(self::allocations(), $record->node_id, $record->id))->firstWhere('id', $preferred);
                                $set('mc_router_allocation_id', $chosen['id'] ?? null);
                                if (!$get('mc_router_slug_edited')) {
                                    $set('mc_router_slug', $route ? explode('.', $route->hostname)[0] : Hostname::suggest($record->name));
                                }
                            }

                            return;
                        }
                        if (!$state) {
                            if ($get('mc_router_saved_normal')) {
                                $set('allocation_id', $get('mc_router_normal_allocation'));
                                $set('allocation_additional', $get('mc_router_normal_additional'));
                                $livewire->node = \App\Models\Node::find($get('node_id'));
                            }

                            return;
                        }
                        $set('mc_router_normal_allocation', $get('allocation_id'));
                        $set('mc_router_normal_additional', $get('allocation_additional'));
                        $set('mc_router_saved_normal', true);
                        $chosen = self::autoAllocation((int) $get('node_id'), self::allocations(), (int) $get('allocation_id'));
                        $set('mc_router_allocation_id', $chosen['id'] ?? null);
                        $set('allocation_id', $chosen['id'] ?? null);
                        if (($chosen['id'] ?? null) !== $get('mc_router_normal_allocation')) {
                            $set('allocation_additional', null);
                        }
                        if (!$get('mc_router_slug_edited')) {
                            $set('mc_router_slug', Hostname::suggest($get('name') ?? 'minecraft'));
                        }
                    }),
                Placeholder::make('mc_router_info')->hiddenLabel()
                    ->content(fn (Get $get, ?Server $record) => new HtmlString('<p>' . e(self::previewText($get, $record, $creating)) . '</p>')),
            ]),
            Grid::make(2)->columnSpanFull()->schema([
                TextInput::make('mc_router_slug')->label('Hostname label')->live(onBlur: true)->maxLength(63)
                    ->prefixIcon(TablerIcon::Link)->suffix('.' . app(Settings::class)->get()['suffix'])
                    ->default(fn (?Server $record) => ($route = self::record($record)) ? explode('.', $route->hostname)[0] : Hostname::suggest($record?->name ?? 'minecraft'))
                    ->visible(fn (Get $get) => (bool) $get('mc_router_enabled'))->dehydrated($creating)
                    ->required(fn (Get $get) => $creating && $get('mc_router_enabled'))
                    ->afterStateUpdated(fn (Set $set) => $set('mc_router_slug_edited', true)),
                Select::make('mc_router_allocation_id')->label('Primary allocation')->live()->dehydrated($creating)
                    ->prefixIcon(TablerIcon::Network)->selectablePlaceholder(false)
                    ->placeholder('No eligible primary selected')
                    ->visible(fn (Get $get) => (bool) $get('mc_router_enabled'))
                    ->default(function (?Server $record) {
                        if (!$record) { return null; }
                        $route = self::record($record);
                        $preferred = $route?->enabled ? ($route->desired_allocation_id ?? $record->allocation_id) : $record->allocation_id;

                        return collect(self::selector()->options(self::allocations(), $record->node_id, $record->id))->firstWhere('id', $preferred)['id'] ?? null;
                    })
                    ->options(fn (Get $get, ?Server $record) => collect(self::selector()->options(self::allocations(), $record?->node_id ?? (int) $get('node_id'), $record?->id))
                        ->mapWithKeys(fn (array $a) => [$a['id'] => AllocationSelector::backend($a)]))
                    ->required(fn (Get $get) => $creating && $get('mc_router_enabled'))
                    ->afterStateUpdated(function ($state, Set $set) use ($creating) {
                        $set('mc_router_confirmed_allocation', null);
                        if ($creating) {
                            $set('allocation_id', $state);
                            $set('allocation_additional', null);
                        }
                    }),
            ]),
            Hidden::make('mc_router_slug_edited')->default(false)->dehydrated(false),
            Hidden::make('mc_router_confirmed_allocation')->dehydrated(false),
            Hidden::make('mc_router_normal_allocation')->dehydrated(false),
            Hidden::make('mc_router_normal_additional')->dehydrated(false),
            Hidden::make('mc_router_saved_normal')->dehydrated(false),
        ];
        if (!$creating) {
            // Routing settings are saved with the page's own "Save changes" button;
            // these actions only stage state inside the form.
            $fields[] = Actions::make([
                Action::make('mc_router_select_replacement')->label('Automatically select eligible replacement')->color('gray')
                    ->icon(TablerIcon::Wand)
                    ->visible(fn (Get $get) => $get('mc_router_enabled') && !$get('mc_router_allocation_id'))
                    ->requiresConfirmation()->modalHeading('Replace the current primary allocation?')
                    ->modalDescription('The current primary is not eligible. A free eligible primary on this node will be selected. Saving in live mode will change the server port; dry-run mode only stores a proposal.')
                    ->action(function (Set $set, Server $record) {
                        $chosen = self::selector()->choose(self::allocations(), $record->node_id, $record->id, $record->allocation_id);
                        $set('mc_router_allocation_id', $chosen['id']);
                        $set('mc_router_confirmed_allocation', $chosen['id']);
                    }),
                Action::make('mc_router_confirm_change')->label('Confirm this primary allocation change')->color('warning')
                    ->icon(TablerIcon::ArrowsExchange)
                    ->visible(fn (Get $get, Server $record) => $get('mc_router_enabled') && $get('mc_router_allocation_id')
                        && (int) $get('mc_router_allocation_id') !== $record->allocation_id
                        && (int) $get('mc_router_confirmed_allocation') !== (int) $get('mc_router_allocation_id'))
                    ->requiresConfirmation()->modalHeading('Change this server’s primary allocation?')
                    ->modalDescription(fn (Get $get, Server $record) => 'Current: ' . $record->allocation?->address . '. Proposed: ' . Allocation::find($get('mc_router_allocation_id'))?->address . '. Saving in live mode changes the server port.')
                    ->action(fn (Get $get, Set $set) => $set('mc_router_confirmed_allocation', $get('mc_router_allocation_id'))),
            ])->key('mc-router-actions')->columnSpanFull();
        }

        return $fields;
    }
}
