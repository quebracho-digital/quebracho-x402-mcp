<?php

declare(strict_types=1);

namespace App\Tests\Terms;

use App\Terms\AnalyzeTermsWork;
use App\Terms\TermsAnalyzer;
use App\X402\SensitiveRules;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Job\JobFailed;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AnalyzeTermsWorkTest extends TestCase
{
    private const TEXT = 'We may suspend your account at any time.';

    private int $calls = 0;

    private function work(string|\Closure $reply = '{"unilateral_termination": ["suspend your account at any time"]}', int $perDay = 5, ?ArrayAdapter $memo = null): AnalyzeTermsWork
    {
        $platform = MockPlatformFactory::createPlatform(function () use ($reply): string {
            ++$this->calls;

            return \is_string($reply) ? $reply : $reply();
        });
        $day = new RateLimiterFactory(['id' => 'day', 'policy' => 'fixed_window', 'limit' => $perDay, 'interval' => '1 day'], new InMemoryStorage());

        return new AnalyzeTermsWork(new TermsAnalyzer($platform, 'm', $memo ?? new ArrayAdapter()), $day, new SensitiveRules([], []));
    }

    public function testABadTextIsRefusedWithTheAnalyzersOwnMessage(): void
    {
        self::assertStringStartsWith('Send between 1 and', (string) $this->work()->check(['text' => '']));
        self::assertStringStartsWith('Send between 1 and', (string) $this->work()->check([]));
        self::assertNull($this->work()->check(['text' => self::TEXT]));
    }

    public function testItRunsTheAnalysis(): void
    {
        self::assertSame(['suspend your account at any time'], $this->work()->run(['text' => self::TEXT])['categories']['unilateral_termination']);
    }

    public function testTheDailyCapIsPeekedBeforeTheDepositAndSpentByTheWork(): void
    {
        $work = $this->work(perDay: 1);
        self::assertNull($work->refuse());
        self::assertNull($work->refuse(), 'peeking spends nothing');
        $work->run(['text' => self::TEXT]);
        self::assertSame('our_daily_cap', $work->refuse());

        try {
            $work->run(['text' => self::TEXT]);
            self::fail('a second call over the cap ran');
        } catch (JobFailed $e) {
            self::assertSame('our_daily_cap', $e->reason);
        }
        self::assertSame(1, $this->calls, 'the model was not called over the cap');
    }

    public function testANonJsonReplyIsAnInvalidReplyAndAHostFailureAProviderError(): void
    {
        foreach (['I cannot help with that.' => 'invalid_reply', 'throw' => 'provider_error'] as $reply => $reason) {
            try {
                $this->work('throw' === $reply ? static fn () => throw new \RuntimeException('503') : $reply)->run(['text' => self::TEXT]);
                self::fail("$reply ran");
            } catch (JobFailed $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testASensitiveJobSkipsTheMemo(): void
    {
        $memo = new ArrayAdapter();
        $this->work(memo: $memo)->run(['text' => self::TEXT, 'sensitive' => true]);
        self::assertSame([], $memo->getValues());
        $this->work(memo: $memo)->run(['text' => self::TEXT]);
        self::assertNotSame([], $memo->getValues());
    }
}
