<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    // The audit_logs table has created_at/updated_at columns and every
    // AuditLog::create() call site relies on Eloquent to populate them
    // automatically (none set created_at explicitly) — timestamps must
    // stay enabled (Eloquent's default) or every row is created with a
    // NULL created_at, which crashes the audit log view's ->format() call.

    protected $fillable = [
        'user_id',
        'action',
        'model',
        'model_id',
        'old_values',
        'new_values',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}