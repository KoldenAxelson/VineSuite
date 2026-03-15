@php
    use App\Models\WorkOrder;

    $statusColors = [
        'pending' => 'bg-yellow-100 text-yellow-800',
        'in_progress' => 'bg-blue-100 text-blue-800',
        'completed' => 'bg-green-100 text-green-800',
        'skipped' => 'bg-gray-100 text-gray-800',
    ];

    $priorityColors = [
        'high' => 'border-red-400',
        'normal' => 'border-blue-400',
        'low' => 'border-green-400',
    ];
@endphp

<div class="space-y-6 p-6">
    <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold tracking-tight text-gray-900">Work Orders Calendar</h2>
        <div class="flex items-center gap-4">
            <a href="{{ route('filament.admin.resources.work-orders.calendar', ['week' => $previousWeekStart->format('Y-m-d')]) }}"
               class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                ← Previous Week
            </a>
            <span class="text-sm font-medium text-gray-700">
                {{ $startOfWeek->format('M d') }} - {{ $endOfWeek->format('M d, Y') }}
            </span>
            <a href="{{ route('filament.admin.resources.work-orders.calendar', ['week' => $nextWeekStart->format('Y-m-d')]) }}"
               class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                Next Week →
            </a>
        </div>
    </div>

    <div class="grid grid-cols-7 gap-4">
        @foreach($weekDays as $dateString => $day)
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden shadow-sm">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                    <p class="font-semibold text-gray-900">
                        {{ $day['date']->format('D') }}
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ $day['date']->format('M d') }}
                    </p>
                </div>

                <div class="p-3 min-h-96 space-y-2">
                    @forelse($day['workOrders'] as $workOrder)
                        <div class="p-3 border-l-4 rounded bg-gray-50 {{ $priorityColors[$workOrder->priority] ?? 'border-gray-400' }}">
                            <p class="text-xs font-semibold text-gray-700 line-clamp-2">
                                {{ $workOrder->operation_type }}
                            </p>
                            <p class="text-xs text-gray-600 mt-1">
                                {{ $workOrder->lot?->name ?? 'No Lot' }}
                            </p>
                            @if($workOrder->assignedUser)
                                <p class="text-xs text-gray-500 mt-1">
                                    👤 {{ $workOrder->assignedUser->name }}
                                </p>
                            @endif
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span class="inline-block px-2 py-1 text-xs font-medium rounded {{ $statusColors[$workOrder->status] ?? 'bg-gray-100 text-gray-800' }}">
                                    {{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}
                                </span>
                            </div>
                            <a href="{{ route('filament.admin.resources.work-orders.edit', $workOrder) }}"
                               class="text-xs text-blue-600 hover:text-blue-800 mt-2 inline-block">
                                View Details →
                            </a>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 text-center py-8">
                            No work orders
                        </p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <h3 class="font-semibold text-gray-900 mb-3">Legend</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="flex items-center gap-2">
                <span class="inline-block w-3 h-3 border-l-4 border-red-400 rounded"></span>
                <span class="text-sm text-gray-700">High Priority</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-block w-3 h-3 border-l-4 border-blue-400 rounded"></span>
                <span class="text-sm text-gray-700">Normal Priority</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-block w-3 h-3 border-l-4 border-green-400 rounded"></span>
                <span class="text-sm text-gray-700">Low Priority</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-block px-2 py-1 text-xs font-medium rounded bg-green-100 text-green-800"></span>
                <span class="text-sm text-gray-700">Completed</span>
            </div>
        </div>
    </div>
</div>
