<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkOrderResource\Pages;

use App\Filament\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Resources\Pages\Page;

class CalendarWorkOrders extends Page
{
    protected static string $resource = WorkOrderResource::class;

    protected string $view = 'filament.resources.work-order-resource.pages.calendar-work-orders';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?int $navigationSort = 1;

    public function getViewData(): array
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        $workOrders = WorkOrder::whereBetween('due_date', [$startOfWeek, $endOfWeek])
            ->with(['lot', 'assignedUser'])
            ->get();

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $weekDays[$date->format('Y-m-d')] = [
                'date' => $date,
                'workOrders' => $workOrders->filter(fn (WorkOrder $wo) => $wo->due_date->format('Y-m-d') === $date->format('Y-m-d')),
            ];
        }

        return [
            'weekDays' => $weekDays,
            'startOfWeek' => $startOfWeek,
            'endOfWeek' => $endOfWeek,
            'previousWeekStart' => $startOfWeek->copy()->subWeek(),
            'nextWeekStart' => $startOfWeek->copy()->addWeek(),
        ];
    }
}
