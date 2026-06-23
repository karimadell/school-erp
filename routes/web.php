<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\StudentController;
use App\Http\Controllers\Dashboard\EnrollmentController;
use App\Http\Controllers\Dashboard\InvoiceController;
use App\Http\Controllers\Dashboard\FeeController;
use App\Http\Controllers\Dashboard\FeePriceController;
use App\Http\Controllers\Dashboard\ClassController;
use App\Http\Controllers\Dashboard\SubjectController;
use App\Http\Controllers\Dashboard\TeacherController;
use App\Http\Controllers\Dashboard\StageController;
use App\Http\Controllers\Dashboard\GradeController;
use App\Http\Controllers\Dashboard\StudentGradeController;
use App\Http\Controllers\Dashboard\TimetableController;
use App\Http\Controllers\Dashboard\AttendanceController;
use App\Http\Controllers\Dashboard\ExamController;
use App\Http\Controllers\Dashboard\ReportCardController;
use App\Http\Controllers\Dashboard\JournalController;
use App\Http\Controllers\Dashboard\ReportController;
use App\Http\Controllers\Dashboard\DebtController;
use App\Http\Controllers\Dashboard\TransportController;
use App\Http\Controllers\Dashboard\SalaryController;
use App\Http\Controllers\Dashboard\CashReportController;

use App\Http\Controllers\Cash\CashTransactionController;
use App\Http\Controllers\Cash\CashTransferController;
use App\Http\Controllers\CashAccountController;

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\AuditLogController;

Route::get('/', function () {
    return redirect('/dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard/cash-reports', [CashReportController::class, 'index'])
        ->name('cash.reports.index');

    Route::get('salaries', [SalaryController::class, 'index'])
        ->name('dashboard.salaries.index');

    Route::get('salaries/create', [SalaryController::class, 'create'])
        ->name('dashboard.salaries.create');

    Route::post('salaries/store', [SalaryController::class, 'store'])
        ->name('dashboard.salaries.store');

    Route::get('salaries/payslip/{id}', [SalaryController::class, 'payslip'])
        ->name('dashboard.salaries.payslip');

    Route::post('salaries/import', [SalaryController::class, 'import'])
        ->name('dashboard.salaries.import');
});

