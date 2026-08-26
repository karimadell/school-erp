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

    // Cash Operations Phase 1. Both are new, deliberately distinct type
    // values (no schema change — see the Phase 1 seeding migration) so
    // isCashDrawer() below keeps working unchanged:
    //   - owner_cash IS physical cash, but is a holding account the
    //     owner keeps outside the accountant's shift — it is
    //     intentionally NOT a session-tracked drawer.
    //   - instapay is an electronic channel, never physical cash.
    const TYPE_OWNER_CASH = 'owner_cash';
    const TYPE_INSTAPAY = 'instapay';

    /*
    |--------------------------------------------------------------------------
    | Canonical roles
    |--------------------------------------------------------------------------
    | The semantic identity for "which account plays this part in the Cash
    | Operations workflow" — never a display name (see the migration that
    | introduced this column for why: a name-based lookup created a
    | duplicate operating account on UAT). Nullable and unique: at most one
    | account may ever hold a given role, while any number of ordinary
    | (role=null) accounts of the same type — extra cash drawers, Phase 3
    | already supports several — coexist freely.
    */

    const ROLE_OPERATING = 'operating';

    const ROLE_OWNER = 'owner';

    const ROLE_BANK = 'bank';

    const ROLE_INSTAPAY = 'instapay';

    /*
    |--------------------------------------------------------------------------
    | Fillable
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'type',
        'role',
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

    /*
    |--------------------------------------------------------------------------
    | Canonical role resolvers
    |--------------------------------------------------------------------------
    | The one central place Cash Operations (and anything else that needs
    | "the" operating/owner/bank/instapay account) should look these up —
    | never a scattered where('name', ...). Returns null when that role
    | hasn't been assigned yet (e.g. on a database ambiguous enough that
    | the backfill migration deliberately left it unresolved); callers must
    | handle that rather than assume a row always exists.
    */

    public static function forRole(string $role): ?self
    {
        return static::query()->where('role', $role)->first();
    }

    public static function operating(): ?self
    {
        return static::forRole(self::ROLE_OPERATING);
    }

    public static function owner(): ?self
    {
        return static::forRole(self::ROLE_OWNER);
    }

    public static function bank(): ?self
    {
        return static::forRole(self::ROLE_BANK);
    }

    public static function instapay(): ?self
    {
        return static::forRole(self::ROLE_INSTAPAY);
    }

    /**
     * The owner's holding account must never appear as a destination a
     * cashier can pick for an ordinary student payment (Cash Operations
     * Phase 4). Any account selector rendered for student-facing payment
     * forms should build its options through this scope.
     */
    public function scopeExcludingOwner($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('role')->orWhere('role', '!=', self::ROLE_OWNER);
        });
    }

    /**
     * Cash Operations Phase 4 — payment_method → canonical account role.
     * Every student-facing entry point that accepts a payment_method
     * (Quick Registration, charge & collect, the finance workspace, the
     * classic invoice screen) must resolve the account through this before
     * calling InvoicePaymentService::record() — never forward the
     * cash_account_id a browser submitted for cash/bank/instapay, or a
     * tampered value could redirect real money. card/transfer have no
     * canonical mapping yet, so null here means "use the submitted,
     * validated account id" as before.
     */
    public static function canonicalRoleForMethod(string $paymentMethod): ?string
    {
        return match ($paymentMethod) {
            'cash' => self::ROLE_OPERATING,
            'bank' => self::ROLE_BANK,
            'instapay' => self::ROLE_INSTAPAY,
            default => null,
        };
    }

    /**
     * Resolves the account id InvoicePaymentService::record() should
     * actually be given for $paymentMethod: the canonical account's id when
     * one is mapped (ignoring $submittedAccountId entirely), otherwise the
     * submitted id unchanged. Returns 0 (never a real row) when a method
     * has a canonical mapping but that account hasn't been configured yet,
     * so a misconfigured school fails closed instead of silently trusting
     * whatever the browser sent.
     */
    public static function resolvePaymentAccountId(string $paymentMethod, ?int $submittedAccountId): int
    {
        $role = self::canonicalRoleForMethod($paymentMethod);
        if ($role === null) {
            return $submittedAccountId ?? 0;
        }

        return self::forRole($role)?->id ?? 0;
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
