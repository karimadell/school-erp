<?php

namespace App\Filament\Pages;

use App\Models\Student;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class StudentFinance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Финансы студентов';

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected string $view = 'filament.pages.student-finance';

    public $studentId = null;

    public $student = null;

    public $invoices = [];

    public $total = 0;

    public $paid = 0;

    public $balance = 0;


    public function loadStudent()
    {
        $this->student = Student::with('invoices')->find($this->studentId);

        if (!$this->student) {
            return;
        }

        $this->invoices = $this->student->invoices;

        $this->total = $this->invoices->sum('total_amount');

        $this->paid = $this->invoices
            ->where('status', 'paid')
            ->sum('total_amount');

        $this->balance = $this->total - $this->paid;
    }
}