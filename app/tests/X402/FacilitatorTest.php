<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\Facilitator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Quebracho\X402\CircleFacilitator;
use Quebracho\X402\HttpFacilitator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FacilitatorTest extends TestCase
{
    private const TX = '0xef2ee93a74e2e62dfbcabff838f123de2993a958f4656ee6fdaed9fc6cd6b20a';
    private const FROM = '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23';
    private const PAYMENT = ['x402Version' => 2, 'payload' => ['authorization' => ['from' => self::FROM, 'nonce' => '0x01']]];
    private const REQ = ['network' => 'eip155:84532', 'amount' => '10000'];

    private function settle(MockResponse $response, ?array &$sent = null): array
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response, &$sent) {
            $sent = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return $response;
        });

        return (new Facilitator('https://f.example/facilitator/', new NullLogger(), $http))(self::PAYMENT, self::REQ);
    }

    public function testPostsTheV2BodyAndKeepsAGoodReceipt(): void
    {
        $ok = ['success' => true, 'transaction' => self::TX, 'network' => 'eip155:84532', 'payer' => strtolower(self::FROM)];
        $this->assertSame($ok, $this->settle(new JsonMockResponse($ok), $sent));
        $this->assertSame('https://f.example/facilitator/settle', $sent['url']);
        $this->assertSame(['x402Version' => 2, 'paymentPayload' => self::PAYMENT, 'paymentRequirements' => self::REQ], $sent['body']);
    }

    public function testOnlyAConsistentSuccessCounts(): void
    {
        $good = ['success' => true, 'transaction' => self::TX, 'network' => 'eip155:84532'];
        $bad = [
            '503 with a success body' => new JsonMockResponse($good, ['http_code' => 503]),
            'no transaction' => new JsonMockResponse(['transaction' => ''] + $good),
            'other network' => new JsonMockResponse(['network' => 'eip155:1'] + $good),
            'other payer' => new JsonMockResponse(['payer' => '0x'.str_repeat('1', 40)] + $good),
            'HTML 502' => new MockResponse('<html>bad gateway</html>', ['http_code' => 502]),
            'network error' => new MockResponse('', ['error' => 'timeout']),
            'redirect' => new MockResponse('', ['http_code' => 307, 'response_headers' => ['Location' => 'https://evil.example/settle']]),
        ];
        foreach ($bad as $case => $response) {
            $this->assertFalse($this->settle($response)['success'], $case);
        }
    }

    public function testRejectsAnythingButMockOrAnHttpsUrl(): void
    {
        foreach (['', 'MOCK', 'http://f.example', 'https://', 'f.example'] as $mode) {
            try {
                new Facilitator($mode, new NullLogger(), new MockHttpClient());
                $this->fail($mode);
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testVerifyGivesTheFacilitatorsReason(): void
    {
        $verify = fn (MockResponse $r) => (new Facilitator('https://f.example', new NullLogger(), new MockHttpClient($r)))->verify(self::PAYMENT, self::REQ);
        $this->assertNull($verify(new JsonMockResponse(['isValid' => true])));
        $this->assertSame('insufficient_funds', $verify(new JsonMockResponse(['isValid' => false, 'invalidReason' => 'insufficient_funds'])));
        $this->assertSame('invalid_payment', $verify(new JsonMockResponse(['isValid' => false])));
        // Unreachable: thrown, so the middleware answers facilitator_unavailable.
        $this->expectException(\Quebracho\X402\FacilitatorException::class);
        $verify(new MockResponse('', ['error' => 'timeout']));
    }

    private function remoteOf(string $mode): ?object
    {
        return (new \ReflectionProperty(Facilitator::class, 'remote'))->getValue(new Facilitator($mode, new NullLogger(), new MockHttpClient()));
    }

    public function testCirclesUrlIsChosenWhateverTheCaseOrQuery(): void
    {
        foreach (['https://API.circle.com/v1/facilitator/x402', 'https://api.circle.com/v1/facilitator/x402?x=1', 'HTTPS://api.circle.com/v1/facilitator/x402/', 'https://api.circle.com/v1/facilitator/x402#f'] as $mode) {
            self::assertInstanceOf(CircleFacilitator::class, $this->remoteOf($mode), $mode);
        }
        self::assertInstanceOf(HttpFacilitator::class, $this->remoteOf('https://api.circle.com/v1/other'));
    }

    public function testTheLogicExceptionNeverEchoesTheUrlsCredentialsOrQuery(): void
    {
        try {
            new Facilitator('http://user:secret@f.example/x?token=abc', new NullLogger(), new MockHttpClient());
            self::fail('an http URL must be refused');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('user', $e->getMessage());
            self::assertStringNotContainsString('token', $e->getMessage());
            self::assertStringContainsString('f.example', $e->getMessage());
        }
    }

    public function testMockVerifiesOfflineOnly(): void
    {
        $this->assertNull((new Facilitator('mock', new NullLogger(), new MockHttpClient()))->verify(self::PAYMENT, self::REQ));
    }
}
