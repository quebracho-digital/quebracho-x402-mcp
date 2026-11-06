<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\SensitiveToolsWarmer;
use PHPUnit\Framework\TestCase;

final class SensitiveToolsWarmerTest extends TestCase
{
    /** app.mcp_tools (Kernel): served tools by name. */
    private const SERVED = ['wisdom' => ['job' => null, 'schema' => '{}'], 'analyze_terms' => ['job' => null, 'schema' => '{}']];

    public function testAServedNameWarmsUp(): void
    {
        $warmer = new SensitiveToolsWarmer(['analyze_terms'], self::SERVED);
        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
    }

    public function testATypoFailsTheWarmupAndNamesIt(): void
    {
        $warmer = new SensitiveToolsWarmer(['analyze_terms', 'anaylze_terms'], self::SERVED);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('anaylze_terms');
        $warmer->warmUp(sys_get_temp_dir());
    }

    public function testAnEmptyListPassesWithoutLookingAtTheTools(): void
    {
        // No served tools at all: an empty list still passes, nothing is compared.
        $warmer = new SensitiveToolsWarmer([], []);
        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
    }

    public function testOnlyTheInvalidNameIsReported(): void
    {
        $warmer = new SensitiveToolsWarmer(['wisdom', 'nope'], self::SERVED);
        try {
            $warmer->warmUp(sys_get_temp_dir());
            self::fail('an unserved name must fail the warmup');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nope', $e->getMessage());
            self::assertStringNotContainsString('wisdom', $e->getMessage());
        }
    }

    public function testItIsNotOptional(): void
    {
        self::assertFalse((new SensitiveToolsWarmer([], []))->isOptional());
    }
}
