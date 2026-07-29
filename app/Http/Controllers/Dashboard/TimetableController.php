<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Timetable;
use App\Models\SchoolClass;
use App\Models\Day;
use App\Models\Period;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\TimetableConflictChecker;
use App\Support\TimetableSlot;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    public function __construct(private readonly TimetableConflictChecker $conflictChecker)
    {
    }

    public function index()
    {
        $classes = SchoolClass::with('grade.stage')
            ->orderBy('grade_id')
            ->orderBy('name_ru')
            ->get();

        return view('dashboard.timetable.index', compact('classes'));
    }

    public function show(SchoolClass $class)
    {
        // The days table has no 'order' column (only id/name); order by id
        // to match how create()/pdf() already load days on this controller.
        $days = Day::orderBy('id')->get();
        $periods = Period::orderBy('number')->get();

        $timetable = Timetable::with(['subject','teacher'])
            ->where('class_id', $class->id)
            ->get()
            ->keyBy(fn($item) => $item->day_id.'_'.$item->period_id);

        return view('dashboard.timetable.show', compact('class','days','periods','timetable'));
    }

    public function create()
    {
        return view('dashboard.timetable.create', [
            'classes' => SchoolClass::all(),
            'days' => Day::all(),
            'periods' => Period::all(),
            'subjects' => Subject::all(),
            'teachers' => Teacher::where('is_active',1)->get(),
        ]);
    }

    public function teachersBySubject(Subject $subject)
{
    $teachers = $subject->teachers()
        ->where('is_active', true)
        ->orderBy('last_name')
        ->orderBy('first_name')
        ->get();

    if ($teachers->isEmpty()) {
        $teachers = Teacher::where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    return response()->json([
        'teachers' => $teachers->map(function ($teacher) {
            return [
                'id' => $teacher->id,
                'name' => $teacher->short_name . ' — ' . ($teacher->specialization ?? __('timetable.no_specialization')),
            ];
        }),
    ]);
}

    public function store(Request $request)
    {
        $data = $request->validate([
            'class_id'   => 'required|exists:classes,id',
            'day_id'     => 'required|exists:days,id',
            'period_id'  => 'required|exists:periods,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'required|exists:teachers,id',
            'room'       => 'nullable|string|max:50',
        ]);

        $result = $this->conflictChecker->check($this->slotFromData($data));

        if ($result->hasConflict()) {
            return back()->withInput()->withErrors(['error' => __($result->first())]);
        }

        Timetable::create($data);

        return redirect()
            ->route('dashboard.timetable.show', $data['class_id'])
            ->with('success', __('timetable.saved_success'));
    }

    public function update(Request $request, Timetable $timetable)
    {
        $data = $request->validate([
            'class_id'   => 'required|exists:classes,id',
            'day_id'     => 'required|exists:days,id',
            'period_id'  => 'required|exists:periods,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'required|exists:teachers,id',
            'room'       => 'nullable|string|max:50',
        ]);

        $result = $this->conflictChecker->check($this->slotFromData($data, [$timetable->id]));

        if ($result->hasConflict()) {
            return back()->withInput()->withErrors(['error' => __($result->first())]);
        }

        $timetable->update($data);

        return redirect()
            ->route('dashboard.timetable.show', $data['class_id'])
            ->with('success', __('timetable.updated_success'));
    }

    public function destroy(Timetable $timetable)
    {
        $classId = $timetable->class_id;

        $timetable->delete();

        return redirect()
            ->route('dashboard.timetable.show', $classId)
            ->with('success', __('timetable.deleted_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | DRAG & DROP MOVE (WITH CONFLICT)
    |--------------------------------------------------------------------------
    */
    public function move(Request $request, Timetable $timetable)
    {
        $data = $request->validate([
            'day_id'    => 'required|exists:days,id',
            'period_id' => 'required|exists:periods,id',
        ]);

        $newData = [
            'class_id'   => $timetable->class_id,
            'day_id'     => $data['day_id'],
            'period_id'  => $data['period_id'],
            'subject_id' => $timetable->subject_id,
            'teacher_id' => $timetable->teacher_id,
            'room'       => $timetable->room,
        ];

        $result = $this->conflictChecker->check($this->slotFromData($newData, [$timetable->id]));

        if ($result->hasConflict()) {
            return response()->json([
                'success' => false,
                'message' => __($result->first()),
            ], 422);
        }

        $timetable->update($data);

        return response()->json([
            'success' => true,
            'message' => __('timetable.moved_success'),
        ]);
    }

    public function pdf(SchoolClass $class)
    {
        $days = Day::all();
        $periods = Period::all();

        $timetable = Timetable::with(['subject','teacher'])
            ->where('class_id', $class->id)
            ->get()
            ->keyBy(fn($item) => $item->day_id.'_'.$item->period_id);

        $pdf = Pdf::loadView('dashboard.timetable.pdf', compact(
            'class','days','periods','timetable'
        ))->setPaper('a4','landscape');

        return $pdf->download('timetable-'.$class->id.'.pdf');
    }

    /*
    |--------------------------------------------------------------------------
    | Orchestration only — conflict business logic lives in
    | App\Services\TimetableConflictChecker and its rules.
    |--------------------------------------------------------------------------
    */
    private function slotFromData(array $data, array $ignoreIds = []): TimetableSlot
    {
        return new TimetableSlot(
            classId: (int) $data['class_id'],
            dayId: (int) $data['day_id'],
            periodId: (int) $data['period_id'],
            teacherId: (int) $data['teacher_id'],
            subjectId: (int) $data['subject_id'],
            room: $data['room'] ?? null,
            ignoreIds: $ignoreIds,
        );
    }
}