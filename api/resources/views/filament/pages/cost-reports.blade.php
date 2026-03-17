<x-filament-panels::page>
    <div class="space-y-6">
        @php $summary = $this->getSummary(); @endphp

        {{-- Summary Stats --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">COGS Summaries</p>
                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $summary['cogs_summaries_count'] }}</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg $/Bottle</p>
                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $summary['avg_cost_per_bottle'] }}</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Bottles Produced</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['total_bottles_produced'] }}</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Cost Tracked</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['total_cost_tracked'] }}</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Lots with Costs</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['lots_with_costs'] }}</p>
                </div>
            </x-filament::section>
        </div>

        {{-- COGS by Lot Table --}}
        <x-filament::section heading="COGS by Lot">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Per-lot COGS summaries generated at bottling completion. Filterable by vintage and variety.
            </p>

            {{ $this->table }}
        </x-filament::section>

        {{-- Cost by Vintage Summary --}}
        @php $vintageData = $this->getCostByVintage(); @endphp
        @if($vintageData->isNotEmpty())
            <x-filament::section heading="Cost Summary by Vintage">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Vintage</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Lots</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Total Cost</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Total Bottles</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Avg $/Bottle</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Avg $/Gallon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vintageData as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-4 py-2 font-bold text-gray-900 dark:text-gray-100">{{ $row->vintage }}</td>
                                    <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $row->lot_count }}</td>
                                    <td class="px-4 py-2 text-right font-medium text-gray-900 dark:text-gray-100">{{ $row->total_cost }}</td>
                                    <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $row->total_bottles }}</td>
                                    <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $row->avg_cost_per_bottle }}</td>
                                    <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $row->avg_cost_per_gallon }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Margin Report --}}
        @php $marginData = $this->getMarginReport(); @endphp
        @if($marginData->isNotEmpty())
            <x-filament::section heading="Margin Report">
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Selling price vs. COGS by SKU. Only active SKUs with both price and cost data shown.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Wine</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Vintage</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Varietal</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Price</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">COGS/Bottle</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Margin $</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Margin %</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($marginData as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-4 py-2 font-bold text-gray-900 dark:text-gray-100">{{ $row->wine_name }}</td>
                                    <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $row->vintage }}</td>
                                    <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ $row->varietal }}</td>
                                    <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $row->price }}</td>
                                    <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $row->cost_per_bottle }}</td>
                                    <td class="px-4 py-2 text-right font-medium text-gray-900 dark:text-gray-100">{{ $row->margin_dollars }}</td>
                                    <td class="px-4 py-2 text-right font-bold {{ $row->margin_class }}">{{ $row->gross_margin }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
