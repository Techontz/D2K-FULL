<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampusResource\Pages;
use App\Models\Campus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Universities and colleges customers can choose for campus delivery.
 *
 * Disabling a campus hides it from the picker without touching the addresses
 * that already use it. A delivery fee here replaces distance pricing for every
 * order delivered to that campus; leave it empty for normal pricing.
 */
class CampusResource extends Resource
{
    protected static ?string $model = Campus::class;
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?string $navigationGroup = 'Orders & customers';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Campuses';
    protected static ?string $modelLabel = 'campus';
    protected static ?string $pluralModelLabel = 'campuses';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Campus')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull()
                        ->helperText('Full name, as customers know it, e.g. University of Dar es Salaam (UDSM) – Mlimani.'),

                    Forms\Components\TextInput::make('short_name')
                        ->label('Short name')
                        ->maxLength(255)
                        ->placeholder('e.g. UDSM Mlimani'),

                    Forms\Components\TextInput::make('area')
                        ->maxLength(255)
                        ->placeholder('e.g. Ubungo'),

                    Forms\Components\TextInput::make('delivery_fee')
                        ->label('Delivery fee')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('TZS')
                        ->helperText('Flat fee for every order to this campus. Leave empty for normal delivery pricing.'),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Lower numbers appear first in the customer\'s list.'),

                    Forms\Components\TextInput::make('latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90),

                    Forms\Components\TextInput::make('longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Shown to customers')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (Campus $campus) => $campus->short_name),

                Tables\Columns\TextColumn::make('area')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('delivery_fee')
                    ->label('Delivery fee')
                    ->money('TZS')
                    ->placeholder('Normal pricing'),

                Tables\Columns\TextColumn::make('addresses_count')
                    ->counts('addresses')
                    ->label('Addresses')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Shown'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Shown to customers'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCampuses::route('/'),
            'create' => Pages\CreateCampus::route('/create'),
            'edit'   => Pages\EditCampus::route('/{record}/edit'),
        ];
    }
}
