@php
    use App\Models\WorkOrder;

    $statusColors = [
        'pending' => 'warning',
        'in_progress' => 'info',
        'completed' => 'success',
        'skipped' => 'gray',
    ];

    $priorityBorders = [
        'high' => 'border-red-400 dark:border-red-500',
        'normal' => 'border-blue-400 dark:border-blue-500',
        'low' => 'border-green-400 dark:border-green-500',
    ];
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Navigation Header --}}
        <x-filament::section>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">Work Orders Calendar</h2>
                <div class="flex items-center gap-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.work-orders.calendar', ['week' => $previousWeekStart->format('Y-m-d')]) }}"
                        color="gray"
                        size="sm"
                    >
                        Previous Week
                    </x-filament::button>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $startOfWeek->format('M d') }} - {{ $endOfWeek->format('M d, Y') }}
                    </span>
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.work-orders.calendar', ['week' => $nextWeekStart->format('Y-m-d')]) }}"
                        color="gray"
                        size="sm"
                    >
                        Next Week
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        {{-- Calendar Grid --}}
        <div class="grid grid-cols-7 gap-4">
            @foreach($weekDays as $dateString => $day)
                <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                    <div class="bg-gray-50 dark:bg-white/5 px-4 py-3 border-b border-gray-200 dark:border-white/10">
                        <p class="font-semibold text-gray-950 dark:text-white">
                            {{ $day['date']->format('D') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $day['date']->format('M d') }}
                        </p>
                    </div>

                    <div class="p-3 min-h-96 space-y-2">
                        @forelse($day['workOrders'] as $workOrder)
                            <div class="p-3 border-l-4 rounded-lg bg-gray-50 dark:bg-white/5 {{ $priorityBorders[$workOrder->priority] ?? 'border-gray-400 dark:border-gray-600' }}">
                                <p class="text-xs font-semibold text-gray-950 dark:text-white line-clamp-2">
                                    {{ $workOrder->operation_type }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    {{ $workOrder->lot?->name ?? 'No Lot' }}
                                </p>
                                @if($workOrder->assignedUser)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        {{ $workOrder->assignedUser->name }}
                                    </p>
                                @endif
                                <div class="mt-2">
                                    <x-filament::badge :color="$statusColors[$workOrder->status] ?? 'gray'" size="sm">
                                        {{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}
                                    </x-filament::badge>
                                </div>
                                <a href="{{ route('filament.admin.resources.work-orders.edit', $workOrder) }}"
                                   class="text-xs text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300 mt-2 inline-block">
                                    View Details
                                </a>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-8">
                                No work orders
                            </p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Legend --}}
        <x-filament::section heading="Legend">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 h-3 border-l-4 border-red-400 dark:border-red-500 rounded"></span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">High Priority</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 h-3 border-l-4 border-blue-400 dark:border-blue-500 rounded"></span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">Normal Priority</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 h-3 border-l-4 border-green-400 dark:border-green-500 rounded"></span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">Low Priority</span>
                </div>
                <div class="flex items-center gap-2">
                    <x-filament::badge color="success" size="sm">Completed</x-filament::badge>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
