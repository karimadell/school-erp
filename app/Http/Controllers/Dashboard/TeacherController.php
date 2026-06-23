<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\TeacherDocument;
use App\Models\Day;
use App\Models\Period;
use App\Exports\TeachersExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class TeacherController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */
    public function index(Request $request)
    {
        $teachers = Teacher::with('subjects')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->q;

                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('patronymic', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('specialization', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('specialization'), function ($query) use ($request) {
                $query->where('specialization', $request->specialization);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('is_active', $request->status);
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $specializations = Teacher::whereNotNull('specialization')
            ->where('specialization', '!=', '')
            ->distinct()
            ->orderBy('specialization')
            ->pluck('specialization');

        return view('dashboard.teachers.index', compact('teachers', 'specializations'));
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */
    public function create()
    {
        $subjects = Subject::orderBy('name_ru')->get();
        return view('dashboard.teachers.create', compact('subjects'));
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */
    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'patronymic' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'specialization' => 'nullable|string|max:255',
            'hire_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
        ]);

        $teacher = Teacher::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'specialization' => $data['specialization'] ?? null,
            'hire_date' => $data['hire_date'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        $teacher->subjects()->sync($request->input('subjects', []));

        return redirect()
            ->route('dashboard.teachers.index')
            ->with('success', __('teachers.created_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */
    public function show(Teacher $teacher)
    {
        $teacher->load([
            'subjects',
            'documents',
            'timetables.subject',
            'timetables.class',
            'timetables.day',
            'timetables.period',
        ]);

        $days = Day::orderBy('order')->get();
        $periods = Period::orderBy('number')->get();

        return view('dashboard.teachers.show', compact('teacher', 'days', 'periods'));
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT
    |--------------------------------------------------------------------------
    */
    public function edit(Teacher $teacher)
    {
        $subjects = Subject::orderBy('name_ru')->get();
        $teacher->load('subjects');

        return view('dashboard.teachers.edit', compact('teacher', 'subjects'));
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */
    public function update(Request $request, Teacher $teacher)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'patronymic' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'specialization' => 'nullable|string|max:255',
            'hire_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
        ]);

        $teacher->update($data);
        $teacher->subjects()->sync($request->input('subjects', []));

        return redirect()
            ->route('dashboard.teachers.index')
            ->with('success', __('teachers.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */
    public function destroy(Teacher $teacher)
    {
        $teacher->subjects()->detach();

        foreach ($teacher->documents as $document) {
            Storage::disk('public')->delete($document->file_path);
            $document->delete();
        }

        $teacher->delete();

        return redirect()
            ->route('dashboard.teachers.index')
            ->with('success', __('teachers.deleted_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | PRINT
    |--------------------------------------------------------------------------
    */
    public function print()
    {
        $teachers = Teacher::with('subjects')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return view('dashboard.teachers.print', compact('teachers'));
    }

    /*
    |--------------------------------------------------------------------------
    | PDF LIST
    |--------------------------------------------------------------------------
    */
    public function pdf()
    {
        $teachers = Teacher::with('subjects')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $pdf = Pdf::loadView('dashboard.teachers.pdf', compact('teachers'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('teachers-list.pdf');
    }

    /*
    |--------------------------------------------------------------------------
    | SINGLE TEACHER PDF ✅ (نسخة واحدة فقط)
    |--------------------------------------------------------------------------
    */
    public function teacherPdf(Teacher $teacher)
    {
        $teacher->load([
            'subjects',
            'documents',
            'timetables.subject',
            'timetables.class',
        ]);

        $days = Day::orderBy('order')->get();
        $periods = Period::orderBy('number')->get();

        $pdf = Pdf::loadView(
            'dashboard.teachers.single-pdf',
            compact('teacher', 'days', 'periods')
        )->setPaper('a4', 'landscape');

        return $pdf->download('teacher-' . $teacher->id . '.pdf');
    }

    /*
    |--------------------------------------------------------------------------
    | EXCEL
    |--------------------------------------------------------------------------
    */
    public function excel()
    {
        return Excel::download(
            new TeachersExport(),
            'teachers-list.xlsx'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DOCUMENTS
    |--------------------------------------------------------------------------
    */
    public function documents(Teacher $teacher)
    {
        $teacher->load('documents');
        return view('dashboard.teachers.documents', compact('teacher'));
    }

    public function storeDocument(Request $request, Teacher $teacher)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'document_date' => 'nullable|date',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store('teacher-documents', 'public');

        $teacher->documents()->create([
            'title' => $data['title'],
            'document_date' => $data['document_date'] ?? null,
            'file_path' => $path,
            'file_type' => $file->getClientOriginalExtension(),
        ]);

        return redirect()
            ->route('dashboard.teachers.documents', $teacher)
            ->with('success', __('teachers.document_uploaded_success'));
    }

    public function deleteDocument(TeacherDocument $document)
    {
        Storage::disk('public')->delete($document->file_path);

        $teacher = $document->teacher;
        $document->delete();

        return redirect()
            ->route('dashboard.teachers.documents', $teacher)
            ->with('success', __('teachers.document_deleted_success'));
    }
}