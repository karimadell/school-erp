<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\FinanceServiceController;
use App\Http\Controllers\Dashboard\FinanceTariffController;
use App\Http\Controllers\Dashboard\FinanceTariffRolloverController;
use App\Http\Controllers\Dashboard\FinancePriceListPdfController;
use App\Http\Controllers\Dashboard\FinanceOperationsController;
use App\Http\Controllers\Dashboard\CashSessionController;
use App\Http\Controllers\Dashboard\PaymentPlanController;
use App\Http\Controllers\Dashboard\StudentSubscriptionController;
use App\Http\Controllers\Dashboard\StudentInvoiceController;
use App\Http\Controllers\Dashboard\MassBillingController;
use App\Http\Controllers\Dashboard\AcademicYearController;
use App\Http\Controllers\Dashboard\QuarterController;
use App\Http\Controllers\Dashboard\CurriculumController;
use App\Http\Controllers\Dashboard\StudentController;
use App\Http\Controllers\Dashboard\StudentProfileController;
use App\Http\Controllers\Dashboard\EnrollmentController;
use App\Http\Controllers\Dashboard\InvoiceController;
use App\Http\Controllers\Dashboard\QuickStudentRegistrationController;
use App\Http\Controllers\Dashboard\FeeController;
use App\Http\Controllers\Dashboard\FeePriceController;
use App\Http\Controllers\Dashboard\ClassController;
use App\Http\Controllers\Dashboard\ClassTimetableController;
use App\Http\Controllers\Dashboard\SchoolTimetableController;
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
use App\Http\Controllers\Dashboard\SchoolSettingController;
use App\Http\Controllers\Dashboard\SchoolEnrollmentController;
use App\Http\Controllers\Dashboard\EnrollmentModeController;
use App\Http\Controllers\Dashboard\StudentDocumentController;
use App\Http\Controllers\Dashboard\StudentProfileCompletionController;
use App\Http\Controllers\Dashboard\BellScheduleController;
use App\Http\Controllers\Dashboard\BellSchedulePeriodController;
use App\Http\Controllers\Dashboard\AcademicCalendarController;
use App\Http\Controllers\Dashboard\CalendarEventController;
use App\Http\Controllers\Dashboard\ClassroomController;
use App\Http\Controllers\Dashboard\AdministrationController;

use App\Http\Controllers\Cash\CashTransactionController;
use App\Http\Controllers\Cash\CashTransferController;
use App\Http\Controllers\CashAccountController;

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\AuditLogController;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Dashboard\TeacherSalaryPrintController;

Route::get('/', function () {
    return redirect('/dashboard');
});

