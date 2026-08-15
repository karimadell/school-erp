<?php

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\Finance\BatchNotExecutableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MassBillingBatchRequest;
use App\Models\AcademicYear;
use App\Models\BillingBatch;
use App\Models\BillingBatchStudent;
use App\Models\Fee;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Finance\MassBillingExecutionService;
use App\Services\Finance\MassBillingPreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Mass Billing controller. Checkpoint 2 covers drafting a batch, defining
 * targets and previewing; Checkpoint 3 adds transactional execution that issues
 * the invoices and records run history.
 */
class MassBillingController extends Controller
{
    public function __construct()
    {
        // Read history/results, manage drafts, and execute are separately
        // permissioned so the sensitive execute step is gated independently.
        $this->middleware('permission:view mass billing')->only(['index', 'show']);
        $this->middleware('permission:manage mass billing')->only(['create', 'store', 'edit', 'update', 'preview']);
        $this->middleware('permission:execute mass billing')->only('execute');
    }

    public function index(): View
    {
        $batches = BillingBatch::query()
            ->with(['academicYear', 'fee'])
            ->latest()
            ->paginate(15);

        return view('dashboard.finance.mass-billing.index', compact('batches'));
    }

    public function create(): View
    {
        return view('dashboard.finance.mass-billing.create', $this->formData());
    }

    public function store(MassBillingBatchRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $batch = DB::transaction(function () use ($data, $request) {
            $batch = BillingBatch::create([
                'academic_year_id' => $data['academic_year_id'],
                'fee_id' => $data['fee_id'],
                'quantity' => $data['quantity'],
                'currency' => 'EGP',
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'description' => $data['description'] ?? null,
                'target_mode' => $data['target_mode'],
                'status' => BillingBatch::STATUS_DRAFT,
                'created_by' => $request->user()->id,
            ]);

            $this->syncTargets($batch, $data);

            return $batch;
        });

        return redirect()
            ->route('dashboard.finance.mass-billing.show', $batch)
            ->with('success', __('mass_billing.flash.created'));
    }

    public function show(BillingBatch $batch): View
    {
        $batch->load([
            'academicYear', 'fee', 'classTargets.schoolClass', 'studentTargets.student',
            'latestRun.executor',
            'latestRun.items' => fn ($query) => $query->orderBy('id'),
            'latestRun.items.student', 'latestRun.items.invoice',
        ]);

        return view('dashboard.finance.mass-billing.show', compact('batch'));
    }

    public function edit(BillingBatch $batch): View
    {
        abort_unless($this->isEditable($batch), 403);

        $batch->load(['classTargets', 'studentTargets']);

        return view('dashboard.finance.mass-billing.edit', array_merge($this->formData(), [
            'batch' => $batch,
            'selectedClassIds' => $batch->classTargets->pluck('class_id')->all(),
            'includeStudentIds' => $batch->includedStudentIds()->all(),
            'excludeStudentIds' => $batch->excludedStudentIds()->all(),
        ]));
    }

    public function update(MassBillingBatchRequest $request, BillingBatch $batch): RedirectResponse
    {
        abort_unless($this->isEditable($batch), 403);

        $data = $request->validated();

        DB::transaction(function () use ($batch, $data): void {
            $batch->update([
                'academic_year_id' => $data['academic_year_id'],
                'fee_id' => $data['fee_id'],
                'quantity' => $data['quantity'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'description' => $data['description'] ?? null,
                'target_mode' => $data['target_mode'],
                // Editing invalidates any prior preview.
                'status' => BillingBatch::STATUS_DRAFT,
                'selected_count' => null,
                'eligible_count' => null,
                'skipped_count' => null,
                'expected_invoice_count' => null,
                'expected_total_amount' => null,
                'preview_snapshot' => null,
                'previewed_at' => null,
            ]);

            $this->syncTargets($batch, $data);
        });

        return redirect()
            ->route('dashboard.finance.mass-billing.show', $batch)
            ->with('success', __('mass_billing.flash.updated'));
    }

    public function preview(BillingBatch $batch, MassBillingPreviewService $preview): RedirectResponse
    {
        abort_unless($this->isEditable($batch), 403);

        $preview->preview($batch);

        return redirect()
            ->route('dashboard.finance.mass-billing.show', $batch)
            ->with('success', __('mass_billing.flash.previewed'));
    }

    public function execute(BillingBatch $batch, MassBillingExecutionService $execution): RedirectResponse
    {
        $redirect = redirect()->route('dashboard.finance.mass-billing.show', $batch);

        try {
            $execution->execute($batch, request()->user(), request()->ip(), request()->userAgent());
        } catch (BatchNotExecutableException $exception) {
            return $redirect->with('error', __('mass_billing.execute_errors.'.$exception->reason));
        } catch (Throwable) {
            // Invoices (if any) were rolled back and the batch/run recorded as
            // failed; surface a generic, non-sensitive message.
            return $redirect->with('error', __('mass_billing.execute_errors.'.MassBillingExecutionService::FAILURE_CODE));
        }

        return $redirect->with('success', __('mass_billing.flash.executed'));
    }

    /** @param array<string, mixed> $data */
    private function syncTargets(BillingBatch $batch, array $data): void
    {
        $batch->classTargets()->delete();
        $batch->studentTargets()->delete();

        if ($data['target_mode'] === BillingBatch::TARGET_MODE_CLASSES) {
            foreach (collect($data['class_ids'] ?? [])->unique() as $classId) {
                $batch->classTargets()->create(['class_id' => $classId]);
            }
        }

        foreach (collect($data['include_student_ids'] ?? [])->unique() as $studentId) {
            $batch->studentTargets()->create(['student_id' => $studentId, 'mode' => BillingBatchStudent::MODE_INCLUDE]);
        }

        foreach (collect($data['exclude_student_ids'] ?? [])->unique() as $studentId) {
            $batch->studentTargets()->create(['student_id' => $studentId, 'mode' => BillingBatchStudent::MODE_EXCLUDE]);
        }
    }

    private function isEditable(BillingBatch $batch): bool
    {
        return in_array($batch->status, [BillingBatch::STATUS_DRAFT, BillingBatch::STATUS_PREVIEWED], true);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'years' => AcademicYear::orderByDesc('start_date')->get(),
            'fees' => Fee::active()->orderBy('category')->orderBy('name_ru')->get(),
            'classes' => SchoolClass::with('grade')->orderBy('name_ru')->get(),
            'students' => Student::with('class')->orderBy('last_name_ru')->orderBy('name')->get(),
        ];
    }
}
