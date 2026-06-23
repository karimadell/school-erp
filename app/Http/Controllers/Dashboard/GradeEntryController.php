<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GradeEntryController extends Controller
{

    /**
     * Show grade entry form
     */
    public function create(): View
    {
        return view('dashboard.grades.create', [
            'students' => Student::orderBy('name')->get(),
            'subjects' => Subject::orderBy('name')->get(),
            'terms' => Term::orderBy('id')->get(),
        ]);
    }

    /**
     * Store grade
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required','exists:students,id'],
            'subject_id' => ['required','exists:subjects,id'],
            'term_id' => ['required','exists:terms,id'],
            'grade' => ['required','integer','min:2','max:5'],
            'comment' => ['nullable','string','max:500'],
        ]);

        Grade::create($data);

        return back()->with('success','Grade saved successfully');
    }

}
