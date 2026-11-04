<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Quebracho\X402\Networks;
use Quebracho\X402\Rpc;

/**
 * The middlewares' $lookup: what the chain (EVM transaction) or the facilitator (paymentId) did with a
 * 'pending' payment. The stored state keeps the settle receipt under 'receipt'.
 *
 * true receipt: confirmed. success false: refuted (the claim is freed). NULL: unknown, still pending.
 * Never throws: any failure to ask is NULL, so a network error never frees a claim.
 */
final class ReceiptLookup
{
    public function __construct(
        private readonly Facilitator $facilitator,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return ?array{success: bool, transaction?: string, network?: string, payer?: ?string, errorReason?: string}
     */
    public function __invoke(array $state): ?array
    {
        try {
            $receipt = \is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
            $network = $receipt['network'] ?? null;
            if (!\is_string($network)) {
                return null;
            }
            $transaction = $receipt['transaction'] ?? '';
            if (str_starts_with($network, 'eip155:') && \is_string($transaction) && '' !== $transaction) {
                return $this->evm($network, $transaction, $receipt);
            }
            $paymentId = $receipt['paymentId'] ?? null;

            return \is_string($paymentId) && '' !== $paymentId ? $this->facilitator($paymentId, $network, $receipt) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $receipt
     */
    private function evm(string $network, string $transaction, array $receipt): ?array
    {
        if (1 !== preg_match('/^0x[0-9a-fA-F]{64}$/', $transaction)) {
            return null;
        }
        $rpc = new Rpc($this->http, $this->requests, $this->streams, Networks::get($network)['rpc']);
        // Not mined yet: the node answers null.
        $mined = $rpc('eth_getTransactionReceipt', [$transaction]);
        if (!\is_array($mined)) {
            return null;
        }

        return match ($mined['status'] ?? null) {
            '0x1' => ['success' => true, 'transaction' => $transaction, 'network' => $network, 'payer' => $receipt['payer'] ?? null],
            '0x0' => ['success' => false, 'errorReason' => 'transaction_reverted', 'transaction' => $transaction, 'network' => $network],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $receipt
     */
    private function facilitator(string $paymentId, string $network, array $receipt): ?array
    {
        $status = $this->facilitator->status($paymentId, $network);

        return match ($status['status'] ?? null) {
            'completed' => ['success' => true, 'transaction' => (string) ($status['transaction'] ?? $receipt['transaction'] ?? ''), 'network' => $network, 'payer' => $status['payer'] ?? null],
            'failed' => ['success' => false, 'errorReason' => \is_string($status['reason'] ?? null) ? $status['reason'] : 'settlement_failed', 'transaction' => '', 'network' => $network],
            default => null,
        };
    }
}
