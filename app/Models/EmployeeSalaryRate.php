<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryRate extends Model
{
    protected $fillable = ['employee_user_id', 'amount', 'effective_from', 'effective_to', 'position', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }
}
