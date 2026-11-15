<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\FacilitatorFactory;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\CircleFacilitator;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\MockFacilitator;
use Quebracho\X402\PaymentStatus;
use Quebracho\X402\Settlement;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * X402_FACILITATOR through App\X402\FacilitatorFactory: which library facilitator it builds, and that the
 * app's HTTP client (no redirects, the key as a Bearer) is the one it talks through.
 */
final class FacilitatorTest extends TestCase
{
    private const TX = '0xef2ee93a74e2e62dfbcabff838f123de2993a958f4656ee6fdaed9fc6cd6b20a';
    private const FROM = '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23';
    private const PAYMENT = ['x402Version' => 2, 'payload' => ['authorization' => ['from' => self::FROM, 'nonce' => '0x01']]];
    private const REQ = ['network' => 'eip155:84532', 'amount' => '10000'];

    /** The receipt as the middleware gets it: the library runs every settle through Settlement::run. */
    private function settle(MockResponse $response, ?array &$sent = null): array
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response, &$sent) {
            $sent = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return $response;
        });

        return Settlement::run(FacilitatorFactory::create('https://f.example/facilitator/', $http)->settle(...), self::PAYMENT, self::REQ);
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

    public function testTheKeyTravelsAsABearerToTheFacilitatorOnly(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen[] = [$url, $options['normalized_headers']['authorization'][0] ?? null];

            return new JsonMockResponse(['success' => true, 'transaction' => self::TX, 'network' => 'eip155:84532', 'payer' => strtolower(self::FROM)]);
        });
        FacilitatorFactory::create('https://f.example/facilitator', $http, 'k-123')->settle(self::PAYMENT, self::REQ);
        FacilitatorFactory::create('https://f.example/facilitator', $http)->settle(self::PAYMENT, self::REQ);

        $this->assertSame([['https://f.example/facilitator/settle', 'Authorization: Bearer k-123'], ['https://f.example/facilitator/settle', null]], $seen, 'no key set, no header');
    }

    public function testRejectsAnythingButMockOrAnHttpsUrl(): void
    {
        foreach (['', 'MOCK', 'http://f.example', 'https://', 'f.example'] as $mode) {
            try {
                FacilitatorFactory::create($mode, new MockHttpClient());
                $this->fail($mode);
            } catch (\LogicException $e) {
                // The env variable is named: the library's message alone does not say where the value came from.
                $this->assertStringStartsWith('X402_FACILITATOR: ', $e->getMessage());
            }
        }
    }

    public function testVerifyGivesTheFacilitatorsReason(): void
    {
        // As services.yaml builds the middleware's $verify (app.x402_verify).
        $verify = fn (MockResponse $r) => Settlement::verifier(FacilitatorFactory::create('https://f.example', new MockHttpClient($r)))(self::PAYMENT, self::REQ);
        $this->assertNull($verify(new JsonMockResponse(['isValid' => true])));
        $this->assertSame('insufficient_funds', $verify(new JsonMockResponse(['isValid' => false, 'invalidReason' => 'insufficient_funds'])));
        $this->assertSame('invalid_payment', $verify(new JsonMockResponse(['isValid' => false])));
        // Unreachable: thrown, so the middleware answers facilitator_unavailable.
        $this->expectException(FacilitatorException::class);
        $verify(new MockResponse('', ['error' => 'timeout']));
    }

    public function testCirclesUrlIsChosenWhateverTheCaseOrQuery(): void
    {
        foreach (['https://API.circle.com/v1/facilitator/x402', 'https://api.circle.com/v1/facilitator/x402?x=1', 'HTTPS://api.circle.com/v1/facilitator/x402/', 'https://api.circle.com/v1/facilitator/x402#f'] as $mode) {
            $circle = FacilitatorFactory::create($mode, new MockHttpClient());
            self::assertInstanceOf(CircleFacilitator::class, $circle, $mode);
            // Circle has a status route: the receipt lookup asks it.
            self::assertInstanceOf(PaymentStatus::class, FacilitatorFactory::status($circle));
        }
        $other = FacilitatorFactory::create('https://api.circle.com/v1/other', new MockHttpClient());
        self::assertInstanceOf(HttpFacilitator::class, $other);
        self::assertNull(FacilitatorFactory::status($other), 'no status route: the lookup asks the chain alone');
    }

    public function testTheErrorNeverEchoesTheUrlsCredentialsOrQuery(): void
    {
        try {
            FacilitatorFactory::create('http://user:secret@f.example/x?token=abc', new MockHttpClient());
            self::fail('an http URL must be refused');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('user', $e->getMessage());
            self::assertStringNotContainsString('token', $e->getMessage());
            self::assertStringContainsString('f.example', $e->getMessage());
        }
    }

    public function testMockMovesNothingAndVerifiesOfflineOnly(): void
    {
        $mock = FacilitatorFactory::create('mock', new MockHttpClient());
        self::assertInstanceOf(MockFacilitator::class, $mock);
        self::assertNull(Settlement::verifier($mock)(self::PAYMENT, self::REQ));
        self::assertNull(FacilitatorFactory::status($mock));
    }
}
