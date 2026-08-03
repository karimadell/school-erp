<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\FinanceServiceController;
use App\Http\Controllers\Dashboard\FinanceTariffController;
use App\Http\Controllers\Dashboard\FinanceTariffRolloverController;
use App\Http\Controllers\Dashboard\CurriculumController;
use App\Http\Controllers\Dashboard\StudentController;
use App\Http\Controllers\Dashboard\EnrollmentController;
use App\Http\Controllers\Dashboard\InvoiceController;
use App\Http\Controllers\Dashboard\QuickStudentRegistrationController;
use App\Http\Controllers\Dashboard\FeeController;
use App\Http\Controllers\Dashboard\FeePriceController;
use App\Http\Controllers\Dashboard\ClassController;
use App\Http\Controllers\Dashboard\SubjectController;
use App\Http\Controllers\Dashboard\TeacherController;
use App\Http\Controllers\Dashboard\StageController;
use App\Http\Controllers\Dashboard\GradeController;
use App\Http\Controllers\Dashboard\StudentGradeController;
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

use App\Http\Controllers\ProfileController;

Route::get('/', function () {
    return redirect('/dashboard');
});

Route::middleware(['auth', 'administrative'])->group(function () {
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

// Name alias for the dashboard.index route defined below (same URI, same
// controller action — no duplicated logic). Several stock Breeze auth
// controllers (registration, email verification, password confirmation)
// call route('dashboard'), so that name must resolve.
//
// This cannot be a second Route::get('/dashboard', ...)->name('dashboard'):
// Laravel's RouteCollection keeps only one Route per exact method-set + URI,
// so a second GET registration on the same URI silently evicts the earlier
// one from the name lookup table, leaving whichever name was registered
// first unresolvable (this previously left route('dashboard') broken).
// Registering the alias under a verb browsers never send for navigation
// (OPTIONS, which — unlike GET — Laravel does not auto-pair with HEAD) gives
// it a distinct method-set key, so both names coexist without collision,
// while real GET/HEAD traffic to /dashboard is still handled exclusively by
// dashboard.index below.
Route::match(['OPTIONS'], '/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'administrative'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'administrative'])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {

        Route::get('/', [DashboardController::class, 'index'])->name('index');

        Route::resource('students', StudentController::class);

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
        Route::resource('curricula', CurriculumController::class)->except(['show']);

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
        Route::prefix('finance')->name('finance.')->group(function () {
            Route::resource('services', FinanceServiceController::class)
                ->parameters(['services' => 'fee'])
                ->except(['destroy']);
            Route::resource('tariffs', FinanceTariffController::class)
                ->parameters(['tariffs' => 'feePrice'])->only(['index', 'create', 'store', 'show']);
            Route::get('tariffs-rollover', [FinanceTariffRolloverController::class, 'create'])->name('tariffs.rollover.create');
            Route::post('tariffs-rollover/preview', [FinanceTariffRolloverController::class, 'preview'])->name('tariffs.rollover.preview');
            Route::post('tariffs-rollover', [FinanceTariffRolloverController::class, 'store'])->name('tariffs.rollover.store');
        });

        /*
        |--------------------------------------------------------------------------
        | Invoices
        |--------------------------------------------------------------------------
        */
        Route::resource('invoices', InvoiceController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::get('quick-registration', [QuickStudentRegistrationController::class, 'create'])
            ->name('quick-registration.create');
        Route::post('quick-registration', [QuickStudentRegistrationController::class, 'store'])
            ->name('quick-registration.store');
        Route::post('quick-registration/price', [QuickStudentRegistrationController::class, 'price'])
            ->name('quick-registration.price');
        Route::get('quick-registration/{invoice}', [QuickStudentRegistrationController::class, 'summary'])
            ->name('quick-registration.summary');

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

                Route::get('accounts/create', [CashAccountController::class, 'create'])
                    ->name('accounts.create');

                Route::post('accounts/store', [CashAccountController::class, 'store'])
                    ->name('accounts.store');

                Route::get('accounts/{account}/edit', [CashAccountController::class, 'edit'])
                    ->name('accounts.edit');

                Route::put('accounts/{account}', [CashAccountController::class, 'update'])
                    ->name('accounts.update');

                Route::delete('accounts/{account}', [CashAccountController::class, 'destroy'])
                    ->name('accounts.destroy');

                Route::get('ledger', [CashAccountController::class, 'ledger'])
                    ->name('ledger');

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
            ->group(function () {

                Route::get('users', [UserController::class, 'index'])
                    ->middleware('permission:manage users')
                    ->name('users.index');

                Route::get('roles', [RoleController::class, 'index'])
                    ->middleware('permission:manage roles')
                    ->name('roles.index');

                Route::get('audit-logs', [AuditLogController::class, 'index'])
                    ->middleware('permission:view audit logs')
                    ->name('audit.logs.index');
            });
    });

Route::get('lang/{lang}', function ($lang) {
    session()->put('locale', $lang);

    return redirect()->back();
})->name('lang.switch');

require __DIR__ . '/auth.php';