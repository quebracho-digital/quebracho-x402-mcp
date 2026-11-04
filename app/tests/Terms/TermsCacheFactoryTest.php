<?php

declare(strict_types=1);

namespace App\Tests\Terms;

use App\Terms\TermsCacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class TermsCacheFactoryTest extends TestCase
{
    private function dir(): string
    {
        return sys_get_temp_dir().'/terms-cache-test-'.bin2hex(random_bytes(4));
    }

    public function testNoRedisUrlIsTheFilesOfThisHost(): void
    {
        self::assertInstanceOf(FilesystemAdapter::class, TermsCacheFactory::create('', $this->dir()));
    }

    public function testARedisThatDoesNotAnswerFallsBackToFilesWithAWarning(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = [(string) $level, (string) $message];
            }
        };

        $cache = TermsCacheFactory::create('redis://127.0.0.1:1', $this->dir(), $logger);

        self::assertInstanceOf(FilesystemAdapter::class, $cache);
        self::assertCount(1, $logger->warnings);
        self::assertSame('warning', $logger->warnings[0][0]);
    }

    public function testAnInvalidRedisUrlFallsBackToFiles(): void
    {
        self::assertInstanceOf(FilesystemAdapter::class, TermsCacheFactory::create('not a redis url', $this->dir()));
    }
}
