<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * WorkOrderService — business logic for work order operations.
 *
 * Handles creation (single and bulk), completion, and event logging.
 * Work order completion is the trigger for most event log writes —
 * when a cellar hand completes a "Pump Over" work order, the
 * appropriate event is written to the event log.
 */
class WorkOrderService
{
    public function __construct(
        protected EventLogger $eventLogger,
    ) {}

    /**
     * Create a single work order.
     *
     * @param  array<string, mixed>  $data  Validated work order data
     * @param  string  $createdBy  UUID of the user creating the work order
     */
    public function createWorkOrder(array $data, string $createdBy): WorkOrder
    {
        $workOrder = WorkOrder::create($data);

        $this->eventLogger->log(
            entityType: 'work_order',
            entityId: $workOrder->id,
            operationType: 'work_order_created',
            payload: [
                'operation_type' => $workOrder->operation_type,
                'lot_id' => $workOrder->lot_id,
                'vessel_id' => $workOrder->vessel_id,
                'assigned_to' => $workOrder->assigned_to,
                'due_date' => $workOrder->due_date?->toDateString(),
                'priority' => $workOrder->priority,
            ],
            performedBy: $createdBy,
            performedAt: now(),
        );

        Log::info('Work order created', [
            'work_order_id' => $workOrder->id,
            'operation_type' => $workOrder->operation_type,
            'assigned_to' => $workOrder->assigned_to,
            'tenant_id' => tenant('id'),
            'user_id' => $createdBy,
        ]);

        return $workOrder;
    }

    /**
     * Create a work order from a template.
     *
     * @param  array<string, mixed>  $overrides  Additional/override fields
     * @param  string  $createdBy  UUID of the user
     */
    public function createFromTemplate(WorkOrderTemplate $template, array $overrides, string $createdBy): WorkOrder
    {
        $data = array_merge([
            'operation_type' => $template->operation_type,
            'notes' => $template->default_notes,
            'template_id' => $template->id,
        ], $overrides);

        return $this->createWorkOrder($data, $createdBy);
    }

    /**
     * Bulk create work orders — same operation across multiple lots/vessels.
     *
     * @param  array<string, mixed>  $baseData  Common fields (operation_type, due_date, etc.)
     * @param  array<int, array<string, string>>  $targets  Array of [{lot_id, vessel_id}]
     * @param  string  $createdBy  UUID of the user
     * @return Collection<int, WorkOrder>
     */
    public function bulkCreate(array $baseData, array $targets, string $createdBy): Collection
    {
        $workOrders = collect();

        foreach ($targets as $target) {
            $data = array_merge($baseData, $target);
            $workOrders->push($this->createWorkOrder($data, $createdBy));
        }

        Log::info('Bulk work orders created', [
            'count' => $workOrders->count(),
            'operation_type' => $baseData['operation_type'] ?? null,
            'tenant_id' => tenant('id'),
            'user_id' => $createdBy,
        ]);

        return $workOrders;
    }

    /**
     * Complete a work order and write the appropriate event.
     *
     * @param  array<string, mixed>  $completionData  Completion notes and other data
     * @param  string  $completedBy  UUID of the user completing the work order
     */
    public function completeWorkOrder(WorkOrder $workOrder, array $completionData, string $completedBy): WorkOrder
    {
        $workOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $completedBy,
            'completion_notes' => $completionData['completion_notes'] ?? null,
        ]);

        // Write a work_order_completed event
        $this->eventLogger->log(
            entityType: 'work_order',
            entityId: $workOrder->id,
            operationType: 'work_order_completed',
            payload: [
                'operation_type' => $workOrder->operation_type,
                'lot_id' => $workOrder->lot_id,
                'vessel_id' => $workOrder->vessel_id,
                'completion_notes' => $completionData['completion_notes'] ?? null,
            ],
            performedBy: $completedBy,
            performedAt: now(),
        );

        // If this work order is tied to a lot or vessel, also write
        // a domain-specific event on that entity for the timeline.
        if ($workOrder->lot_id) {
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $workOrder->lot_id,
                operationType: strtolower(str_replace(' ', '_', $workOrder->operation_type)).'_completed',
                payload: [
                    'work_order_id' => $workOrder->id,
                    'vessel_id' => $workOrder->vessel_id,
                    'completion_notes' => $completionData['completion_notes'] ?? null,
                ],
                performedBy: $completedBy,
                performedAt: now(),
            );
        }

        Log::info('Work order completed', [
            'work_order_id' => $workOrder->id,
            'operation_type' => $workOrder->operation_type,
            'tenant_id' => tenant('id'),
            'user_id' => $completedBy,
        ]);

        return $workOrder->fresh();
    }

    /**
     * Update a work order's mutable fields.
     *
     * @param  array<string, mixed>  $data  Validated update data
     * @param  string  $updatedBy  UUID of the user
     */
    public function updateWorkOrder(WorkOrder $workOrder, array $data, string $updatedBy): WorkOrder
    {
        $oldStatus = $workOrder->status;

        $workOrder->update($data);

        // If status changed to completed via generic update, handle completion
        if (isset($data['status']) && $data['status'] === 'completed' && $oldStatus !== 'completed') {
            $workOrder->update([
                'completed_at' => $workOrder->completed_at ?? now(),
                'completed_by' => $workOrder->completed_by ?? $updatedBy,
            ]);

            $this->eventLogger->log(
                entityType: 'work_order',
                entityId: $workOrder->id,
                operationType: 'work_order_completed',
                payload: [
                    'operation_type' => $workOrder->operation_type,
                    'lot_id' => $workOrder->lot_id,
                    'vessel_id' => $workOrder->vessel_id,
                ],
                performedBy: $updatedBy,
                performedAt: now(),
            );
        }

        Log::info('Work order updated', [
            'work_order_id' => $workOrder->id,
            'changes' => $data,
            'tenant_id' => tenant('id'),
            'user_id' => $updatedBy,
        ]);

        return $workOrder->fresh();
    }
}
