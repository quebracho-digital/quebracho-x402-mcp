<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Cache\CacheItemPoolInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\ChainalysisApi;
use Quebracho\X402\OfacSdn;
use Quebracho\X402\OfacSdnSync;
use Quebracho\X402\Screening as LibScreening;
use Quebracho\X402\SyncResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The app's lists on the library's AddressCheck: the payer (we sell) and payTo (we buy). A listener of
 * AddressCheck: without the attribute below the deny list and the API are asked by nobody (AppKernelTest).
 *
 * The checks and their order are the library's (Quebracho\X402\Screening): OFAC first, asked by the library
 * before any listener; our allowlist (var/screening/allow.txt), which skips the next two and never OFAC nor
 * the token's own blacklist; our denylist (deny.txt, address_denied); Chainalysis' sanctions API, only when
 * X402_SCREENING_API_KEY is set, cached X402_SCREENING_CACHE_TTL seconds. An API that does not answer within
 * X402_SCREENING_TIMEOUT follows X402_SCREENING_UNAVAILABLE: "reject" (default) or "allow".
 *
 * What this class adds is the Symfony wiring (env, the HTTP client's timeouts) and the admin's sync of
 * OFAC's list into var/screening (app:screening sync).
 */
#[AsEventListener]
final class Screening
{
    private readonly LibScreening $lib;
    private readonly Psr18Client $client;
    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/screening')] private readonly string $dir,
        #[Autowire(env: 'X402_SCREENING_API_KEY')] #[\SensitiveParameter] string $apiKey,
        #[Autowire(env: 'int:X402_SCREENING_CACHE_TTL')] int $cacheTtl,
        #[Autowire(env: 'float:X402_SCREENING_TIMEOUT')] float $timeout,
        #[Autowire(env: 'X402_SCREENING_UNAVAILABLE')] string $unavailable,
        private readonly OfacSdn $ofac,
        HttpClientInterface $http,
        CacheItemPoolInterface $cache,
    ) {
        $api = '' === $apiKey ? null : new ChainalysisApi(
            new Psr18Client($http->withOptions(['max_redirects' => 0, 'timeout' => $timeout, 'max_duration' => $timeout])),
            new Psr18Client($http->withOptions(['max_redirects' => 0])),
            $apiKey,
        );
        $this->lib = new LibScreening($dir, $api, $cache, $cacheTtl, $unavailable);
        // OFAC's own redirects are followed by OfacSdnSync, HTTPS only.
        $this->client = new Psr18Client($http->withOptions(['max_redirects' => 0]));
        $this->fs = new Filesystem();
    }

    /** Delegate to library: the payer/payTo check invoked by the x402 middleware. */
    public function __invoke(AddressCheck $check): void
    {
        $this->lib->__invoke($check);
    }

    /** Delegate to library: check an address against the site's lists. */
    public function check(string $address): ?string
    {
        return $this->lib->check($address, $this->ofac);
    }

    /** Delegate to library: add/remove an address from allow/deny lists. */
    public function set(string $address, ?string $list): void
    {
        $this->lib->set($address, $list);
    }

    /** Delegate to library: list the site's addresses. */
    public function own(): array
    {
        return $this->lib->own();
    }

    /**
     * Downloads OFAC's SDN list (the official source) to the snapshot OfacSdn reads.
     * App-specific admin method not present in the library's Screening.
     */
    public function sync(): SyncResult
    {
        $this->fs->mkdir($this->dir, 0755);

        return (new OfacSdnSync($this->client, $this->client))->sync($this->dir.'/ofac-sdn.json');
    }

    /**
     * The OFAC list in use: published when, and whether it is the copy shipped with the library.
     * App-specific admin method not present in the library's Screening.
     */
    public function ofac(): OfacSdn
    {
        return $this->ofac;
    }
}
