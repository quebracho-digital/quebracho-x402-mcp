<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Circle's x402 facilitator: an HttpFacilitator at self::URL, plus what only Circle asks for.
 *
 * - An API key ($bearer), or keyless with the payTo key ($seller signs a seller
 *   proof on each call). With a bearer, no proof is sent.
 * - A settle still pending (it has a paymentId) is polled at /status, up to
 *   $pollAttempts times: the first query goes out at once, and $pollDelaySeconds
 *   pass only between queries (DECISIONS.md D16). Still pending, it comes back
 *   as settlement_pending with that paymentId.
 *
 * @see https://developers.circle.com/facilitator-service/quickstart
 */
final class CircleFacilitator implements Facilitator, PaymentStatus
{
    public const URL = 'https://api.circle.com/v1/facilitator/x402';

    private readonly HttpFacilitator $http;

    public function __construct(
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        #[\SensitiveParameter] ?string $bearer = null,
        ?Signer $seller = null,
        private readonly int $pollDelaySeconds = 1,
        private readonly int $pollAttempts = 5,
    ) {
        $proof = null;
        if (null === $bearer && null !== $seller) {
            $proof = static fn (string $purpose, string $method, string $body, string $network): array => [
                'Facilitator-Seller-Proof' => SellerProof::header($seller, $purpose, $method, $body, $network),
            ];
        }
        $this->http = new HttpFacilitator($http, $requests, $streams, self::URL, $bearer, headers: $proof);
    }

    public function verify(array $payment, array $req): array
    {
        return $this->http->verify($payment, $req);
    }

    public function settle(array $payment, array $req): array
    {
        try {
            $out = $this->http->settleRaw($payment, $req);
        } catch (\InvalidArgumentException) {
            // The proof is built before anything is sent: a refusal, so the claim is freed.
            return ['success' => false, 'errorReason' => 'seller_proof_failed', 'transaction' => '', 'network' => $req['network']];
        }
        $id = $out['extensions']['settlement-status']['paymentId'] ?? null;
        // The first /status query is immediate; the delay sits between queries only (DECISIONS.md D16).
        for ($i = 0; true !== ($out['success'] ?? null) && is_string($id) && preg_match('/^[\w-]+$/', $id) && $i < $this->pollAttempts; ++$i) {
            if ($i > 0) {
                sleep($this->pollDelaySeconds);
            }
            try {
                $status = $this->status($id, $req['network']);
            } catch (FacilitatorException) {
                // Settled or not, Circle knows it by this id.
                break;
            }
            if ('completed' === ($status['status'] ?? null)) {
                $out = ['success' => true, 'transaction' => $status['transaction'] ?? null, 'network' => $status['network'] ?? $req['network'], 'payer' => $status['payer'] ?? $out['payer'] ?? null];
            } elseif ('failed' === ($status['status'] ?? null)) {
                return ['success' => false, 'errorReason' => is_string($status['reason'] ?? null) ? $status['reason'] : 'settlement_failed', 'transaction' => '', 'network' => $req['network']];
            }
        }
        if (true !== ($out['success'] ?? null) && is_string($id)) {
            // Still pending after polling: its id is what reconciles it.
            return ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => is_string($out['transaction'] ?? null) ? $out['transaction'] : '', 'network' => $req['network'], 'paymentId' => $id];
        }

        return $this->http->checkSettle($out, $payment, $req);
    }

    /**
     * A settle's status by its paymentId (GET /status). Throws FacilitatorException when Circle cannot be asked.
     */
    public function status(string $paymentId, string $network): array
    {
        if (1 !== preg_match('/^[\w-]+$/', $paymentId)) {
            throw new \InvalidArgumentException("Not a paymentId: $paymentId");
        }

        return $this->http->get("/status/$paymentId", 'status', $network);
    }
}
