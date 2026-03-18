<x-filament-panels::page>
    {{-- Stats rendered by getHeaderWidgets() when viewing a specific count --}}

    @if(! $this->countId)
        <x-filament::section heading="Physical Inventory Counts">
            <x-slot name="description">
                Start a count session for a location, enter actual quantities, review variances, and approve adjustments.
                Click "View Lines" on any count to see the SKU-by-SKU breakdown.
            </x-slot>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
