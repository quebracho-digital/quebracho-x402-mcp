<?php

declare(strict_types=1);

namespace App\Tests\Terms;

use App\Mcp\AnalyzeTermsTool;
use App\Terms\TermsAnalyzer;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class TermsAnalyzerTest extends TestCase
{
    private const TEXT = "We may suspend your account at any time, without notice.\nAny dispute goes to binding arbitration in Delaware.\nWe share your data with our partners.";

    private function analyzer(string $reply, ?array &$seen = null): TermsAnalyzer
    {
        $platform = MockPlatformFactory::createPlatform(function ($model, $input, array $options = []) use ($reply, &$seen) {
            $seen = ['model' => $model, 'options' => $options, 'input' => $input];

            return $reply;
        });

        return new TermsAnalyzer($platform, 'some/model');
    }

    private function tool(TermsAnalyzer $analyzer, int $perIp = 10, int $perDay = 500, ?RequestStack $requests = null): AnalyzeTermsTool
    {
        $limiter = static fn (string $id, int $limit) => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 day'], new InMemoryStorage());

        return new AnalyzeTermsTool($analyzer, $limiter('ip', $perIp), $limiter('day', $perDay), $requests ?? new RequestStack());
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

    public function testAClientOverItsLimitGetsAToolErrorAndTheModelIsNotCalled(): void
    {
        $calls = 0;
        $platform = MockPlatformFactory::createPlatform(function () use (&$calls) {
            ++$calls;

            return '{"arbitration": ["binding arbitration in Delaware"]}';
        });
        $tool = $this->tool(new TermsAnalyzer($platform, 'some/model'), perIp: 1);

        $tool(self::TEXT);
        try {
            $tool(self::TEXT);
            self::fail('The second call should be refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Rate limit', $e->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testEachClientIpHasItsOwnBucketAndTheDayLimitIsShared(): void
    {
        $ok = $this->analyzer('{"arbitration": ["binding arbitration in Delaware"]}');
        $requests = new RequestStack();
        $from = static function (string $ip) use ($requests): void {
            while (null !== $requests->pop()) {
            }
            $requests->push(Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $ip]));
        };
        $tool = $this->tool($ok, perIp: 1, perDay: 2, requests: $requests);

        $from('203.0.113.1');
        $tool(self::TEXT);
        $from('203.0.113.2');
        $tool(self::TEXT);

        $this->expectException(ToolCallException::class);
        $from('203.0.113.3');
        $tool(self::TEXT);
    }
}
