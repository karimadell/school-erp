<?php

namespace App\Models;

use App\Services\Finance\ExpenseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Status workflow: draft -> approved -> paid, with void reachable only from
 * draft/approved (a paid expense's ledger effect is never reversed or
 * mutated in this version — see ExpenseService::void()).
 *
 * Backward compatibility: status defaults to 'paid' (see $attributes
 * below). Any Expense::create() call that omits status — every pre-existing
 * caller and regression test uses this shape — behaves exactly like the
 * original unconditional-post hook: it posts to the cash ledger immediately,
 * exactly once. New callers (the Filament create form) opt into the review
 * workflow by creating with status = STATUS_DRAFT explicitly.
 *
 * Posting itself lives in ExpenseService::postToLedger(), which is
 * idempotent (row lock + existence check + the unique constraint on
 * cash_transactions.expense_id as a database-level backstop). This model's
 * hooks only decide *when* to call it — on creation for the legacy
 * immediate-paid path, and on any transition into 'paid' for the explicit
 * approve/pay workflow.
 *
 * ATOMICITY: this created-hook trigger is not, by itself, atomic with the
 * Expense insert — a plain Model::create()/save() opens no transaction of
 * its own. ExpenseService::create() is the canonical, atomic entry point:
 * it wraps Expense::create() in DB::transaction(), so a failure inside
 * postToLedger() (fired by the same hook, from within that transaction)
 * rolls the insert back too. Every supported application entry point
 * (the Filament CreateExpense page) goes through ExpenseService::create().
 * A *raw* Expense::create() call made outside the service (as every
 * pre-existing regression test does, and as any future direct/legacy
 * caller might) keeps working identically for backward compatibility, but
 * is not wrapped in an outer transaction by this model alone — that
 * narrow window is a documented, deterministically-recoverable legacy
 * compatibility path (re-calling ExpenseService::postToLedger() for the
 * affected row completes the posting), not a defect in the hook itself.
 */
class Expense extends Model
{
    const STATUS_DRAFT = 'draft';

    const STATUS_APPROVED = 'approved';

    const STATUS_PAID = 'paid';

    const STATUS_VOID = 'void';

    protected $fillable = [
        'reference_number',
        'title',
        'amount',
        'category',
        'expense_category_id',
        'payee_id',
        'description',
        'notes',
        'expense_date',
        'cash_account_id',
        'payment_method',
        'currency',
        'external_reference',
        'attachment_path',
        'attachment_name',
        'attachment_type',
        'attachment_size',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_PAID,
        'currency' => 'EGP',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'attachment_size' => 'integer',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public static function numberFor(int $id, int|string $year): string
    {
        return sprintf('EXP-%s-%06d', $year, $id);
    }

    /**
     * Derives receipt/invoice attachment metadata from an already-stored
     * private-disk path, for the Filament create/edit pages to merge into
     * form data after FileUpload has moved the file.
     */
    public static function attachmentMetadataFrom(?string $path): array
    {
        if (! $path) {
            return [];
        }

        $disk = Storage::disk(config('filesystems.uploads.private'));
        if (! $disk->exists($path)) {
            return [];
        }

        return [
            'attachment_name' => basename($path),
            'attachment_size' => $disk->size($path),
            'attachment_type' => $disk->mimeType($path),
        ];
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    // expense_category_id is intentionally not named category() — the
    // legacy free-text `category` column already occupies that attribute
    // name, and colliding the two would make $expense->category ambiguous.
    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function payee()
    {
        return $this->belongsTo(Payee::class);
    }

    public function cashTransaction()
    {
        return $this->hasOne(CashTransaction::class, 'expense_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            $expense->created_by = $expense->created_by ?: auth()->id();
        });

        static::created(function (Expense $expense) {
            if ($expense->reference_number === null) {
                $expense->reference_number = self::numberFor(
                    $expense->id,
                    $expense->created_at?->format('Y') ?? now()->format('Y')
                );
                $expense->saveQuietly();
            }

            if ($expense->status === self::STATUS_PAID) {
                app(ExpenseService::class)->postToLedger($expense);
            }
        });

        // A paid or voided expense is terminal — no further mutation of any
        // kind, mirroring CashSession's immutable-once-closed rule. Uses
        // isDirty() (not a blanket block) so an incidental no-op save never
        // throws; saveQuietly() (reference-number stamping, the paid_at
        // stamp in ExpenseService::postToLedger) bypasses this entirely.
        static::saving(function (Expense $expense) {
            if (
                $expense->exists
                && $expense->isDirty()
                && in_array($expense->getOriginal('status'), [self::STATUS_PAID, self::STATUS_VOID], true)
            ) {
                throw new LogicException("Расход в статусе «{$expense->getOriginal('status')}» нельзя изменить.");
            }
        });

        static::updated(function (Expense $expense) {
            if ($expense->wasChanged('status') && $expense->status === self::STATUS_PAID) {
                app(ExpenseService::class)->postToLedger($expense->fresh());
            }
        });

        static::deleting(function () {
            throw new LogicException('Финансовые расходы нельзя удалять.');
        });
    }
}
