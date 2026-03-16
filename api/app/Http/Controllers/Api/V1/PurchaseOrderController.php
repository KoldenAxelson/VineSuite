<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceivePurchaseOrderRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\DryGoodsItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RawMaterial;
use App\Services\EventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * List purchase orders with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()->with('lines');

        if ($request->filled('status')) {
            $query->ofStatus($request->input('status'));
        }

        if ($request->filled('vendor')) {
            $query->forVendor($request->input('vendor'));
        }

        if ($request->boolean('open_only')) {
            $query->open();
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        $query->orderByDesc('order_date');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (PurchaseOrder $po) => new PurchaseOrderResource($po));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single purchase order with lines.
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load('lines');

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder));
    }

    /**
     * Create a new purchase order with line items.
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $po = DB::transaction(function () use ($validated) {
            $po = PurchaseOrder::create([
                'vendor_name' => $validated['vendor_name'],
                'vendor_id' => $validated['vendor_id'] ?? null,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'status' => $validated['status'] ?? 'ordered',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['lines'] as $lineData) {
                $po->lines()->create([
                    'item_type' => $lineData['item_type'],
                    'item_id' => $lineData['item_id'],
                    'item_name' => $lineData['item_name'],
                    'quantity_ordered' => $lineData['quantity_ordered'],
                    'cost_per_unit' => $lineData['cost_per_unit'] ?? null,
                ]);
            }

            $po->recalculateTotalCost();
            $po->load('lines');

            return $po;
        });

        $this->eventLogger->log(
            entityType: 'purchase_order',
            entityId: $po->id,
            operationType: 'purchase_order_created',
            payload: [
                'vendor_name' => $po->vendor_name,
                'order_date' => $po->order_date->toDateString(),
                'line_count' => $po->lines->count(),
                'total_cost' => (float) $po->total_cost,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Purchase order created', [
            'po_id' => $po->id,
            'vendor' => $po->vendor_name,
            'lines' => $po->lines->count(),
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::created(new PurchaseOrderResource($po));
    }

    /**
     * Update purchase order header (not lines).
     */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->update($request->validated());
        $purchaseOrder->load('lines');

        $this->eventLogger->log(
            entityType: 'purchase_order',
            entityId: $purchaseOrder->id,
            operationType: 'purchase_order_updated',
            payload: [
                'vendor_name' => $purchaseOrder->vendor_name,
                'status' => $purchaseOrder->status,
                'total_cost' => (float) $purchaseOrder->total_cost,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder));
    }

    /**
     * Receive items on a purchase order (full or partial).
     *
     * Updates line quantities, adjusts PO status, and increments
     * the corresponding DryGoodsItem or RawMaterial on_hand.
     */
    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if (in_array($purchaseOrder->status, ['received', 'cancelled'], true)) {
            return ApiResponse::error("Cannot receive items on a {$purchaseOrder->status} PO.", 422);
        }

        $validated = $request->validated();
        $receivedLines = [];

        DB::transaction(function () use ($purchaseOrder, $validated, &$receivedLines) {
            foreach ($validated['lines'] as $lineData) {
                /** @var PurchaseOrderLine|null $line */
                $line = $purchaseOrder->lines()->where('id', $lineData['line_id'])->first();

                if (! $line) {
                    continue;
                }

                $qtyToReceive = (float) $lineData['quantity_received'];

                // Update cost_per_unit if provided (capture actual cost at receipt)
                if (isset($lineData['cost_per_unit'])) {
                    $line->cost_per_unit = $lineData['cost_per_unit'];
                }

                $previousReceived = (float) $line->quantity_received;
                $line->quantity_received = $previousReceived + $qtyToReceive;
                $line->save();

                // Update inventory item on_hand
                $this->incrementInventory($line, $qtyToReceive);

                $receivedLines[] = [
                    'line_id' => $line->id,
                    'item_name' => $line->item_name,
                    'item_type' => $line->item_type,
                    'quantity_received' => $qtyToReceive,
                    'total_received' => (float) $line->quantity_received,
                    'quantity_ordered' => (float) $line->quantity_ordered,
                ];
            }

            // Recalculate total cost (cost_per_unit may have been updated)
            $purchaseOrder->recalculateTotalCost();

            // Auto-update PO status
            $purchaseOrder->load('lines');
            if ($purchaseOrder->isFullyReceived()) {
                $purchaseOrder->update(['status' => 'received']);
            } elseif ($purchaseOrder->lines->contains(fn (PurchaseOrderLine $l) => (float) $l->quantity_received > 0)) {
                $purchaseOrder->update(['status' => 'partial']);
            }
        });

        $purchaseOrder->load('lines');

        $this->eventLogger->log(
            entityType: 'purchase_order',
            entityId: $purchaseOrder->id,
            operationType: 'purchase_order_received',
            payload: [
                'vendor_name' => $purchaseOrder->vendor_name,
                'status' => $purchaseOrder->status,
                'lines_received' => $receivedLines,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Purchase order received', [
            'po_id' => $purchaseOrder->id,
            'lines_received' => count($receivedLines),
            'new_status' => $purchaseOrder->status,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder));
    }

    /**
     * Increment the on_hand of the inventory item for a received line.
     */
    private function incrementInventory(PurchaseOrderLine $line, float $quantity): void
    {
        if ($line->item_type === 'dry_goods') {
            /** @var DryGoodsItem|null $item */
            $item = DryGoodsItem::lockForUpdate()->find($line->item_id);
            if ($item) {
                $item->increment('on_hand', $quantity);

                // Update cost_per_unit if PO line has one (latest cost)
                if ($line->cost_per_unit !== null) {
                    $item->update(['cost_per_unit' => $line->cost_per_unit]);
                }
            } else {
                Log::warning('PO line references non-existent dry goods item', [
                    'line_id' => $line->id,
                    'item_id' => $line->item_id,
                ]);
            }
        } elseif ($line->item_type === 'raw_material') {
            /** @var RawMaterial|null $item */
            $item = RawMaterial::lockForUpdate()->find($line->item_id);
            if ($item) {
                $item->increment('on_hand', $quantity);

                if ($line->cost_per_unit !== null) {
                    $item->update(['cost_per_unit' => $line->cost_per_unit]);
                }
            } else {
                Log::warning('PO line references non-existent raw material', [
                    'line_id' => $line->id,
                    'item_id' => $line->item_id,
                ]);
            }
        }
    }
}
