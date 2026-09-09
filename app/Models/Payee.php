<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal payee/recipient record for the Expenses module. Deliberately not
 * a supplier/vendor/procurement entity — no such concept exists elsewhere
 * in the codebase to reuse, and building one is out of scope here. Kept
 * small enough to extend into one later without a rewrite.
 */
class Payee extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
