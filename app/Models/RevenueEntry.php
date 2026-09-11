<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Status workflow: draft -> posted -> reversed.
 *
 * Unlike Expense (which had to preserve a pre-existing "instant post on
 * create" caller), RevenueEntry is a clean slate: there is no legacy
 * caller to be compatible with, so this model has NO auto-posting hook at
 * all. RevenueService owns every state transition and the entire
 * transaction boundary — the model's own booted() hooks exist only to
 * protect invariants (immutability, no destructive delete of posted
 * history), never to mutate the ledger.
 *
 * Immutability: a posted entry is immutable EXCEPT for the one legitimate
 * outgoing transition, posted -> reversed (RevenueService::reverse()) — the
 * saving() guard below allows exactly that transition and blocks every
 * other attempted change to a posted row. A reversed entry is fully
 * terminal — no further change of any kind.
 *
 * Draft delete policy (documented per the design task): a DRAFT has never
 * touched the ledger — no CashTransaction exists yet — so it is not yet
 * "financial history" and may be hard-deleted. The canonical, authorized
 * path is RevenueService::deleteDraft() (permission check + audit trail +
 * atomicity); the model's own deleting() hook only re-enforces the state
 * invariant (draft-only) as defense in depth — it carries no actor
 * awareness and writes no audit entry itself, by design, so that a raw
 * $entry->delete() bypassing the service is at least still blocked for
 * anything but a draft, even though it wouldn't be audited. Once posted or
 * reversed, deletion is blocked unconditionally, matching Expense's
 * blanket delete-block for genuinely financially significant rows.
 */
class RevenueEntry extends Model
{
    const STATUS_DRAFT = 'draft';

    const STATUS_POSTED = 'posted';

    const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'reference_number',
        'revenue_category_id',
        'student_id',
        'payer_name',
        'amount',
        'revenue_date',
        'cash_account_id',
        'payment_method',
        'description',
        'notes',
        'attachment_path',
        'attachment_name',
        'attachment_type',
        'attachment_size',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'revenue_date' => 'date',
        'amount' => 'decimal:2',
        'attachment_size' => 'integer',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public static function numberFor(int $id, int|string $year): string
    {
        return sprintf('REV-%s-%06d', $year, $id);
    }

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

    public function category()
    {
        return $this->belongsTo(RevenueCategory::class, 'revenue_category_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function cashTransaction()
    {
        return $this->hasOne(CashTransaction::class, 'revenue_entry_id');
    }

    public function reversalTransaction()
    {
        return $this->hasOne(CashTransaction::class, 'reversed_revenue_entry_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    protected static function booted(): void
    {
        static::creating(function (RevenueEntry $entry) {
            $entry->status = $entry->status ?: self::STATUS_DRAFT;
        });

        static::saving(function (RevenueEntry $entry) {
            if (! $entry->exists || ! $entry->isDirty()) {
                return;
            }

            $original = $entry->getOriginal('status');

            if ($original === self::STATUS_REVERSED) {
                throw new LogicException('Сторнированную запись о доходе нельзя изменить.');
            }

            if ($original === self::STATUS_POSTED && $entry->status !== self::STATUS_REVERSED) {
                throw new LogicException('Проведённую запись о доходе нельзя изменить.');
            }
        });

        // State invariant only — no actor/authorization awareness and no
        // audit write here on purpose. Authorization and the audit trail
        // for a legitimate draft deletion live in
        // RevenueService::deleteDraft(), the canonical application path;
        // this guard is defense in depth against any other caller,
        // ensuring the invariant holds even if something bypasses the
        // service.
        static::deleting(function (RevenueEntry $entry) {
            if ($entry->status !== self::STATUS_DRAFT) {
                throw new LogicException('Проведённую или сторнированную запись о доходе нельзя удалить.');
            }
        });
    }
}
