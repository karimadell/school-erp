<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Student;
use App\Models\Invoice;
use UnitEnum;
use BackedEnum;

class StudentLedger extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Финансы ученика';

    protected static ?int $navigationSort = 70;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected string $view = 'filament.pages.student-ledger';

    public $studentId = null;

    public $student = null;

    public $invoices = [];

    public $total = 0;

    public function loadStudent()
    {
        $this->student = Student::find($this->studentId);

        if ($this->student) {

            $this->invoices = Invoice::where('student_id', $this->studentId)
                ->orderBy('created_at')
                ->get();

            $this->total = $this->invoices->sum('total_amount');
        }
    }
}
