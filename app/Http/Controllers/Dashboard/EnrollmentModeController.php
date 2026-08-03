<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEnrollmentModeRequest;
use App\Http\Requests\UpdateEnrollmentModeRequest;
use App\Models\EnrollmentMode;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class EnrollmentModeController extends Controller
{
    public function __construct() { $this->middleware('permission:manage academic years'); }

    public function index(): View
    {
        return view('dashboard.enrollment-modes.index', ['modes'=>EnrollmentMode::ordered()->withCount('enrollments')->get()]);
    }
    public function create(): View { return view('dashboard.enrollment-modes.create'); }
    public function store(StoreEnrollmentModeRequest $request): RedirectResponse
    {
        EnrollmentMode::create($request->validated());
        return redirect()->route('dashboard.academic.enrollment-modes.index')->with('success','Форма обучения создана.');
    }
    public function edit(EnrollmentMode $enrollmentMode): View { return view('dashboard.enrollment-modes.edit',compact('enrollmentMode')); }
    public function update(UpdateEnrollmentModeRequest $request, EnrollmentMode $enrollmentMode): RedirectResponse
    {
        $enrollmentMode->update($request->validated());
        return redirect()->route('dashboard.academic.enrollment-modes.index')->with('success','Форма обучения обновлена.');
    }
}
