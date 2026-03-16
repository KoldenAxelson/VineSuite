<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only bulk wine inventory aggregation.
 *
 * Queries existing lot/vessel/lot_vessel data — no separate data store.
 * Provides real-time gallons by lot, by vessel, and by location, plus
 * volume reconciliation (lot book value vs. sum of vessel contents).
 */
class BulkWineInventoryController extends Controller
{
    /**
     * Summary: total gallons in bulk, lot count, vessel count.
     */
    public function summary(): JsonResponse
    {
        $stats = DB::table('lot_vessel')
            ->whereNull('emptied_at')
            ->selectRaw('COUNT(DISTINCT lot_id) as active_lot_count')
            ->selectRaw('COUNT(DISTINCT vessel_id) as active_vessel_count')
            ->selectRaw('COALESCE(SUM(volume_gallons), 0) as total_gallons_in_vessels')
            ->first();

        $lotBookTotal = DB::table('lots')
            ->whereIn('status', ['in_progress', 'aging'])
            ->sum('volume_gallons');

        return ApiResponse::success([
            'total_gallons_in_vessels' => (float) $stats->total_gallons_in_vessels,
            'total_gallons_book_value' => (float) $lotBookTotal,
            'variance_gallons' => round((float) $lotBookTotal - (float) $stats->total_gallons_in_vessels, 4),
            'active_lot_count' => (int) $stats->active_lot_count,
            'active_vessel_count' => (int) $stats->active_vessel_count,
        ]);
    }

    /**
     * Gallons by lot — each active/aging lot with its vessel breakdown.
     */
    public function byLot(Request $request): JsonResponse
    {
        $query = DB::table('lots')
            ->leftJoin('lot_vessel', function ($join) {
                $join->on('lots.id', '=', 'lot_vessel.lot_id')
                    ->whereNull('lot_vessel.emptied_at');
            })
            ->leftJoin('vessels', 'lot_vessel.vessel_id', '=', 'vessels.id')
            ->whereIn('lots.status', ['in_progress', 'aging'])
            ->select([
                'lots.id as lot_id',
                'lots.name as lot_name',
                'lots.variety',
                'lots.vintage',
                'lots.status as lot_status',
                'lots.volume_gallons as book_volume',
                DB::raw('COALESCE(SUM(lot_vessel.volume_gallons), 0) as vessel_volume'),
                DB::raw("STRING_AGG(DISTINCT vessels.name, ', ' ORDER BY vessels.name) as vessel_names"),
                DB::raw('COUNT(DISTINCT lot_vessel.vessel_id) as vessel_count'),
            ])
            ->groupBy('lots.id', 'lots.name', 'lots.variety', 'lots.vintage', 'lots.status', 'lots.volume_gallons')
            ->orderBy('lots.name');

        if ($request->filled('vintage')) {
            $query->where('lots.vintage', $request->integer('vintage'));
        }

        if ($request->filled('variety')) {
            $query->where('lots.variety', 'ilike', '%'.$request->input('variety').'%');
        }

        if ($request->filled('status')) {
            $query->where('lots.status', $request->input('status'));
        }

        $results = $query->get()->map(function ($row) {
            $bookVolume = (float) $row->book_volume;
            $vesselVolume = (float) $row->vessel_volume;

            return [
                'lot_id' => $row->lot_id,
                'lot_name' => $row->lot_name,
                'variety' => $row->variety,
                'vintage' => $row->vintage,
                'lot_status' => $row->lot_status,
                'book_volume' => $bookVolume,
                'vessel_volume' => $vesselVolume,
                'variance' => round($bookVolume - $vesselVolume, 4),
                'vessel_count' => (int) $row->vessel_count,
                'vessel_names' => $row->vessel_names,
            ];
        });

        return ApiResponse::success($results);
    }

