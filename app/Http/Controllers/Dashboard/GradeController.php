<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Stage;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function index()
    {
        $grades = Grade::with('stage')
            ->orderBy('stage_id')
            ->orderBy('id')
            ->get();

        return view('dashboard.grades.index', compact('grades'));
    }

    public function create()
    {
        $stages = Stage::orderBy('id')->get();

        return view('dashboard.grades.create', compact('stages'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'stage_id' => 'required|exists:stages,id',
            'name' => 'required|string|max:255',
        ]);

        Grade::create([
            'stage_id' => $request->stage_id,
            'name' => $request->name,
        ]);

        return redirect()
            ->route('dashboard.grades.index')
            ->with('success', __('grades.created_success'));
    }

    public function show(string $id)
    {
        return redirect()->route('dashboard.grades.edit', $id);
    }

    public function edit(string $id)
    {
        $grade = Grade::findOrFail($id);
        $stages = Stage::orderBy('id')->get();

        return view('dashboard.grades.edit', compact('grade', 'stages'));
    }

    public function update(Request $request, string $id)
    {
        $grade = Grade::findOrFail($id);

        $request->validate([
            'stage_id' => 'required|exists:stages,id',
            'name' => 'required|string|max:255',
        ]);

        $grade->update([
            'stage_id' => $request->stage_id,
            'name' => $request->name,
        ]);

        return redirect()
            ->route('dashboard.grades.index')
            ->with('success', __('grades.updated_success'));
    }

    public function destroy(string $id)
    {
        $grade = Grade::findOrFail($id);
        $grade->delete();

        return redirect()
            ->route('dashboard.grades.index')
            ->with('success', __('grades.deleted_success'));
    }
}