<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use App\Models\Teacher;
use App\Models\Timetable;
use UnitEnum;
use BackedEnum;

class TeacherTimetable extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static string|UnitEnum|null $navigationGroup = 'Преподаватель';

    protected static ?string $navigationLabel = 'Расписание';

    protected string $view = 'filament.teacher.pages.teacher-timetable';

    public $lessons = [];

    public function mount(): void
    {
        $teacher = Teacher::where('user_id', Auth::id())->first();

        if ($teacher) {

            $this->lessons = Timetable::with([
                'class',
                'subject',
                'day',
                'period'
            ])
            ->where('teacher_id', $teacher->id)
            ->get();
        }
    }
}