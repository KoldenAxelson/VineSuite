<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FermentationChartController extends Controller
{
    /**
     * Return fermentation chart data for a round in a chart-ready format.
     *
     * Dual-axis: Brix/density on left Y, temperature on right Y, date on X.
     * Entries sorted chronologically. Includes round metadata for labelling.
     */
    public function show(Request $request, string $roundId): JsonResponse
    {
        $round = FermentationRound::with('lot')->findOrFail($roundId);

        $entries = FermentationEntry::where('fermentation_round_id', $roundId)
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();

        $series = $entries->map(fn (FermentationEntry $entry) => [
            'date' => $entry->entry_date->toDateString(),
            'temperature' => $entry->temperature !== null ? (float) $entry->temperature : null,
            'brix_or_density' => $entry->brix_or_density !== null ? (float) $entry->brix_or_density : null,
            'measurement_type' => $entry->measurement_type,
            'free_so2' => $entry->free_so2 !== null ? (float) $entry->free_so2 : null,
        ])->values()->toArray();

        return ApiResponse::success([
            'round' => [
                'id' => $round->id,
                'lot_id' => $round->lot_id,
                'lot_name' => $round->lot->name,
                'lot_variety' => $round->lot->variety,
                'fermentation_type' => $round->fermentation_type,
                'round_number' => $round->round_number,
                'inoculation_date' => $round->inoculation_date->toDateString(),
                'yeast_strain' => $round->yeast_strain,
                'ml_bacteria' => $round->ml_bacteria,
                'target_temp' => $round->target_temp !== null ? (float) $round->target_temp : null,
                'status' => $round->status,
            ],
            'series' => $series,
            'axes' => [
                'x' => 'date',
                'y_left' => $this->resolveLeftAxisLabel($entries),
                'y_right' => 'temperature_f',
            ],
            'entry_count' => count($series),
        ]);
    }

    /**
     * Return chart data for all rounds of a lot (overlay comparison).
     */
    public function lotOverview(Request $request, string $lotId): JsonResponse
    {
        $rounds = FermentationRound::where('lot_id', $lotId)
            ->with('lot')
            ->orderBy('round_number')
            ->get();

        $chartData = $rounds->map(function (FermentationRound $round) {
            $entries = $round->entries()
                ->orderBy('entry_date')
                ->orderBy('created_at')
                ->get();

            return [
                'round_id' => $round->id,
                'round_number' => $round->round_number,
                'fermentation_type' => $round->fermentation_type,
                'status' => $round->status,
                'label' => sprintf(
                    'Round %d — %s',
                    $round->round_number,
                    $round->fermentation_type === 'primary' ? 'Primary' : 'ML',
                ),
                'series' => $entries->map(fn (FermentationEntry $entry) => [
                    'date' => $entry->entry_date->toDateString(),
                    'temperature' => $entry->temperature !== null ? (float) $entry->temperature : null,
                    'brix_or_density' => $entry->brix_or_density !== null ? (float) $entry->brix_or_density : null,
                    'measurement_type' => $entry->measurement_type,
                ])->values()->toArray(),
            ];
        })->values()->toArray();

        $lotName = $rounds->first()?->lot?->name;

        return ApiResponse::success([
            'lot_id' => $lotId,
            'lot_name' => $lotName,
            'rounds' => $chartData,
        ]);
    }

    /**
     * Determine the left Y axis label based on measurement types present.
     *
     * @param  Collection<int, FermentationEntry>  $entries
     */
    private function resolveLeftAxisLabel($entries): string
    {
        $types = $entries->pluck('measurement_type')->filter()->unique()->values();

        if ($types->count() === 1) {
            return $types->first() === 'brix' ? 'brix' : 'specific_gravity';
        }

        // Mixed or no measurement data — default to brix (most common)
        return 'brix_or_density';
    }
}
