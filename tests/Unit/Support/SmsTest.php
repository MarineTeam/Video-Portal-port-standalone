<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Sms;
use PHPUnit\Framework\TestCase;

/** lib/sms.test.ts — the half the composer and the server share. */
final class SmsTest extends TestCase
{
    // -- normalizePhone ----------------------------------------------------

    public function testItKeepsANumberThatIsAlreadyInternational(): void
    {
        $this->assertSame('+14155550132', Sms::normalizePhone('+1 (415) 555-0132'));
        $this->assertSame('+447700900123', Sms::normalizePhone('+44 7700 900123', '1'), 'and does not re-apply a default');
    }

    public function testItReadsDoubleZeroAsTheOtherWayOfWritingPlus(): void
    {
        $this->assertSame('+447700900123', Sms::normalizePhone('0044 7700 900123'));
    }

    public function testItAddsACountryCodeToANationalNumberAndDropsTheTrunkZero(): void
    {
        $this->assertSame('+447700900123', Sms::normalizePhone('07700 900123', '44'));
        $this->assertSame('+447700900123', Sms::normalizePhone('07700900123', '+44'));
    }

    public function testItRefusesANationalNumberWithNoCountryCodeToAddRatherThanGuessing(): void
    {
        $this->assertNull(Sms::normalizePhone('07700 900123'));
        $this->assertNull(Sms::normalizePhone('07700 900123', ''));
    }

    public function testItRefusesSomethingThatIsNotAPhoneNumber(): void
    {
        $this->assertNull(Sms::normalizePhone('ring the office'));
        $this->assertNull(Sms::normalizePhone(''));
        $this->assertNull(Sms::normalizePhone('   '));
        $this->assertNull(Sms::normalizePhone(null));
        $this->assertNull(Sms::normalizePhone('12345'), 'and one too short to be one');
    }

    public function testItRefusesOneLongerThanAnyRealNumber(): void
    {
        $this->assertNull(Sms::normalizePhone('+1234567890123456'));
    }

    // -- smsSegments -------------------------------------------------------

    public function testOneHundredAndSixtyPlainCharactersFitInOneMessage(): void
    {
        $counted = Sms::segments(str_repeat('a', 160));
        $this->assertSame(1, $counted['messages']);
        $this->assertSame('GSM-7', $counted['encoding']);
        $this->assertSame(0, $counted['remaining']);
    }

    public function testItSpillsIntoTwoAt161WhichAre153Each(): void
    {
        $counted = Sms::segments(str_repeat('a', 161));
        $this->assertSame(2, $counted['messages']);
        $this->assertSame(153, $counted['perMessage']);
        $this->assertSame(145, $counted['remaining']);
    }

    public function testTheBracketFamilyCostsTwoPlacesAsTheStandardSays(): void
    {
        $this->assertSame(2, Sms::segments('[')['units']);
        $this->assertSame(2, Sms::segments('€')['units']);
        // Eighty of them fill a message exactly; eighty-one spill.
        $this->assertSame(1, Sms::segments(str_repeat('{', 80))['messages']);
        $this->assertSame(2, Sms::segments(str_repeat('{', 81))['messages']);
        $this->assertSame('GSM-7', Sms::segments('{}[]~|^\\€')['encoding'], 'they are still 7-bit');
    }

    public function testOneCharacterOutsideTheSevenBitSetHalvesTheAllowance(): void
    {
        // The curly apostrophe a word processor substitutes.
        $counted = Sms::segments('don’t' . str_repeat('a', 65));
        $this->assertSame('UCS-2', $counted['encoding']);
        $this->assertSame(70, $counted['characters']);
        $this->assertSame(1, $counted['messages']);
        $this->assertSame(2, Sms::segments('don’t' . str_repeat('a', 66))['messages']);
        $this->assertSame(67, Sms::segments('don’t' . str_repeat('a', 66))['perMessage']);
    }

    public function testAnEmojiCountsAsTheTwoUnitsItCosts(): void
    {
        $counted = Sms::segments('👋');
        $this->assertSame(1, $counted['characters'], 'one character to a reader');
        $this->assertSame(2, $counted['units'], 'two units to a carrier');
        $this->assertSame('UCS-2', $counted['encoding']);
        $this->assertSame(2, Sms::segments(str_repeat('👋', 35) . 'a')['messages']);
    }

    public function testItNeverReportsZeroMessagesEvenForNothing(): void
    {
        $this->assertSame(1, Sms::segments('')['messages']);
        $this->assertSame(0, Sms::segments('')['units']);
    }
}
