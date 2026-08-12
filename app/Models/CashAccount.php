<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    protected $table = 'cash_accounts';

    /*
    |--------------------------------------------------------------------------
    | Account Types
    |--------------------------------------------------------------------------
    */

    const TYPE_CASH = 'cash';
    const TYPE_BANK = 'bank';

    /*
    |--------------------------------------------------------------------------
    | Fillable
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'type',
        'parent_id',
        'balance',
        'is_active',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    // الحساب ← الفواتير
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    // الحساب ← المعاملات المالية
    public function transactions()
    {
        return $this->hasMany(CashTransaction::class);
    }

    // Phase 3: الحساب ← الكاش سيشنز (كل الورديات على هذه الكسة)
    public function cashSessions()
    {
        return $this->hasMany(CashSession::class);
    }

    public function isCashDrawer(): bool
    {
        return $this->type === self::TYPE_CASH;
    }

    // الحساب الرئيسي (Parent)
    public function parent()
    {
        return $this->belongsTo(CashAccount::class, 'parent_id');
    }

    // الحسابات الفرعية (Children)
    public function children()
    {
        return $this->hasMany(CashAccount::class, 'parent_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Hierarchy Helpers
    |--------------------------------------------------------------------------
    */

    // كل الحسابات التابعة (بشكل متكرر) لمنع التسلسل الدائري
    public function descendantIds(): array
    {
        $ids = [];
        $stack = [$this->id];

        while ($stack) {
            $parentId = array_pop($stack);

            $childIds = static::where('parent_id', $parentId)->pluck('id')->all();

            foreach ($childIds as $childId) {
                if (! in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $stack[] = $childId;
                }
            }
        }

        return $ids;
    }
}
