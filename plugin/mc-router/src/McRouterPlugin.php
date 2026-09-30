<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Kynzo\McRouter\Filament\Admin\Pages\RoutingPreview;

final class McRouterPlugin implements Plugin
{
    public function getId(): string
    {
        return 'mc-router';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            $panel->pages([RoutingPreview::class]);
        }
    }

    public function boot(Panel $panel): void {}
}
