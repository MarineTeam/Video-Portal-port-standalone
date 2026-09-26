<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Modules\I18n\I18n;
use MarineTeam\Plugins\Broadcasts\Broadcast;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/broadcasts/src/Broadcast.php';

/** lib/broadcast.test.ts — who a broadcast reaches, and who it does not. */
final class BroadcastTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        I18n::extend('en', (array) require dirname(__DIR__, 3) . '/plugins/broadcasts/lang/en.php');
    }

    /** @return array<string, mixed> */
    private function member(array $extra = []): array
    {
        return $extra + [
            'userId' => 'u1',
            'name' => 'Ruth',
            'email' => 'ruth@test.example',
            'phone' => '+447700900123',
            'broadcastEmails' => true,
            'smsOptIn' => true,
            'pushDevices' => 1,
        ];
    }

    private const ALL = [Broadcast::EMAIL, Broadcast::SMS, Broadcast::PUSH];

    // -- planDelivery -------------------------------------------------------

    public function testItReachesSomebodyOnEveryChannelTheyAreSetUpFor(): void
    {
        $plan = Broadcast::planDelivery([$this->member()], self::ALL);
        $this->assertSame(['EMAIL', 'SMS', 'PUSH'], array_column($plan['rows'], 'channel'));
        $this->assertSame(['ruth@test.example', '+447700900123', 'u1'], array_column($plan['rows'], 'address'));
        $this->assertSame(1, $plan['reached']);
        $this->assertSame(0, $plan['missed']);
        $this->assertSame([], $plan['skips']);
    }

    public function testItWillNotTextSomebodyWhoNeverAgreedToBeTexted(): void
    {
        // A number on an event's sign-up form is not permission to text.
        $plan = Broadcast::planDelivery([$this->member(['smsOptIn' => false])], [Broadcast::SMS]);
        $this->assertSame([], $plan['rows']);
        $this->assertSame(Broadcast::NOT_OPTED_IN, $plan['skips'][0]['reason']);
        $this->assertSame(1, $plan['missed']);
    }

    public function testItEmailsByDefaultAndStopsForSomebodyWhoTurnedAnnouncementsOff(): void
    {
        $unknown = $this->member();
        unset($unknown['broadcastEmails']);
        $this->assertCount(1, Broadcast::planDelivery([$unknown], [Broadcast::EMAIL])['rows'], 'on unless somebody turned it off');

        $plan = Broadcast::planDelivery([$this->member(['broadcastEmails' => false])], [Broadcast::EMAIL]);
        $this->assertSame([], $plan['rows']);
        $this->assertSame(Broadcast::ANNOUNCEMENTS_OFF, $plan['skips'][0]['reason']);
    }

    public function testItSkipsANumberItCannotMakeSenseOfRatherThanSendingItAnyway(): void
    {
        $plan = Broadcast::planDelivery([$this->member(['phone' => '07700 900123'])], [Broadcast::SMS]);
        $this->assertSame(Broadcast::BAD_PHONE, $plan['skips'][0]['reason'], 'a national number with no country code is not guessed at');
        $this->assertSame(
            '+447700900123',
            Broadcast::planDelivery([$this->member(['phone' => '07700 900123'])], [Broadcast::SMS], ['defaultCountry' => '44'])['rows'][0]['address'],
            'and is read once there is a code to read it with',
        );
        $this->assertSame(Broadcast::NO_PHONE, Broadcast::planDelivery([$this->member(['phone' => ''])], [Broadcast::SMS])['skips'][0]['reason']);
    }

    public function testItCannotPushToSomebodyWithNoAccountOrNoDevice(): void
    {
        $this->assertSame(Broadcast::NO_DEVICE, Broadcast::planDelivery([$this->member(['pushDevices' => 0])], [Broadcast::PUSH])['skips'][0]['reason']);
        $signUp = ['userId' => null, 'name' => 'Visitor', 'email' => 'visitor@test.example'];
        $this->assertSame(Broadcast::NO_ACCOUNT, Broadcast::planDelivery([$signUp], [Broadcast::PUSH])['skips'][0]['reason']);
    }

    public function testItStillEmailsSomebodyWithNoAccount(): void
    {
        // An event's sign-ups are full of them, and they are reachable at the
        // address they typed.
        $plan = Broadcast::planDelivery([['userId' => null, 'name' => 'Visitor', 'email' => 'visitor@test.example']], self::ALL);
        $this->assertSame(['EMAIL'], array_column($plan['rows'], 'channel'));
        $this->assertSame(1, $plan['reached']);
        $this->assertNull($plan['rows'][0]['userId']);
    }

    public function testItWritesToSomebodyOnceEvenWhenTheyAreInTheAudienceTwice(): void
    {
        $plan = Broadcast::planDelivery([$this->member(), $this->member()], self::ALL);
        $this->assertCount(3, $plan['rows']);
        $this->assertSame(1, $plan['reached']);
    }

    public function testItDedupesAccountLessPeopleByAddress(): void
    {
        $signUp = ['userId' => null, 'name' => 'Visitor', 'email' => 'Visitor@Test.example'];
        $again = ['userId' => null, 'name' => 'Visitor', 'email' => 'visitor@test.example'];
        $plan = Broadcast::planDelivery([$signUp, $again], [Broadcast::EMAIL]);
        $this->assertCount(1, $plan['rows'], 'they have no id to match on, so the address is the identity');
        $this->assertSame(1, $plan['reached']);
    }

    public function testTwoPeopleSharingOneAddressAreWrittenToOnce(): void
    {
        // The unique index is (broadcast, channel, address), so the second
        // insert would be refused; it is collapsed here instead.
        $plan = Broadcast::planDelivery([
            $this->member(['userId' => 'u1', 'phone' => '', 'pushDevices' => 0]),
            $this->member(['userId' => 'u2', 'name' => 'Sam', 'phone' => '', 'pushDevices' => 0]),
        ], [Broadcast::EMAIL]);
        $this->assertCount(1, $plan['rows']);
        $this->assertSame(2, $plan['reached'], 'and both are still counted as reached');
    }

    public function testSomebodyIsReachedIfAnyOneChannelWorks(): void
    {
        $plan = Broadcast::planDelivery([$this->member(['smsOptIn' => false, 'pushDevices' => 0])], self::ALL);
        $this->assertSame(1, $plan['reached']);
        $this->assertSame(0, $plan['missed']);
        $this->assertCount(2, $plan['skips'], 'the skips are still recorded');
    }

    public function testSomebodyIsMissedWhenNothingWorks(): void
    {
        $plan = Broadcast::planDelivery([
            $this->member(['broadcastEmails' => false, 'smsOptIn' => false, 'pushDevices' => 0]),
            $this->member(['userId' => 'u2', 'name' => 'Sam']),
        ], self::ALL);
        $this->assertSame(1, $plan['reached']);
        $this->assertSame(1, $plan['missed'], 'the number that matters');
    }

    public function testAChannelNobodyAskedForIsNotPlanned(): void
    {
        $plan = Broadcast::planDelivery([$this->member()], [Broadcast::EMAIL]);
        $this->assertSame(['EMAIL'], array_column($plan['rows'], 'channel'));
        $this->assertSame([], $plan['skips']);
    }

    // -- summariseSkips -----------------------------------------------------

    public function testItSaysWhyCommonestFirst(): void
    {
        $people = [
            $this->member(['userId' => 'a', 'broadcastEmails' => false, 'smsOptIn' => false, 'pushDevices' => 0]),
            $this->member(['userId' => 'b', 'broadcastEmails' => false, 'smsOptIn' => false, 'pushDevices' => 0]),
            $this->member(['userId' => 'c', 'email' => '', 'smsOptIn' => false, 'pushDevices' => 0]),
        ];
        $plan = Broadcast::planDelivery($people, self::ALL);
        $summary = Broadcast::summariseSkips($plan['skips'], $plan['rows']);
        $this->assertSame(Broadcast::ANNOUNCEMENTS_OFF, $summary[0]['reason']);
        $this->assertSame(2, $summary[0]['count']);
        $this->assertSame(Broadcast::NO_EMAIL, $summary[1]['reason']);
        $this->assertSame(1, $summary[1]['count']);
        $this->assertSame(3, array_sum(array_column($summary, 'count')), 'each person counted once, not once per channel');
    }

    public function testItIsEmptyWhenEverybodyIsReachable(): void
    {
        $plan = Broadcast::planDelivery([$this->member(), $this->member(['userId' => 'u2', 'smsOptIn' => false])], self::ALL);
        $this->assertSame([], Broadcast::summariseSkips($plan['skips'], $plan['rows']), 'a member with no text who got the email is not missing out');
    }

    // -- audienceLabel ------------------------------------------------------

    public function testTheLabelStillReadsSensiblyAfterTheGroupItNamedIsGone(): void
    {
        $this->assertSame('Everyone', Broadcast::audienceLabel(['audience' => Broadcast::EVERYONE]));
        $this->assertSame('Small group: Tuesday evening', Broadcast::audienceLabel(['audience' => Broadcast::SMALL_GROUP, 'audienceName' => 'Tuesday evening']));
        $gone = Broadcast::audienceLabel(['audience' => Broadcast::SMALL_GROUP, 'audienceName' => null]);
        $this->assertStringContainsString('Small group', $gone);
        $this->assertStringNotContainsString('SMALL_GROUP', $gone);
    }

    // -- progressOf ---------------------------------------------------------

    public function testItCountsAnythingNotStillPendingAsDone(): void
    {
        $progress = Broadcast::progressOf([Broadcast::OK => 180, Broadcast::FAILED => 2, Broadcast::SKIPPED => 8, Broadcast::PENDING => 10]);
        $this->assertSame(200, $progress['total']);
        $this->assertSame(190, $progress['done']);
        $this->assertSame(95, $progress['percent']);
        $this->assertFalse($progress['finished']);
    }

    public function testItIsFinishedOnlyWhenNothingIsPendingFailuresIncluded(): void
    {
        $this->assertTrue(Broadcast::progressOf([Broadcast::OK => 1, Broadcast::FAILED => 3])['finished']);
        $this->assertFalse(Broadcast::progressOf([Broadcast::PENDING => 1])['finished']);
        $this->assertTrue(Broadcast::progressOf([])['finished'], 'and nothing to do is done');
        $this->assertSame(100, Broadcast::progressOf([])['percent']);
    }
}
