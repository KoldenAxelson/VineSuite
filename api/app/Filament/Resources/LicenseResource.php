<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseResource\Pages;
use App\Models\License;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LicenseResource extends Resource
{
    protected static ?string $model = License::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Licenses & Permits';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('License Details')
                    ->schema([
                        Forms\Components\Select::make('license_type')
                            ->options([
                                'ttb_permit' => 'TTB Basic Permit',
                                'state_license' => 'State License',
                                'cola' => 'COLA (Certificate of Label Approval)',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('jurisdiction')
                            ->required()
                            ->helperText('e.g., "Federal" for TTB, or state name for state licenses'),
                        Forms\Components\TextInput::make('license_number')
                            ->required(),
                        Forms\Components\DatePicker::make('issued_date'),
                        Forms\Components\DatePicker::make('expiration_date'),
                        Forms\Components\TextInput::make('renewal_lead_days')
                            ->numeric()
                            ->default(90)
                            ->helperText('Days before expiration to trigger reminders'),
                        Forms\Components\FileUpload::make('document_path')
                            ->label('License Document (PDF)')
                            ->acceptedFileTypes(['application/pdf'])
                            ->directory('licenses'),
                        Forms\Components\Textarea::make('notes'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('license_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ttb_permit' => 'TTB Permit',
                        'state_license' => 'State License',
                        'cola' => 'COLA',
                        default => ucfirst($state),
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('jurisdiction')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('license_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('expiration_date')
                    ->date('M j, Y')
                    ->sortable()
                    ->color(fn (?License $record) => match (true) {
                        $record?->isExpired() => 'danger',
                        $record?->needsRenewalReminder() => 'warning',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('renewal_lead_days')
                    ->label('Reminder')
                    ->suffix(' days')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('document_path')
                    ->label('Doc')
                    ->boolean()
                    ->trueIcon('heroicon-o-document')
                    ->falseIcon('heroicon-o-minus'),
            ])
            ->defaultSort('expiration_date', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('license_type')
                    ->options([
                        'ttb_permit' => 'TTB Permit',
                        'state_license' => 'State License',
                        'cola' => 'COLA',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenses::route('/'),
            'create' => Pages\CreateLicense::route('/create'),
            'view' => Pages\ViewLicense::route('/{record}'),
            'edit' => Pages\EditLicense::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
