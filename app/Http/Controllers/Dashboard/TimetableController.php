<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Timetable;
use App\Models\SchoolClass;
use App\Models\Day;
use App\Models\Period;
use App\Models\Subject;
use App\Models\Teacher;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
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
        $days = Day::orderBy('order')->get();
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

        $error = $this->checkConflicts($data);

        if ($error) {
            return back()->withInput()->withErrors($error);
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

        $error = $this->checkConflicts($data, $timetable->id);

        if ($error) {
            return back()->withInput()->withErrors($error);
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

        $error = $this->checkConflicts($newData, $timetable->id);

        if ($error) {
            return response()->json([
                'success' => false,
                'message' => collect($error)->first(),
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
    | 🔥 CONFLICT ENGINE
    |--------------------------------------------------------------------------
    */
    private function checkConflicts($data, $ignoreId = null)
    {
        // 1️⃣ Class
        $class = Timetable::where('class_id',$data['class_id'])
            ->where('day_id',$data['day_id'])
            ->where('period_id',$data['period_id']);

        if ($ignoreId) $class->where('id','!=',$ignoreId);

        if ($class->exists()) {
            return ['error' => __('timetable.class_conflict')];
        }

        // 2️⃣ Teacher
        $teacher = Timetable::where('teacher_id',$data['teacher_id'])
            ->where('day_id',$data['day_id'])
            ->where('period_id',$data['period_id']);

        if ($ignoreId) $teacher->where('id','!=',$ignoreId);

        if ($teacher->exists()) {
            return ['error' => __('timetable.teacher_conflict')];
        }

        // 3️⃣ Room
        if (!empty($data['room'])) {
            $room = Timetable::where('room',$data['room'])
                ->where('day_id',$data['day_id'])
                ->where('period_id',$data['period_id']);

            if ($ignoreId) $room->where('id','!=',$ignoreId);

            if ($room->exists()) {
                return ['error' => __('timetable.room_conflict')];
            }
        }

        return null;
    }
}