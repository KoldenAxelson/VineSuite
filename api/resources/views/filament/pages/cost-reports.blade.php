<x-filament-panels::page>
    {{-- KPI stats rendered by getHeaderWidgets() → CostReportsStatsWidget --}}

    <x-filament::section heading="COGS by Lot">
        <x-slot name="description">
            Per-lot COGS summaries generated at bottling completion. Filterable by vintage and variety.
        </x-slot>
        {{ $this->table }}
    </x-filament::section>

    {{-- Vintage summary and Margin Report rendered by getFooterWidgets() --}}
</x-filament-panels::page>
