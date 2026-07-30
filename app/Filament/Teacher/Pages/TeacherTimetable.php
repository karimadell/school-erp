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

    protected string $view = 'filament.teacher.pages.teacher-timetable';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('teacher_portal.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('teacher_portal.nav_timetable');
    }

    public $lessons = [];

    public function mount(): void
    {
        $teacher = Teacher::where('user_id', Auth::id())->first();

        if ($teacher) {

            // Batch 8: eager-loaded 'class', a relation that doesn't
            // exist on Timetable (it's named schoolClass()) — this page
            // has never actually rendered successfully. Unrelated to
            // this batch's scoping work, which was already correct.
            $this->lessons = Timetable::with([
                'schoolClass',
                'subject',
                'day',
                'period'
            ])
            ->where('teacher_id', $teacher->id)
            ->get();
        }
    }
}