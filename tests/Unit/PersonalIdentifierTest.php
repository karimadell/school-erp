<?php

namespace Tests\Unit;

use App\Support\PersonalIdentifier;
use PHPUnit\Framework\TestCase;

class PersonalIdentifierTest extends TestCase
{
    public function test_snils_is_nullable_normalized_and_checksum_validated(): void
    {
        $this->assertNull(PersonalIdentifier::normalize(' - '));
        $this->assertSame('11223344595', PersonalIdentifier::normalize('112-233-445 95'));
        $this->assertTrue(PersonalIdentifier::validSnils('112-233-445 95'));
        $this->assertFalse(PersonalIdentifier::validSnils('112-233-445 94'));
    }

    public function test_ten_and_twelve_digit_inn_checksums_are_validated(): void
    {
        $this->assertTrue(PersonalIdentifier::validInn('7707083893'));
        $this->assertTrue(PersonalIdentifier::validInn('500100732259'));
        $this->assertFalse(PersonalIdentifier::validInn('7707083894'));
        $this->assertFalse(PersonalIdentifier::validInn('500100732258'));
    }
}
