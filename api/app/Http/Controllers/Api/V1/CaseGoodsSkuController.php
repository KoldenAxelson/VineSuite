<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCaseGoodsSkuRequest;
use App\Http\Requests\UpdateCaseGoodsSkuRequest;
use App\Http\Resources\CaseGoodsSkuResource;
use App\Http\Responses\ApiResponse;
use App\Models\CaseGoodsSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CaseGoodsSkuController extends Controller
{
    /**
     * List case goods SKUs with filtering, search, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        // Meilisearch full-text search
        if ($request->filled('search')) {
            $searchQuery = CaseGoodsSku::search($request->input('search'));

            // Apply filters to search
            if ($request->filled('is_active')) {
                $searchQuery->where('is_active', $request->boolean('is_active'));
            }
            if ($request->filled('vintage')) {
                $searchQuery->where('vintage', (int) $request->input('vintage'));
            }

            // Scout paginate returns a contract; use query() to get an Eloquent builder
            // so we get a concrete LengthAwarePaginator that supports load()/through()
            $searchIds = $searchQuery->keys();
            $perPage = $request->integer('per_page', 25);

            $paginator = CaseGoodsSku::query()
                ->with(['lot'])
                ->whereIn('id', $searchIds)
                ->paginate($perPage);
            $paginator->through(fn (CaseGoodsSku $sku) => new CaseGoodsSkuResource($sku));

            return ApiResponse::paginated($paginator);
        }

        // Database query with filters
        $query = CaseGoodsSku::query()->with(['lot']);

        if ($request->filled('vintage')) {
            $query->ofVintage((int) $request->input('vintage'));
        }

        if ($request->filled('varietal')) {
            $query->ofVarietal($request->input('varietal'));
        }

        if ($request->filled('format')) {
            $query->ofFormat($request->input('format'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (CaseGoodsSku $sku) => new CaseGoodsSkuResource($sku));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single SKU with relationships.
     */
    public function show(CaseGoodsSku $sku): JsonResponse
    {
        $sku->load(['lot', 'bottlingRun']);

        return ApiResponse::success(new CaseGoodsSkuResource($sku));
    }

    /**
     * Create a new case goods SKU.
     */
    public function store(StoreCaseGoodsSkuRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('sku-images', 'public');
        }
        unset($data['image']);

        // Handle tech sheet upload
        if ($request->hasFile('tech_sheet')) {
            $data['tech_sheet_path'] = $request->file('tech_sheet')->store('sku-tech-sheets', 'public');
        }
        unset($data['tech_sheet']);

        $sku = CaseGoodsSku::create($data);
        $sku->load(['lot', 'bottlingRun']);

        Log::info('Case goods SKU created', [
            'sku_id' => $sku->id,
            'wine_name' => $sku->wine_name,
            'vintage' => $sku->vintage,
            'varietal' => $sku->varietal,
            'format' => $sku->format,
            'tenant_id' => tenant('id'),
            'user_id' => $request->user()->id,
        ]);

        return ApiResponse::created(new CaseGoodsSkuResource($sku));
    }

    /**
     * Update an existing SKU.
     */
    public function update(UpdateCaseGoodsSkuRequest $request, CaseGoodsSku $sku): JsonResponse
    {
        $data = $request->validated();

        // Handle image upload (replace existing)
        if ($request->hasFile('image')) {
            if ($sku->image_path) {
                Storage::disk('public')->delete($sku->image_path);
            }
            $data['image_path'] = $request->file('image')->store('sku-images', 'public');
        }
        unset($data['image']);

        // Handle tech sheet upload (replace existing)
        if ($request->hasFile('tech_sheet')) {
            if ($sku->tech_sheet_path) {
                Storage::disk('public')->delete($sku->tech_sheet_path);
            }
            $data['tech_sheet_path'] = $request->file('tech_sheet')->store('sku-tech-sheets', 'public');
        }
        unset($data['tech_sheet']);

        $sku->update($data);
        $sku->load(['lot', 'bottlingRun']);

        Log::info('Case goods SKU updated', [
            'sku_id' => $sku->id,
            'wine_name' => $sku->wine_name,
            'tenant_id' => tenant('id'),
            'user_id' => $request->user()->id,
        ]);

        return ApiResponse::success(new CaseGoodsSkuResource($sku));
    }
}
