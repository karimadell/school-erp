<?php

namespace App\Filament\Resources\FeePrices;

use App\Filament\Resources\FeePrices\FeePriceResource\Pages;
use App\Models\AcademicYear;
use App\Models\FeePrice;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeePriceResource extends Resource
{
    protected static ?string $model = FeePrice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Цены на услуги';

    protected static ?string $modelLabel = 'цена услуги';

    protected static ?string $pluralModelLabel = 'цены на услуги';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fee_id')->label('Услуга')->relationship('fee', 'name_ru')->searchable()->preload()->required(),
            Select::make('academic_year_id')->label('Учебный год')
                ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id'))
                ->searchable()->required(),
            TextInput::make('amount')->label('Цена')->numeric()->minValue(0.01)->suffix('EGP')->required(),
            TextInput::make('currency')->label('Валюта')->default('EGP')->readOnly()->required(),
            DatePicker::make('start_date')->label('Действует с')->required(),
            DatePicker::make('end_date')->label('Действует до')->afterOrEqual('start_date'),
            Select::make('grade_id')->label('Класс')->relationship('grade', 'name')->searchable()->preload(),
            TextInput::make('grade_group')->label('Группа классов')->maxLength(100),
            Select::make('payment_period')->label('Период оплаты')->options([
                'once' => 'Разово', 'daily' => 'Ежедневно', 'monthly' => 'Ежемесячно',
                'quarterly' => 'Ежеквартально', 'term' => 'За семестр', 'yearly' => 'За год', 'package' => 'Пакет',
            ]),
            TextInput::make('option_type')->label('Тип варианта')->maxLength(100),
            TextInput::make('option_value')->label('Значение варианта')->maxLength(150),
            TextInput::make('item')->label('Изделие')->maxLength(100),
            TextInput::make('size')->label('Размер')->maxLength(50),
            TextInput::make('notes')->label('Примечание')->maxLength(1000),
            Toggle::make('is_active')->label('Активна')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('fee.name_ru')->label('Услуга')->searchable()->sortable(),
            TextColumn::make('academicYear.name')->label('Учебный год')->sortable(),
            TextColumn::make('amount')->label('Цена')->money('EGP')->sortable(),
            TextColumn::make('currency')->label('Валюта'),
            TextColumn::make('start_date')->label('Действует с')->date('d.m.Y')->sortable(),
            TextColumn::make('end_date')->label('Действует до')->date('d.m.Y')->placeholder('Без ограничения'),
            IconColumn::make('is_active')->label('Активна')->boolean(),
        ])->defaultSort('start_date', 'desc')->recordActions([EditAction::make()->label('Изменить')]);
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
