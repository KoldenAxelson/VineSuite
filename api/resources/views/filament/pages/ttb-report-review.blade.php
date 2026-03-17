<x-filament-panels::page>
    {{-- Report Header --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
        <div class="flex justify-between items-start">
            <div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                    TTB Form 5120.17 — {{ $this->record->periodLabel() }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Generated: {{ $this->record->generated_at?->format('M j, Y g:i A') }}
                    | Status:
                    <span @class([
                        'font-semibold',
                        'text-yellow-600' => $this->record->status === 'draft',
                        'text-blue-600' => $this->record->status === 'reviewed',
                        'text-green-600' => $this->record->status === 'filed',
                    ])>
                        {{ ucfirst($this->record->status) }}
                    </span>
                </p>
            </div>
            @if($this->record->canReview())
                <button
                    wire:click="approveReport"
                    wire:confirm="Are you sure you want to approve this report for filing?"
                    class="bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg"
                >
                    Approve for Filing
                </button>
            @endif
        </div>
    </div>

    {{-- Review Flags --}}
    @if(count($this->getReviewFlags()) > 0)
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-xl p-4 mb-6">
            <h3 class="font-semibold text-yellow-800 dark:text-yellow-200 mb-2">Items Requiring Review</h3>
            <ul class="list-disc list-inside space-y-1 text-sm text-yellow-700 dark:text-yellow-300">
                @foreach($this->getReviewFlags() as $flag)
                    <li>{{ $flag }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Part I: Summary --}}
    @php $summary = $this->getSummary(); @endphp
    @if(!empty($summary))
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Part I — Summary of Operations</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500 uppercase">Opening Inventory</div>
                    <div class="text-xl font-bold">{{ number_format($summary['opening_inventory'] ?? 0, 1) }}</div>
                    <div class="text-xs text-gray-400">gallons</div>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500 uppercase">Produced</div>
                    <div class="text-xl font-bold text-blue-600">{{ number_format($summary['total_produced'] ?? 0, 1) }}</div>
                    <div class="text-xs text-gray-400">gallons</div>
                </div>
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500 uppercase">Received</div>
                    <div class="text-xl font-bold text-green-600">{{ number_format($summary['total_received'] ?? 0, 1) }}</div>
                    <div class="text-xs text-gray-400">gallons</div>
                </div>
                <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500 uppercase">Total Available</div>
                    <div class="text-xl font-bold text-purple-600">{{ number_format($summary['total_available'] ?? 0, 1) }}</div>
                    <div class="text-xs text-gray-400">gallons</div>
                </div>
                <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500 uppercase">Removed</div>
                    <div class="text-xl font-bold text-orange-600">{{ number_format($summary['total_removed'] ?? 0, 1) }}</div>
                    <div class="text-xs text-gray-400">gallons</div>
                </div>
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500 uppercase">Losses</div>
                    <div class="text-xl font-bold text-red-600">{{ number_format($summary['total_losses'] ?? 0, 1) }}</div>
                    <div class="text-xs text-gray-400">gallons</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-center col-span-2">
                    <div class="text-xs text-gray-500 uppercase">Closing Inventory</div>
                    <div class="text-2xl font-bold">{{ number_format($summary['closing_inventory'] ?? 0, 1) }}</div>
                    <div class="text-xs text-gray-400">gallons</div>
                    @if($summary['balanced'] ?? false)
                        <span class="text-xs text-green-600 font-semibold">Balance verified</span>
                    @else
                        <span class="text-xs text-red-600 font-semibold">BALANCE ERROR</span>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Parts II-V: Line Items --}}
    @php $linesByPart = $this->getLinesByPart(); @endphp
    @foreach(['II' => 'Wine Produced', 'III' => 'Wine Received in Bond', 'IV' => 'Wine Removed from Bond', 'V' => 'Losses'] as $part => $title)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Part {{ $part }} — {{ $title }}</h3>
            @if(isset($linesByPart[$part]) && $linesByPart[$part]->isNotEmpty())
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b dark:border-gray-700">
                            <th class="text-left py-2 px-3">Line</th>
                            <th class="text-left py-2 px-3">Description</th>
                            <th class="text-left py-2 px-3">Wine Type</th>
                            <th class="text-right py-2 px-3">Gallons</th>
                            <th class="text-center py-2 px-3">Events</th>
                            <th class="text-center py-2 px-3">Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($linesByPart[$part] as $line)
                            <tr
                                class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer"
                                wire:click="drillDown('{{ $line->id }}')"
                            >
                                <td class="py-2 px-3 font-mono">{{ $line->line_number }}</td>
                                <td class="py-2 px-3">{{ $line->description }}</td>
                                <td class="py-2 px-3">
                                    <span @class([
                                        'inline-block px-2 py-0.5 rounded text-xs font-semibold',
                                        'bg-blue-100 text-blue-800' => $line->wine_type === 'table',
                                        'bg-amber-100 text-amber-800' => $line->wine_type === 'dessert',
                                        'bg-cyan-100 text-cyan-800' => $line->wine_type === 'sparkling',
                                        'bg-purple-100 text-purple-800' => $line->wine_type === 'special_natural',
                                        'bg-gray-100 text-gray-800' => $line->wine_type === 'all',
                                    ])>
                                        {{ ucfirst(str_replace('_', ' ', $line->wine_type)) }}
                                    </span>
                                </td>
                                <td class="py-2 px-3 text-right font-mono">{{ number_format((float) $line->gallons, 1) }}</td>
                                <td class="py-2 px-3 text-center">
                                    @if(count($line->source_event_ids ?? []) > 0)
                                        <span class="text-blue-600">{{ count($line->source_event_ids) }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-center">
                                    @if($line->needs_review)
                                        <span class="text-yellow-600">⚠</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-sm text-gray-400 italic">No activity in this part for the reporting period.</p>
            @endif
        </div>
    @endforeach

    {{-- Drill-Down Panel --}}
    @if($this->selectedLineId && count($this->drillDownEvents) > 0)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6 border-2 border-blue-300">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Source Events</h3>
                <button
                    wire:click="$set('selectedLineId', null)"
                    class="text-gray-400 hover:text-gray-600"
                >
                    Close
                </button>
            </div>
            <div class="space-y-3">
                @foreach($this->drillDownEvents as $event)
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                        <div class="flex justify-between">
                            <span class="font-mono text-sm font-semibold">{{ $event['operation_type'] }}</span>
                            <span class="text-xs text-gray-500">{{ $event['performed_at'] }}</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">{{ $event['entity_type'] }} — {{ $event['id'] }}</div>
                        @if(!empty($event['payload']))
                            <pre class="text-xs mt-2 bg-gray-100 dark:bg-gray-600 p-2 rounded overflow-x-auto">{{ json_encode($event['payload'], JSON_PRETTY_PRINT) }}</pre>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