Route::middleware(['auth'])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {

        Route::get('/', [DashboardController::class, 'index'])->name('index');

        Route::resource('students', StudentController::class);

        Route::get('students/{id}/financial', [StudentController::class, 'financial'])
            ->name('students.financial');

        Route::get('enrollments', [EnrollmentController::class, 'index'])
            ->name('enrollments.index');

        Route::get('students/{student}/enrollments/create', [EnrollmentController::class, 'create'])
            ->name('enrollments.create');

        Route::post('students/{student}/enrollments', [EnrollmentController::class, 'store'])
            ->name('enrollments.store');

        Route::get('students/{student}/enrollments/history', [EnrollmentController::class, 'history'])
            ->name('enrollments.history');

        Route::get('enrollments/{enrollment}/edit', [EnrollmentController::class, 'edit'])
            ->name('enrollments.edit');

        Route::put('enrollments/{enrollment}', [EnrollmentController::class, 'update'])
            ->name('enrollments.update');

        Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])
            ->name('enrollments.destroy');

        Route::resource('stages', StageController::class);
        Route::resource('grades', GradeController::class);
        Route::resource('classes', ClassController::class);
        Route::resource('subjects', SubjectController::class);

        Route::get('teachers/print', [TeacherController::class, 'print'])
            ->name('teachers.print');

        Route::get('teachers/pdf', [TeacherController::class, 'pdf'])
            ->name('teachers.pdf');

        Route::get('teachers/excel', [TeacherController::class, 'excel'])
            ->name('teachers.excel');

        Route::get('teachers/{teacher}/pdf', [TeacherController::class, 'teacherPdf'])
            ->name('teachers.single.pdf');

        Route::get('teachers/{teacher}/documents', [TeacherController::class, 'documents'])
            ->name('teachers.documents');

        Route::post('teachers/{teacher}/documents', [TeacherController::class, 'storeDocument'])
            ->name('teachers.documents.store');

        Route::delete('teachers/documents/{document}', [TeacherController::class, 'deleteDocument'])
            ->name('teachers.documents.delete');

        Route::resource('teachers', TeacherController::class);

        Route::get('student-grades/bulk', [StudentGradeController::class, 'bulkForm'])
            ->name('student-grades.bulk.form');

        Route::get('student-grades/bulk/students', [StudentGradeController::class, 'bulkStudents'])
            ->name('student-grades.bulk.students');

        Route::post('student-grades/bulk', [StudentGradeController::class, 'bulkStore'])
            ->name('student-grades.bulk.store');

        Route::get('student-grades/report', [StudentGradeController::class, 'reportForm'])
            ->name('student-grades.report.form');

        Route::post('student-grades/report', [StudentGradeController::class, 'reportGenerate'])
            ->name('student-grades.report.generate');

        Route::post('student-grades/report/pdf', [StudentGradeController::class, 'reportPdf'])
            ->name('student-grades.report.pdf');

        Route::post('student-grades/report/excel', [StudentGradeController::class, 'reportExcel'])
            ->name('student-grades.report.excel');

        Route::resource('student-grades', StudentGradeController::class);

        Route::get('timetable', [TimetableController::class, 'index'])
            ->name('timetable.index');

        Route::get('timetable/create', [TimetableController::class, 'create'])
            ->name('timetable.create');

        Route::post('timetable', [TimetableController::class, 'store'])
            ->name('timetable.store');

        Route::get('timetable/{timetable}/edit', [TimetableController::class, 'edit'])
            ->name('timetable.edit');

        Route::put('timetable/{timetable}', [TimetableController::class, 'update'])
            ->name('timetable.update');

        Route::delete('timetable/{timetable}', [TimetableController::class, 'destroy'])
            ->name('timetable.destroy');

        Route::post('timetable/{timetable}/move', [TimetableController::class, 'move'])
            ->name('timetable.move');

        Route::get('timetable/subject/{subject}/teachers', [TimetableController::class, 'teachersBySubject'])
            ->name('timetable.subject.teachers');

        Route::get('timetable/class/{class}/pdf', [TimetableController::class, 'pdf'])
            ->name('timetable.pdf');

        Route::get('timetable/class/{class}', [TimetableController::class, 'show'])
            ->name('timetable.show');

        Route::get('attendance', [AttendanceController::class, 'index'])
            ->name('attendance.index');

        Route::get('attendance/create', [AttendanceController::class, 'take'])
            ->name('attendance.create');

        Route::post('attendance', [AttendanceController::class, 'store'])
            ->name('attendance.store');

        Route::get('attendance/reports/class', [AttendanceController::class, 'classReport'])
            ->name('attendance.reports.class');

        Route::get('attendance/reports/student', [AttendanceController::class, 'studentReport'])
            ->name('attendance.reports.student');

        Route::get('attendance/dashboard', [AttendanceController::class, 'dashboard'])
            ->name('attendance.dashboard');

        Route::resource('exams', ExamController::class);

        Route::get('report-cards', [ReportCardController::class, 'index'])
            ->name('report_cards.index');

        Route::get('report-cards/{id}', [ReportCardController::class, 'show'])
            ->name('report_cards.show');

        Route::get('report-cards/{id}/print', [ReportCardController::class, 'print'])
            ->name('report_cards.print');

        Route::get('restaurant-report', [ReportController::class, 'restaurant'])
            ->name('reports.restaurant');

        Route::get('restaurant-report-weekly', [ReportController::class, 'restaurantWeekly'])
            ->name('reports.restaurant.weekly');

        Route::get('restaurant-kitchen-report', [ReportController::class, 'restaurantKitchen'])
            ->name('reports.restaurant.kitchen');

        Route::get('restaurant-kitchen-pdf', [ReportController::class, 'restaurantKitchenPdf'])
            ->name('reports.restaurant.kitchen.pdf');

        Route::get('debts', [DebtController::class, 'index'])
            ->name('debts.index');

        Route::get('debts/{id}', [DebtController::class, 'show'])
            ->name('debts.show');

        Route::post('debts/pay/{invoice}', [DebtController::class, 'pay'])
            ->name('debts.pay');

        Route::get('debts/receipt/{invoice}', [DebtController::class, 'receipt'])
            ->name('debts.receipt');

        Route::get('journal/{class}', [JournalController::class, 'index'])
            ->name('journal.index');

        Route::post('journal/save', [JournalController::class, 'save'])
            ->name('journal.save');

        /*
        |--------------------------------------------------------------------------
        | Fees / Price List
        |--------------------------------------------------------------------------
        */
        Route::resource('fees', FeeController::class);
        Route::patch('fees/{fee}/toggle', [FeeController::class, 'toggle'])
             ->name('fees.toggle');
        Route::resource('fee-prices', FeePriceController::class);

        /*
        |--------------------------------------------------------------------------
        | Invoices
        |--------------------------------------------------------------------------
        */
        Route::resource('invoices', InvoiceController::class);

        Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])
            ->name('invoices.pay');

        Route::post('invoices/{invoice}/refund', [InvoiceController::class, 'refund'])
            ->name('invoices.refund');

        Route::get('invoices/{invoice}/print', [InvoiceController::class, 'print'])
            ->name('invoices.print');

        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
            ->name('invoices.pdf');

        Route::prefix('cash')
            ->name('cash.')
            ->group(function () {

                Route::get('accounts', [CashAccountController::class, 'index'])
                    ->name('accounts');

                Route::get('transactions', [CashTransactionController::class, 'index'])
                    ->name('transactions');

                Route::post('transactions/in', [CashTransactionController::class, 'storeIn'])
                    ->name('transactions.in');

                Route::post('transactions/out', [CashTransactionController::class, 'storeOut'])
                    ->name('transactions.out');

                Route::get('transfers', [CashTransferController::class, 'index'])
                    ->name('transfers');

                Route::get('transfer/create', [CashTransferController::class, 'create'])
                    ->name('transfer.form');

                Route::post('transfer/store', [CashTransferController::class, 'store'])
                    ->name('transfer.store');

                Route::get('income', [CashTransactionController::class, 'income'])
                    ->name('income');

                Route::post('income/store', [CashTransactionController::class, 'storeIncome'])
                    ->name('income.store');

                Route::get('expenses', [CashTransactionController::class, 'expenses'])
                    ->name('expenses');

                Route::post('expenses/store', [CashTransactionController::class, 'storeExpense'])
                    ->name('expenses.store');

                Route::post('transfer', [CashAccountController::class, 'transfer'])
                    ->name('transfer');

                Route::get('reports', [CashTransactionController::class, 'reports'])
                    ->name('reports');
            });

        Route::get('transport', [TransportController::class, 'index'])
            ->name('transport.index');

        Route::get('transport/create', [TransportController::class, 'create'])
            ->name('transport.create');

        Route::post('transport/store', [TransportController::class, 'store'])
            ->name('transport.store');

        Route::get('transport/subscribe', [TransportController::class, 'subscribeForm'])
            ->name('transport.subscribe.form');

        Route::post('transport/subscribe', [TransportController::class, 'subscribe'])
            ->name('transport.subscribe');

        Route::get('transport/subscriptions', [TransportController::class, 'subscriptions'])
            ->name('transport.subscriptions');

        Route::get('transport/move/{id}', [TransportController::class, 'moveForm'])
            ->name('transport.move.form');

        Route::post('transport/move/{id}', [TransportController::class, 'move'])
            ->name('transport.move');

        Route::post('transport/stop/{id}', [TransportController::class, 'stop'])
            ->name('transport.stop');

        Route::get('transport/report', [TransportController::class, 'report'])
            ->name('transport.report');

        Route::get('transport/report-pdf', [TransportController::class, 'reportPdf'])
            ->name('transport.report.pdf');

        Route::post('transport/monthly-invoices', [TransportController::class, 'monthlyInvoices'])
            ->name('transport.monthly.invoices');

        Route::prefix('admin')
            ->name('admin.')
            ->middleware('role:admin')
            ->group(function () {

                Route::get('users', [UserController::class, 'index'])
                    ->name('users.index');

                Route::get('roles', [RoleController::class, 'index'])
                    ->name('roles.index');

                Route::get('audit-logs', [AuditLogController::class, 'index'])
                    ->name('audit.logs.index');
            });
    });

Route::get('lang/{lang}', function ($lang) {
    session()->put('locale', $lang);

    return redirect()->back();
})->name('lang.switch');

require __DIR__ . '/auth.php';