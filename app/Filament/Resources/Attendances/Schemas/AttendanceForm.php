<?php

namespace App\Filament\Resources\Attendances\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class AttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('enrollment_id')
                    ->label(__('attendance.enrollment'))
                    ->required()
                    ->numeric(),
                DatePicker::make('date')
                    ->label(__('attendance.date'))
                    ->required(),
                Select::make('type')
                    ->label(__('attendance.type'))
                    ->options([
                        'daily' => __('attendance.daily'),
                        'period' => __('attendance.period'),
                    ])
                    ->default('daily')
                    ->required(),
                TextInput::make('period_id')
                    ->label(__('attendance.period'))
                    ->numeric()
                    ->nullable(),
                Select::make('status')
                    ->label(__('attendance.status'))
                    ->options([
                        'present' => __('attendance.present'),
                        'absent' => __('attendance.absent'),
                        'late' => __('attendance.late'),
                        'excused' => __('attendance.excused'),
                    ])
                    ->default('present')
                    ->required(),
                Textarea::make('note')
                    ->label(__('attendance.note'))
                    ->columnSpanFull(),
            ]);
    }
}
