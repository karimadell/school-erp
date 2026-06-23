<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Student;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use UnitEnum;
use BackedEnum;

class StudentFinanceReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Финансы студента';

    protected string $view = 'filament.pages.student-finance-report';

    public $studentId = null;

    public $student = null;

    public $invoices = [];

    public $total = 0;

    public $paid = 0;

    public $due = 0;

    public function loadStudent()
    {
        $this->student = Student::find($this->studentId);

        if ($this->student) {

            $this->invoices = Invoice::where('student_id',$this->studentId)->get();

            $this->total = $this->invoices->sum('total_amount');

            $this->paid = $this->invoices->where('status','paid')->sum('total_amount');

            $this->due = $this->total - $this->paid;
        }
    }

    public function downloadPdf()
    {
        if (!$this->student) {
            return;
        }

        $pdf = Pdf::loadView('pdf.student-finance',[
            'student'=>$this->student,
            'invoices'=>$this->invoices,
            'total'=>$this->total,
            'paid'=>$this->paid,
            'due'=>$this->due
        ]);

        return response()->streamDownload(
            fn()=>print($pdf->output()),
            'student_finance_'.$this->student->id.'.pdf'
        );
    }
}