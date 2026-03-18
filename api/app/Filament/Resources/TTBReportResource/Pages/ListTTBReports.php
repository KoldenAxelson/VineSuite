<?php

declare(strict_types=1);

namespace App\Filament\Resources\TTBReportResource\Pages;

use App\Filament\Resources\TTBReportResource;
use App\Jobs\GenerateMonthlyTTBReportJob;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTTBReports extends ListRecords
{
    protected static string $resource = TTBReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate')
                ->label('Generate Report')
                ->icon('heroicon-o-arrow-path')
                ->form([
                    Forms\Components\Select::make('month')
                        ->options(collect(range(1, 12))->mapWithKeys(
                            fn (int $m) => [$m => date('F', mktime(0, 0, 0, $m, 1))]
                        ))
                        ->default(now()->subMonth()->month)
                        ->required(),
                    Forms\Components\TextInput::make('year')
                        ->numeric()
                        ->default(now()->subMonth()->year)
                        ->required(),
                    Forms\Components\TextInput::make('opening_bulk_inventory')
                        ->label('Opening Bulk Wine Inventory')
                        ->numeric()
                        ->step(1)
                        ->default(0)
                        ->helperText('Leave at 0 to auto-detect from previous month.'),
                    Forms\Components\TextInput::make('opening_bottled_inventory')
                        ->label('Opening Bottled Wine Inventory')
                        ->numeric()
                        ->step(1)
                        ->default(0)
                        ->helperText('Leave at 0 to auto-detect from previous month.'),
                ])
                ->action(function (array $data): void {
                    /** @var Tenant|null $tenant */
                    $tenant = tenant();
                    if (! $tenant instanceof Tenant) {
                        return;
                    }

                    GenerateMonthlyTTBReportJob::dispatchSync(
                        tenantId: $tenant->id,
                        month: (int) $data['month'],
                        year: (int) $data['year'],
                        openingBulkInventory: (float) ($data['opening_bulk_inventory'] ?? 0),
                        openingBottledInventory: (float) ($data['opening_bottled_inventory'] ?? 0),
                    );

                    Notification::make()
                        ->title('TTB Report Generated')
                        ->body('Report for '.date('F', mktime(0, 0, 0, (int) $data['month'], 1)).' '.$data['year'].' has been generated.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
