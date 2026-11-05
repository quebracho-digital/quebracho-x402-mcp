<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\PaidJob;
use App\X402\Pricing;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    private const PAY_TO = '0x7e510E6B4c3E2121CB64CD0686a4f241Df326510';
    private const TOOLS = [
        'wisdom' => ['job' => null, 'schema' => '{"type":"object","properties":{"topic":{"type":["null","string"],"default":null}}}'],
        'analyze_terms' => ['job' => null, 'schema' => '{"type":"object","properties":{"text":{"type":"string"}},"required":["text"]}'],
        'echo' => ['job' => null, 'schema' => '{"type":"object","properties":{}}'],
        'free' => ['job' => null, 'schema' => '{"type":"object","properties":{}}'],
    ];

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
        foreach (array_keys(array_filter($tools, static fn (array $t) => null !== $t['job'])) as $name) {
            self::assertNotNull($pricing(['name' => $name]), "$name must be paid");
        }
    }

    public function testEveryToolNamedLikeAJobIsPricedByItsRole(): void
    {
        // By name this time, over every #[McpTool] scanned: an unflagged _start or _result is refused by the catalog
        // (testAJobToolMistakeStopsTheBuild); the rest must be priced, the deposit upfront, and _status free (decision 2).
        $pricing = new Pricing([], 'eip155:84532', '0.01', self::PAY_TO, tools: PaidJob::catalog(self::handlers()));
        $seen = [];
        foreach (array_keys(PaidJob::catalog(self::handlers())) as $name) {
            if (!preg_match('/_(start|result|status)$/', $name, $m)) {
                continue;
            }
            $seen[$m[1]] = true;
            $offer = $pricing(['name' => $name]);
            if ('status' === $m[1]) {
                self::assertNull($offer, "$name is a free read");
                continue;
            }
            self::assertNotNull($offer, "$name must be paid");
            self::assertSame('start' === $m[1] ? 'upfront' : null, $offer[0]['extra']['paymentFlow'] ?? null, $name);
        }
        self::assertSame(['start', 'result', 'status'], array_keys($seen), 'the scan reached one tool of each kind');
    }

    /** @return iterable<string, array{list<string>, array<string, string>, array<string, int>, array<string, int>, string}> */
    public static function typos(): iterable
    {
        yield 'X402_PAID_TOOLS' => [['wisdon'], [], [], [], 'X402_PAID_TOOLS names "wisdon"'];
        yield 'X402_PRICES' => [[], ['wisdon' => '0.02'], [], [], 'X402_PRICES names "wisdon"'];
        yield 'X402_TIMEOUTS' => [[], [], ['wisdon' => 120], [], 'X402_TIMEOUTS names "wisdon"'];
        yield 'X402_DURATIONS' => [[], [], [], ['wisdon' => 10], 'X402_DURATIONS names "wisdon"'];
    }

    /**
     * @param list<string>          $paid
     * @param array<string, string> $prices
     * @param array<string, int>    $timeouts
     * @param array<string, int>    $durations
     */
    #[DataProvider('typos')]
    public function testANameThatIsNoToolIsAConfigError(array $paid, array $prices, array $timeouts, array $durations, string $message): void
    {
        // Fails open otherwise: the tool meant to be charged is free.
        $this->expectExceptionMessage($message);
        new Pricing($paid, 'eip155:84532', '0.01', self::PAY_TO, $prices, $timeouts, $durations, self::TOOLS);
    }

    /** @return iterable<string, array{string, array<string, string>, array<string, mixed>, string}> */
    public static function badPrices(): iterable
    {
        yield 'X402_PRICE not a number' => ['abc', [], self::TOOLS, 'X402_PRICE: '];
        yield 'X402_PRICES not a number' => ['0.01', ['wisdom' => 'abc'], self::TOOLS, 'X402_PRICES[wisdom]: '];
        yield 'X402_PRICES zero' => ['0.01', ['wisdom' => '0'], self::TOOLS, 'X402_PRICES[wisdom]: '];
        // Past PaidJob::catalog's check (a price the token cannot represent): Pricing names the flag.
        yield 'PaidJob price too precise' => ['0.01', [], ['j_start' => ['job' => ['role' => 'start', 'pairedWith' => 'j_result', 'price' => '0.0000001'], 'schema' => '{}']] + self::TOOLS, '#[PaidJob] price of j_start: '];
    }

    /**
     * @param array<string, string> $prices
     * @param array<string, mixed>  $tools
     */
    #[DataProvider('badPrices')]
    public function testEveryPaidToolIsPricedUpFront(string $price, array $prices, array $tools, string $message): void
    {
        $this->expectExceptionMessage($message);
        new Pricing(['wisdom'], 'eip155:84532', $price, self::PAY_TO, $prices, tools: $tools);
    }

    public function testAPriceForAFreeToolIsAConfigError(): void
    {
        $this->expectExceptionMessage('X402_PRICES prices "echo", which is free');
        new Pricing(['wisdom'], 'eip155:84532', '0.01', self::PAY_TO, ['echo' => '0.01'], tools: self::TOOLS);
    }

    public function testATimeoutTypoIsReportedBeforeTheDurationItBreaks(): void
    {
        // analyze_terms keeps the 60 s default, so its 60 s duration no longer fits: the typo is the cause.
        $this->expectExceptionMessage('X402_TIMEOUTS names "analyze_term"');
        new Pricing(['analyze_terms'], 'eip155:84532', '0.01', self::PAY_TO, [], ['analyze_term' => 120], ['analyze_terms' => 60], self::TOOLS);
    }

    public function testAnUnusedDefaultPriceIsNotChecked(): void
    {
        self::assertNull((new Pricing([], 'eip155:84532', 'abc', self::PAY_TO, tools: self::TOOLS))(['name' => 'wisdom']));
    }

    private static function edgeTools(): array
    {
        $edge = new class {
            #[McpTool(name: 'edge_start', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'edge_result']])]
            #[PaidJob('start', 'edge_result')]
            public function start(Color $color, ?string $nullable, string|int $union, RequestContext $ctx, string $n, int $withDefault = 1, ?\DateTimeImmutable $when = null): string
            {
                return 'job';
            }

            #[McpTool(name: 'edge_result', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'edge_start']])]
            #[PaidJob('result', 'edge_start')]
            public function result(string $jobId): string
            {
                return 'result';
            }

            #[McpTool(name: 'edge_none')]
            public function none(): string
            {
                return 'none';
            }
        };

        return PaidJob::catalog([[$edge::class, 'start'], [$edge::class, 'result'], [$edge::class, 'none']]);
    }

    public function testTheCatalogKeepsTheSchemaTheSdkGenerates(): void
    {
        $schema = json_decode(self::edgeTools()['edge_start']['schema'], true);
        // Enums and nullables are required too; the injected context and defaults are not.
        self::assertSame(['color', 'nullable', 'union', 'n'], $schema['required']);
        self::assertSame(['red', 'blue'], $schema['properties']['color']['enum']);
        self::assertArrayNotHasKey('ctx', $schema['properties']);
    }

    /** @return iterable<string, array{array<string, mixed>|string|null, ?string}> */
    public static function startCalls(): iterable
    {
        $ok = ['color' => 'red', 'nullable' => null, 'union' => 5, 'n' => 'x'];
        yield 'all fine' => [$ok, null];
        yield 'optional ones given' => [$ok + ['withDefault' => 3, 'when' => null], null];
        yield 'a union as string' => [['union' => 'five'] + $ok, null];
        yield 'an extra key, as the SDK allows' => [$ok + ['extra' => 1], null];
        yield 'n missing' => [array_diff_key($ok, ['n' => 1]), 'n'];
        yield 'n null' => [['n' => null] + $ok, '/n'];
        yield 'n a number' => [['n' => 123] + $ok, '/n'];
        yield 'not an enum value' => [['color' => 'green'] + $ok, '/color'];
        yield 'a union of neither' => [['union' => true] + $ok, '/union'];
        yield 'a default of the wrong type' => [$ok + ['withDefault' => 'x'], '/withDefault'];
        yield 'no arguments at all' => [null, 'color'];
        yield 'arguments not an object' => ['x', 'Invalid parameters'];
    }

    /** @param array<string, mixed>|string|null $args */
    #[DataProvider('startCalls')]
    public function testValidateChecksTheArgumentsAgainstTheToolsSchema(array|string|null $args, ?string $error): void
    {
        $pricing = new Pricing([], 'eip155:84532', '0.01', self::PAY_TO, tools: self::edgeTools());
        $reason = $pricing->validate(['name' => 'edge_start'] + (null === $args ? [] : ['arguments' => $args]));
        null === $error ? self::assertNull($reason) : self::assertStringContainsString($error, (string) $reason);
        if (null !== $error) {
            self::assertStringStartsWith("Invalid parameters for tool 'edge_start'", (string) $reason);
        }
    }

    public function testAToolWithoutArgumentsTakesAnEmptyCall(): void
    {
        $pricing = new Pricing(['edge_none'], 'eip155:84532', '0.01', self::PAY_TO, tools: self::edgeTools());
        self::assertNull($pricing->validate(['name' => 'edge_none']));
        self::assertNull($pricing->validate(['name' => 'edge_none', 'arguments' => []]));
    }

    public function testTheServedCatalogFailsClosedAndCountsOneHandlerOnce(): void
    {
        $plain = new class {
            public function run(): void
            {
            }
        };
        // A served tool the catalog cannot read (here: no #[McpTool] to read) would be free.
        try {
            PaidJob::served([[[JobToolsFixture::class, 'status'], 'demo_status'], [[$plain::class, 'run'], 'plain']]);
            self::fail('a served tool missing from the catalog must stop the build');
        } catch (\LogicException $e) {
            self::assertStringContainsString('MCP tool plain is served but not in app.mcp_tools', $e->getMessage());
        }
        // The same handler on two servers is one tool, not a duplicate name.
        $twice = [[[JobToolsFixture::class, 'status'], 'demo_status'], [[JobToolsFixture::class, 'status'], 'demo_status']];
        self::assertSame(['demo_status'], array_keys(PaidJob::served($twice)));
    }

    public function testAClassLevelFlagOnAnInvokableToolCounts(): void
    {
        $result = new class {
            #[McpTool(name: 'invokable_result', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'invokable_start']])]
            #[PaidJob('result', 'invokable_start')]
            public function result(): void
            {
            }
        };
        $tools = PaidJob::catalog([[InvokableJobStartFixture::class, '__invoke'], [$result::class, 'result']]);
        self::assertSame('start', $tools['invokable_start']['job']['role'] ?? null);
        self::assertSame(['n'], json_decode($tools['invokable_start']['schema'], true)['required'], 'the request context is injected, not required');
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
        yield 'two tools with one name' => [new class {
            #[McpTool(name: 'a')]
            public function a(): void
            {
            }

            #[McpTool(name: 'a')]
            public function b(): void
            {
            }
        }];
        yield 'a deposit price that is no price' => [new class {
            #[McpTool(name: 'a', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'b']])]
            #[PaidJob('start', 'b', price: 'abc')]
            public function a(): void
            {
            }

            #[McpTool(name: 'b', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'a']])]
            #[PaidJob('result', 'a')]
            public function b(): void
            {
            }
        }];
        yield 'a zero deposit' => [new class {
            #[McpTool(name: 'a', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'b']])]
            #[PaidJob('start', 'b', price: '0.00')]
            public function a(): void
            {
            }

            #[McpTool(name: 'b', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'a']])]
            #[PaidJob('result', 'a')]
            public function b(): void
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
        $pricing = new Pricing(['wisdom', 'analyze_terms'], 'eip155:84532', '0.01', self::PAY_TO, ['analyze_terms' => '0.02'], ['analyze_terms' => 120], ['analyze_terms' => 60], self::TOOLS);

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
        new Pricing(['analyze_terms'], 'eip155:84532', '0.01', self::PAY_TO, [], $timeouts, $durations, self::TOOLS);
    }

    public function testTheLargestDurationsThatFit(): void
    {
        $edge = new Pricing(['analyze_terms', 'wisdom'], 'eip155:84532', '0.01', self::PAY_TO, [], ['analyze_terms' => 600, 'wisdom' => 1], ['analyze_terms' => 593, 'echo' => 53, 'free' => 0], self::TOOLS);
        self::assertSame(593, $edge->duration(['name' => 'analyze_terms']));
        self::assertSame(53, $edge->duration(['name' => 'echo']));
        self::assertSame(1, $edge(['name' => 'wisdom'])[0]['maxTimeoutSeconds']);
    }
}
