<?php

namespace App\Imports;

use App\Models\Salary;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SalaryImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new Salary([
            'teacher_id'   => $row['teacher_id'],
            'base_salary'  => $row['base_salary'],
            'bonus'        => $row['bonus'] ?? 0,
            'deduction'    => $row['deduction'] ?? 0,
            'net_salary'   => ($row['base_salary'] + ($row['bonus'] ?? 0)) - ($row['deduction'] ?? 0),
            'month'        => Carbon::now(),
        ]);
    }
}