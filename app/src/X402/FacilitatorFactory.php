<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Facilitator;
use Quebracho\X402\Facilitators;
use Quebracho\X402\PaymentStatus;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The seller's one facilitator (services.yaml), from env: X402_FACILITATOR is "mock" (signatures checked,
 * nothing moves), Circle's URL or any other facilitator's base URL, e.g. https://x402.org/facilitator.
 * Anything else makes every /mcp request fail (the middleware cannot be built) instead of serving paid tools
 * for free.
 *
 * The choice is the library's (Facilitators::from). What it returns is a Quebracho\X402\Facilitator, which the
 * middleware and JobDesk take as they are: the library wraps its settle (a throw becomes a failed receipt,
 * never an answer delivered unpaid) and reports every outcome as an event, which PaymentLog writes down.
 * This class only adds the HTTP client and names the env variable in the error.
 */
final class FacilitatorFactory
{
    public static function create(string $setting, HttpClientInterface $http, #[\SensitiveParameter] string $key = ''): Facilitator
    {
        $client = new Psr18Client($http->withOptions([
            'timeout' => 30,
            'max_duration' => 60,
            // The signed payment goes to this host only.
            'max_redirects' => 0,
        ]));
        try {
            return Facilitators::from($setting, $client, $client, $client, $key);
        } catch (\InvalidArgumentException $e) {
            // The library's message shows only scheme and host: the URL may carry a password or a token.
            throw new \LogicException('X402_FACILITATOR: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * For the library's ReceiptLookup: the facilitator when it can say how a settle ended by its paymentId
     * (only Circle's has that route), else NULL and the lookup asks the chain alone.
     */
    public static function status(Facilitator $facilitator): ?PaymentStatus
    {
        return $facilitator instanceof PaymentStatus ? $facilitator : null;
    }
}
