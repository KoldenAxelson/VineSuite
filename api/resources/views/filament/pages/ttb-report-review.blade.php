<x-filament-panels::page>
    {{-- Report Header --}}
    <x-filament::section>
        <div class="flex justify-between items-start flex-wrap gap-4">
            <div>
                <h2 class="fi-sc-section-label">
                    TTB Form 5120.17 — {{ $this->record->periodLabel() }}
                </h2>
                <div class="flex items-center gap-3 mt-1 flex-wrap">
                    <span class="fi-wi-stats-overview-stat-description">
                        Generated: {{ $this->record->generated_at?->format('M j, Y g:i A') }}
                    </span>
                    <x-filament::badge
                        :color="match($this->record->status) {
                            'draft'    => 'warning',
                            'reviewed' => 'info',
                            'filed'    => 'success',
                            default    => 'gray',
                        }"
                    >
                        {{ ucfirst($this->record->status) }}
                    </x-filament::badge>
                </div>
            </div>
            @if($this->record->canReview())
                <x-filament::button
                    wire:click="approveReport"
                    wire:confirm="Are you sure you want to approve this report for filing?"
                    color="success"
                    icon="heroicon-m-check-circle"
                >
                    Approve for Filing
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

    {{-- Review Flags --}}
    @if(count($this->getReviewFlags()) > 0)
        <x-filament::section icon="heroicon-m-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Items Requiring Review</x-slot>
            @foreach($this->getReviewFlags() as $flag)
                <p class="fi-wi-stats-overview-stat-description">{{ $flag }}</p>
            @endforeach
        </x-filament::section>
    @endif

    {{-- Stats (Section A & B) rendered by getHeaderWidgets() --}}
    {{-- Line item tables (Section A & B) rendered by getFooterWidgets() --}}

    {{-- Drill-Down Panel --}}
    @if($this->selectedLineId && count($this->drillDownEvents) > 0)
        <x-filament::section icon="heroicon-m-magnifying-glass-circle" heading="Source Events">
            <x-slot name="headerEnd">
                <x-filament::icon-button
                    wire:click="$set('selectedLineId', null)"
                    icon="heroicon-m-x-mark"
                    color="gray"
                    label="Close"
                />
            </x-slot>

            @foreach($this->drillDownEvents as $event)
                <x-filament::section compact>
                    <div class="flex justify-between items-start">
                        <span class="fi-sc-section-label">{{ $event['operation_type'] }}</span>
                        <span class="fi-wi-stats-overview-stat-description">{{ $event['performed_at'] }}</span>
                    </div>
                    <p class="fi-wi-stats-overview-stat-description">{{ $event['entity_type'] }} — {{ $event['id'] }}</p>
                </x-filament::section>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