Route::middleware(['auth', 'administrative'])->group(function () {
    Route::get('/dashboard/teacher-salaries/{teacherSalary}/print', [TeacherSalaryPrintController::class, 'show'])
        ->name('dashboard.teacher-salaries.print');
    Route::get('/dashboard/teacher-salaries/{teacherSalary}/pdf', [TeacherSalaryPrintController::class, 'pdf'])
        ->name('dashboard.teacher-salaries.pdf');

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

    // Thin bridges into EmployeePayrollService so approve/pay can be
    // triggered from the dashboard-native payroll list without leaving
    // the unified shell — see SalaryController's class docblock.
    Route::post('salaries/{teacherSalary}/approve', [SalaryController::class, 'approve'])
        ->name('dashboard.salaries.approve');

    Route::get('salaries/{teacherSalary}/pay', [SalaryController::class, 'showPayForm'])
        ->name('dashboard.salaries.pay.create');

    Route::post('salaries/{teacherSalary}/pay', [SalaryController::class, 'pay'])
        ->name('dashboard.salaries.pay.store');
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

        Route::get('administration/school-settings', [SchoolSettingController::class, 'edit'])
            ->name('settings.school.edit');
        Route::put('administration/school-settings', [SchoolSettingController::class, 'update'])
            ->name('settings.school.update');

        Route::resource('students', StudentController::class)->except(['show']);
        Route::get('students/{student}/print', [StudentProfileController::class, 'print'])->name('students.print');
        Route::get('students/{student}', [StudentProfileController::class, 'show'])->name('students.show');

        Route::get('students/{student}/complete-registration', [StudentProfileCompletionController::class, 'edit'])->name('students.complete-registration.edit');
        Route::put('students/{student}/complete-registration', [StudentProfileCompletionController::class, 'update'])->name('students.complete-registration.update');
        Route::post('students/{student}/submit-registration-review', [StudentProfileCompletionController::class, 'submitReview'])->name('students.registration-review.submit');
        Route::post('students/{student}/complete-registration-review', [StudentProfileCompletionController::class, 'completeReview'])->name('students.registration-review.complete');
        Route::get('students/{student}/documents', [StudentDocumentController::class, 'index'])->name('students.documents.index');
        Route::post('students/{student}/documents', [StudentDocumentController::class, 'store'])->name('students.documents.store');
        Route::get('students/{student}/legacy-documents/download', [StudentController::class, 'downloadLegacyDocument'])->name('students.legacy-documents.download');
        Route::get('students/{student}/documents/{studentFile}/preview', [StudentDocumentController::class, 'preview'])->name('students.documents.preview');
        Route::get('students/{student}/documents/{studentFile}/download', [StudentDocumentController::class, 'download'])->name('students.documents.download');
        Route::patch('students/{student}/documents/{studentFile}/archive', [StudentDocumentController::class, 'archive'])->name('students.documents.archive');
        Route::patch('students/{student}/documents/{studentFile}/restore', [StudentDocumentController::class, 'restore'])->name('students.documents.restore');

        Route::get('enrollment', [SchoolEnrollmentController::class, 'index'])
            ->name('school-enrollment.index');
        Route::get('enrollment/create', [SchoolEnrollmentController::class, 'create'])
            ->name('school-enrollment.create');
        Route::post('enrollment', [SchoolEnrollmentController::class, 'store'])
            ->name('school-enrollment.store');

        Route::prefix('academic')->name('academic.')->group(function () {
            Route::resource('enrollment-modes', EnrollmentModeController::class)
                ->parameters(['enrollment-modes' => 'enrollmentMode'])
                ->except(['show', 'destroy']);
        });

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
        Route::get('classes/{class}/timetable', [ClassTimetableController::class, 'show'])
            ->name('classes.timetable');
        Route::post('classes/{class}/timetable', [ClassTimetableController::class, 'save'])
            ->name('classes.timetable.save');
        Route::delete('classes/{class}/timetable', [ClassTimetableController::class, 'destroy'])
            ->name('classes.timetable.destroy');
        Route::post('classes/{class}/timetable/generate', [ClassTimetableController::class, 'generate'])
            ->name('classes.timetable.generate');
        Route::get('classes/{class}/timetable/pdf', [ClassTimetableController::class, 'pdf'])
            ->name('classes.timetable.pdf');
        Route::get('classes/{class}/timetable/pdf/download', [ClassTimetableController::class, 'pdfDownload'])
            ->name('classes.timetable.pdf.download');
        Route::get('classes/{class}/timetable/print', [ClassTimetableController::class, 'print'])
            ->name('classes.timetable.print');
        Route::get('school-timetable', [SchoolTimetableController::class, 'index'])
            ->name('school-timetable.index');
        Route::resource('subjects', SubjectController::class);
        Route::resource('academic-years', AcademicYearController::class)->except(['show']);
        Route::resource('academic-years.quarters', QuarterController::class)
            ->except(['show'])
            ->parameters(['academic-years' => 'academicYear']);
        Route::resource('curricula', CurriculumController::class)->except(['show']);

        // Phase 1 — dashboard migration of Filament BellScheduleResource /
        // AcademicCalendarResource / ClassroomResource. All three deny
        // delete (see App\Models\BellSchedule::canDelete-equivalent,
        // App\Models\PhysicalClassroom::booted 'deleting' guard, and
        // App\Filament\Resources\AcademicCalendars\AcademicCalendarResource
        // ::canDelete) so no destroy route exists here either — this is
        // parity, not an omission.
        Route::resource('bell-schedules', BellScheduleController::class)->except(['show', 'destroy']);
        Route::prefix('bell-schedules/{bellSchedule}/periods')
            ->name('bell-schedules.periods.')
            ->group(function () {
                Route::get('create', [BellSchedulePeriodController::class, 'create'])->name('create');
                Route::post('/', [BellSchedulePeriodController::class, 'store'])->name('store');
                Route::get('{period}/edit', [BellSchedulePeriodController::class, 'edit'])->name('edit');
                Route::put('{period}', [BellSchedulePeriodController::class, 'update'])->name('update');
            });

        Route::resource('academic-calendars', AcademicCalendarController::class)->except(['show', 'destroy']);
        Route::prefix('academic-calendars/{academicCalendar}/events')
            ->name('academic-calendars.events.')
            ->group(function () {
                Route::get('create', [CalendarEventController::class, 'create'])->name('create');
                Route::post('/', [CalendarEventController::class, 'store'])->name('store');
                Route::get('{event}/edit', [CalendarEventController::class, 'edit'])->name('edit');
                Route::put('{event}', [CalendarEventController::class, 'update'])->name('update');
            });

        Route::resource('classrooms', ClassroomController::class)->except(['show', 'destroy']);

        // Navigation fix: the sidebar's top-level "Администрирование" item
        // used to link straight into the Filament /admin panel. It now
        // lands here — a dashboard-native hub linking to whichever
        // administration destinations already exist in /dashboard, plus a
        // clearly labelled technical fallback section for what Filament
        // still exclusively owns (Users/Roles) in this phase.
        Route::get('administration', [AdministrationController::class, 'index'])
            ->name('administration.index');

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

        Route::get('teachers/documents/{document}/download', [TeacherController::class, 'downloadDocument'])
            ->name('teachers.documents.download');

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
            Route::get('workspace', [FinanceOperationsController::class, 'workspace'])->name('workspace');
            Route::get('installments', [PaymentPlanController::class, 'reports'])->name('installments.index');
            Route::get('subscriptions', [StudentSubscriptionController::class, 'control'])->name('subscriptions.index');
            Route::resource('payment-plans', PaymentPlanController::class)->except(['show', 'destroy']);
            Route::resource('services', FinanceServiceController::class)
                ->parameters(['services' => 'fee'])
                ->except(['destroy']);
            Route::get('tariffs/price-list', [FinancePriceListPdfController::class, 'create'])->name('tariffs.price-list.create');
            Route::get('tariffs/price-list/pdf', [FinancePriceListPdfController::class, 'download'])->name('tariffs.price-list.pdf');
            Route::resource('tariffs', FinanceTariffController::class)
                ->parameters(['tariffs' => 'feePrice'])->only(['index', 'create', 'store', 'show']);
            Route::get('tariffs-rollover', [FinanceTariffRolloverController::class, 'create'])->name('tariffs.rollover.create');
            Route::post('tariffs-rollover/preview', [FinanceTariffRolloverController::class, 'preview'])->name('tariffs.rollover.preview');
            Route::post('tariffs-rollover', [FinanceTariffRolloverController::class, 'store'])->name('tariffs.rollover.store');

            Route::get('mass-billing', [MassBillingController::class, 'index'])->name('mass-billing.index');
            Route::get('mass-billing/create', [MassBillingController::class, 'create'])->name('mass-billing.create');
            Route::post('mass-billing', [MassBillingController::class, 'store'])->name('mass-billing.store');
            Route::get('mass-billing/{batch}', [MassBillingController::class, 'show'])->name('mass-billing.show');
            Route::get('mass-billing/{batch}/edit', [MassBillingController::class, 'edit'])->name('mass-billing.edit');
            Route::put('mass-billing/{batch}', [MassBillingController::class, 'update'])->name('mass-billing.update');
            Route::post('mass-billing/{batch}/preview', [MassBillingController::class, 'preview'])->name('mass-billing.preview');
            Route::post('mass-billing/{batch}/execute', [MassBillingController::class, 'execute'])->name('mass-billing.execute');
        });

        /*
        |--------------------------------------------------------------------------
        | Invoices
        |--------------------------------------------------------------------------
        */
        Route::resource('invoices', InvoiceController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::get('students/{student}/finance', [FinanceOperationsController::class, 'student'])->name('students.finance');
        Route::get('students/{student}/subscriptions/renew', [StudentSubscriptionController::class, 'renew'])->name('students.subscriptions.renew');
        Route::get('students/{student}/finance/statement', [FinanceOperationsController::class, 'statement'])->name('students.finance.statement');
        Route::get('students/{student}/finance/statement/pdf', [FinanceOperationsController::class, 'statementPdf'])->name('students.finance.statement.pdf');
        Route::post('students/{student}/finance/coverages', [\App\Http\Controllers\Dashboard\ServiceCoverageController::class, 'store'])->name('students.finance.coverages.store');
        Route::post('finance/adjustments/preview', [\App\Http\Controllers\Dashboard\FinanceAdjustmentController::class, 'preview'])->name('finance.adjustments.preview');
        Route::post('finance/adjustments', [\App\Http\Controllers\Dashboard\FinanceAdjustmentController::class, 'store'])->name('finance.adjustments.store');
        Route::post('students/{student}/finance/promises', [\App\Http\Controllers\Dashboard\PromiseToPayController::class, 'store'])->name('students.finance.promises.store');
        Route::post('finance/promises/{promiseToPay}/cancel', [\App\Http\Controllers\Dashboard\PromiseToPayController::class, 'cancel'])->name('finance.promises.cancel');
        Route::post('finance/promises/{promiseToPay}/fulfill', [\App\Http\Controllers\Dashboard\PromiseToPayController::class, 'fulfill'])->name('finance.promises.fulfill');
        Route::post('finance/credits/{studentCredit}/apply', [\App\Http\Controllers\Dashboard\StudentCreditController::class, 'apply'])->name('finance.credits.apply');
        Route::post('students/{student}/subscriptions/renew/preview', [StudentSubscriptionController::class, 'renewPreview'])->name('students.subscriptions.renew.preview');
        Route::post('students/{student}/subscriptions/renew', [StudentSubscriptionController::class, 'renewStore'])->name('students.subscriptions.renew.store');
        Route::get('students/{student}/subscriptions', [StudentSubscriptionController::class, 'index'])->name('students.subscriptions.index');
        Route::get('students/{student}/subscriptions/create', [StudentSubscriptionController::class, 'create'])->name('students.subscriptions.create');
        Route::post('students/{student}/subscriptions', [StudentSubscriptionController::class, 'store'])->name('students.subscriptions.store');
        Route::get('students/{student}/subscriptions/{subscription}', [StudentSubscriptionController::class, 'show'])->name('students.subscriptions.show');
        Route::get('students/{student}/subscriptions/{subscription}/edit', [StudentSubscriptionController::class, 'edit'])->name('students.subscriptions.edit');
        Route::put('students/{student}/subscriptions/{subscription}', [StudentSubscriptionController::class, 'update'])->name('students.subscriptions.update');
        Route::post('students/{student}/subscriptions/{subscription}/pause', [StudentSubscriptionController::class, 'pause'])->name('students.subscriptions.pause');
        Route::post('students/{student}/subscriptions/{subscription}/resume', [StudentSubscriptionController::class, 'resume'])->name('students.subscriptions.resume');
        Route::post('students/{student}/subscriptions/{subscription}/end', [StudentSubscriptionController::class, 'end'])->name('students.subscriptions.end');
        Route::get('students/{student}/invoices/create', [StudentInvoiceController::class, 'create'])->name('students.invoices.create');
        Route::post('students/{student}/invoices', [StudentInvoiceController::class, 'store'])->name('students.invoices.store');

        // Phase 2 — cashier charge & collect (issue a charge and take payment in
        // one atomic action; permission-gated in FinanceOperationsController).
        Route::get('students/{student}/charge', [FinanceOperationsController::class, 'chargeCreate'])->name('students.charge.create');
        Route::post('students/{student}/charge', [FinanceOperationsController::class, 'chargeStore'])->name('students.charge.store');
        Route::get('invoices/{invoice}/payments/create', [FinanceOperationsController::class, 'createPayment'])->name('invoices.payments.create');
        Route::post('invoices/{invoice}/payments', [FinanceOperationsController::class, 'storePayment'])->name('invoices.payments.store');
        Route::get('payments/{invoicePayment}/receipt', [FinanceOperationsController::class, 'receipt'])->name('payments.receipt');
        Route::get('payments/{invoicePayment}/receipt/pdf', [FinanceOperationsController::class, 'receiptPdf'])->name('payments.receipt.pdf');

        // Phase 1 — canonical invoice void + payment refunds (permission-gated
        // inside FinanceOperationsController; never call the legacy refund).
        Route::post('invoices/{invoice}/void', [FinanceOperationsController::class, 'voidInvoice'])->name('invoices.void');
        Route::get('payments/{invoicePayment}/refund', [FinanceOperationsController::class, 'createRefund'])->name('payments.refund.create');
        Route::post('payments/{invoicePayment}/refund', [FinanceOperationsController::class, 'storeRefund'])->name('payments.refund.store');
        Route::get('refunds/{paymentRefund}/receipt', [FinanceOperationsController::class, 'refundReceipt'])->name('refunds.receipt');

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

                // Phase 3 — cash-drawer sessions (кассовые смены). Permissions
                // are gated inside CashSessionController; the variance-close
                // check is enforced in CashSessionService.
                Route::get('sessions', [CashSessionController::class, 'index'])
                    ->name('sessions.index');
                Route::get('sessions/open', [CashSessionController::class, 'create'])
                    ->name('sessions.create');
                Route::post('sessions', [CashSessionController::class, 'store'])
                    ->name('sessions.store');
                Route::get('sessions/{cashSession}', [CashSessionController::class, 'show'])
                    ->name('sessions.show');
                Route::post('sessions/{cashSession}/close', [CashSessionController::class, 'close'])
                    ->name('sessions.close');
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
