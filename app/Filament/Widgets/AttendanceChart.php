<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Attendance;
use Carbon\Carbon;

class AttendanceChart extends ChartWidget
{
    protected ?string $heading = 'Посещаемость за 30 дней';

    protected function getData(): array
    {
        $labels = [];
        $data = [];

        for ($i = 29; $i >= 0; $i--) {

            $date = Carbon::now()->subDays($i);

            $labels[] = $date->format('d M');

            $data[] = Attendance::whereDate('date', $date)
                ->where('status', 'present')
                ->count();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Посещаемость',
                    'data' => $data,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}