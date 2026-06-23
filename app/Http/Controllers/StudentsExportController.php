<?php

namespace App\Http\Controllers;

use App\Exports\StudentsExport;
use Maatwebsite\Excel\Facades\Excel;

class StudentsExportController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:students.export');
    }

    public function excel()
    {
        return Excel::download(new StudentsExport, 'students.xlsx');
    }

    public function csv()
    {
        return Excel::download(new StudentsExport, 'students.csv');
    }
}