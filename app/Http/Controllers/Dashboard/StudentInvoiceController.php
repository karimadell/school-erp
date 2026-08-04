<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Support\Carbon;

class StudentInvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function create(Student $student): View
    {
        $student->load(['currentEnrollment.academicYear','currentEnrollment.grade','currentEnrollment.serviceSubscriptions.fee']);
        $year = $student->currentEnrollment?->academicYear;
        $fees = Fee::active()->with(['prices' => fn ($query) => $query
            ->when($year, fn ($query) => $query->where('academic_year_id', $year->id))
            ->where('is_active', true)->orderByDesc('start_date')])->orderBy('category')->orderBy('name_ru')->get();

        return view('dashboard.finance.invoices.create', compact('student', 'year', 'fees'));
    }

    public function store(StoreInvoiceRequest $request, Student $student, InvoiceCalculationService $calculator): RedirectResponse
    {
        $data = $request->validated();
        if ((int) $data['student_id'] !== $student->id) {
            throw ValidationException::withMessages(['student_id' => 'Выбранный ученик не соответствует адресу формы.']);
        }

        $invoice = DB::transaction(function () use ($data, $student, $calculator, $request) {
            $student = Student::query()->lockForUpdate()->findOrFail($student->id);
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
            $enrollment = Enrollment::query()->where('student_id', $student->id)->where('academic_year_id', $year->id)->where('is_active', true)->lockForUpdate()->first();
            if (! $year->is_active || ! $enrollment) {
                throw ValidationException::withMessages(['academic_year_id' => 'Активное зачисление на выбранный учебный год не найдено.']);
            }

            $registrationFeeIds = Fee::whereIn('id', collect($data['items'])->pluck('fee_id'))->where('category', Fee::CATEGORY_REGISTRATION)->pluck('id');
            if ($registrationFeeIds->isNotEmpty() && InvoiceItem::whereHas('invoice', fn ($query) => $query->where('student_id', $student->id)->where('academic_year_id', $year->id))->whereIn('fee_id', $registrationFeeIds)->exists()) {
                throw ValidationException::withMessages(['fees' => 'Регистрационный взнос уже начислен ученику за этот учебный год.']);
            }

            $items = collect($data['items'])->map(function (array $item) use ($enrollment) {
                $fee = Fee::findOrFail($item['fee_id']);
                if (in_array($fee->category, [Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR, Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL], true)
                    && blank($item['grade_group'] ?? null)) {
                    $item['grade_id'] = $enrollment->grade_id;
                }
                $item['enrollment_mode_id'] = $enrollment->enrollment_mode_id;

                return $item;
            })->all();
            $calculation = $calculator->calculate($items, null, null, '0', $data['pricing_date'], $year->id);
            $invoiceData = [
                'student_id'=>$student->id, 'academic_year_id'=>$year->id, 'customer_name'=>$student->full_name,
                'currency'=>'EGP', 'subtotal_amount'=>$calculation['subtotal'], 'total_amount'=>$calculation['total_amount'],
                'discount_amount'=>'0.00', 'paid_amount'=>'0.00', 'remaining_amount'=>$calculation['total_amount'],
                'status'=>Invoice::STATUS_UNPAID, 'due_date'=>$data['due_date'],
                'created_by'=>$request->user()->id,
            ];
            if (Schema::hasColumn('invoices', 'note')) {
                $invoiceData['note'] = $data['notes'] ?? null;
            }
            $invoice = new Invoice($invoiceData);
            $invoice->created_at = Carbon::parse($data['pricing_date'])->startOfDay();
            $invoice->save();
            $invoice->invoice_number = Invoice::numberFor($invoice->id, $invoice->created_at->format('Y'));
            $invoice->save();

            foreach ($calculation['line_items'] as $line) {
                $selection = collect($items)->firstWhere('fee_id', $line['fee_id']);
                $fee = Fee::findOrFail($line['fee_id']);
                $subscription = $enrollment->serviceSubscriptions()->where('fee_id', $line['fee_id'])->where('status', 'active')->first();
                InvoiceItem::create([
                    'invoice_id'=>$invoice->id, 'fee_id'=>$line['fee_id'], 'subscription_id'=>$subscription?->id,
                    'description'=>$line['description'], 'unit_price'=>$line['unit_price'], 'quantity'=>$line['quantity'],
                    'amount'=>$line['amount'], 'paid_amount'=>'0.00', 'remaining_amount'=>$line['amount'],
                    'is_non_refundable'=>$fee->is_non_refundable,
                    'metadata'=>collect($selection)->except(['fee_id','quantity'])->merge([
                        'pricing_date'=>$data['pricing_date'], 'tariff_valid_from'=>$line['tariff_valid_from'], 'tariff_valid_to'=>$line['tariff_valid_to'],
                    ])->filter(fn ($value) => filled($value))->all(),
                ]);
                $invoice->fees()->attach($line['fee_id'], [
                    'amount'=>$line['amount'], 'item'=>$line['item'], 'size'=>$line['size'],
                    'option_type'=>$line['option_type'], 'option_value'=>$line['option_value'],
                ]);
            }

            AuditLog::create(['user_id'=>$request->user()->id,'action'=>'created','model'=>'Invoice','model_id'=>$invoice->id,'new_values'=>['invoice_number'=>$invoice->invoice_number,'total_amount'=>$invoice->total_amount],'ip'=>$request->ip(),'user_agent'=>$request->userAgent()]);
            return $invoice;
        });

        return redirect()->route('dashboard.invoices.show', $invoice)->with('success', 'Счёт успешно создан.');
    }
}
