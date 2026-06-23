<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use Illuminate\Http\Request;

class StudentsController extends Controller
{
    /**
     * Display a listing of the students
     * With filtering by stage / grade / class
     */
    public function index(Request $request)
    {
        $query = Student::with(['stage', 'grade', 'classRoom'])

            ->when($request->stage_id, function ($q) use ($request) {
                $q->where('stage_id', $request->stage_id);
            })

            ->when($request->grade_id, function ($q) use ($request) {
                $q->where('grade_id', $request->grade_id);
            })

            ->when($request->class_room_id, function ($q) use ($request) {
                $q->where('class_room_id', $request->class_room_id);
            });

        $students = $query->paginate($request->get('per_page', 10));

        return $this->success($students, 'Students list');
    }

    /**
     * Store a newly created student
     */
    public function store(StoreStudentRequest $request)
    {
        $student = Student::create($request->validated());

        $student->load(['stage', 'grade', 'classRoom']);

        return $this->success($student, 'Student created', 201);
    }

    /**
     * Display a specific student
     */
    public function show($id)
    {
        $student = Student::with(['stage', 'grade', 'classRoom'])
            ->findOrFail($id);

        return $this->success($student, 'Student details');
    }

    /**
     * Update a student
     */
    public function update(UpdateStudentRequest $request, $id)
    {
        $student = Student::findOrFail($id);

        $student->update($request->validated());

        $student->load(['stage', 'grade', 'classRoom']);

        return $this->success($student, 'Student updated');
    }

    /**
     * Soft delete a student
     */
    public function destroy($id)
    {
        Student::findOrFail($id)->delete();

        return $this->success(null, 'Student deleted');
    }
}