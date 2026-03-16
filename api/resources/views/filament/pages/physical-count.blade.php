<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Physical Inventory Counts">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Start a count session for a location, enter actual quantities, review variances, and approve adjustments.
                Use the API endpoints to manage count sessions programmatically.
            </p>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
