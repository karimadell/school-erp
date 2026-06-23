<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Enrollment;
use App\Models\Period;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index()
    {
        $classes = SchoolClass::orderBy('name_ru')->get();

        return view('dashboard.attendance.index', compact('classes'));
    }

    public function take(Request $request)
    {
        $request->validate([
            'class_id' => 'required',
            'date' => 'required|date',
            'type' => 'required|in:daily,period',
        ]);

        $class = SchoolClass::findOrFail($request->class_id);

        $students = Enrollment::with('student')
            ->where('class_id', $class->id)
            ->get();

        $periods = Period::orderBy('number')->get();

        return view('dashboard.attendance.take', [
            'class' => $class,
            'students' => $students,
            'periods' => $periods,
            'date' => $request->date,
            'type' => $request->type,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'class_id' => 'required',
            'date' => 'required|date',
            'type' => 'required|in:daily,period',
            'attendance' => 'required|array',
        ]);

        $type = $request->type;
        $date = $request->date;

        foreach ($request->attendance as $enrollmentId => $periods) {
            if ($type === 'daily') {
                $status = $periods['status'] ?? 'present';
                $key = "daily-{$enrollmentId}-{$date}";

                Attendance::updateOrCreate(
                    ['attendance_key' => $key],
                    [
                        'enrollment_id' => $enrollmentId,
                        'period_id' => null,
                        'date' => $date,
                        'type' => 'daily',
                        'status' => $status,
                    ]
                );
            }

            if ($type === 'period') {
                foreach ($periods as $periodId => $status) {
                    $key = "period-{$enrollmentId}-{$date}-{$periodId}";

                    Attendance::updateOrCreate(
                        ['attendance_key' => $key],
                        [
                            'enrollment_id' => $enrollmentId,
                            'period_id' => $periodId,
                            'date' => $date,
                            'type' => 'period',
                            'status' => $status,
                        ]
                    );
                }
            }
        }

        return redirect()
            ->back()
            ->with('success', __('attendance.saved_success'));
    }

    public function studentReport(Request $request)
    {
        $students = Student::orderBy('name')->get();

        $query = Attendance::with('enrollment.student');

        if ($request->filled('student_id')) {
            $query->whereHas('enrollment', function ($q) use ($request) {
                $q->where('student_id', $request->student_id);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->to);
        }

        $attendances = $query->get();

        $stats = [
            'present' => $attendances->where('status', 'present')->count(),
            'absent' => $attendances->where('status', 'absent')->count(),
            'late' => $attendances->where('status', 'late')->count(),
            'excused' => $attendances->where('status', 'excused')->count(),
        ];

        $total = $attendances->count();

        $percentage = $total > 0
            ? round(($stats['present'] / $total) * 100, 2)
            : 0;

        return view('dashboard.attendance.reports.student', compact(
            'students',
            'attendances',
            'stats',
            'percentage'
        ));
    }

    public function classReport(Request $request)
    {
        $classes = SchoolClass::orderBy('name_ru')->get();

        $attendances = collect();
        $summary = collect();
        $selectedClass = null;

        if ($request->filled('class_id')) {
            $selectedClass = SchoolClass::findOrFail($request->class_id);

            $query = Attendance::with('enrollment.student')
                ->whereHas('enrollment', function ($q) use ($request) {
                    $q->where('class_id', $request->class_id);
                });

            if ($request->filled('from')) {
                $query->whereDate('date', '>=', $request->from);
            }

            if ($request->filled('to')) {
                $query->whereDate('date', '<=', $request->to);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            $attendances = $query->get();

            $summary = $attendances
                ->groupBy('enrollment_id')
                ->map(function ($items) {
                    $student = $items->first()->enrollment->student ?? null;
                    $total = $items->count();
                    $present = $items->where('status', 'present')->count();

                    return [
                        'student' => $student,
                        'total' => $total,
                        'present' => $present,
                        'absent' => $items->where('status', 'absent')->count(),
                        'late' => $items->where('status', 'late')->count(),
                        'excused' => $items->where('status', 'excused')->count(),
                        'percentage' => $total > 0 ? round(($present / $total) * 100, 2) : 0,
                    ];
                });
        }

        return view('dashboard.attendance.reports.class', compact(
            'classes',
            'selectedClass',
            'attendances',
            'summary'
        ));
    }

    public function dashboard()
    {
        $data = Attendance::selectRaw("
                DATE(date) as day,
                SUM(status = 'present') as present,
                SUM(status = 'absent') as absent,
                SUM(status = 'late') as late,
                SUM(status = 'excused') as excused
            ")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return view('dashboard.attendance.dashboard', compact('data'));
    }
}