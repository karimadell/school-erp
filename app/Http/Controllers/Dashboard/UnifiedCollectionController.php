<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnifiedCollectionRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\MealPlan;
use App\Models\Stage;
use App\Models\Student;
use App\Services\Finance\FinanceCollectionService;
use App\Services\Finance\StudentFinanceSummaryService;
use App\Services\Finance\StudentObligationsViewModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Unified Cashier Workspace (PR C1) — the parent-facing "Приход" collection
 * screen: search/select an existing Student → one explicit AcademicYear →
 * pay existing obligations and/or charge+collect new services in ONE
 * atomic FinanceCollection.
 *
 * Deliberately NOT named FinanceCollectionController — the pre-existing
 * FinanceCollectionsController (plural) is an unrelated, unchanged,
 * read-only "Поступления" ledger page (Phase 2A); this is a different
 * screen entirely.
 *
 * FinanceCollectionService::collect() remains the SOLE write engine —
 * this controller never touches Invoice/InvoicePayment/CashTransaction
 * directly, and contains no accounting rules of its own. Every field the
 * browser can influence is either a plain identity/option choice or an
 * operator-typed receive-now amount; no price is ever accepted as
 * authoritative from the request (see StoreUnifiedCollectionRequest's own
 * docblock and FinanceCollectionService's unchanged charge-vs-received
 * invariant).
 *
 * New-student creation is explicitly NOT part of this screen — "Новый
 * ученик" is a plain link to the existing, unchanged Quick Registration
 * flow (see the approved PR C audit §6); this controller only ever
 * operates on an already-existing Student, exactly like
 * FinanceCollectionService itself.
 */
class UnifiedCollectionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function create(
        Student $student,
        Request $request,
        StudentObligationsViewModel $obligations,
        StudentFinanceSummaryService $summaries,
    ): View|RedirectResponse {
        $student->loadMissing('currentEnrollment.academicYear', 'currentEnrollment.schoolClass');

        // §5 of the approved design — the year is resolved ONCE, explicitly,
        // before anything else on this screen: the student's own current
        // (active-year) Enrollment when one exists, else the requested
        // ?academic_year_id (e.g. a future "collect for {other year}" link),
        // else the globally active year. Never re-derived or silently
        // changed anywhere else on this screen or in store() below.
        $requestedYearId = $request->integer('academic_year_id') ?: null;
        $academicYearId = $requestedYearId
            ?? $student->currentEnrollment?->academic_year_id
            ?? AcademicYear::where('is_active', true)->value('id');

        if ($academicYearId === null) {
            return redirect()->route('dashboard.students.finance', $student)
                ->with('error', 'Не удалось определить учебный год для сбора оплаты.');
        }

        $year = AcademicYear::findOrFail($academicYearId);

        $yearSummary = $summaries->summarizeByYear($student);
        $yearFigures = $yearSummary['byYear'][$year->id] ?? null;

        $enrollment = $student->enrollments()->where('academic_year_id', $year->id)->first();

        // Existing-obligation collection has no active-year requirement at
        // all (FinanceCollectionService's own documented split — old debt
        // remains payable regardless of year lifecycle). New-service
        // charging stays subject to InvoiceIssuanceService::issue()'s own
        // unchanged "year is_active + active Enrollment" gate — mirrored
        // here only as a display decision, never bypassed.
        $canChargeNewServices = $year->is_active && $enrollment !== null;

        $fees = collect();
        $mealPlans = collect();
        $transportRoutes = collect();
        $uniformProducts = collect();
        if ($canChargeNewServices) {
            $fees = Fee::with(['prices', 'billingPeriods'])->nonTest()->active()->orderBy('category')->orderBy('name_ru')->get();
            $mealPlans = MealPlan::active()->orderBy('name_ru')->get();
            $transportRoutes = DB::table('transport_routes')->where('is_active', true)->orderBy('name')->get();
            $uniformProducts = DB::table('uniform_products')->where('is_active', true)->orderBy('name_ru')->orderBy('size')->get();
        }

        return view('dashboard.finance.unified-collection.create', [
            'student' => $student,
            'year' => $year,
            'enrollment' => $enrollment,
            'yearFigures' => $yearFigures,
            'canChargeNewServices' => $canChargeNewServices,
            'obligations' => $obligations->forStudentYear($student, $year),
            'fees' => $fees,
            'mealPlans' => $mealPlans,
            'transportRoutes' => $transportRoutes,
            'uniformProducts' => $uniformProducts,
            'cashAccounts' => CashAccount::where('is_active', true)->excludingOwner()->orderBy('name')->get(),
            'canRegisterAnnually' => $request->user()->can('register students for year'),
            'structureStages' => $this->structureStagesForAnnualRegistration(),
            'enrollmentModes' => EnrollmentMode::active()->ordered()->get(),
            'idempotencyToken' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreUnifiedCollectionRequest $request, Student $student, FinanceCollectionService $service): RedirectResponse
    {
        $data = $request->validated();

        // Corrective decision (§3/§11 of the approved design) — a row the
        // operator left at 0 (untouched) is not a selected obligation at
        // all; omitted here so FinanceCollectionService never even sees
        // it (it would itself skip a zero line safely, but this keeps the
        // submitted payload an honest reflection of what was actually
        // selected).
        $existingObligations = collect($data['existing_obligations'] ?? [])
            ->filter(fn (array $line) => bccomp((string) $line['receive_now_amount'], '0.00', 2) > 0)
            ->values()->all();

        try {
            $collection = $service->collect([
                'idempotency_token' => $data['idempotency_token'],
                'student_id' => $student->id,
                'academic_year_id' => $data['academic_year_id'],
                'payment_method' => $data['payment_method'],
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'existing_obligations' => $existingObligations,
                'new_services' => $data['new_services'] ?? [],
                'annual_registration' => $data['annual_registration'] ?? null,
            ], $request->user());
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        // J. Temporary safe destination for PR C1 — PR C2 adds the
        // FinanceCollection receipt; until then, the collection_number is
        // surfaced via a flash message on the student's own financial
        // account page (already the canonical "what does this student owe
        // now" view, unchanged).
        return redirect()->route('dashboard.students.finance', $student)
            ->with('success', "Сбор оплаты №{$collection->collection_number} успешно проведён. Получено: {$collection->grossReceivedTotal()}.");
    }

    /**
     * Kept out of the Blade template on purpose — a heavily-nested
     * closure-based transformation inline in a Blade @json() directive
     * broke that template's compilation; building the plain array here
     * and letting the view do nothing but @json() it is both the fix and
     * the better place for this logic anyway.
     *
     * @return array<int, array{id: int, name: string, grades: array<int, array{id: int, name: string, classes: array<int, array{id: int, name: string}>}>}>
     */
    private function structureStagesForAnnualRegistration(): array
    {
        return Stage::with([
            'grades' => fn ($query) => $query->ordered(),
            'grades.classes' => fn ($query) => $query->where('is_active', true)->orderBy('code'),
        ])->where('is_active', true)->orderBy('order')->get()
            ->map(fn ($stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'grades' => $stage->grades->map(fn ($grade) => [
                    'id' => $grade->id,
                    'name' => $grade->name,
                    'classes' => $grade->classes->map(fn ($class) => ['id' => $class->id, 'name' => $class->name_ru])->all(),
                ])->all(),
            ])->all();
    }
}
