<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Invoice;
use App\Models\CashTransaction;
use App\Models\CashAccount;
use App\Models\Teacher;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        /*
        |--------------------------------------------------------------------------
        | Basic Counts
        |--------------------------------------------------------------------------
        */
        $studentsCount = Student::count();
        $teachersCount = Teacher::count();
        $activeTeachersCount = Teacher::where('is_active', true)->count();
        $inactiveTeachersCount = Teacher::where('is_active', false)->count();

        $subjectsCount = Subject::count();
        $classesCount = DB::table('classes')->count();
        $invoicesCount = Invoice::count();

        /*
        |--------------------------------------------------------------------------
        | Finance
        |--------------------------------------------------------------------------
        */
        $totalIncome = CashTransaction::where('type', 'in')->sum('amount');

        $todayRevenue = CashTransaction::whereDate('created_at', today())
            ->where('type', 'in')
            ->sum('amount');

        $cashBalance = CashAccount::sum('balance');
        $transactionsCount = CashTransaction::count();

        /*
        |--------------------------------------------------------------------------
        | Daily Invoices Chart - Last 30 Days
        |--------------------------------------------------------------------------
        */
        $invoiceDaily = Invoice::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as total')
            )
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        /*
        |--------------------------------------------------------------------------
        | Cash Flow Chart - Last 30 Days
        |--------------------------------------------------------------------------
        */
        $cashDailyRaw = [
            'in' => CashTransaction::select(
                        DB::raw('DATE(created_at) as date'),
                        DB::raw('SUM(amount) as total')
                    )
                    ->where('type', 'in')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get(),

            'out' => CashTransaction::select(
                        DB::raw('DATE(created_at) as date'),
                        DB::raw('SUM(amount) as total')
                    )
                    ->where('type', 'out')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Latest Payments
        |--------------------------------------------------------------------------
        */
        $latestPayments = CashTransaction::latest()
            ->take(5)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Students Charts
        |--------------------------------------------------------------------------
        */
        $studentsByStage = Student::select('stage', DB::raw('count(*) as total'))
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->toArray();

        $studentsPerClass = DB::table('students')
            ->select('class_id', DB::raw('count(*) as total'))
            ->groupBy('class_id')
            ->pluck('total', 'class_id')
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | Teachers Charts
        |--------------------------------------------------------------------------
        */
        $teachersBySpecialization = Teacher::select(
                DB::raw("COALESCE(NULLIF(specialization, ''), 'Без специализации') as specialization"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('specialization')
            ->orderBy('specialization')
            ->pluck('total', 'specialization')
            ->toArray();

        $teachersStatusChart = [
            'Активные' => $activeTeachersCount,
            'Неактивные' => $inactiveTeachersCount,
        ];

        $topTeacherSubjects = DB::table('teacher_subject')
            ->join('subjects', 'teacher_subject.subject_id', '=', 'subjects.id')
            ->select('subjects.name_ru', DB::raw('COUNT(*) as total'))
            ->groupBy('subjects.id', 'subjects.name_ru')
            ->orderByDesc('total')
            ->limit(6)
            ->pluck('total', 'subjects.name_ru')
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | Attendance
        |--------------------------------------------------------------------------
        */
        $totalAttendance = DB::table('attendances')->count();

        $presentAttendance = DB::table('attendances')
            ->where('status', 'present')
            ->count();

        $attendanceRate = $totalAttendance > 0
            ? round(($presentAttendance / $totalAttendance) * 100, 2)
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Upcoming Exams
        |--------------------------------------------------------------------------
        */
        $upcomingExams = DB::table('exams')
            ->whereDate('exam_date', '>=', today())
            ->orderBy('exam_date')
            ->limit(5)
            ->get();

        return view('dashboard.index', [
            'studentsCount' => $studentsCount,
            'teachersCount' => $teachersCount,
            'activeTeachersCount' => $activeTeachersCount,
            'inactiveTeachersCount' => $inactiveTeachersCount,
            'subjectsCount' => $subjectsCount,
            'classesCount' => $classesCount,

            'invoicesCount' => $invoicesCount,
            'totalIncome' => $totalIncome,
            'todayRevenue' => $todayRevenue,
            'cashBalance' => $cashBalance,
            'transactionsCount' => $transactionsCount,

            'invoiceDaily' => $invoiceDaily,
            'cashDailyRaw' => $cashDailyRaw,
            'latestPayments' => $latestPayments,

            'studentsByStage' => $studentsByStage,
            'studentsPerClass' => $studentsPerClass,

            'teachersBySpecialization' => $teachersBySpecialization,
            'teachersStatusChart' => $teachersStatusChart,
            'topTeacherSubjects' => $topTeacherSubjects,

            'attendanceRate' => $attendanceRate,
            'upcomingExams' => $upcomingExams,
        ]);
    }
}