<x-filament-panels::page>
    <div class="space-y-6">
        @if($this->countId && $summary = $this->getCountSummary())
            {{-- Detail view: count session summary cards --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Status</div>
                    <div class="text-lg font-semibold">
                        @php
                            $statusColors = ['in_progress' => 'text-yellow-600', 'completed' => 'text-green-600', 'cancelled' => 'text-red-600'];
                        @endphp
                        <span class="{{ $statusColors[$summary['status']] ?? '' }}">
                            {{ ucfirst(str_replace('_', ' ', $summary['status'])) }}
                        </span>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Progress</div>
                    <div class="text-lg font-semibold">{{ $summary['progress'] }}</div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Variances Found</div>
                    <div class="text-lg font-semibold {{ $summary['variances'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ $summary['variances'] }}
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Started</div>
                    <div class="text-sm font-medium">{{ $summary['started_at'] }}</div>
                    <div class="text-xs text-gray-400">by {{ $summary['started_by'] }}</div>
                    @if($summary['completed_at'])
                        <div class="text-sm font-medium mt-1">Completed {{ $summary['completed_at'] }}</div>
                        <div class="text-xs text-gray-400">by {{ $summary['completed_by'] }}</div>
                    @endif
                </x-filament::section>
            </div>

            @if($summary['notes'])
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Notes</div>
                    <div class="text-sm">{{ $summary['notes'] }}</div>
                </x-filament::section>
            @endif
        @else
            {{-- List view: header --}}
            <x-filament::section heading="Physical Inventory Counts">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Start a count session for a location, enter actual quantities, review variances, and approve adjustments.
                    Click "View Lines" on any count to see the SKU-by-SKU breakdown.
                </p>
            </x-filament::section>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
