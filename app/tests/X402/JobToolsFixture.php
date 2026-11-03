<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\PaidJob;
use Mcp\Capability\Attribute\McpTool;

/**
 * Stand-in for job-mode tools until App\Mcp has real ones; PricingTest scans it with App\Mcp.
 */
final class JobToolsFixture
{
    #[McpTool(name: 'demo_start', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'demo_result']])]
    #[PaidJob('start', pairedWith: 'demo_result', price: '0.005')]
    public function start(string $text): string
    {
        return 'job';
    }

    #[McpTool(name: 'demo_result', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => 'demo_start']])]
    #[PaidJob('result', pairedWith: 'demo_start')]
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
