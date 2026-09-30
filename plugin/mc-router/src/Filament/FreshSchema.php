<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Filament;

use Filament\Schemas\Schema;

/** Panel stores custom components statically; never retain another Livewire instance's child state. */
trait FreshSchema
{
    public function container(Schema $schema): static
    {
        unset($this->rootContainer);
        $this->clearCachedChildSchemas();
        return parent::container($schema);
    }
}
