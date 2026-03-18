<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WorkOrderResource\Pages\CalendarWorkOrders;
use App\Filament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\Pages\ListWorkOrders;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Production';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Work Orders';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Work Order Details')
                    ->schema([
                        TextInput::make('operation_type')
                            ->required()
                            ->maxLength(255),
                        Select::make('lot_id')
                            ->relationship('lot', 'name')
                            ->required(),
                        Select::make('vessel_id')
                            ->relationship('vessel', 'name')
                            ->nullable(),
                        Select::make('assigned_to')
                            ->relationship('assignedUser', 'name')
                            ->nullable(),
                        DatePicker::make('due_date')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'in_progress' => 'In Progress',
                                'completed' => 'Completed',
                                'skipped' => 'Skipped',
                            ])
                            ->required(),
                        Select::make('priority')
                            ->options([
                                'low' => 'Low',
                                'normal' => 'Normal',
                                'high' => 'High',
                            ])
                            ->required(),
                        Textarea::make('notes')
                            ->columnSpanFull(),
                    ]),
                Section::make('Completion')
                    ->schema([
                        DateTimePicker::make('completed_at'),
                        Textarea::make('completion_notes')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($livewire) => $livewire instanceof EditRecord),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('operation_type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lot.name')
                    ->sortable(),
                TextColumn::make('assignedUser.name')
                    ->placeholder('Unassigned')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning', 'in_progress' => 'info', 'completed' => 'success', 'skipped' => 'secondary', default => 'gray'
                    }),
                TextColumn::make('priority')
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'danger', 'normal' => 'info', 'low' => 'secondary', default => 'gray'
                    }),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'skipped' => 'Skipped',
                    ]),
                SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                    ]),
                SelectFilter::make('assigned_to')
                    ->relationship('assignedUser', 'name')
                    ->label('Assigned To'),
                TernaryFilter::make('overdue')
                    ->queries(
                        true: fn (Builder $query) => $query->where('due_date', '<', now())
                            ->whereNotIn('status', ['completed', 'skipped']),
                        false: fn (Builder $query) => $query->where('due_date', '>=', now())
                            ->orWhereIn('status', ['completed', 'skipped']),
                    ),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('complete')
                    ->label('Complete')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WorkOrder $record) => $record->status !== 'completed')
                    ->requiresConfirmation()
                    ->action(function (WorkOrder $record): void {
                        app(WorkOrderService::class)->completeWorkOrder(
                            $record,
                            [],
                            auth()->id(),
                        );
                    }),
            ])
            ->bulkActions([
                // Bulk actions can be added here
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'create' => CreateWorkOrder::route('/create'),
            'edit' => EditWorkOrder::route('/{record}/edit'),
            'calendar' => CalendarWorkOrders::route('/calendar'),
        ];
    }
}
