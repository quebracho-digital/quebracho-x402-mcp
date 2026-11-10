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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter: wraps the library's Screening and adds Symfony-specific dependencies
 * (Filesystem, HttpClient) for admin operations (sync, ofac).
 *
 * The library's Screening is simpler (no Symfony deps). This wrapper keeps the
 * Symfony wiring for the app's admin CLI commands while delegating __invoke()
 * to the library implementation.
 */
final class Screening
{
    private readonly LibScreening $lib;
    private readonly ?ChainalysisApi $api;
    private readonly Psr18Client $client;
    private readonly Filesystem $fs;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%/var/screening')] private readonly string $dir,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'X402_SCREENING_API_KEY')] #[\SensitiveParameter] string $apiKey,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'int:X402_SCREENING_CACHE_TTL')] private readonly int $cacheTtl,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'float:X402_SCREENING_TIMEOUT')] private readonly float $timeout,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'X402_SCREENING_UNAVAILABLE')] private readonly string $unavailable,
        private readonly OfacSdn $ofac,
        HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
    ) {
        // Build the library Screening (simpler constructor, no Symfony deps)
        $chainalysisApi = '' === $apiKey ? null : new ChainalysisApi(
            new Psr18Client($http->withOptions(['max_redirects' => 0, 'timeout' => $timeout, 'max_duration' => $timeout])),
            new Psr18Client($http->withOptions(['max_redirects' => 0])),
            $apiKey,
        );
        $this->lib = new LibScreening($this->dir, $chainalysisApi, $this->cache, $this->cacheTtl, $this->unavailable);

        // Keep Symfony-specific deps for admin operations
        $this->api = $chainalysisApi;
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
