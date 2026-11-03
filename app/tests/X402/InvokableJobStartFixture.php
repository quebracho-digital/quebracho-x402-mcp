<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\PaidJob;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/**
 * A class-level #[McpTool] and #[PaidJob] on an invokable tool, paired in PricingTest.
 */
#[McpTool(name: 'invokable_start', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => 'invokable_result']])]
#[PaidJob('start', pairedWith: 'invokable_result')]
final class InvokableJobStartFixture
{
    public function __invoke(int $n, RequestContext $context, string $label = ''): string
    {
        return 'job';
    }
}
