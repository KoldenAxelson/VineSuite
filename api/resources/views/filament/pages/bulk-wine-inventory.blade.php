<x-filament-panels::page>
    <div class="space-y-6">
        {{-- KPI stats are rendered by getHeaderWidgets() → BulkWineStatsWidget --}}

        {{-- Bulk Wine Table --}}
        <x-filament::section heading="Bulk Wine by Lot">
            <x-slot name="description">
                Active and aging lots with their current vessel contents and book-to-vessel variance.
            </x-slot>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
