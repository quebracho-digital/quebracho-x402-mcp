<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mcp\OmbuSearchTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OmbuSearchToolTest extends TestCase
{
    private const MARKET = "> Every page also reads as markdown.\n\n# Ombú market\n\n117 services.\n\n"
        ."- [Check domain](https://ombu.example/service/a.md): `Orthogonal · Data enrichment · \$0.01`\n"
        ."- [Create domain](https://ombu.example/service/b.md): `AgentMail · Infrastructure · \$10`\n"
        ."\n## Categories\n- [Infrastructure](https://ombu.example/market.md?search=domain&category%5B0%5D=Infrastructure) 1\n";

    public function testReturnsOnlyServicesNotFacetsAndSendsTheQuery(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$sent) {
            $sent = $url;

            return new MockResponse(self::MARKET);
        });

        $out = (new OmbuSearchTool($http, 'https://ombu.example'))('domain');

        self::assertSame('https://ombu.example/market.md?search=domain', $sent);
        self::assertSame(
            "- [Check domain](https://ombu.example/service/a.md): `Orthogonal · Data enrichment · \$0.01`\n"
            .'- [Create domain](https://ombu.example/service/b.md): `AgentMail · Infrastructure · $10`',
            $out,
        );
    }

    public function testCapsTheListSoItFitsInAPrompt(): void
    {
        $many = str_repeat("- [Service](https://ombu.example/service/s.md): `P · C · \$1`\n", 30);
        $http = new MockHttpClient(new MockResponse($many));

        self::assertCount(8, explode("\n", (new OmbuSearchTool($http))('x')));
    }

    public function testSaysSoWhenNothingMatches(): void
    {
        $http = new MockHttpClient(new MockResponse("# Ombú market\n\n0 services.\n"));

        self::assertSame('No service on Ombu matches "zzz".', (new OmbuSearchTool($http))('zzz'));
    }

    public function testAnUnreachableMarketIsAnAnswerNotACrash(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));

        self::assertStringStartsWith('Ombu is unreachable', (new OmbuSearchTool($http))('x'));
    }

    public function testTheFacetIsServerSideAndLastSoTheQueryCannotLiftIt(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$sent) {
            $sent = $url;

            return new MockResponse(self::MARKET);
        });

        (new OmbuSearchTool($http, 'https://ombu.example', ['seller' => ['ours' => 'ours']]))('x&seller[ours]=all');

        self::assertStringEndsWith('&seller[ours]=ours', (string) $sent);
        self::assertStringContainsString('search=x%26seller', (string) $sent);
    }
}
