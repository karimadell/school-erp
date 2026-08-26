<?php

namespace App\Services\Finance;

use App\Models\CashAccount;
use Illuminate\Support\Facades\Log;

/**
 * Cash Operations — safe canonical-role resolution.
 *
 * The single place "adopt, create, or flag ambiguous" lives, so the
 * backfill migration and anything that later needs to re-run safe
 * initialization (e.g. an Artisan command, or a future install wizard)
 * share the exact same, tested logic rather than two copies of it.
 *
 * For a given role (operating/owner/bank/instapay) and its corresponding
 * physical type:
 *   - role already assigned to some account -> leave it alone.
 *   - exactly one active, unroled account of that type -> ADOPT: role
 *     only. Name, balance, transactions, transfers, sessions, payments —
 *     untouched.
 *   - zero such accounts -> CREATE the canonical account (fresh install,
 *     or a role genuinely missing).
 *   - more than one such account -> AMBIGUOUS. Touch nothing and log it;
 *     a person decides which one is canonical. Never guessed.
 *
 * Calling this repeatedly is always safe: once a role is assigned,
 * every later call for that role is a no-op (branch one above).
 */
class CashAccountRoleService
{
    public const ADOPTED = 'adopted';

    public const CREATED = 'created';

    public const AMBIGUOUS = 'ambiguous';

    public const ALREADY_ASSIGNED = 'already_assigned';

    /** role => [type, fresh-install display name] */
    public const ROLE_MAP = [
        CashAccount::ROLE_OPERATING => ['type' => CashAccount::TYPE_CASH, 'name' => 'Операционная касса'],
        CashAccount::ROLE_OWNER => ['type' => CashAccount::TYPE_OWNER_CASH, 'name' => 'Касса владельца'],
        CashAccount::ROLE_BANK => ['type' => CashAccount::TYPE_BANK, 'name' => 'Банковский счёт'],
        CashAccount::ROLE_INSTAPAY => ['type' => CashAccount::TYPE_INSTAPAY, 'name' => 'InstaPay'],
    ];

    public function ensureAllRoles(): void
    {
        foreach (self::ROLE_MAP as $role => $config) {
            $this->ensureRole($role, $config['type'], $config['name']);
        }
    }

    public function ensureRole(string $role, string $type, string $freshInstallName): string
    {
        if (CashAccount::query()->where('role', $role)->exists()) {
            return self::ALREADY_ASSIGNED;
        }

        $candidates = CashAccount::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->whereNull('role')
            ->get();

        if ($candidates->count() === 1) {
            $candidates->first()->forceFill(['role' => $role])->save();

            return self::ADOPTED;
        }

        if ($candidates->count() === 0) {
            CashAccount::create([
                'name' => $freshInstallName,
                'type' => $type,
                'role' => $role,
                'balance' => '0.00',
                'is_active' => true,
            ]);

            return self::CREATED;
        }

        Log::warning(
            "Cash Operations: cannot auto-assign canonical role '{$role}' — ".
            "{$candidates->count()} active, unroled '{$type}' accounts exist. ".
            'A person must decide which one is canonical (see the ids below) '.
            'and set its role manually; none were touched.',
            ['role' => $role, 'type' => $type, 'candidate_ids' => $candidates->pluck('id')->all()],
        );

        return self::AMBIGUOUS;
    }
}
