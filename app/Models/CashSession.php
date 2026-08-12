<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Phase 3 — a cash-drawer session (кассовая смена).
 *
 * Brackets one cashier's shift on one cash-type drawer: it opens with a
 * system-derived expected baseline, gathers the cash movements posted while it
 * is open (linked by FK at creation, never reconstructed from timestamps), and
 * closes with a single counted total the system reconciles against the expected
 * cash to surface a shortage/overage. A closed session is immutable.
 *
 * Reconciliation is scoped strictly to this session's own cash movements — the
 * owning CashAccount.balance (which also carries bank/card income and
 * historical, session-less rows) is never touched by opening or closing.
 */
class CashSession extends Model
{
    const STATUS_OPEN   = 'open';
    const STATUS_CLOSED = 'closed';

    /** Provenance of the opening_expected baseline. */
    const SOURCE_PREVIOUS_SESSION = 'previous_session';
    const SOURCE_ACCOUNT_BALANCE  = 'account_balance';
    const SOURCE_OVERRIDE         = 'override';

    protected $fillable = [
        'cash_account_id',
        'opened_by',
        'closed_by',
        'reconciled_by',
        'opened_at',
        'closed_at',
        'reconciled_at',
        'opening_expected',
        'opening_expected_source',
        'closing_counted',
        'expected_cash',
        'variance',
        'status',
        'open_note',
        'close_note',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'opening_expected' => 'decimal:2',
        'closing_counted' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class, 'cash_session_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /*
    |--------------------------------------------------------------------------
    | State helpers
    |--------------------------------------------------------------------------
    */

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation math (this session's own cash movements only)
    |--------------------------------------------------------------------------
    */

    /** Sum of cash paid into the drawer during this session. */
    public function cashIn(): string
    {
        return $this->money($this->transactions()->where('type', CashTransaction::TYPE_IN)->sum('amount'));
    }

    /** Sum of cash paid out of the drawer during this session. */
    public function cashOut(): string
    {
        return $this->money($this->transactions()->where('type', CashTransaction::TYPE_OUT)->sum('amount'));
    }

    /**
     * Expected cash in the drawer right now:
     * opening_expected + session cash inflows − session cash outflows.
     */
    public function expectedClosing(): string
    {
        return bcsub(bcadd($this->money($this->opening_expected), $this->cashIn(), 2), $this->cashOut(), 2);
    }

    private function money($value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Immutability guard — a closed session can never be mutated again.
    |--------------------------------------------------------------------------
    | Defense-in-depth behind the service: the only legitimate write is the
    | close transition (original status still 'open' at save time). Any later
    | attempt to update, or to delete, a closed session is rejected here.
    */

    protected static function booted(): void
    {
        static::updating(function (CashSession $session) {
            if ($session->getOriginal('status') === self::STATUS_CLOSED) {
                throw new RuntimeException('Закрытая кассовая смена неизменяема.');
            }
        });

        static::deleting(function (CashSession $session) {
            throw new RuntimeException('Кассовую смену нельзя удалить.');
        });
    }
}
