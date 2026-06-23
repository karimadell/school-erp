<?php

namespace App\Observers;

use App\Models\Student;
use App\Models\AuditLog;

class StudentObserver
{
    /**
     * Handle the Student "created" event.
     */
    public function created(Student $student): void
    {
        AuditLog::create([
            'user_id'    => auth()->id(),
            'action'     => 'created',
            'model'      => 'Student',
            'model_id'   => $student->id,
            'new_values' => $student->toArray(),
            'ip'         => request()->ip(),
        ]);
    }

    /**
     * Handle the Student "updated" event.
     */
    public function updated(Student $student): void
    {
        AuditLog::create([
            'user_id'    => auth()->id(),
            'action'     => 'updated',
            'model'      => 'Student',
            'model_id'   => $student->id,
            'old_values' => $student->getOriginal(),
            'new_values' => $student->getChanges(),
            'ip'         => request()->ip(),
        ]);
    }

    /**
     * Handle the Student "deleted" event.
     */
    public function deleted(Student $student): void
    {
        AuditLog::create([
            'user_id'    => auth()->id(),
            'action'     => 'deleted',
            'model'      => 'Student',
            'model_id'   => $student->id,
            'old_values' => $student->toArray(),
            'ip'         => request()->ip(),
        ]);
    }
}