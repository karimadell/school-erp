<?php

namespace App\Filament\Resources\FeePrices;

use App\Filament\Resources\FeePrices\FeePriceResource\Pages;
use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\MealPlan;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeePriceResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = FeePrice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Цены на услуги';

    protected static ?string $modelLabel = 'цена услуги';

    protected static ?string $pluralModelLabel = 'цены на услуги';

    protected static ?int $navigationSort = 20;

    /**
     * monthly / quarterly / yearly / daily / once / term / package — the
     * single source of truth for this Select's own options, reused by the
     * table's own payment_period column so the two can never drift.
     *
     * @return array<string, string>
     */
    public static function paymentPeriodLabels(): array
    {
        return [
            'once' => 'Разово', 'daily' => 'Ежедневно', 'monthly' => 'Ежемесячно',
            'quarterly' => 'Ежеквартально', 'term' => 'За семестр', 'yearly' => 'За год', 'package' => 'Пакет',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fee_id')->label('Услуга')->relationship('fee', 'name_ru')->searchable()->preload()->live()->required(),
            Select::make('academic_year_id')->label('Учебный год')
                ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id'))
                ->searchable()->required(),
            TextInput::make('amount')->label('Цена')->numeric()->minValue(0.01)->suffix('EGP')->required(),
            TextInput::make('currency')->label('Валюта')->default('EGP')->readOnly()->required(),
            DatePicker::make('start_date')->label('Действует с')->required(),
            DatePicker::make('end_date')->label('Действует до')->afterOrEqual('start_date'),
            Select::make('grade_id')->label('Класс')->options(fn () => Grade::ordered()->pluck('name', 'id'))->searchable()->preload()
                ->visible(fn (Get $get): bool => self::isTuition($get('fee_id')))->dehydratedWhenHidden(false),
            Select::make('grade_group')->label('Группа классов')->options(array_combine(FeePrice::GRADE_GROUPS, FeePrice::GRADE_GROUPS))
                ->visible(fn (Get $get): bool => self::isTuition($get('fee_id')))->dehydratedWhenHidden(false),
            // Study mode (Tuition only) — a first-class EnrollmentMode
            // selector, never raw option_type/option_value text. Bound to
            // the SAME option_value column Transport/Food already use
            // (mutually exclusive via visible(), exactly like those two
            // already coexist on this one field name) — left BLANK, a
            // generic (non-mode-scoped) Tuition tariff is created exactly
            // as before; CreateFeePrice::mutateFormDataBeforeCreate()
            // derives option_type from whether a mode was actually chosen,
            // so opening/viewing this form never forces a mode onto an
            // existing generic row merely by rendering it.
            Select::make('option_value')->label('Форма обучения')
                ->options(fn () => EnrollmentMode::query()->orderBy('display_order')->pluck('name_ru', 'code'))
                ->visible(fn (Get $get): bool => self::isTuition($get('fee_id')))->dehydratedWhenHidden(false)
                ->searchable()
                ->helperText('Оставьте пустым для общего тарифа без привязки к форме обучения.'),
            Select::make('payment_period')->label('Период оплаты')->options(self::paymentPeriodLabels()),
            Hidden::make('option_type')->default('zone')
                ->visible(fn (Get $get): bool => self::category($get('fee_id')) === Fee::CATEGORY_TRANSPORT)->dehydratedWhenHidden(false),
            TextInput::make('option_value')->label(__('finance_uat.transport_zone'))->maxLength(150)
                ->visible(fn (Get $get): bool => self::category($get('fee_id')) === Fee::CATEGORY_TRANSPORT)->dehydratedWhenHidden(false),
            // Food is priced per MealPlan — the same domain entity Quick
            // Registration already resolves prices by (option_type='meal_plan',
            // option_value=<meal_plans.id>). A free-text field here would let an
            // admin create a tariff no consumer can ever match.
            Hidden::make('option_type')->default('meal_plan')
                ->visible(fn (Get $get): bool => self::category($get('fee_id')) === Fee::CATEGORY_FOOD)->dehydratedWhenHidden(false),
            Select::make('option_value')->label(__('finance_uat.meal_item'))
                ->options(fn () => MealPlan::query()->active()->orderBy('name_ru')->pluck('name_ru', 'id'))
                ->visible(fn (Get $get): bool => self::category($get('fee_id')) === Fee::CATEGORY_FOOD)
                ->dehydratedWhenHidden(false)->searchable(),
            TextInput::make('item')->label(__('finance_uat.uniform_item'))->maxLength(100)
                ->visible(fn (Get $get): bool => self::category($get('fee_id')) === Fee::CATEGORY_UNIFORM)->dehydratedWhenHidden(false),
            TextInput::make('size')->label(__('finance_uat.uniform_size'))->maxLength(50)
                ->visible(fn (Get $get): bool => self::category($get('fee_id')) === Fee::CATEGORY_UNIFORM)->dehydratedWhenHidden(false),
            TextInput::make('notes')->label('Примечание')->maxLength(1000),
            Toggle::make('is_active')->label('Активна')->default(true),
        ]);
    }

    private static function category(mixed $feeId): ?string
    {
        return $feeId ? Fee::query()->whereKey($feeId)->value('category') : null;
    }

    private static function isTuition(mixed $feeId): bool
    {
        return in_array(self::category($feeId), [
            Fee::CATEGORY_TUITION,
            Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY,
            Fee::CATEGORY_TUITION_EXTERNAL,
        ], true);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('fee.name_ru')->label('Услуга')->searchable()->sortable(),
            TextColumn::make('academicYear.name')->label('Учебный год')->sortable(),
            TextColumn::make('grade.name')->label('Класс')->placeholder('—'),
            TextColumn::make('grade_group')->label('Группа классов')->placeholder('—'),
            // Study mode — resolved from the SAME small EnrollmentMode
            // lookup table once per table render (a `static` variable
            // inside this closure persists across every row's invocation
            // within the same request, so this is exactly one extra query
            // total, never one per row).
            TextColumn::make('option_value')->label('Форма обучения')
                ->formatStateUsing(function (?string $state, FeePrice $record): string {
                    if ($record->option_type !== 'enrollment_mode' || blank($state)) {
                        return '—';
                    }
                    static $modes = null;
                    $modes ??= EnrollmentMode::query()->pluck('name_ru', 'code');

                    return $modes[$state] ?? $state;
                }),
            TextColumn::make('payment_period')->label('Период оплаты')
                ->formatStateUsing(fn (?string $state): string => self::paymentPeriodLabels()[$state] ?? '—'),
            TextColumn::make('amount')->label('Цена')->money('EGP')->sortable(),
            TextColumn::make('currency')->label('Валюта'),
            TextColumn::make('start_date')->label('Действует с')->date('d.m.Y')->sortable(),
            TextColumn::make('end_date')->label('Действует до')->date('d.m.Y')->placeholder('Без ограничения'),
            IconColumn::make('is_active')->label('Активна')->boolean(),
        ])->defaultSort('start_date', 'desc');
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
