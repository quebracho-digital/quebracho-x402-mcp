<?php

declare(strict_types=1);

namespace App\Tests\X402;

use Mcp\Capability\Attribute\McpTool;

/**
 * Stand-in for job-mode tools until App\Mcp has real ones; PricingTest scans it with App\Mcp.
 */
final class JobToolsFixture
{
    #[McpTool(name: 'demo_start')]
    public function start(string $text): string
    {
        return 'job';
    }

    #[McpTool(name: 'demo_result')]
    public function result(string $jobId): string
    {
        return 'result';
    }

    #[McpTool(name: 'demo_status')]
    public function status(string $jobId): string
    {
        return 'queued';
    }
}
