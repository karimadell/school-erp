<?php

namespace App\Services\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\EmployeeSalaryRate;
use App\Models\TeacherSalary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeePayrollService
{
    public function __construct(private CashSessionService $sessions) {}

    /** @param array<int, array{type:string, amount:string|int|float, reason:string}> $adjustments */
    public function create(User $employee, string $month, string $baseSalary, array $adjustments, User $actor, ?string $position = null): TeacherSalary
    {
        abort_unless($actor->can('manage payroll'), 403);
        $month = CarbonImmutable::parse($month)->startOfMonth();
        $baseSalary = $this->money($baseSalary, 'base_salary', allowZero: true);
        $this->assertEmployee($employee);

        return DB::transaction(function () use ($employee, $month, $baseSalary, $adjustments, $actor, $position): TeacherSalary {
            $existing = TeacherSalary::query()->where('employee_user_id', $employee->id)->whereDate('salary_month', $month)->lockForUpdate()->first();
            if ($existing) {
                throw ValidationException::withMessages(['salary_month' => __('teacher_salary.validation.duplicate')]);
            }
            $position = trim((string) ($position ?: $employee->teacher?->specialization ?: $employee->roles->pluck('name')->join(', ')));
            $this->setRate($employee, $month, $baseSalary, $position, $actor);

            try {
                $payroll = TeacherSalary::create([
                    'employee_user_id' => $employee->id,
                    'teacher_id' => $employee->teacher?->id,
                    'employee_name' => $employee->name,
                    'position' => $position,
                    'base_salary' => $baseSalary,
                    'bonus' => '0.00', 'allowances' => '0.00', 'deductions' => '0.00',
                    'salary_month' => $month, 'status' => TeacherSalary::STATUS_DRAFT,
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23505' || str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
                    throw ValidationException::withMessages(['salary_month' => __('teacher_salary.validation.duplicate')]);
                }
                throw $exception;
            }
            foreach ($adjustments as $line) {
                $reason = trim((string) $line['reason']);
                if ($reason === '') {
                    throw ValidationException::withMessages(['adjustments' => __('teacher_salary.validation.reason')]);
                }
                $payroll->adjustments()->create([
                    'type' => $line['type'],
                    'amount' => $this->money((string) $line['amount'], 'adjustments'),
                    'reason' => $reason,
                    'created_by' => $actor->id,
                ]);
            }
            $payroll->refreshTotals();
            if (bccomp((string) $payroll->net_salary, '0.00', 2) < 0) {
                throw ValidationException::withMessages(['deductions' => __('teacher_salary.validation.negative_net')]);
            }

            return $payroll->fresh(['employee', 'teacher', 'adjustments']);
        });
    }

    /** @param array<int, array{type:string, amount:string|int|float, reason:string}> $adjustments */
    public function updateDraft(TeacherSalary $payroll, string $baseSalary, array $adjustments, User $actor, ?string $position = null): TeacherSalary
    {
        abort_unless($actor->can('manage payroll'), 403);

        return DB::transaction(function () use ($payroll, $baseSalary, $adjustments, $actor, $position): TeacherSalary {
            $payroll = TeacherSalary::query()->lockForUpdate()->findOrFail($payroll->id);
            if ($payroll->status !== TeacherSalary::STATUS_DRAFT || ! $payroll->employee) {
                throw ValidationException::withMessages(['status' => __('teacher_salary.validation.locked')]);
            }
            $baseSalary = $this->money($baseSalary, 'base_salary', allowZero: true);
            $position = trim((string) ($position ?: $payroll->position));
            $this->setRate($payroll->employee, CarbonImmutable::instance($payroll->salary_month), $baseSalary, $position, $actor);
            $payroll->forceFill(['base_salary' => $baseSalary, 'position' => $position])->save();
            $payroll->adjustments()->delete();
            foreach ($adjustments as $line) {
                $reason = trim((string) $line['reason']);
                if ($reason === '') {
                    throw ValidationException::withMessages(['adjustments' => __('teacher_salary.validation.reason')]);
                }
                $payroll->adjustments()->create([
                    'type' => $line['type'], 'amount' => $this->money((string) $line['amount'], 'adjustments'),
                    'reason' => $reason, 'created_by' => $actor->id,
                ]);
            }
            $payroll->refreshTotals();
            if (bccomp((string) $payroll->net_salary, '0.00', 2) < 0) {
                throw ValidationException::withMessages(['deductions' => __('teacher_salary.validation.negative_net')]);
            }

            return $payroll->fresh(['employee', 'teacher', 'adjustments']);
        });
    }

    public function approve(TeacherSalary $payroll, User $actor): TeacherSalary
    {
        abort_unless($actor->can('approve payroll'), 403);

        return DB::transaction(function () use ($payroll, $actor): TeacherSalary {
            $payroll = TeacherSalary::query()->lockForUpdate()->findOrFail($payroll->id);
            if ($payroll->status === TeacherSalary::STATUS_APPROVED || $payroll->status === TeacherSalary::STATUS_PAID) {
                return $payroll;
            }
            $payroll->forceFill(['status' => TeacherSalary::STATUS_APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()])->save();

            return $payroll->fresh();
        });
    }

    public function pay(TeacherSalary $payroll, CashAccount $account, string $method, User $actor): TeacherSalary
    {
        abort_unless($actor->can('pay payroll'), 403);
        if (! in_array($method, [CashTransaction::METHOD_CASH, CashTransaction::METHOD_CARD, CashTransaction::METHOD_BANK, CashTransaction::METHOD_TRANSFER], true)) {
            throw ValidationException::withMessages(['payment_method' => __('teacher_salary.validation.payment_method')]);
        }

        return DB::transaction(function () use ($payroll, $account, $method, $actor): TeacherSalary {
            $payroll = TeacherSalary::query()->lockForUpdate()->findOrFail($payroll->id);
            if ($payroll->status === TeacherSalary::STATUS_PAID) {
                return $payroll->fresh('cashTransaction');
            }
            if ($payroll->status !== TeacherSalary::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => __('teacher_salary.validation.approve_first')]);
            }
            $account = CashAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (! $account->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => __('teacher_salary.validation.cash_account')]);
            }
            $sessionId = null;
            if ($method === CashTransaction::METHOD_CASH && $account->isCashDrawer()) {
                $session = $this->sessions->activeFor($account, lock: true);
                if (! $session) {
                    throw ValidationException::withMessages(['payment_method' => __('teacher_salary.validation.cash_session')]);
                }
                $sessionId = $session->id;
            }
            $transaction = CashTransaction::query()->where('teacher_salary_id', $payroll->id)->first();
            if (! $transaction) {
                $transaction = CashTransaction::create([
                    'cash_account_id' => $account->id, 'cash_session_id' => $sessionId,
                    'created_by' => $actor->id, 'teacher_salary_id' => $payroll->id,
                    'amount' => $payroll->net_salary, 'type' => CashTransaction::TYPE_OUT,
                    'category' => CashTransaction::CATEGORY_EXPENSE, 'payment_method' => $method,
                    'description' => __('teacher_salary.payment_description', ['employee' => $payroll->employee_display_name, 'month' => $payroll->salary_month->format('m.Y')]),
                ]);
            }
            $payroll->forceFill([
                'status' => TeacherSalary::STATUS_PAID, 'paid_by' => $actor->id,
                'paid_at' => now(), 'cash_transaction_id' => $transaction->id,
            ])->save();

            return $payroll->fresh('cashTransaction');
        });
    }

    public function rateFor(User $employee, CarbonImmutable $month): ?EmployeeSalaryRate
    {
        $rates = EmployeeSalaryRate::query()->where('employee_user_id', $employee->id)
            ->whereDate('effective_from', '<=', $month)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $month))
            ->orderByDesc('effective_from')->get();
        if ($rates->count() > 1) {
            throw ValidationException::withMessages(['base_salary' => __('teacher_salary.validation.ambiguous_rate')]);
        }

        return $rates->first();
    }

    private function setRate(User $employee, CarbonImmutable $month, string $amount, string $position, User $actor): EmployeeSalaryRate
    {
        $exact = EmployeeSalaryRate::query()->where('employee_user_id', $employee->id)
            ->whereDate('effective_from', $month)->lockForUpdate()->first();
        if ($exact) {
            $exact->update(['amount' => $amount, 'position' => $position]);

            return $exact;
        }
        $active = $this->rateFor($employee, $month);
        if ($active && bccomp((string) $active->amount, $amount, 2) === 0) {
            return $active;
        }
        if ($active) {
            $active->update(['effective_to' => $month->subDay()]);
        }

        return EmployeeSalaryRate::create([
            'employee_user_id' => $employee->id, 'amount' => $amount,
            'effective_from' => $month, 'position' => $position, 'created_by' => $actor->id,
        ]);
    }

    private function assertEmployee(User $employee): void
    {
        if (! $employee->is_active || ! $employee->roles()->exists()) {
            throw ValidationException::withMessages(['employee_user_id' => __('teacher_salary.validation.employee')]);
        }
    }

    private function money(string $value, string $field, bool $allowZero = false): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([$field => __('teacher_salary.validation.amount')]);
        }
        $money = bcadd($value, '0', 2);
        if (bccomp($money, '0.00', 2) < ($allowZero ? 0 : 1)) {
            throw ValidationException::withMessages([$field => __('teacher_salary.validation.positive_amount')]);
        }

        return $money;
    }
}
