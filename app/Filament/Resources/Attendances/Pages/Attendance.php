<?php

namespace App\Filament\Resources\Attendances\Pages;

use Filament\Pages\Page;
use UnitEnum;
use BackedEnum;

class Attendance extends Page
{
    protected static ?string $navigationLabel = 'Посещаемость';

    protected static ?string $title = 'Посещаемость';

    protected static string|\UnitEnum|null $navigationGroup = 'Академия';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected string $view = 'filament.pages.attendance';
}