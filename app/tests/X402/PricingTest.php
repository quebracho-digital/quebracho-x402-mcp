<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\Pricing;
use Mcp\Capability\Attribute\McpTool;
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

    public function testADurationNoSignatureCanCoverIsAConfigError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pricing(['analyze_terms'], 'eip155:84532', '0.01', self::PAY_TO, [], [], ['analyze_terms' => 60]);
    }
}
