<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Support\Collection;

class AcademicYearPriceListService
{
    public const CATEGORY_LABELS = [
        Fee::CATEGORY_REGISTRATION => 'ОРГАНИЗАЦИОННЫЙ ВЗНОС',
        Fee::CATEGORY_TUITION => 'ОБУЧЕНИЕ',
        Fee::CATEGORY_TUITION_REGULAR => 'ОБУЧЕНИЕ',
        Fee::CATEGORY_TUITION_FAMILY => 'ОБУЧЕНИЕ',
        Fee::CATEGORY_TRANSPORT => 'ТРАНСПОРТ',
        Fee::CATEGORY_FOOD => 'ПИТАНИЕ',
        Fee::CATEGORY_TUITION_EXTERNAL => 'ЭКСТЕРНАТ',
        Fee::CATEGORY_UNIFORM => 'ШКОЛЬНАЯ ФОРМА',
        Fee::CATEGORY_BOOKS => 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ',
        Fee::CATEGORY_EXTRA_CLASSES => 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ',
        Fee::CATEGORY_ACTIVITY => 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ',
        Fee::CATEGORY_OTHER => 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ',
    ];

    /** @return array{year:AcademicYear,groups:Collection<string,Collection<int,FeePrice>>,tariffs:Collection<int,FeePrice>} */
    public function data(AcademicYear $year, array $categories, bool $activeOnly): array
    {
        $tariffs = FeePrice::query()
            ->with(['fee', 'grade'])
            ->where('academic_year_id', $year->id)
            ->whereHas('fee', fn ($query) => $query->whereIn('category', $categories))
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('fee_id')->orderBy('grade_group')->orderBy('option_value')->orderBy('size')->orderBy('item')->orderBy('payment_period')
            ->get();

        $groups = $tariffs->groupBy(fn (FeePrice $price) => self::CATEGORY_LABELS[$price->fee->category] ?? 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ');
        $ordered = collect(array_values(array_unique(self::CATEGORY_LABELS)))
            ->filter(fn (string $label) => $groups->has($label))
            ->mapWithKeys(fn (string $label) => [$label => $groups->get($label)]);

        return ['year' => $year, 'groups' => $ordered, 'tariffs' => $tariffs];
    }

    public static function categoryOptions(): array
    {
        return [
            Fee::CATEGORY_REGISTRATION => 'Организационный взнос', Fee::CATEGORY_TUITION => 'Обучение',
            Fee::CATEGORY_TUITION_REGULAR => 'Обычное обучение', Fee::CATEGORY_TUITION_FAMILY => 'Семейное обучение',
            Fee::CATEGORY_TRANSPORT => 'Транспорт', Fee::CATEGORY_FOOD => 'Питание',
            Fee::CATEGORY_TUITION_EXTERNAL => 'Экстернат', Fee::CATEGORY_UNIFORM => 'Школьная форма',
            Fee::CATEGORY_BOOKS => 'Книги', Fee::CATEGORY_EXTRA_CLASSES => 'Дополнительные занятия',
            Fee::CATEGORY_ACTIVITY => 'Мероприятия', Fee::CATEGORY_OTHER => 'Дополнительные услуги',
        ];
    }

    public static function periodLabel(?string $period): ?string
    {
        return [
            Fee::PERIOD_ONCE => 'Разово', Fee::PERIOD_DAILY => 'Ежедневно', Fee::PERIOD_MONTHLY => 'Ежемесячно',
            Fee::PERIOD_QUARTERLY => 'Ежеквартально', Fee::PERIOD_TERM => 'За семестр',
            Fee::PERIOD_YEARLY => 'За год', Fee::PERIOD_PACKAGE => 'Комплект',
        ][$period] ?? null;
    }

    public static function variantLabel(FeePrice $price): string
    {
        return collect([
            $price->grade_group ? 'Класс: '.$price->grade_group : null,
            self::periodLabel($price->payment_period) ? 'Период: '.self::periodLabel($price->payment_period) : null,
            $price->option_value ? (($price->option_type ?: 'Вариант').': '.$price->option_value) : null,
            $price->item ? 'Позиция: '.$price->item : null,
            $price->size ? 'Размер: '.$price->size : null,
        ])->filter()->implode(' · ') ?: 'Общий тариф';
    }
}
