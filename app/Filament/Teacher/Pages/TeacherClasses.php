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

    public function mount(): void
    {
        $teacher = Teacher::where('user_id', Auth::id())->first();

        if ($teacher) {

            $this->classes = $teacher
                ->subjects()
                ->with('classes')
                ->get();
        }
    }
}