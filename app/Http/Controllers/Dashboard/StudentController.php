<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $students = Student::with('class')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->q;

                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name_ru', 'like', "%{$q}%")
                        ->orWhere('last_name_ru', 'like', "%{$q}%")
                        ->orWhere('patronymic_ru', 'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('gender'), function ($query) use ($request) {
                $query->where('gender', $request->gender);
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('dashboard.students.index', compact('students'));
    }

    public function create()
    {
        $classes = SchoolClass::orderBy('name_ru')->get();

        return view('dashboard.students.create', compact('classes'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:classes,id',

            'last_name_ru' => 'required|string|max:255',
            'first_name_ru' => 'required|string|max:255',
            'patronymic_ru' => 'nullable|string|max:255',

            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',

            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',

            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'nationality' => 'nullable|string|max:100',
            'address' => 'nullable|string',

            'photo' => 'nullable|image|max:2048',
            'documents.*' => 'nullable|file|max:4096',
        ]);

        $data = $request->only([
            'class_id',
            'last_name_ru',
            'first_name_ru',
            'patronymic_ru',
            'first_name',
            'last_name',
            'birth_date',
            'gender',
            'phone',
            'email',
            'nationality',
            'address',
        ]);

        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('students/photos', 'public');
        }

        if ($request->hasFile('documents')) {
            $files = [];

            foreach ($request->file('documents') as $file) {
                $files[] = $file->store('students/documents', 'public');
            }

            $data['documents'] = $files;
        }

        Student::create($data);

        return redirect()
            ->route('dashboard.students.index')
            ->with('success', __('students.created_success'));
    }

    public function show($id)
    {
        $student = Student::with(['class', 'grades.subject', 'grades.exam'])
            ->findOrFail($id);

        return view('dashboard.students.show', compact('student'));
    }

    public function edit($id)
    {
        $student = Student::findOrFail($id);
        $classes = SchoolClass::orderBy('name_ru')->get();

        return view('dashboard.students.edit', compact('student', 'classes'));
    }

    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);

        $request->validate([
            'class_id' => 'required|exists:classes,id',

            'last_name_ru' => 'required|string|max:255',
            'first_name_ru' => 'required|string|max:255',
            'patronymic_ru' => 'nullable|string|max:255',

            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',

            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',

            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'nationality' => 'nullable|string|max:100',
            'address' => 'nullable|string',

            'photo' => 'nullable|image|max:2048',
            'documents.*' => 'nullable|file|max:4096',
        ]);

        $data = $request->only([
            'class_id',
            'last_name_ru',
            'first_name_ru',
            'patronymic_ru',
            'first_name',
            'last_name',
            'birth_date',
            'gender',
            'phone',
            'email',
            'nationality',
            'address',
        ]);

        if ($request->hasFile('photo')) {
            if ($student->photo) {
                Storage::disk('public')->delete($student->photo);
            }

            $data['photo'] = $request->file('photo')->store('students/photos', 'public');
        }

        if ($request->hasFile('documents')) {
            if (!empty($student->documents)) {
                foreach ((array) $student->documents as $file) {
                    Storage::disk('public')->delete($file);
                }
            }

            $files = [];

            foreach ($request->file('documents') as $file) {
                $files[] = $file->store('students/documents', 'public');
            }

            $data['documents'] = $files;
        }

        $student->update($data);

        return redirect()
            ->route('dashboard.students.index')
            ->with('success', __('students.updated_success'));
    }

    public function destroy($id)
    {
        $student = Student::findOrFail($id);

        if ($student->photo) {
            Storage::disk('public')->delete($student->photo);
        }

        if (!empty($student->documents)) {
            foreach ((array) $student->documents as $file) {
                Storage::disk('public')->delete($file);
            }
        }

        $student->delete();

        return redirect()
            ->route('dashboard.students.index')
            ->with('success', __('students.deleted_success'));
    }
}