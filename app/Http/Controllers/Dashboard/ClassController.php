<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SchoolClass;
use App\Models\Grade;

class ClassController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $classes = SchoolClass::with(['grade.stage'])
            ->latest()
            ->get();

        return view('dashboard.classes.index', compact('classes'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $grades = Grade::with('stage')
            ->orderBy('stage_id')
            ->orderBy('id')
            ->get();

        $stages = \App\Models\Stage::orderBy('id')->get();

        return view('dashboard.classes.create', compact('grades', 'stages'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'code' => 'required|string|max:50',
            'name_ru' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
        ]);

        SchoolClass::create([
            'grade_id' => $request->grade_id,
            'code' => $request->code,
            'name_ar' => $request->name_ru, // auto copy
            'name_ru' => $request->name_ru,
            'capacity' => $request->capacity ?? 25,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()
            ->route('dashboard.classes.index')
            ->with('success', __('classes.created_success'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $class = SchoolClass::with(['grade.stage'])->findOrFail($id);

        return view('dashboard.classes.show', compact('class'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $class = SchoolClass::findOrFail($id);

        $grades = Grade::with('stage')
            ->orderBy('stage_id')
            ->orderBy('id')
            ->get();

        $stages = \App\Models\Stage::orderBy('id')->get();

        return view('dashboard.classes.edit', compact('class', 'grades', 'stages'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $class = SchoolClass::findOrFail($id);

        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'code' => 'required|string|max:50',
            'name_ru' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $class->update([
            'grade_id' => $request->grade_id,
            'code' => $request->code,
            'name_ar' => $request->name_ru,
            'name_ru' => $request->name_ru,
            'capacity' => $request->capacity ?? 25,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()
            ->route('dashboard.classes.index')
            ->with('success', __('classes.updated_success'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $class = SchoolClass::findOrFail($id);
        $class->delete();

        return redirect()
            ->route('dashboard.classes.index')
            ->with('success', __('classes.deleted_success'));
    }
}