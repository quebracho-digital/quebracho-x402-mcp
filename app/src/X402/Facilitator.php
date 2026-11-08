<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Log\LoggerInterface;
use Quebracho\X402\CircleFacilitator;
use Quebracho\X402\Facilitator as LibFacilitator;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\PaymentStatus;
use Quebracho\X402\Settlement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Settles paid calls. X402_FACILITATOR is "mock" (signature checked, nothing
 * moves), Circle's URL (CircleFacilitator, keyless) or any other facilitator's
 * base URL, e.g. https://x402.org/facilitator (HttpFacilitator). Anything else
 * makes every /mcp request fail (the controller cannot be built) instead of
 * serving paid tools for free.
 */
final class Facilitator implements PaymentStatus
{
    private readonly ?LibFacilitator $remote;

    public function __construct(
        #[Autowire(env: 'X402_FACILITATOR')] string $mode,
        private readonly LoggerInterface $logger,
        HttpClientInterface $http,
    ) {
        try {
            $client = new Psr18Client($http->withOptions([
                'timeout' => 30,
                'max_duration' => 60,
                // The signed payment goes to this host only.
                'max_redirects' => 0,
            ]));
            $this->remote = match (true) {
                'mock' === $mode => null,
                self::normalize($mode) === self::normalize(CircleFacilitator::URL) => new CircleFacilitator($client, $client, $client),
                default => new HttpFacilitator($client, $client, $client, $mode),
            };
        } catch (\InvalidArgumentException) {
            // Only scheme and host are shown: the URL may carry a password or a token in its userinfo or query.
            throw new \LogicException(\sprintf('X402_FACILITATOR="%s": "mock" or an https:// facilitator URL.', self::origin($mode)));
        }
    }

    /**
     * The URL as compared with Circle's: scheme and host in lower case, no query, fragment or trailing slash.
     */
    private static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['host'])) {
            return rtrim($url, '/');
        }

        return strtolower($parts['scheme'] ?? '').'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($parts['path'] ?? '', '/');
    }

    /** scheme://host[:port] of a URL, without userinfo, path or query; "(unparseable)" when there is no such thing. */
    private static function origin(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '(unparseable)';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * The middlewares' $verify: the facilitator's /verify before the tool runs
     * (balance, nonce unused), so an empty wallet costs no work. Mock: offline only.
     */
    public function verify(array $payment, array $req): ?string
    {
        if (null === $this->remote) {
            return null;
        }
        $out = $this->remote->verify($payment, $req);

        return true === $out['isValid'] ? null : (\is_string($out['invalidReason'] ?? null) ? $out['invalidReason'] : 'invalid_payment');
    }

    /**
     * A settle's status by its paymentId, for the library's ReceiptLookup. NULL when the facilitator has no status
     * route (only PaymentStatus ones have it: Circle) or is mock (nothing was sent to a facilitator).
     */
    public function status(string $paymentId, string $network): ?array
    {
        return $this->remote instanceof PaymentStatus ? $this->remote->status($paymentId, $network) : null;
    }

    public function __invoke(array $payment, array $req): array
    {
        if (null === $this->remote) {
            $this->logger->warning('x402 mock settlement: signature verified, nothing moved on chain.', ['network' => $req['network'], 'amount' => $req['amount']]);

            return Settlement::run(Settlement::mock(), $payment, $req);
        }

        $receipt = Settlement::run(function () use ($payment, $req): array {
            try {
                return $this->remote->settle($payment, $req);
            } catch (\Throwable $e) {
                $this->logger->error('x402 facilitator unreachable or unreadable.', ['exception' => $e]);
                throw $e;
            }
        }, $payment, $req);
        if (true !== $receipt['success']) {
            // The tool ran and its output is withheld: enough to reconcile with authorizationState(from, nonce).
            $auth = $payment['payload']['authorization'] ?? [];
            $this->logger->error('x402 settlement failed.', ['receipt' => $receipt, 'payer' => $auth['from'] ?? null, 'nonce' => $auth['nonce'] ?? null]);
        }

        return $receipt;
    }
}
