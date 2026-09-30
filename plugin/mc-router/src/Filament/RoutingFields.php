<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Filament;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Kynzo\McRouter\Domain\AllocationSelector;
use Kynzo\McRouter\Domain\Hostname;
use Kynzo\McRouter\Models\RouteRecord;
use Kynzo\McRouter\Services\RouteManager;
use Kynzo\McRouter\Services\Settings;

final class RoutingFields
{
    public static function record(?Server $server): ?RouteRecord
    {
        return $server ? RouteRecord::where('server_uuid', $server->uuid)->first() : null;
    }

    public static function allocations(): array
    {
        return Allocation::whereIn('node_id', array_keys(app(Settings::class)->get()['pools']))
            ->get(['id', 'node_id', 'server_id', 'ip', 'port'])->toArray();
    }

    public static function selector(): AllocationSelector
    {
        return new AllocationSelector(app(Settings::class)->get()['pools']);
    }

    public static function fields(bool $creating): array
    {
        $fields = [
            Text::make(fn () => app(Settings::class)->get()['mode'] === 'dry-run'
                ? 'Dry-run: routing choices can be saved, but no existing server ports or mc-router routes will be changed.'
                : 'Routing uses mc-router. Active means runtime mapping accepted, not Minecraft online or guaranteed disk persistence.'),
            Toggle::make('mc_router_enabled')->label('Use mc-router')->live()->default(fn (?Server $record) => self::record($record)?->enabled ?? false)
                ->dehydrated($creating)
                ->afterStateUpdated(function ($state, Get $get, Set $set, ?Server $record, $livewire) use ($creating) {
                    $set('mc_router_confirmed_allocation', null);
                    if (!$state) {
                        if ($creating && $get('mc_router_saved_normal')) {
                            $set('node_id', $get('mc_router_normal_node'));
                            $set('allocation_id', $get('mc_router_normal_allocation'));
                            $set('allocation_additional', $get('mc_router_normal_additional'));
                            $livewire->node = Node::find($get('node_id'));
                        }
                        return;
                    }
                    if ($creating) {
                        $set('mc_router_normal_node', $get('node_id'));
                        $set('mc_router_normal_allocation', $get('allocation_id'));
                        $set('mc_router_normal_additional', $get('allocation_additional'));
                        $set('mc_router_saved_normal', true);
                        $nodeId = (int) $get('mc_router_node_id');
                        $set('node_id', $nodeId);
                        $livewire->node = Node::find($nodeId);
                    } else {
                        $nodeId = $record->node_id;
                    }
                    $current = $creating ? $get('allocation_id') : $record->allocation_id;
                    $options = self::selector()->options(self::allocations(), $nodeId, $record?->id);
                    $kept = collect($options)->firstWhere('id', (int) $current);
                    $chosen = $kept ?? ($creating ? (collect($options)->first()) : null);
                    $set('mc_router_allocation_id', $chosen['id'] ?? null);
                    if ($creating) {
                        $set('allocation_id', $chosen['id'] ?? null);
                        if (($chosen['id'] ?? null) !== (int) $get('mc_router_normal_allocation') || $nodeId !== (int) $get('mc_router_normal_node')) {
                            $set('allocation_additional', null);
                        }
                    }
                    if (!$get('mc_router_slug_edited')) {
                        $set('mc_router_slug', Hostname::suggest($record?->name ?? $get('name') ?? 'minecraft'));
                    }
                }),
            Hidden::make('mc_router_slug_edited')->default(false)->dehydrated(false),
            Hidden::make('mc_router_confirmed_allocation')->dehydrated(false),
            Hidden::make('mc_router_normal_node')->dehydrated(false),
            Hidden::make('mc_router_normal_allocation')->dehydrated(false),
            Hidden::make('mc_router_normal_additional')->dehydrated(false),
            Hidden::make('mc_router_saved_normal')->dehydrated(false),
        ];
        if ($creating) {
            $fields[] = Select::make('mc_router_node_id')->label('Wings node')->live()->dehydrated(false)
                ->visible(fn (Get $get) => (bool) $get('mc_router_enabled'))
                ->options(fn () => user()?->accessibleNodes()->whereIn('id', array_keys(app(Settings::class)->get()['pools']))->pluck('name', 'id') ?? [])
                ->default(fn () => user()?->accessibleNodes()->whereIn('id', array_keys(app(Settings::class)->get()['pools']))->orderBy('id')->value('id'))
                ->afterStateUpdated(function ($state, Get $get, Set $set, $livewire) {
                    $set('node_id', $state);
                    $livewire->node = Node::find($state);
                    $chosen = self::selector()->options(self::allocations(), (int) $state)[0] ?? null;
                    $set('mc_router_allocation_id', $chosen['id'] ?? null);
                    $set('allocation_id', $chosen['id'] ?? null);
                    $set('allocation_additional', null);
                });
        }
        $fields[] = TextInput::make('mc_router_slug')->label('Hostname label')->live(onBlur: true)->maxLength(63)
            ->default(fn (?Server $record) => self::record($record) ? explode('.', self::record($record)->hostname)[0] : Hostname::suggest($record?->name ?? 'minecraft'))
            ->visible(fn (Get $get) => (bool) $get('mc_router_enabled'))->dehydrated($creating)
            ->required(fn (Get $get) => $creating && $get('mc_router_enabled'))
            ->afterStateUpdated(fn (Set $set) => $set('mc_router_slug_edited', true));
        $fields[] = Select::make('mc_router_allocation_id')->label('Primary allocation')->live()->dehydrated($creating)
            ->visible(fn (Get $get) => (bool) $get('mc_router_enabled'))
            ->placeholder('No eligible primary selected')->selectablePlaceholder(false)
            ->default(function (?Server $record) {
                if (!$record) { return null; }
                $route = self::record($record);
                $preferred = $route?->enabled ? ($route->desired_allocation_id ?? $record->allocation_id) : $record->allocation_id;
                return collect(self::selector()->options(self::allocations(), $record->node_id, $record->id))->firstWhere('id', $preferred)['id'] ?? null;
            })
            ->options(fn (Get $get, ?Server $record) => collect(self::selector()->options(self::allocations(), $record?->node_id ?? (int) $get('mc_router_node_id'), $record?->id))
                ->mapWithKeys(fn ($a) => [$a['id'] => AllocationSelector::backend($a)]))
            ->required(fn (Get $get) => $creating && $get('mc_router_enabled'))
            ->afterStateUpdated(function ($state, Set $set) use ($creating) {
                $set('mc_router_confirmed_allocation', null);
                if ($creating) {
                    $set('allocation_id', $state);
                    $set('allocation_additional', null);
                }
            });
        if (!$creating) {
            $fields[] = Actions::make([
                Action::make('mc_router_select_replacement')->label('Automatically select eligible replacement')
                    ->visible(fn (Get $get) => $get('mc_router_enabled') && !$get('mc_router_allocation_id'))
                    ->requiresConfirmation()->modalHeading('Replace the current primary allocation?')
                    ->modalDescription('The current primary is not eligible. Select a free eligible primary on this node. Saving in live mode will change the server port; dry-run mode only stores a proposal.')
                    ->action(function (Set $set, Server $record) {
                        $chosen = self::selector()->choose(self::allocations(), $record->node_id, $record->id, $record->allocation_id);
                        $set('mc_router_allocation_id', $chosen['id']);
                        $set('mc_router_confirmed_allocation', $chosen['id']);
                    }),
                Action::make('mc_router_confirm_change')->label('Confirm this primary allocation change')
                    ->visible(fn (Get $get, Server $record) => $get('mc_router_enabled') && $get('mc_router_allocation_id')
                        && (int) $get('mc_router_allocation_id') !== $record->allocation_id
                        && (int) $get('mc_router_confirmed_allocation') !== (int) $get('mc_router_allocation_id'))
                    ->requiresConfirmation()->modalHeading('Change this server’s primary allocation?')
                    ->modalDescription(fn (Get $get, Server $record) => 'Current: ' . $record->allocation?->address . '. Proposed: ' . Allocation::find($get('mc_router_allocation_id'))?->address . '. Saving in live mode changes the server port.')
                    ->action(fn (Get $get, Set $set) => $set('mc_router_confirmed_allocation', $get('mc_router_allocation_id'))),
                Action::make('mc_router_save')->label('Save MC Router settings')->color('primary')
                    ->action(function (Get $get, Server $record) {
                        abort_unless(user()?->isRootAdmin(), 403);
                        app(RouteManager::class)->save($record, (bool) $get('mc_router_enabled'), (string) $get('mc_router_slug'),
                            (int) $get('mc_router_allocation_id'), (int) $get('mc_router_confirmed_allocation'));
                        Notification::make()->title('MC Router settings saved')->success()->send();
                    }),
            ])->key('mc-router-actions');
        }
        $fields[] = Text::make(function (Get $get, ?Server $record) use ($creating) {
            if (!$get('mc_router_enabled')) { return 'mc-router is disabled. Normal Pelican allocation behavior is unchanged.'; }
            try {
                $hostname = Hostname::make((string) $get('mc_router_slug'), app(Settings::class)->get()['suffix'], config('mc-router.reserved', []));
                $allocation = Allocation::find($get('mc_router_allocation_id'));
                $route = self::record($record);
                $status = $route ? " Stored status: {$route->status}. " . ($route->last_error ?? '') : '';
                $hint = !$allocation ? ' No eligible primary selected; approve automatic replacement or choose one.' : '';
                if ($creating && (int) $get('allocation_id') !== (int) $get('mc_router_allocation_id')) {
                    $hint = ' The Information step’s primary differs. Return here to choose the final eligible primary; creation will reject mismatched choices.';
                }
                return 'Join address: ' . $hostname . ' (public TCP 25565).' . $hint . $status;
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }
        })->columnSpanFull();

        return $fields;
    }
}
