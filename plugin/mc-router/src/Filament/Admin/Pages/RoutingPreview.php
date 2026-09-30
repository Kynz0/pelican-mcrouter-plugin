<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Filament\Admin\Pages;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use Kynzo\McRouter\Domain\AllocationSelector;
use Kynzo\McRouter\Domain\Hostname;

/** Isolated UI spike: reads Panel models; never changes allocations or router routes. */
final class RoutingPreview extends Page
{
    protected static ?string $navigationLabel = 'MC Router (prototype)';
    protected static ?string $title = 'MC Router — dry-run preview';
    protected static ?string $slug = 'mc-router-preview';
    protected string $view = 'mc-router::routing-preview';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return user()?->isRootAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('server_id')->label('Existing server (optional)')
                ->options(fn () => Server::orderBy('name')->pluck('name', 'id'))->searchable()->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    $server = Server::find($state);
                    $set('node_id', $server?->node_id);
                    $set('name', $server?->name ?? '');
                    $set('slug', Hostname::suggest($server?->name ?? ''));
                    $set('slug_edited', false);
                    $set('allocation_id', null);
                    $set('confirm_reallocation', false);
                }),
            TextInput::make('name')->label('Server name')->live(onBlur: true)
                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                    if (!$get('slug_edited')) {
                        $set('slug', Hostname::suggest($state ?? ''));
                    }
                }),
            TextInput::make('slug')->label('Hostname label')->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set) => $set('slug_edited', true)),
            Select::make('node_id')->label('Wings node')->options(fn () => Node::orderBy('name')->pluck('name', 'id'))
                ->disabled(fn (Get $get) => (bool) $get('server_id'))->live()
                ->afterStateUpdated(function (Set $set) {
                    $set('allocation_id', null);
                    $set('confirm_reallocation', false);
                }),
            Select::make('allocation_id')->label('Optional allocation override')
                ->placeholder('Automatically choose (keep eligible current allocation)')->live()
                ->afterStateUpdated(fn (Set $set) => $set('confirm_reallocation', false))
                ->options(function (Get $get) {
                    $options = $this->selector()->options($this->allocations(), (int) $get('node_id'), $get('server_id') ? (int) $get('server_id') : null);

                    return collect($options)->mapWithKeys(fn ($a) => [$a['id'] => AllocationSelector::backend($a)])->all();
                }),
            Toggle::make('confirm_reallocation')->label('I would allow selecting a different eligible primary allocation')->live()
                ->helperText('Preview only. No server configuration, allocation or route will be changed.'),
        ]);
    }

    public function preview(): array
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->data ?? [];
        try {
            $hostname = Hostname::make($data['slug'] ?? '', config('mc-router.suffix'), config('mc-router.reserved'));
            $server = Server::find($data['server_id'] ?? null);
            $nodeId = $server?->node_id ?? (int) ($data['node_id'] ?? 0);
            $allocation = $this->selector()->choose($this->allocations(), $nodeId, $server?->id, $server?->allocation_id,
                empty($data['allocation_id']) ? null : (int) $data['allocation_id']);
            $needsConfirmation = $server && $allocation['id'] !== $server->allocation_id && !($data['confirm_reallocation'] ?? false);

            return [
                'hostname' => $hostname,
                'backend' => AllocationSelector::backend($allocation),
                'message' => $needsConfirmation ? 'Confirmation required before changing this existing server’s primary allocation.' : 'Dry-run only: proposed route; not applied or reserved.',
            ];
        } catch (InvalidArgumentException $exception) {
            return ['message' => $exception->getMessage()];
        }
    }

    private function selector(): AllocationSelector
    {
        return new AllocationSelector(config('mc-router.pools', []));
    }

    private function allocations(): array
    {
        return Allocation::whereIn('node_id', array_keys(config('mc-router.pools', [])))
            ->get(['id', 'node_id', 'server_id', 'ip', 'port'])->toArray();
    }
}
