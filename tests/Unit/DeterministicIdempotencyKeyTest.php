<?php

namespace Tests\Unit;

use App\Support\DeterministicIdempotencyKey;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Unified Collection foundation (PR A) — proves derive() is byte-identical
 * to the formula QuickStudentRegistrationService always computed inline
 * (Uuid::uuid5(Uuid::NAMESPACE_URL, "{namespace}:{token}:{suffix}")), and
 * that a null token still falls back to a fresh random UUID rather than a
 * deterministic (and therefore collidable) value.
 */
class DeterministicIdempotencyKeyTest extends TestCase
{
    public function test_derive_matches_the_original_inline_uuid5_formula(): void
    {
        $expected = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'quick-registration:some-outer-token:mixed-once');

        $actual = DeterministicIdempotencyKey::derive('some-outer-token', 'quick-registration', 'mixed-once');

        $this->assertSame($expected, $actual);
    }

    public function test_derive_is_deterministic_across_repeated_calls_with_the_same_inputs(): void
    {
        $first = DeterministicIdempotencyKey::derive('token-a', 'quick-registration', '0');
        $second = DeterministicIdempotencyKey::derive('token-a', 'quick-registration', '0');

        $this->assertSame($first, $second);
    }

    public function test_a_different_namespace_never_collides_with_another_namespaces_key_for_the_same_token_and_suffix(): void
    {
        $a = DeterministicIdempotencyKey::derive('shared-token', 'quick-registration', 'calendar');
        $b = DeterministicIdempotencyKey::derive('shared-token', 'finance-collection', 'calendar');

        $this->assertNotSame($a, $b);
    }

    public function test_a_null_outer_token_falls_back_to_a_fresh_random_uuid_each_call(): void
    {
        $a = DeterministicIdempotencyKey::derive(null, 'quick-registration', 'mixed-once');
        $b = DeterministicIdempotencyKey::derive(null, 'quick-registration', 'mixed-once');

        $this->assertNotSame($a, $b);
        $this->assertTrue(Uuid::isValid($a));
        $this->assertTrue(Uuid::isValid($b));
    }
}
