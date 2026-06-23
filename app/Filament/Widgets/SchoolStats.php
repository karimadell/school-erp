<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Invoice;
use App\Models\Attendance;

class SchoolStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [

            Stat::make(
                'Студенты',
                Student::count()
            )
            ->description('Всего студентов')
            ->color('primary'),

            Stat::make(
                'Учителя',
                Teacher::count()
            )
            ->description('Всего преподавателей')
            ->color('info'),

            Stat::make(
                'Доход школы',
                number_format(
                    Invoice::where('status','paid')->sum('total_amount'),
                    2
                ) . ' EGP'
            )
            ->description('Оплаченные счета')
            ->color('success'),

            Stat::make(
                'Неоплаченные счета',
                Invoice::where('status','unpaid')->count()
            )
            ->description('Счета к оплате')
            ->color('danger'),

            Stat::make(
                'Посещаемость сегодня',
                Attendance::whereDate('date', today())->count()
            )
            ->description('Сегодняшние отметки')
            ->color('warning'),

        ];
    }
}