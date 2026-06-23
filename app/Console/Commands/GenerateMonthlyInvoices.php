<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Fee;
use App\Models\Student;
use App\Models\Invoice;
use Carbon\Carbon;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'invoices:generate-monthly';

    protected $description = 'Generate monthly invoices automatically';

    public function handle()
    {
        $today = Carbon::now();

        $this->info("Starting Monthly Invoice Generation...");

        // ===== كل الرسوم الشهرية =====
        $fees = Fee::where('type', 'monthly')
            ->where('is_active', 1)
            ->get();

        $count = 0;

        foreach ($fees as $fee) {

            $students = Student::all();

            foreach ($students as $student) {

                // منع التكرار لنفس الشهر
                $exists = Invoice::where('student_id', $student->id)
                    ->where('fee_id', $fee->id)
                    ->whereMonth('due_date', $today->month)
                    ->whereYear('due_date', $today->year)
                    ->exists();

                if ($exists) continue;

                Invoice::create([
                    'student_id' => $student->id,
                    'fee_id' => $fee->id,
                    'amount' => $fee->amount,
                    'service' => $fee->category ?? 'monthly',
                    'status' => 'unpaid',
                    'due_date' => $today->copy()->startOfMonth(),
                ]);

                $count++;
            }
        }

        $this->info("Done! Created $count invoices.");
    }
}