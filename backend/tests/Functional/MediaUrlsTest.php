<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\MediaUrls;
use PHPUnit\Framework\TestCase;

/**
 * Signed media URLs (spec §11 décision 41): stable within a week (HTTP
 * cache, device copies), valid 7 to 14 days, bound to their path.
 */
final class MediaUrlsTest extends TestCase
{
    public function testUrlsAreStableForAWeekAndUsableUpToTwo(): void
    {
        $urls = new MediaUrls('https://api.example', 'secret');
        $monday = MediaUrls::windowStart()->getTimestamp();

        $url = (string) $urls->url('ideas/abc.jpg', $monday + 10);
        self::assertSame($url, $urls->url('ideas/abc.jpg', $monday + MediaUrls::PERIOD - 1), 'same URL all week');
        self::assertNotSame($url, $urls->url('ideas/abc.jpg', $monday + MediaUrls::PERIOD), 'new one the week after');

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame($monday + 2 * MediaUrls::PERIOD, (int) $query['e']);
        self::assertTrue($urls->isValid('ideas/abc.jpg', (int) $query['e'], (string) $query['s']));
        self::assertFalse($urls->isValid('ideas/other.jpg', (int) $query['e'], (string) $query['s']), 'bound to its path');
        self::assertNull($urls->url(null));
    }
}
