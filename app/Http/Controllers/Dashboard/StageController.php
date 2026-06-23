<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Stage;
use Illuminate\Http\Request;

class StageController extends Controller
{

    public function index()
    {
        $stages = Stage::orderBy('order')->get();

        return view('dashboard.stages.index', compact('stages'));
    }

    public function create()
    {
        return view('dashboard.stages.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required'
        ]);

        Stage::create([
            'name' => $request->name,
            'description' => $request->description,
            'order' => $request->order ?? 1,
            'is_active' => 1
        ]);

        return redirect()
            ->route('dashboard.stages.index')
            ->with('success','Stage created');
    }

    public function edit(Stage $stage)
    {
        return view('dashboard.stages.edit', compact('stage'));
    }

    public function update(Request $request, Stage $stage)
    {
        $stage->update($request->all());

        return redirect()
            ->route('dashboard.stages.index')
            ->with('success','Stage updated');
    }

    public function destroy(Stage $stage)
    {
        $stage->delete();

        return back()->with('success','Stage deleted');
    }
}