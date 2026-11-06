<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

/**
 * A fresh FileClaims directory per call, removed after the test.
 */
trait TempClaims
{
    /** @var list<string> */
    private array $claimDirs = [];

    private function claimsDir(): string
    {
        return $this->claimDirs[] = sys_get_temp_dir().'/x402-claims-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ($this->claimDirs as $dir) {
            foreach (array_diff(@scandir($dir) ?: [], ['.', '..']) as $file) {
                unlink("$dir/$file");
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }
}
