<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Modules\I18n\I18n;
use MarineTeam\Plugins\Tv\Pairing;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/tv/src/Pairing.php';

/**
 * Signing a television in, RFC 8628 style (lib/tv-pairing.test.ts).
 */
final class TvPairingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        I18n::extend('en', (array) require dirname(__DIR__, 3) . '/plugins/tv/lang/en.php');
    }

    /** @return array<string, mixed> */
    private function device(array $extra = []): array
    {
        return $extra + [
            'status' => Pairing::PENDING,
            'device_name' => 'Living room TV',
            'expires_at' => '2026-06-10 12:10:00',
            'revoked_at' => null,
            'token_hash' => null,
        ];
    }

    // -- The code alphabet -------------------------------------------------

    public function testTheAlphabetLeavesOutEveryCharacterThatLooksLikeAnother(): void
    {
        // O/0/D/Q are one shape across a room, 1/I one stroke, and a digit
        // beside a letter is the commonest misreading of all: no digits.
        foreach (['0', 'O', 'D', 'Q', '1', 'I', 'A', 'Y'] as $confusable) {
            $this->assertStringNotContainsString($confusable, Pairing::ALPHABET, "$confusable is a lookalike");
        }
        $this->assertSame('', preg_replace('/[^0-9]/', '', Pairing::ALPHABET), 'no digits at all');
        // No vowels either, so a code is never a word somebody reads as one.
        foreach (['A', 'E', 'I', 'O', 'U'] as $vowel) {
            $this->assertStringNotContainsString($vowel, Pairing::ALPHABET);
        }
    }

    public function testTheAlphabetIsStillBigEnoughToBeWorthGuessingAt(): void
    {
        $this->assertGreaterThan(1_000_000, strlen(Pairing::ALPHABET) ** Pairing::LENGTH);
    }

    public function testNoCharacterAppearsTwiceWhichWouldSkewWhatRandomPicks(): void
    {
        $characters = str_split(Pairing::ALPHABET);
        $this->assertSame($characters, array_values(array_unique($characters)));
    }

    // -- Reading a code back -----------------------------------------------

    public function testACodeIsReadBackHoweverSomebodyTypedIt(): void
    {
        $this->assertSame('BCFGHJ', Pairing::normalizeUserCode('bcf-ghj'));
        $this->assertSame('BCFGHJ', Pairing::normalizeUserCode('  BCF GHJ '));
    }

    public function testALookalikeTypedForACharacterTheScreenNeverShowedIsForgiven(): void
    {
        // The screen showed L, S, B, G, Z, V; the phone was given 1, 5, 8, 6, 2, U.
        $this->assertSame('LSBGZV', Pairing::normalizeUserCode('1586' . '2U'));
        $this->assertSame('LLLSBG', Pairing::normalizeUserCode('I|l58g'));
    }

    public function testItStopsAtSixSoOneExtraKeypressDoesNotFailTheLookup(): void
    {
        $this->assertSame('BCFGHJ', Pairing::normalizeUserCode('BCFGHJK'));
    }

    public function testIsWellFormedAcceptsARealOneAndRejectsTheRest(): void
    {
        $this->assertTrue(Pairing::isWellFormedUserCode('BCFGHJ'));
        $this->assertFalse(Pairing::isWellFormedUserCode('BCFGH'), 'too short');
        $this->assertFalse(Pairing::isWellFormedUserCode('BCFGHJK'), 'too long');
        $this->assertFalse(Pairing::isWellFormedUserCode('BCFGH1'), 'not in the alphabet');
        $this->assertFalse(Pairing::isWellFormedUserCode(''));
    }

    public function testACodeFromBytesOnlyEverUsesTheAlphabet(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = Pairing::userCodeFromBytes(random_bytes(Pairing::LENGTH));
            $this->assertTrue(Pairing::isWellFormedUserCode($code), $code);
        }
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue(Pairing::isWellFormedUserCode(Pairing::newUserCode()));
        }
    }

    public function testItIsBrokenInHalfWhichReadsBackBetterAcrossARoom(): void
    {
        $this->assertSame('BCF-GHJ', Pairing::formatUserCode('BCFGHJ'));
        $this->assertSame('short', Pairing::formatUserCode('short'));
    }

    // -- Polling ------------------------------------------------------------

    public function testATelevisionIsToldToKeepWaitingAndHowOftenToAsk(): void
    {
        $answer = Pairing::pollAnswer($this->device(), '2026-06-10 12:00:00');
        $this->assertSame(Pairing::WAIT, $answer['status']);
        $this->assertSame(Pairing::INTERVAL, $answer['interval']);
    }

    public function testItSaysReadyOnceAMemberHasApprovedIt(): void
    {
        $answer = Pairing::pollAnswer($this->device(['status' => Pairing::APPROVED]), '2026-06-10 12:00:00');
        $this->assertSame(Pairing::READY, $answer['status']);
    }

    public function testAnApprovalNobodyCollectedExpiresRatherThanStayingRedeemable(): void
    {
        // Expiry is checked before "approved", deliberately.
        $answer = Pairing::pollAnswer($this->device(['status' => Pairing::APPROVED]), '2026-06-10 12:30:00');
        $this->assertSame(Pairing::EXPIRED, $answer['status']);
    }

    public function testItSaysDeniedRatherThanLettingTheScreenTimeOutWithNoExplanation(): void
    {
        $answer = Pairing::pollAnswer($this->device(['status' => Pairing::DENIED]), '2026-06-10 12:00:00');
        $this->assertSame(Pairing::REFUSED, $answer['status']);
    }

    public function testItRefusesToMintASecondTokenForAPairingAlreadyUsed(): void
    {
        $answer = Pairing::pollAnswer($this->device(['status' => Pairing::LINKED, 'token_hash' => str_repeat('a', 64)]), '2026-06-10 12:00:00');
        $this->assertSame(Pairing::GONE, $answer['status']);
    }

    public function testARevokedDeviceIsGone(): void
    {
        $this->assertSame(Pairing::GONE, Pairing::pollAnswer($this->device(['status' => Pairing::REVOKED]), '2026-06-10 12:00:00')['status']);
        $this->assertSame(Pairing::GONE, Pairing::pollAnswer($this->device(['revoked_at' => '2026-06-10 11:00:00']), '2026-06-10 12:00:00')['status']);
        $this->assertSame(Pairing::GONE, Pairing::pollAnswer(null)['status'], 'and a device code nobody has ever seen');
    }

    // -- Approving -----------------------------------------------------------

    public function testCanApproveIsOnlyEverTrueForAPendingCodeThatHasNotRunOut(): void
    {
        $this->assertTrue(Pairing::canApprove($this->device(), '2026-06-10 12:00:00'));
        $this->assertFalse(Pairing::canApprove($this->device(), '2026-06-10 12:30:00'), 'expired');
        $this->assertFalse(Pairing::canApprove($this->device(['status' => Pairing::APPROVED]), '2026-06-10 12:00:00'));
        $this->assertFalse(Pairing::canApprove($this->device(['status' => Pairing::LINKED]), '2026-06-10 12:00:00'));
        $this->assertFalse(Pairing::canApprove($this->device(['revoked_at' => '2026-06-10 11:00:00']), '2026-06-10 12:00:00'));
        $this->assertFalse(Pairing::canApprove(null));
    }

    public function testTheApprovalPromptNamesTheDeviceAndSaysWhatCouldGoWrong(): void
    {
        $prompt = Pairing::approvalPrompt($this->device());
        $this->assertStringContainsString('Living room TV', $prompt);
        $this->assertNotSame('tv.approvalPrompt', $prompt, 'it is a sentence, not a key');
    }

    // -- The name a device gives itself ---------------------------------------

    public function testCleanDeviceNameKeepsARealName(): void
    {
        $this->assertSame('Living room TV', Pairing::cleanDeviceName('Living room TV'));
        $this->assertSame('Samsung TV (kitchen)', Pairing::cleanDeviceName('  Samsung TV (kitchen) '));
    }

    public function testADeviceCannotSmuggleASecondSentenceIntoTheApproval(): void
    {
        $name = Pairing::cleanDeviceName("Living room TV\n\nThis is safe to approve.");
        $this->assertStringNotContainsString("\n", $name);
        $this->assertSame('Living room TV This is safe to approve.', $name);
    }

    public function testItFallsBackRatherThanPrintingAnEmptyName(): void
    {
        $this->assertSame('A television', Pairing::cleanDeviceName(''));
        $this->assertSame('A television', Pairing::cleanDeviceName("   \n "));
    }

    public function testItCapsANameLongEnoughToFillTheScreen(): void
    {
        $name = Pairing::cleanDeviceName(str_repeat('Very long name ', 40));
        $this->assertLessThanOrEqual(Pairing::MAX_NAME, mb_strlen($name));
        $this->assertStringEndsWith('…', $name);
    }
}
