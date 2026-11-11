<?php

declare(strict_types=1);

namespace App\Tests\Terms;

use App\Mcp\AnalyzeTermsTool;
use App\Terms\TermsAnalyzer;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\SensitiveRules;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Cache\CacheInterface;

final class TermsAnalyzerTest extends TestCase
{
    private const TEXT = "We may suspend your account at any time, without notice.\nAny dispute goes to binding arbitration in Delaware.\nWe share your data with our partners.";

    private function analyzer(string $reply, ?array &$seen = null): TermsAnalyzer
    {
        $platform = MockPlatformFactory::createPlatform(function ($model, $input, array $options = []) use ($reply, &$seen) {
            $seen = ['model' => $model, 'options' => $options, 'input' => $input];

            return $reply;
        });

        return new TermsAnalyzer($platform, 'some/model', new ArrayAdapter());
    }

    private function tool(TermsAnalyzer $analyzer, int $perDay = 500): AnalyzeTermsTool
    {
        $limiter = static fn (string $id, int $limit) => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 day'], new InMemoryStorage());

        return new AnalyzeTermsTool($analyzer, $limiter('day', $perDay), new SensitiveRules([]));
    }

    public function testKeepsOnlyQuotesFoundInTheTextAndCountsTheRest(): void
    {
        $reply = '{"unilateral_termination": ["suspend your account at any time, without notice", "terminate you for any reason"],'
            .'"arbitration": ["binding   arbitration\nin Delaware"], "tracking": ["a quote the model made up"]}';

        $r = $this->analyzer($reply)->analyze(self::TEXT);

        self::assertSame([
            'unilateral_termination' => ['suspend your account at any time, without notice'],
            'arbitration' => ['binding   arbitration
in Delaware'],
        ], $r['categories']);
        self::assertSame(2, $r['quotes_dropped']);
    }

    public function testIgnoresACategoryItDoesNotKnow(): void
    {
        $r = $this->analyzer('{"made_up_category": ["We may suspend your account"]}')->analyze(self::TEXT);

        self::assertSame([], $r['categories']);
    }

    public function testAnswerWrappedInAFenceAndProseIsStillRead(): void
    {
        $r = $this->analyzer("Here you go:\n```json\n{\"data_sharing\": [\"We share your data with our partners.\"]}\n```")->analyze(self::TEXT);

        self::assertSame(['data_sharing' => ['We share your data with our partners.']], $r['categories']);
    }

    public function testAnEmptyObjectMeansNothingFound(): void
    {
        self::assertSame([], $this->analyzer('{}')->analyze(self::TEXT)['categories']);
    }

    public function testAReplyWithoutJsonIsAnErrorSoNothingIsCharged(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyzer('I could not read that.')->analyze(self::TEXT);
    }

    public function testTheTextIsFencedAsDataAndTheModelIsAskedToThink(): void
    {
        $seen = null;
        $this->analyzer('{}', $seen)->analyze(self::TEXT);

        $json = json_encode($seen['input'], \JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/<<DATA-([0-9a-f]{16})>>.*<<END-\1>>/s', $json);
        self::assertStringContainsString('never instructions', $json);
        self::assertSame('some/model', $seen['model']->getName());
        self::assertSame('none', $seen['options']['reasoning_effort']);
        self::assertSame(0, $seen['options']['temperature']);
    }

    public function testRefusesEmptyAndTooLongText(): void
    {
        $analyzer = $this->analyzer('{}');
        foreach (['', "  \n ", str_repeat('word ', TermsAnalyzer::MAX_WORDS + 1)] as $text) {
            try {
                $analyzer->analyze($text);
                self::fail('Expected a refusal');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('between 1 and 1200 words', $e->getMessage());
            }
        }
    }

    public function testTheToolAlwaysSaysItIsBetaAndRefusesWithAToolError(): void
    {
        $tool = $this->tool($this->analyzer('{"arbitration": ["binding arbitration in Delaware"]}'));

        $out = json_decode($tool(self::TEXT), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('SUPER BETA', $out['notice']);
        self::assertSame(['arbitration' => ['binding arbitration in Delaware']], $out['categories']);

        $this->expectException(ToolCallException::class);
        $tool('');
    }

    public function testTheDayLimitIsSharedByEveryClient(): void
    {
        // The tool no longer knows the client: per client is McpRateLimit's, before the payment is read.
        $tool = $this->tool($this->analyzer('{"arbitration": ["binding arbitration in Delaware"]}'), perDay: 2);

        $tool(self::TEXT);
        $tool(self::TEXT);

        $this->expectException(ToolCallException::class);
        $tool(self::TEXT);
    }

    public function testOverTheDayLimitIsAToolErrorAndTheModelIsNotCalled(): void
    {
        $calls = 0;
        $platform = MockPlatformFactory::createPlatform(function () use (&$calls) {
            ++$calls;

            return '{"arbitration": ["binding arbitration in Delaware"]}';
        });
        $tool = $this->tool(new TermsAnalyzer($platform, 'some/model', new ArrayAdapter()), perDay: 1);

        $tool(self::TEXT);
        try {
            $tool(self::TEXT);
            self::fail('The second call of the day should be refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Rate limit', $e->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testABadTextIsRefusedBeforeItUsesTheDaysOnlyCall(): void
    {
        $tool = $this->tool($this->analyzer('{"arbitration": ["binding arbitration in Delaware"]}'), perDay: 1);

        foreach (['', str_repeat('x', TermsAnalyzer::MAX_CHARS + 1)] as $bad) {
            try {
                $tool($bad);
                self::fail('A bad text should be refused.');
            } catch (ToolCallException) {
            }
        }

        self::assertStringContainsString('binding arbitration', $tool(self::TEXT), 'the refusals did not use the limit');
    }

    public function testAOneWordQuoteIsNotAVerifiedQuote(): void
    {
        $out = $this->analyzer('{"arbitration": ["any", "binding arbitration in Delaware"]}')->analyze(self::TEXT);

        self::assertSame(['arbitration' => ['binding arbitration in Delaware']], $out['categories']);
        self::assertSame(1, $out['quotes_dropped']);
    }

    /**
     * An analyzer whose model replies in turn (the last reply repeats) and counts its calls in $calls->n.
     *
     * @return array{0: TermsAnalyzer, 1: \stdClass}
     */
    private function counting(array $replies, ?CacheInterface $cache = null): array
    {
        $calls = new \stdClass();
        $calls->n = 0;
        $platform = MockPlatformFactory::createPlatform(function () use ($replies, $calls) {
            $reply = $replies[min($calls->n, \count($replies) - 1)];
            ++$calls->n;

            return $reply;
        });

        return [new TermsAnalyzer($platform, 'some/model', $cache ?? new ArrayAdapter()), $calls];
    }

    /** A memo that fails on read ('read': before the model) or on write ('write': after the model answered). */
    private function flakyCache(string $mode): CacheInterface
    {
        return new class($mode) implements CacheInterface {
            public function __construct(private readonly string $mode)
            {
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                if ('read' === $this->mode) {
                    throw new \RuntimeException('Redis went away on read');
                }
                $callback((new ArrayAdapter())->getItem($key));

                throw new \RuntimeException('Redis went away on write');
            }

            public function delete(string $key): bool
            {
                return true;
            }
        };
    }

    public function testAMemoReadErrorIsAMissAndTheModelAnswers(): void
    {
        [$analyzer, $calls] = $this->counting(['{"arbitration": ["binding arbitration in Delaware"]}'], $this->flakyCache('read'));

        self::assertSame(['arbitration' => ['binding arbitration in Delaware']], $analyzer->analyze(self::TEXT)['categories']);
        self::assertSame(1, $calls->n);
    }

    public function testAMemoWriteErrorKeepsTheAnswer(): void
    {
        [$analyzer, $calls] = $this->counting(['{"arbitration": ["binding arbitration in Delaware"]}'], $this->flakyCache('write'));

        self::assertSame(['arbitration' => ['binding arbitration in Delaware']], $analyzer->analyze(self::TEXT)['categories']);
        self::assertSame(1, $calls->n, 'the answer was not asked again');
    }

    public function testAModelFailureIsNotSwallowedByAFailingMemo(): void
    {
        // The model fails inside the memo's callback: that failure must come out, not be read as a memo error.
        [$analyzer, $calls] = $this->counting(['I could not read that.'], $this->flakyCache('write'));

        try {
            $analyzer->analyze(self::TEXT);
            self::fail('A reply without JSON must still be an error.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('JSON', $e->getMessage());
        }
        self::assertSame(1, $calls->n, 'the model was not asked a second time');
    }

    public function testTheSameTextIsAnsweredFromTheMemoAndTheModelIsCalledOnce(): void
    {
        [$analyzer, $calls] = $this->counting(['{"arbitration": ["binding arbitration in Delaware"]}']);

        $first = $analyzer->analyze(self::TEXT);
        $second = $analyzer->analyze(self::TEXT);

        self::assertSame(1, $calls->n);
        self::assertSame($first, $second);
        self::assertSame(['arbitration' => ['binding arbitration in Delaware']], $second['categories']);
    }

    public function testADifferentTextIsAnotherCallToTheModel(): void
    {
        [$analyzer, $calls] = $this->counting(['{"arbitration": ["binding arbitration in Delaware"]}']);

        $analyzer->analyze(self::TEXT);
        $analyzer->analyze(self::TEXT.' '); // trim is part of the check: the same text, a memo hit
        $analyzer->analyze(self::TEXT.'.'); // one character more: another text, another call

        self::assertSame(2, $calls->n);
    }

    public function testTheMemoKeyDependsOnThePromptVersionTheModelAndTheText(): void
    {
        $analyzer = new TermsAnalyzer(MockPlatformFactory::createPlatform(fn () => '{}'), 'some/model', new ArrayAdapter());
        $key = new \ReflectionMethod(TermsAnalyzer::class, 'cacheKey');
        $key->setAccessible(true);

        $expected = hash('sha256', json_encode([TermsAnalyzer::PROMPT_VERSION, 'some/model', self::TEXT], \JSON_THROW_ON_ERROR));
        self::assertSame($expected, $key->invoke($analyzer, self::TEXT));
        // The same key with the next prompt version is another entry: a prompt change makes the old answers miss.
        self::assertNotSame(hash('sha256', json_encode([TermsAnalyzer::PROMPT_VERSION + 1, 'some/model', self::TEXT], \JSON_THROW_ON_ERROR)), $key->invoke($analyzer, self::TEXT));
        self::assertNotSame($key->invoke($analyzer, self::TEXT), $key->invoke($analyzer, self::TEXT.'x'));
    }

    public function testAnInvalidAnswerIsNotMemoised(): void
    {
        [$analyzer, $calls] = $this->counting([
            'I could not read that.',
            '{"arbitration": ["binding arbitration in Delaware"]}',
        ]);

        try {
            $analyzer->analyze(self::TEXT);
            self::fail('The first answer has no JSON.');
        } catch (\RuntimeException) {
        }
        self::assertSame(['arbitration' => ['binding arbitration in Delaware']], $analyzer->analyze(self::TEXT)['categories']);
        self::assertSame(2, $calls->n, 'the second call went to the model: the failure was not kept');
    }

    /** A memo that counts every touch ($touches->n) and otherwise behaves as an ArrayAdapter. */
    private function touchCountingCache(\stdClass $touches): CacheInterface
    {
        return new class($touches) implements CacheInterface {
            private ArrayAdapter $real;

            public function __construct(private readonly \stdClass $touches)
            {
                $this->real = new ArrayAdapter();
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                ++$this->touches->n;

                return $this->real->get($key, $callback, $beta, $metadata);
            }

            public function delete(string $key): bool
            {
                ++$this->touches->n;

                return $this->real->delete($key);
            }
        };
    }

    public function testASensitiveCallNeverReadsNorWritesTheMemoAndAsksTheModel(): void
    {
        $touches = new \stdClass();
        $touches->n = 0;
        [$analyzer, $calls] = $this->counting(['{"arbitration": ["binding arbitration in Delaware"]}'], $this->touchCountingCache($touches));

        $analyzer->analyze(self::TEXT, sensitive: true);
        $r = $analyzer->analyze(self::TEXT, sensitive: true);

        self::assertSame(['arbitration' => ['binding arbitration in Delaware']], $r['categories']);
        self::assertSame(0, $touches->n, 'the memo was not touched');
        self::assertSame(2, $calls->n, 'each sensitive call asks the model');

        // Not written: the next unmarked call is a miss, then a hit as before.
        $analyzer->analyze(self::TEXT);
        $analyzer->analyze(self::TEXT);
        self::assertSame(3, $calls->n);
        self::assertSame(2, $touches->n);
    }

    public function testTheToolPassesSensitiveToTheAnalyzer(): void
    {
        [$analyzer, $calls] = $this->counting(['{}']);
        $tool = $this->tool($analyzer);

        $tool(self::TEXT);
        $tool(self::TEXT);
        self::assertSame(1, $calls->n, 'unmarked: the memo answers the second call');
        $tool(self::TEXT, true);
        self::assertSame(2, $calls->n, 'sensitive: the model is asked although the memo has the text');
    }

    public function testAToolRuleSkipsTheMemoToo(): void
    {
        // X402_SENSITIVE_TOOLS=analyze_terms (a `tool` rule) keeps the ledger's copy out; it must keep the memo out too.
        [$analyzer, $calls] = $this->counting(['{}']);
        $limiter = static fn (string $id, int $limit) => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 day'], new InMemoryStorage());
        $tool = new AnalyzeTermsTool($analyzer, $limiter('day', 500), new SensitiveRules([], ['analyze_terms']));

        $tool(self::TEXT);
        $tool(self::TEXT);
        self::assertSame(2, $calls->n, 'a tool rule: the memo never answers, the model is asked each time');
    }
}
