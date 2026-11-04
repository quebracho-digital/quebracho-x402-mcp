<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Log\LoggerInterface;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\Settlement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Settles paid calls. X402_FACILITATOR is "mock" (signature checked, nothing
 * moves) or a facilitator's base URL, e.g. https://x402.org/facilitator,
 * settled through the library's HttpFacilitator. Anything else makes every
 * /mcp request fail (the controller cannot be built) instead of serving
 * paid tools for free.
 */
final class Facilitator
{
    private readonly ?HttpFacilitator $remote;

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
            $this->remote = 'mock' === $mode ? null : new HttpFacilitator($client, $client, $client, $mode);
        } catch (\InvalidArgumentException) {
            throw new \LogicException(\sprintf('X402_FACILITATOR="%s": "mock" or an https:// facilitator URL.', $mode));
        }
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
     * A settle's status by its paymentId, for ReceiptLookup. Mock: NULL (nothing was sent to a facilitator).
     */
    public function status(string $paymentId, string $network): ?array
    {
        return null === $this->remote ? null : $this->remote->status($paymentId, $network);
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
