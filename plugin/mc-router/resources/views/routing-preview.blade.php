{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-filament-panels::page>
    <p>This prototype only previews routing. It never creates routes, reserves ports or changes servers.</p>
    {{ $this->form }}
    @php($preview = $this->preview())
    <x-filament::section heading="Proposed route">
        @if(isset($preview['hostname']))
            <p>Join address: <strong>{{ $preview['hostname'] }}</strong> (public TCP 25565)</p>
            <p>Backend: <code>{{ $preview['backend'] }}</code></p>
        @endif
        <p>{{ $preview['message'] }}</p>
    </x-filament::section>
</x-filament-panels::page>
