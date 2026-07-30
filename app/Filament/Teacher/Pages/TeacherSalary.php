<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use App\Models\Teacher;
use App\Models\TeacherSalary as Salary;
use UnitEnum;
use BackedEnum;

class TeacherSalary extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected string $view = 'filament.teacher.pages.teacher-salary';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('teacher_portal.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('teacher_portal.nav_salary');
    }

    public $salaries = [];

    public function mount(): void
    {
        $teacher = Teacher::where('user_id', Auth::id())->first();

        if ($teacher) {

            $this->salaries = Salary::where('teacher_id', $teacher->id)
                ->orderBy('salary_month', 'desc')
                ->get();
        }
    }
}