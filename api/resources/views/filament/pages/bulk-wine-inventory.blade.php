<x-filament-panels::page>
    <div class="space-y-6">
        @php $summary = $this->getSummary(); @endphp

        <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Vessel Volume</p>
                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $summary['total_gallons_in_vessels'] }} gal</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Book Volume</p>
                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $summary['total_gallons_book_value'] }} gal</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Variance</p>
                    <p class="text-2xl font-bold {{ (float) str_replace(',', '', $summary['variance_gallons']) != 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400' }}">{{ $summary['variance_gallons'] }} gal</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Active Lots</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['active_lot_count'] }}</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Active Vessels</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['active_vessel_count'] }}</p>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Bulk Wine by Lot">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Active and aging lots with their current vessel contents and book-to-vessel variance.
            </p>

            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
