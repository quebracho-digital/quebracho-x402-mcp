<?php

declare(strict_types=1);

namespace App\Tests\Terms;

use App\Terms\TermsAnalyzer;
use App\Terms\TermsCacheFactory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * The memo on the Redis of DDEV (the ddev-redis add-on). Skipped, with the reason, when it does not answer.
 */
final class TermsCacheRedisTest extends TestCase
{
    public function testTheSameTextIsMemoisedInRedisAndTheModelIsCalledOnce(): void
    {
        $url = $_ENV['X402_REDIS_URL'] ?? $_SERVER['X402_REDIS_URL'] ?? 'redis://redis:6379';
        try {
            $cache = TermsCacheFactory::create('terms', 604800, $url, sys_get_temp_dir());
            $cache->get('ping-'.bin2hex(random_bytes(4)), static fn () => 1);
        } catch (\Throwable $e) {
            self::markTestSkipped("Redis does not answer at $url (DDEV: `ddev get ddev/ddev-redis` then `ddev restart`): {$e->getMessage()}");
        }

        // A text no other run sends: the memo is shared and not cleared here.
        $text = 'We may suspend your account at any time. Run '.bin2hex(random_bytes(6)).'.';
        $calls = 0;
        $platform = MockPlatformFactory::createPlatform(function () use (&$calls) {
            ++$calls;

            return '{"unilateral_termination": ["We may suspend your account at any time."]}';
        });
        $analyzer = new TermsAnalyzer($platform, 'some/model', $cache);

        $first = $analyzer->analyze($text);
        $second = $analyzer->analyze($text);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }
}
