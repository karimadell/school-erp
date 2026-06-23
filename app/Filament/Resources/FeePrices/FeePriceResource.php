<?php

namespace App\Filament\Resources\FeePrices;

use App\Filament\Resources\FeePrices\FeePriceResource\Pages;
use App\Models\FeePrice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeePriceResource extends Resource
{
    protected static ?string $model = FeePrice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Fee Prices';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Fee Name')
                ->required()
                ->maxLength(255),

            TextInput::make('amount')
                ->label('Amount (EGP)')
                ->numeric()
                ->required(),

            DatePicker::make('effective_from')
                ->label('Start Date'),

            DatePicker::make('effective_to')
                ->label('End Date'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('amount')
                    ->label('Price')
                    ->sortable(),

                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),

                TextColumn::make('effective_to')
                    ->date()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Created')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeePrices::route('/'),
            'create' => Pages\CreateFeePrice::route('/create'),
            'edit' => Pages\EditFeePrice::route('/{record}/edit'),
        ];
    }
}