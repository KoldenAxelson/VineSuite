<?php

declare(strict_types=1);

namespace App\Filament\Resources\TTBReportResource\Pages;

use App\Filament\Resources\TTBReportResource;
use App\Jobs\GenerateMonthlyTTBReportJob;
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
                    Forms\Components\TextInput::make('opening_inventory')
                        ->label('Opening Inventory (gallons)')
                        ->numeric()
                        ->step(0.1)
                        ->default(0)
                        ->helperText('Leave at 0 to auto-detect from previous month\'s closing inventory.'),
                ])
                ->action(function (array $data): void {
                    /** @var \App\Models\Tenant|null $tenant */
                    $tenant = tenant();
                    if (! $tenant instanceof \App\Models\Tenant) {
                        return;
                    }

                    GenerateMonthlyTTBReportJob::dispatchSync(
                        tenantId: $tenant->id,
                        month: (int) $data['month'],
                        year: (int) $data['year'],
                        openingInventory: (float) ($data['opening_inventory'] ?? 0),
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
