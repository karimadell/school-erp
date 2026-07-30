<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Student;

class StudentBalanceService
{
    public function outstandingBalance(Student $student): float
    {
        return (float) Invoice::where('student_id', $student->id)
            ->outstanding()
            ->sum('remaining_amount');
    }

    /**
     * Whether the student has any outstanding invoice overdue by more than
     * $days (due_date more than $days in the past). Invoices with no
     * due_date are never considered overdue.
     */
    public function hasInvoiceOverdueByMoreThan(Student $student, int $days): bool
    {
        return Invoice::where('student_id', $student->id)
            ->overdue(now()->subDays($days)->toDateString())
            ->exists();
    }
}
