<?php

namespace App\Exports;

use App\Models\Teacher;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TeachersExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return Teacher::with('subjects')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function headings(): array
    {
        return [
            '#',
            'ФИО',
            'Краткое имя',
            'Предметы',
            'Специализация',
            'Телефон',
            'Email',
            'Дата найма',
            'Статус',
        ];
    }

    public function map($teacher): array
    {
        return [
            $teacher->id,
            $teacher->full_name,
            $teacher->short_name,
            $teacher->subjects->pluck('name_ru')->implode(', '),
            $teacher->specialization ?? '—',
            $teacher->phone ?? '—',
            $teacher->email ?? '—',
            $teacher->hire_date?->format('Y-m-d') ?? '—',
            $teacher->is_active ? 'Активен' : 'Неактивен',
        ];
    }
}