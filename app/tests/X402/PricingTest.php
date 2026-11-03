<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\Pricing;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    private const PAY_TO = '0x7e510E6B4c3E2121CB64CD0686a4f241Df326510';

    /** @return list<string> every #[McpTool] name in App\Mcp, plus the fixture's */
    private static function toolNames(): array
    {
        $classes = array_map(static fn (string $f) => 'App\\Mcp\\'.basename($f, '.php'), glob(\dirname(__DIR__, 2).'/src/Mcp/*.php') ?: []);
        $names = [];
        foreach ([...$classes, JobToolsFixture::class] as $class) {
            $r = new \ReflectionClass($class);
            foreach ([$r, ...$r->getMethods()] as $at) {
                foreach ($at->getAttributes(McpTool::class) as $a) {
                    $names[] = $a->newInstance()->name ?? $at->getName();
                }
            }
        }

        return $names;
    }

    public function testJobToolsAreNeverFreeEvenWhenNobodyListedThem(): void
    {
        // X402_PAID_TOOLS left empty: NULL would mean free, i.e. a job run or delivered unpaid.
        $pricing = new Pricing([], 'eip155:84532', '0.01', self::PAY_TO);
        $job = array_filter(self::toolNames(), static fn (string $n) => preg_match('/_(start|result|status)$/', $n));
        self::assertContains('demo_start', $job, 'the scan found the fixture');

        foreach ($job as $name) {
            $accepts = $pricing(['name' => $name]);
            if (str_ends_with($name, '_status')) {
                // The plan (debate r2, decision 2): status is a free, payer-checked read.
                self::assertNull($accepts, "$name is free");
                continue;
            }
            self::assertNotNull($accepts, "$name must be paid");
            self::assertSame(str_ends_with($name, '_start') ? 'upfront' : null, $accepts[0]['extra']['paymentFlow'] ?? null, $name);
        }
    }

    public function testAFastToolKeepsTheDefaultOfferAndNoDuration(): void
    {
        $pricing = new Pricing(['wisdom', 'analyze_terms'], 'eip155:84532', '0.01', self::PAY_TO, ['analyze_terms' => '0.02'], ['analyze_terms' => 120], ['analyze_terms' => 60]);

        self::assertSame(60, $pricing(['name' => 'wisdom'])[0]['maxTimeoutSeconds']);
        self::assertSame(0, $pricing->duration(['name' => 'wisdom']));
        self::assertArrayNotHasKey('paymentFlow', $pricing(['name' => 'wisdom'])[0]['extra']);
        self::assertSame(120, $pricing(['name' => 'analyze_terms'])[0]['maxTimeoutSeconds']);
        self::assertSame(60, $pricing->duration(['name' => 'analyze_terms']));
        self::assertNull($pricing(['name' => 'echo']));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function badConfig(): iterable
    {
        yield 'timeout not a number' => [['analyze_terms' => 'abc'], []];
        yield 'timeout as a string' => [['analyze_terms' => '120'], []];
        yield 'negative timeout' => [['analyze_terms' => -5], []];
        yield 'zero timeout' => [['analyze_terms' => 0], []];
        yield 'timeout over Budget::MAX_TIMEOUT' => [['analyze_terms' => 601], []];
        yield 'a day' => [['analyze_terms' => 86400], []];
        yield 'duration as a string' => [[], ['analyze_terms' => '30']];
        yield 'negative duration' => [[], ['analyze_terms' => -1]];
        yield 'duration plus slack reaches the 60 s default' => [[], ['analyze_terms' => 54]];
        yield 'duration over its own timeout' => [['analyze_terms' => 120], ['analyze_terms' => 114]];
    }

    /**
     * @param array<string, mixed> $timeouts
     * @param array<string, mixed> $durations
     */
    #[DataProvider('badConfig')]
    public function testABadTimeoutOrDurationIsAConfigError(array $timeouts, array $durations): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pricing(['analyze_terms'], 'eip155:84532', '0.01', self::PAY_TO, [], $timeouts, $durations);
    }

    public function testTheLargestDurationsThatFit(): void
    {
        $edge = new Pricing(['analyze_terms', 'wisdom'], 'eip155:84532', '0.01', self::PAY_TO, [], ['analyze_terms' => 600, 'wisdom' => 1], ['analyze_terms' => 593, 'echo' => 53, 'free' => 0]);
        self::assertSame(593, $edge->duration(['name' => 'analyze_terms']));
        self::assertSame(53, $edge->duration(['name' => 'echo']));
        self::assertSame(1, $edge(['name' => 'wisdom'])[0]['maxTimeoutSeconds']);
    }
}
