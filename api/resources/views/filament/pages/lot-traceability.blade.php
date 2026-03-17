<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
        <h2 class="text-lg font-bold mb-4">Select a Lot to Trace</h2>
        <div class="flex gap-4 items-end">
            <div class="flex-1">
                <select
                    wire:model="selectedLotId"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700"
                >
                    <option value="">-- Select a lot --</option>
                    @foreach($this->getLotOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button
                wire:click="runTrace"
                class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg"
            >
                Trace
            </button>
        </div>
    </div>

    @if(!empty($this->traceData))
        {{-- Lot Info --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
            <h3 class="text-lg font-bold mb-2">
                {{ $this->traceData['lot']['name'] }}
            </h3>
            <p class="text-sm text-gray-500">
                {{ $this->traceData['lot']['variety'] ?? 'Unknown variety' }}
                | Vintage: {{ $this->traceData['lot']['vintage'] ?? 'N/A' }}
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            {{-- Backward Trace --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h3 class="text-lg font-bold mb-4 text-blue-600">
                    Backward Trace (Source)
                </h3>
                @if(count($this->traceData['backward']) > 0)
                    <div class="space-y-3">
                        @foreach($this->traceData['backward'] as $step)
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3">
                                <div class="flex justify-between">
                                    <span class="font-semibold text-sm">{{ $step['description'] }}</span>
                                    <span class="text-xs text-gray-500">{{ $step['performed_at'] ? \Carbon\Carbon::parse($step['performed_at'])->format('M j, Y') : '' }}</span>
                                </div>
                                <span class="text-xs text-gray-400">{{ $step['type'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400 italic">No backward trace data found.</p>
                @endif
            </div>

            {{-- Forward Trace --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h3 class="text-lg font-bold mb-4 text-green-600">
                    Forward Trace (Destination)
                </h3>
                @if(count($this->traceData['forward']) > 0)
                    <div class="space-y-3">
                        @foreach($this->traceData['forward'] as $step)
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                                <div class="flex justify-between">
                                    <span class="font-semibold text-sm">{{ $step['description'] }}</span>
                                    <span class="text-xs text-gray-500">{{ $step['performed_at'] ? \Carbon\Carbon::parse($step['performed_at'])->format('M j, Y') : '' }}</span>
                                </div>
                                <span class="text-xs text-gray-400">{{ $step['type'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400 italic">No forward trace data found.</p>
                @endif
            </div>
        </div>

        {{-- Timeline --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
            <h3 class="text-lg font-bold mb-4">Complete Timeline</h3>
            @if(count($this->traceData['timeline']) > 0)
                <div class="relative">
                    <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200 dark:bg-gray-600"></div>
                    <div class="space-y-4">
                        @foreach($this->traceData['timeline'] as $event)
                            <div class="relative pl-10">
                                <div class="absolute left-2.5 w-3 h-3 rounded-full bg-blue-500 border-2 border-white dark:border-gray-800"></div>
                                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                                    <div class="flex justify-between">
                                        <span class="font-semibold text-sm">{{ $event['description'] }}</span>
                                        <span class="text-xs text-gray-500">
                                            {{ $event['performed_at'] ? \Carbon\Carbon::parse($event['performed_at'])->format('M j, Y g:i A') : '' }}
                                        </span>
                                    </div>
                                    <span class="text-xs text-gray-400 font-mono">{{ $event['type'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-400 italic">No events found for this lot.</p>
            @endif
        </div>
    @endif
</x-filament-panels::page>
