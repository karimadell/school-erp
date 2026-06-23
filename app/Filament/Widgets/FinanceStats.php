<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\SchoolClass;
use App\Models\Invoice;

class FinanceStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {

        $todayIncome = Invoice::whereDate('created_at', today())
            ->sum('total_amount');

        $monthIncome = Invoice::whereMonth('created_at', now()->month)
            ->sum('total_amount');

        $students = Student::count();

        $teachers = Teacher::count();

        $classes = SchoolClass::count();

        return [

            Stat::make('Количество студентов', $students)
                ->description('Студенты'),

            Stat::make('Количество учителей', $teachers)
                ->description('Учителя'),

            Stat::make('Количество классов', $classes)
                ->description('Классы'),

            Stat::make('Доход сегодня', number_format($todayIncome,2).' EGP')
                ->description('Сегодняшний доход'),

            Stat::make('Доход за месяц', number_format($monthIncome,2).' EGP')
                ->description('Месячный доход'),

        ];
    }
}