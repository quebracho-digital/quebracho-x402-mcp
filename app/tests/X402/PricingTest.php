<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\PaidJob;
use App\X402\Pricing;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    private const PAY_TO = '0x7e510E6B4c3E2121CB64CD0686a4f241Df326510';

    /** @return list<array{0: class-string, 1: string}> every #[McpTool] handler in App\Mcp, plus the fixture's */
    private static function handlers(): array
    {
        $classes = array_map(static fn (string $f) => 'App\\Mcp\\'.basename($f, '.php'), glob(\dirname(__DIR__, 2).'/src/Mcp/*.php') ?: []);
        $handlers = [];
        foreach ([...$classes, JobToolsFixture::class] as $class) {
            $r = new \ReflectionClass($class);
            if ([] !== $r->getAttributes(McpTool::class)) {
                $handlers[] = [$class, '__invoke'];
            }
            foreach ($r->getMethods() as $m) {
                if ([] !== $m->getAttributes(McpTool::class)) {
                    $handlers[] = [$class, $m->getName()];
                }
            }
        }

        return $handlers;
    }

    public function testTheFlagNotTheNameMakesAJobTool(): void
    {
        $tools = PaidJob::catalog(self::handlers());
        // X402_PAID_TOOLS left empty: NULL would mean free, i.e. a job run or delivered unpaid.
        $pricing = new Pricing([], 'eip155:84532', '0.01', self::PAY_TO, tools: $tools);
        self::assertContains('demo_start', array_keys($tools), 'the scan found the fixture');

        $start = $pricing(['name' => 'demo_start'])[0];
        self::assertSame(['upfront', '5000'], [$start['extra']['paymentFlow'], $start['amount']], 'its own deposit price, settled first');
        $result = $pricing(['name' => 'demo_result'])[0];
        self::assertSame(['10000', null], [$result['amount'], $result['extra']['paymentFlow'] ?? null]);
        self::assertNull($pricing(['name' => 'demo_status']), 'a status read is free');
        // Reviewer paid 0.01 USDC upfront for this one when the name decided.
        self::assertNull($pricing(['name' => 'nosuchtool_start']));
        self::assertNull($pricing(['name' => 'nosuchtool_result']));
    }

    public function testEveryFlaggedToolHasAPrice(): void
    {
        $tools = PaidJob::catalog(self::handlers());
        $pricing = new Pricing([], 'eip155:84532', '0.01', self::PAY_TO, tools: $tools);
        foreach (array_keys(array_filter($tools)) as $name) {
            self::assertNotNull($pricing(['name' => $name]), "$name must be paid");
        }
    }

    public function testAPaidNameThatIsNoToolIsRefusedBeforeAnyPayment(): void
    {
        $pricing = new Pricing(['wisdom', 'wisdon'], 'eip155:84532', '0.01', self::PAY_TO, tools: ['wisdom' => null]);
        self::assertNull($pricing->validate(['name' => 'wisdom']));
        self::assertSame('Unknown tool: wisdon', $pricing->validate(['name' => 'wisdon']));
    }

    /** @return iterable<string, array{object}> */
    public static function badJobTools(): iterable
    {
        yield 'named like a job, no flag' => [new class {
            #[McpTool(name: 'x_start')]
            public function run(): void
            {
            }
        }];
        yield 'flag not published in _meta' => [new class {
            #[McpTool(name: 'x_start')]
            #[PaidJob('start', 'x_result')]
            public function run(): void
            {
            }

            #[McpTool(name: 'x_result', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'x_start']])]
            #[PaidJob('result', 'x_start')]
            public function result(): void
            {
            }
        }];
        yield 'pair missing' => [new class {
            #[McpTool(name: 'x_start', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'x_result']])]
            #[PaidJob('start', 'x_result')]
            public function run(): void
            {
            }
        }];
        yield 'pair with the same role' => [new class {
            #[McpTool(name: 'a', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'b']])]
            #[PaidJob('start', 'b')]
            public function a(): void
            {
            }

            #[McpTool(name: 'b', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'a']])]
            #[PaidJob('start', 'a')]
            public function b(): void
            {
            }
        }];
        yield 'pair pointing elsewhere' => [new class {
            // b and c pair with each other; a names b, but b does not name a back.
            #[McpTool(name: 'a', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'b']])]
            #[PaidJob('start', 'b')]
            public function a(): void
            {
            }

            #[McpTool(name: 'b', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'c']])]
            #[PaidJob('result', 'c')]
            public function b(): void
            {
            }

            #[McpTool(name: 'c', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'b']])]
            #[PaidJob('start', 'b')]
            public function c(): void
            {
            }
        }];
        yield 'unknown role' => [new class {
            // Paired correctly: only the role is wrong.
            #[McpTool(name: 'a', meta: [PaidJob::META => ['role' => 'deposit', 'pairedWith' => 'b']])]
            #[PaidJob('deposit', 'b')]
            public function a(): void
            {
            }

            #[McpTool(name: 'b', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'a']])]
            #[PaidJob('result', 'a')]
            public function b(): void
            {
            }
        }];
    }

    #[DataProvider('badJobTools')]
    public function testAJobToolMistakeStopsTheBuild(object $tools): void
    {
        $handlers = array_map(static fn (\ReflectionMethod $m) => [$tools::class, $m->getName()], (new \ReflectionClass($tools))->getMethods());
        $this->expectException(\LogicException::class);
        PaidJob::catalog($handlers);
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
