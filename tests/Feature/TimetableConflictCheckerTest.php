<?php

namespace Tests\Feature;

use App\Contracts\TimetableConflictRule;
use App\Services\TimetableConflictChecker;
use App\Support\TimetableConflictResult;
use App\Support\TimetableSlot;
use Tests\TestCase;

/**
 * The checker is orchestration only — these tests use fake rules, never
 * a real Timetable row, to prove the orchestration itself (stop at
 * first match, order matters, no match -> empty result) independently
 * of any individual rule's SQL.
 */
class TimetableConflictCheckerTest extends TestCase
{
    protected function makeSlot(): TimetableSlot
    {
        return new TimetableSlot(classId: 1, dayId: 1, periodId: 1, teacherId: 1, subjectId: 1);
    }

    protected function fakeRule(?string $key, ?array &$calls = null): TimetableConflictRule
    {
        return new class($key, $calls) implements TimetableConflictRule {
            public function __construct(private ?string $key, private ?array &$calls)
            {
            }

            public function check(TimetableSlot $slot): ?string
            {
                if ($this->calls !== null) {
                    $this->calls[] = $this->key;
                }

                return $this->key;
            }
        };
    }

    public function test_returns_an_empty_result_when_no_rule_matches(): void
    {
        $checker = new TimetableConflictChecker([
            $this->fakeRule(null),
            $this->fakeRule(null),
        ]);

        $result = $checker->check($this->makeSlot());

        $this->assertInstanceOf(TimetableConflictResult::class, $result);
        $this->assertFalse($result->hasConflict());
        $this->assertNull($result->first());
        $this->assertSame([], $result->all());
    }

    public function test_returns_the_first_matching_rules_key(): void
    {
        $checker = new TimetableConflictChecker([
            $this->fakeRule(null),
            $this->fakeRule('timetable.teacher_conflict'),
            $this->fakeRule('timetable.class_conflict'),
        ]);

        $result = $checker->check($this->makeSlot());

        $this->assertTrue($result->hasConflict());
        $this->assertSame('timetable.teacher_conflict', $result->first());
    }

    public function test_stops_at_the_first_match_and_never_calls_later_rules(): void
    {
        $calls = [];

        $checker = new TimetableConflictChecker([
            $this->fakeRule('timetable.teacher_conflict', $calls),
            $this->fakeRule('timetable.class_conflict', $calls),
        ]);

        $checker->check($this->makeSlot());

        $this->assertSame(['timetable.teacher_conflict'], $calls);
    }
}