    /**
     * Gallons by vessel — each vessel with its current lot contents.
     */
    public function byVessel(Request $request): JsonResponse
    {
        $query = DB::table('vessels')
            ->leftJoin('lot_vessel', function ($join) {
                $join->on('vessels.id', '=', 'lot_vessel.vessel_id')
                    ->whereNull('lot_vessel.emptied_at');
            })
            ->leftJoin('lots', 'lot_vessel.lot_id', '=', 'lots.id')
            ->select([
                'vessels.id as vessel_id',
                'vessels.name as vessel_name',
                'vessels.type as vessel_type',
                'vessels.capacity_gallons',
                'vessels.location',
                'vessels.status as vessel_status',
                DB::raw('COALESCE(SUM(lot_vessel.volume_gallons), 0) as current_volume'),
                DB::raw("STRING_AGG(DISTINCT lots.name, ', ' ORDER BY lots.name) as lot_names"),
                DB::raw('COUNT(DISTINCT lot_vessel.lot_id) as lot_count'),
            ])
            ->groupBy('vessels.id', 'vessels.name', 'vessels.type', 'vessels.capacity_gallons', 'vessels.location', 'vessels.status')
            ->orderBy('vessels.name');

        if ($request->filled('vessel_type')) {
            $query->where('vessels.type', $request->input('vessel_type'));
        }

        if ($request->filled('location')) {
            $query->where('vessels.location', 'ilike', '%'.$request->input('location').'%');
        }

        if ($request->boolean('occupied_only')) {
            $query->havingRaw('COALESCE(SUM(lot_vessel.volume_gallons), 0) > 0');
        }

        $results = $query->get()->map(function ($row) {
            $capacity = (float) $row->capacity_gallons;
            $current = (float) $row->current_volume;

            return [
                'vessel_id' => $row->vessel_id,
                'vessel_name' => $row->vessel_name,
                'vessel_type' => $row->vessel_type,
                'capacity_gallons' => $capacity,
                'current_volume' => $current,
                'available_capacity' => round($capacity - $current, 4),
                'fill_percentage' => $capacity > 0 ? round(($current / $capacity) * 100, 1) : 0,
                'location' => $row->location,
                'vessel_status' => $row->vessel_status,
                'lot_count' => (int) $row->lot_count,
                'lot_names' => $row->lot_names,
            ];
        });

        return ApiResponse::success($results);
    }

    /**
     * Gallons by location — aggregated vessel contents grouped by location.
     */
    public function byLocation(): JsonResponse
    {
        $results = DB::table('vessels')
            ->leftJoin('lot_vessel', function ($join) {
                $join->on('vessels.id', '=', 'lot_vessel.vessel_id')
                    ->whereNull('lot_vessel.emptied_at');
            })
            ->whereNotNull('vessels.location')
            ->select([
                'vessels.location',
                DB::raw('COUNT(DISTINCT vessels.id) as vessel_count'),
                DB::raw('COALESCE(SUM(vessels.capacity_gallons), 0) as total_capacity'),
                DB::raw('COALESCE(SUM(lot_vessel.volume_gallons), 0) as total_volume'),
                DB::raw('COUNT(DISTINCT lot_vessel.lot_id) as lot_count'),
            ])
            ->groupBy('vessels.location')
            ->orderBy('vessels.location')
            ->get()
            ->map(function ($row) {
                $capacity = (float) $row->total_capacity;
                $volume = (float) $row->total_volume;

                return [
                    'location' => $row->location,
                    'vessel_count' => (int) $row->vessel_count,
                    'total_capacity' => $capacity,
                    'total_volume' => $volume,
                    'available_capacity' => round($capacity - $volume, 4),
                    'fill_percentage' => $capacity > 0 ? round(($volume / $capacity) * 100, 1) : 0,
                    'lot_count' => (int) $row->lot_count,
                ];
            });

        return ApiResponse::success($results);
    }

