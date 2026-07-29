<?php

namespace App\Support;

/**
 * Wraps the translation key(s) found for a checked TimetableSlot. Today
 * TimetableConflictChecker always stops at the first match, so this
 * holds at most one key — but callers get the list-shaped API
 * (all()/first()/hasConflict()) from the start, so a future "return
 * every conflict, not just the first" mode only needs to change what
 * the checker puts into this object, never its callers.
 */
final class TimetableConflictResult
{
    /** @param string[] $conflictKeys translation keys, in the order the rules matched */
    public function __construct(private readonly array $conflictKeys = []) {}

    public function hasConflict(): bool
    {
        return $this->conflictKeys !== [];
    }

    public function first(): ?string
    {
        return $this->conflictKeys[0] ?? null;
    }

    /** @return string[] */
    public function all(): array
    {
        return $this->conflictKeys;
    }
}
