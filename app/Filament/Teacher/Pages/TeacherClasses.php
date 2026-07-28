<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use App\Models\Teacher;
use UnitEnum;
use BackedEnum;

class TeacherClasses extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|UnitEnum|null $navigationGroup = 'Преподаватель';

    protected static ?string $navigationLabel = 'Мои классы';

    protected string $view = 'filament.teacher.pages.teacher-classes';

    public $classes = [];

    /**
     * Batch 8: previously listed every class for every subject the
     * teacher is merely qualified to teach (teacher_subject), regardless
     * of whether they're actually assigned to teach it this year. Now
     * backed by TeacherAssignment (the real, year-scoped assignment) —
     * grouped by subject to keep the existing "subject -> classes" view
     * structure unchanged.
     */
    public function mount(): void
    {
        $teacher = Teacher::where('user_id', Auth::id())->first();

        if ($teacher) {

            $this->classes = $teacher
                ->currentAssignments()
                ->with(['schoolClass', 'subject'])
                ->get()
                ->groupBy(fn ($assignment) => $assignment->subject->id)
                ->map(fn ($assignments) => (object) [
                    'name' => $assignments->first()->subject->name,
                    'classes' => $assignments->pluck('schoolClass')->unique('id')->values(),
                ])
                ->values();
        }
    }
}
