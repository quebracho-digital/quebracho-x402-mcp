<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Chainalysis' free sanctions screening API (https://public.chainalysis.com, an API key from Chainalysis),
 * an AddressCheck listener. Opt-in, a second opinion next to OFAC's own list (OfacSdn), never instead of it.
 * Any address form: Chainalysis decides what it knows.
 *
 * The lookup is deferred: the request is sent when the verdict is read (the seller just before settling,
 * the buyer before signing), with the PSR-18 client's own timeout. An address with identifications is
 * refused with `address_sanctioned`.
 *
 * An API that cannot answer (no answer, a 5xx, a reply without `identifications`) rejects
 * (`address_check_failed`), unless $failOpen. A 4xx (a wrong key) always rejects: it is a mistake of ours.
 *
 * ponytail: no cache here; a site that pays per call wraps this listener with its own.
 */
final class ChainalysisApi
{
    public const string URL = 'https://public.chainalysis.com/api/v1/address/%s';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly bool $failOpen = false,
    ) {
        if ('' === $apiKey) {
            throw new \InvalidArgumentException('ChainalysisApi needs an API key.');
        }
    }

    public function __invoke(AddressCheck $check): void
    {
        $address = $check->address;
        $check->defer(function () use ($check, $address): ?string {
            try {
                return $this->ask($address) ? OfacSdn::REASON : null;
            } catch (\Throwable $e) {
                if (!$this->failOpen || $e instanceof \LogicException) {
                    throw $e;
                }
                // Passed unchecked: reported (ScreeningSkipped), so the pass leaves a trace.
                $check->skip(self::class, $e);

                return null;
            }
        });
    }

    /**
     * @throws \Throwable when the API cannot answer and it does not fail open, or on a 4xx
     */
    public function sanctioned(string $address): bool
    {
        try {
            return $this->ask($address);
        } catch (\Throwable $e) {
            if (!$this->failOpen || $e instanceof \LogicException) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * @throws \LogicException on a 4xx (a bad key: never failed open)
     * @throws \Throwable      when the API cannot answer
     */
    private function ask(string $address): bool
    {
        $request = $this->requests->createRequest('GET', \sprintf(self::URL, rawurlencode($address)))
            ->withHeader('X-API-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json');
        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        if ($status >= 400 && $status < 500) {
            throw new \LogicException("Chainalysis API: HTTP $status (check the key).");
        }
        $body = 200 === $status ? json_decode((string) $response->getBody(), true) : null;
        if (!\is_array($body) || !\is_array($body['identifications'] ?? null)) {
            throw new \UnexpectedValueException("Chainalysis API: HTTP $status without identifications.");
        }

        return [] !== $body['identifications'];
    }
}
