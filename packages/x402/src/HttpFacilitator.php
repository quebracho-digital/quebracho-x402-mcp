<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Any x402 facilitator by URL (/verify, /settle, /supported), e.g.
 * https://x402.org/facilitator, with an optional bearer key. A facilitator
 * with its own routes or auth (Circle) wraps this class: CircleFacilitator.
 *
 * A settle counts only when it is consistent: a 2xx, success === true, a
 * transaction (0x+64 hex on EVM), our network and, when named, the payer who
 * signed (a facilitator that names no payer is not checked on it).
 *
 * @see https://github.com/coinbase/x402
 */
final class HttpFacilitator implements Facilitator
{
    private readonly string $url;

    /**
     * @param ?\Closure $headers
     *                           fn (string $purpose, string $method, string $body, string $network): array<string, string>,
     *                           the extra headers of each call (a wrapper's, e.g. Circle's seller proof). It may throw
     *                           \InvalidArgumentException before anything is sent.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        string $url,
        #[\SensitiveParameter] private readonly ?string $bearer = null,
        private readonly ?\Closure $headers = null,
    ) {
        if ('https' !== parse_url($url, \PHP_URL_SCHEME) || !parse_url($url, \PHP_URL_HOST)) {
            throw new \InvalidArgumentException("Not an https facilitator URL: $url");
        }
        $this->url = rtrim($url, '/');
    }

    public function verify(array $payment, array $req): array
    {
        $out = $this->call('POST', '/verify', 'verify', $req['network'], $this->body($payment, $req));
        if (!is_bool($out['isValid'] ?? null)) {
            throw new FacilitatorException('verify: not an x402 answer');
        }

        return $out;
    }

    public function settle(array $payment, array $req): array
    {
        return $this->checkSettle($this->settleRaw($payment, $req), $payment, $req);
    }

    /**
     * The settle answer as it came, a refusal included: nothing is checked.
     *
     * @throws \InvalidArgumentException when a header hook refuses, before anything is sent
     */
    public function settleRaw(array $payment, array $req): array
    {
        return $this->call('POST', '/settle', 'settle', $req['network'], $this->body($payment, $req));
    }

    /**
     * The consistency rule for a settle's answer. A refusal (success not true) comes back as it is.
     *
     * Public for a wrapper that got its settle answer another way (Circle's /status poll).
     */
    public function checkSettle(array $out, array $payment, array $req): array
    {
        if (true !== ($out['success'] ?? null)) {
            return $out;
        }
        $tx = $out['transaction'] ?? null;
        $from = $payment['payload']['authorization']['from'] ?? null;
        $consistent = is_string($tx) && (str_starts_with($req['network'], 'eip155:') ? 1 === preg_match('/^0x[0-9a-fA-F]{64}$/', $tx) : '' !== $tx)
            && ($out['network'] ?? null) === $req['network']
            && (!isset($out['payer'], $from) || 0 === strcasecmp((string) $out['payer'], (string) $from));

        return $consistent ? $out : ['success' => false, 'errorReason' => 'invalid_settle_response', 'transaction' => is_string($tx) ? $tx : '', 'network' => $req['network']];
    }

    /**
     * The "kinds" of /supported: scheme, network, extra per entry.
     */
    public function supported(): array
    {
        $kinds = $this->call('GET', '/supported', 'supported', '', '')['kinds'] ?? null;

        return is_array($kinds) ? $kinds : throw new FacilitatorException('supported: not an x402 answer');
    }

    /**
     * Solana: the facilitator's fee payer for this network (extra.feePayer).
     */
    public function feePayer(string $network): ?string
    {
        foreach ($this->supported() as $kind) {
            if (($kind['network'] ?? null) === $network && is_string($kind['extra']['feePayer'] ?? null)) {
                return $kind['extra']['feePayer'];
            }
        }

        return null;
    }

    /**
     * A GET under the facilitator's URL, for a route beyond the standard (Circle's /status). Same headers and errors as the rest.
     */
    public function get(string $path, string $purpose, string $network): array
    {
        return $this->call('GET', $path, $purpose, $network, '');
    }

    private function body(array $payment, array $req): string
    {
        return json_encode(['x402Version' => 2, 'paymentPayload' => $payment, 'paymentRequirements' => $req], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * The facilitator's JSON. A non-2xx is an answer only when it says
     * isValid/success false (a refusal); anything else throws.
     */
    private function call(string $method, string $path, string $purpose, string $network, string $body): array
    {
        $request = $this->requests->createRequest($method, $this->url.$path);
        if (null !== $this->bearer) {
            $request = $request->withHeader('Authorization', "Bearer $this->bearer");
        }
        if (null !== $this->headers) {
            foreach (($this->headers)($purpose, $method, $body, $network) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        }
        if ('' !== $body) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streams->createStream($body));
        }
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FacilitatorException("$purpose: ".$e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        $out = json_decode((string) $response->getBody(), true);
        if ($status >= 200 && $status < 300 && is_array($out)) {
            return $out;
        }
        if (is_array($out) && (false === ($out['isValid'] ?? null) || false === ($out['success'] ?? null))) {
            return $out;
        }
        $reason = is_array($out) ? ($out['errors'][0]['reason'] ?? $out['message'] ?? null) : null;

        throw new FacilitatorException(is_string($reason) ? $reason : "$purpose: http_$status", $status);
    }
}
