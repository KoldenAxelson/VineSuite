<x-filament-panels::page>
    {{-- Lot Selector --}}
    <x-filament::section heading="Select a Lot to Trace">
        <div class="flex gap-4 items-end">
            <div class="flex-1">
                <select
                    wire:model="selectedLotId"
                    class="fi-input block w-full rounded-lg border-none bg-white shadow-sm ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:ring-white/20 dark:focus:ring-primary-500 sm:text-sm"
                >
                    <option value="">-- Select a lot --</option>
                    @foreach($this->getLotOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <x-filament::button wire:click="runTrace" icon="heroicon-m-magnifying-glass">
                Trace
            </x-filament::button>
        </div>
    </x-filament::section>

    @if(!empty($this->traceData))
        {{-- Lot Info --}}
        <x-filament::section>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                {{ $this->traceData['lot']['name'] }}
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ $this->traceData['lot']['variety'] ?? 'Unknown variety' }}
                | Vintage: {{ $this->traceData['lot']['vintage'] ?? 'N/A' }}
            </p>
        </x-filament::section>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- Backward Trace --}}
            <x-filament::section>
                <x-slot name="heading">
                    <span class="text-blue-600 dark:text-blue-400">Backward Trace (Source)</span>
                </x-slot>
                @if(count($this->traceData['backward']) > 0)
                    <div class="space-y-3">
                        @foreach($this->traceData['backward'] as $step)
                            <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 ring-1 ring-gray-950/5 dark:ring-white/10">
                                <div class="flex justify-between">
                                    <span class="font-semibold text-sm text-gray-950 dark:text-white">{{ $step['description'] }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $step['performed_at'] ? \Carbon\Carbon::parse($step['performed_at'])->format('M j, Y') : '' }}</span>
                                </div>
                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $step['type'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No backward trace data found.</p>
                @endif
            </x-filament::section>

            {{-- Forward Trace --}}
            <x-filament::section>
                <x-slot name="heading">
                    <span class="text-emerald-600 dark:text-emerald-400">Forward Trace (Destination)</span>
                </x-slot>
                @if(count($this->traceData['forward']) > 0)
                    <div class="space-y-3">
                        @foreach($this->traceData['forward'] as $step)
                            <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 ring-1 ring-gray-950/5 dark:ring-white/10">
                                <div class="flex justify-between">
                                    <span class="font-semibold text-sm text-gray-950 dark:text-white">{{ $step['description'] }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $step['performed_at'] ? \Carbon\Carbon::parse($step['performed_at'])->format('M j, Y') : '' }}</span>
                                </div>
                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $step['type'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No forward trace data found.</p>
                @endif
            </x-filament::section>
        </div>

        {{-- Timeline --}}
        <x-filament::section heading="Complete Timeline">
            @if(count($this->traceData['timeline']) > 0)
                <div class="relative">
                    <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200 dark:bg-gray-700"></div>
                    <div class="space-y-4">
                        @foreach($this->traceData['timeline'] as $index => $event)
                            <div class="relative pl-10">
                                <div class="absolute left-2.5 w-3 h-3 rounded-full border-2 border-white dark:border-gray-900 {{ $index === 0 ? 'bg-primary-500' : 'bg-gray-300 dark:bg-gray-600' }}"></div>
                                <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 ring-1 ring-gray-950/5 dark:ring-white/10">
                                    <div class="flex justify-between flex-wrap gap-2">
                                        <span class="font-semibold text-sm text-gray-950 dark:text-white">{{ $event['description'] }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $event['performed_at'] ? \Carbon\Carbon::parse($event['performed_at'])->format('M j, Y g:i A') : '' }}
                                        </span>
                                    </div>
                                    <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $event['type'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-400 dark:text-gray-500 italic">No events found for this lot.</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
