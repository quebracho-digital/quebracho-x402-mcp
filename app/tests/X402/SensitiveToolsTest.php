<?php

declare(strict_types=1);

namespace App\Tests\X402;

use Quebracho\X402\SensitiveRules;
use Quebracho\X402\X402McpMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SensitiveToolsTest extends KernelTestCase
{
    private ?string $savedEnv = null;
    private ?string $savedServer = null;

    protected function tearDown(): void
    {
        $_ENV['X402_SENSITIVE_TOOLS'] = $this->savedEnv;
        $_SERVER['X402_SENSITIVE_TOOLS'] = $this->savedServer;
        parent::tearDown();
    }

    public function testOnlyTheListedToolOrACallerFlagIsSensitive(): void
    {
        $sensitive = new SensitiveRules([['kind' => 'argument', 'name' => 'sensitive', 'value' => true]], ['analyze_terms']);

        self::assertTrue($sensitive(['name' => 'analyze_terms', 'arguments' => ['text' => 'x']]));
        self::assertFalse($sensitive(['name' => 'wisdom', 'arguments' => []]));
        self::assertTrue($sensitive(['name' => 'wisdom', 'arguments' => ['sensitive' => true]]));
        // Only a real true marks it: "false" from a sloppy client does not.
        self::assertFalse($sensitive(['name' => 'wisdom', 'arguments' => ['sensitive' => 'false']]));
        self::assertFalse((new SensitiveRules([]))(['name' => 'analyze_terms']));
    }

    public function testTheMiddlewareGetsX402SensitiveTools(): void
    {
        $this->savedEnv = $_ENV['X402_SENSITIVE_TOOLS'] ?? null;
        $this->savedServer = $_SERVER['X402_SENSITIVE_TOOLS'] ?? null;
        $_ENV['X402_SENSITIVE_TOOLS'] = $_SERVER['X402_SENSITIVE_TOOLS'] = 'analyze_terms';

        $middleware = self::getContainer()->get(X402McpMiddleware::class);
        $sensitive = (new \ReflectionProperty($middleware, 'sensitive'))->getValue($middleware);

        self::assertInstanceOf(\Closure::class, $sensitive);
        self::assertTrue($sensitive(['name' => 'analyze_terms']));
        self::assertFalse($sensitive(['name' => 'wisdom']));
    }
}