    /**
     * Volume reconciliation — lots where book value differs from vessel contents.
     */
    public function reconciliation(): JsonResponse
    {
        $results = DB::table('lots')
            ->leftJoin('lot_vessel', function ($join) {
                $join->on('lots.id', '=', 'lot_vessel.lot_id')
                    ->whereNull('lot_vessel.emptied_at');
            })
            ->whereIn('lots.status', ['in_progress', 'aging'])
            ->select([
                'lots.id as lot_id',
                'lots.name as lot_name',
                'lots.variety',
                'lots.vintage',
                'lots.volume_gallons as book_volume',
                DB::raw('COALESCE(SUM(lot_vessel.volume_gallons), 0) as vessel_volume'),
            ])
            ->groupBy('lots.id', 'lots.name', 'lots.variety', 'lots.vintage', 'lots.volume_gallons')
            ->havingRaw('lots.volume_gallons != COALESCE(SUM(lot_vessel.volume_gallons), 0)')
            ->orderByRaw('ABS(lots.volume_gallons - COALESCE(SUM(lot_vessel.volume_gallons), 0)) DESC')
            ->get()
            ->map(function ($row) {
                $bookVolume = (float) $row->book_volume;
                $vesselVolume = (float) $row->vessel_volume;
                $variance = round($bookVolume - $vesselVolume, 4);

                return [
                    'lot_id' => $row->lot_id,
                    'lot_name' => $row->lot_name,
                    'variety' => $row->variety,
                    'vintage' => $row->vintage,
                    'book_volume' => $bookVolume,
                    'vessel_volume' => $vesselVolume,
                    'variance' => $variance,
                    'variance_percentage' => $bookVolume > 0 ? round(($variance / $bookVolume) * 100, 1) : 0,
                ];
            });

        return ApiResponse::success($results);
    }

    /**
     * Aging schedule — lots currently aging with projected bottling if configured.
     */
    public function agingSchedule(Request $request): JsonResponse
    {
        $query = DB::table('lots')
            ->leftJoin('lot_vessel', function ($join) {
                $join->on('lots.id', '=', 'lot_vessel.lot_id')
                    ->whereNull('lot_vessel.emptied_at');
            })
            ->leftJoin('vessels', 'lot_vessel.vessel_id', '=', 'vessels.id')
            ->where('lots.status', 'aging')
            ->select([
                'lots.id as lot_id',
                'lots.name as lot_name',
                'lots.variety',
                'lots.vintage',
                'lots.volume_gallons as book_volume',
                DB::raw('COALESCE(SUM(lot_vessel.volume_gallons), 0) as vessel_volume'),
                DB::raw("STRING_AGG(DISTINCT vessels.type, ', ' ORDER BY vessels.type) as vessel_types"),
                DB::raw("STRING_AGG(DISTINCT vessels.name, ', ' ORDER BY vessels.name) as vessel_names"),
                DB::raw('MIN(lot_vessel.filled_at) as earliest_fill'),
                DB::raw('COUNT(DISTINCT lot_vessel.vessel_id) as vessel_count'),
            ])
            ->groupBy('lots.id', 'lots.name', 'lots.variety', 'lots.vintage', 'lots.volume_gallons')
            ->orderBy('lots.vintage')
            ->orderBy('lots.name');

        if ($request->filled('vintage')) {
            $query->where('lots.vintage', $request->integer('vintage'));
        }

        if ($request->filled('variety')) {
            $query->where('lots.variety', 'ilike', '%'.$request->input('variety').'%');
        }

        $results = $query->get()->map(function ($row) {
            $earliestFill = $row->earliest_fill;
            $agingDays = $earliestFill ? (int) abs(now()->diffInDays($earliestFill)) : null;

            return [
                'lot_id' => $row->lot_id,
                'lot_name' => $row->lot_name,
                'variety' => $row->variety,
                'vintage' => $row->vintage,
                'book_volume' => (float) $row->book_volume,
                'vessel_volume' => (float) $row->vessel_volume,
                'vessel_types' => $row->vessel_types,
                'vessel_names' => $row->vessel_names,
                'vessel_count' => (int) $row->vessel_count,
                'earliest_fill_date' => $earliestFill,
                'aging_days' => $agingDays,
            ];
        });

        return ApiResponse::success($results);
    }
}
