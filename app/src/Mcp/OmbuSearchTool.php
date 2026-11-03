<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Free tool: lets an agent discover what it can buy. Ombú is a live market
 * of x402 services (https://ombu.quebracho.digital, open source); its
 * /market.md is the same search as markdown, compact enough for a prompt.
 */
final class OmbuSearchTool
{
    private const LIMIT = 8;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $market = 'https://ombu.quebracho.digital',
    ) {
    }

    #[McpTool(name: 'ombu_search', description: 'Searches Ombu, a market of paid x402 services for agents. Returns title, provider, category and price per call, with a link to each service.')]
    public function __invoke(string $query): string
    {
        try {
            $markdown = $this->http->request('GET', $this->market.'/market.md', ['query' => ['search' => $query], 'timeout' => 10])->getContent();
        } catch (ExceptionInterface $e) {
            return 'Ombu is unreachable right now: '.$e->getMessage();
        }

        $lines = array_values(array_filter(explode("\n", $markdown), static fn (string $l) => str_starts_with($l, '- [') && str_contains($l, '/service/')));
        if ([] === $lines) {
            return \sprintf('No service on Ombu matches "%s".', $query);
        }

        return implode("\n", \array_slice($lines, 0, self::LIMIT));
    }
}
