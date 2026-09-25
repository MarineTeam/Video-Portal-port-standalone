<?php

declare(strict_types=1);

namespace Tests\Unit\Push;

use App\Modules\Push\PushEndpoint;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/push-endpoint.test.ts */
final class PushEndpointTest extends TestCase
{
    #[TestDox('accepts the endpoints real browsers hand out')]
    public function testReal(): void
    {
        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc:def',
            'https://updates.push.services.mozilla.com/wpush/v2/gAAA',
            'https://wns2-by3p.notify.windows.com/w/?token=x',
            'https://web.push.apple.com/QGuQ',
            'https://android.googleapis.com/gcm/send/x',
        ] as $url) {
            self::assertTrue(PushEndpoint::isPushServiceEndpoint($url), $url);
        }
    }

    #[TestDox('refuses anything else — including hosts that merely contain a service\'s name')]
    public function testOthers(): void
    {
        foreach ([
            'https://evil.example.com/fcm.googleapis.com',
            'https://fcm.googleapis.com.evil.example/x',
            'https://notfcm.googleapis.com/x',
            'https://push.apple.com.attacker.net/',
            'https://example.com/',
        ] as $url) {
            self::assertFalse(PushEndpoint::isPushServiceEndpoint($url), $url);
        }
    }

    #[TestDox('refuses plain http even to a real service, and credentials in the URL')]
    public function testHttpAndCredentials(): void
    {
        self::assertFalse(PushEndpoint::isPushServiceEndpoint('http://fcm.googleapis.com/fcm/send/x'));
        self::assertFalse(PushEndpoint::isPushServiceEndpoint('https://user:pw@fcm.googleapis.com/fcm/send/x'));
    }

    #[TestDox('refuses what isn\'t a URL at all')]
    public function testNotUrl(): void
    {
        foreach (['', 'fcm.googleapis.com', 'nonsense', '//fcm.googleapis.com/x'] as $url) {
            self::assertFalse(PushEndpoint::isPushServiceEndpoint($url), $url);
        }
    }

    #[TestDox('lets a deployment add a host suffix by environment')]
    public function testExtra(): void
    {
        $extra = PushEndpoint::extraSuffixes(' push.example-browser.net , ');
        self::assertTrue(PushEndpoint::isPushServiceEndpoint('https://eu.push.example-browser.net/x', $extra));
        self::assertFalse(PushEndpoint::isPushServiceEndpoint('https://eu.push.example-browser.net/x'));
    }

    #[TestDox('evicts nothing while there is room for one more')]
    public function testEvictNone(): void
    {
        $subs = array_map(fn ($i) => ['id' => "s$i", 'createdAt' => "2026-01-0{$i}"], range(1, 7));
        self::assertSame([], PushEndpoint::subscriptionsToEvict($subs));
    }

    #[TestDox('evicts the oldest to leave room for exactly one more')]
    public function testEvictOldest(): void
    {
        $subs = array_map(fn ($i) => ['id' => "s$i", 'createdAt' => sprintf('2026-01-%02d', $i)], [3, 1, 2, 4, 5, 6, 7, 8]);
        self::assertSame(['s1'], PushEndpoint::subscriptionsToEvict($subs));
    }

    #[TestDox('evicts as many as it takes when a member is already far over')]
    public function testEvictMany(): void
    {
        $subs = array_map(fn ($i) => ['id' => "s$i", 'createdAt' => sprintf('2026-01-%02d', $i)], range(1, 12));
        self::assertSame(['s1', 's2', 's3', 's4', 's5'], PushEndpoint::subscriptionsToEvict($subs));
    }
}
