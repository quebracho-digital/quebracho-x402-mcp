<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\AddressList;
use Quebracho\X402\ChainalysisApi;
use Quebracho\X402\OfacSdn;
use Quebracho\X402\OfacSdnSync;
use Quebracho\X402\SyncResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The app's lists on the library's AddressCheck: the payer (we sell) and payTo (we buy).
 *
 * Every check, in order, the first refusal decides:
 * 1. OFAC's SDN list (Quebracho\X402\OfacSdn), asked by the library before any listener, so nothing here
 *    can let a sanctioned address through, our allowlist included. Its snapshot is var/screening/ofac-sdn.json
 *    (app:screening sync); until the first sync, the copy shipped with the library;
 * 2. our allowlist (var/screening/allow.txt): skips 3 and 4, never OFAC nor the token's own blacklist;
 * 3. our denylist (var/screening/deny.txt): refused with address_denied;
 * 4. Chainalysis' sanctions API (Quebracho\X402\ChainalysisApi), only when X402_SCREENING_API_KEY is set;
 * 5. the token's own blacklist (Quebracho\X402\TokenBlacklist), a listener of its own (services.yaml).
 *
 * Both lists are the library's text format (AddressList): one address per line, # for comments. A list file
 * that does not exist is empty; one that cannot be read refuses everyone.
 *
 * The API is asked when the verdict is read: just before settling (seller) or signing (buyer). Answers are
 * cached X402_SCREENING_CACHE_TTL seconds. No answer within X402_SCREENING_TIMEOUT (or a 5xx, or a reply
 * that is not one) follows X402_SCREENING_UNAVAILABLE: "reject" (default) or "allow", which lets the address
 * pass and reports ScreeningSkipped. A 4xx (a wrong key) always rejects.
 *
 * Rejections and skips are logged by the library's events (AddressRejected, ScreeningSkipped → PaymentLog).
 */
#[AsEventListener]
final class Screening
{
    private const LISTS = ['allow', 'deny'];

    private readonly ?ChainalysisApi $api;
    private readonly Psr18Client $client;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/screening')] private readonly string $dir,
        #[Autowire(env: 'X402_SCREENING_API_KEY')] #[\SensitiveParameter] string $apiKey,
        #[Autowire(env: 'int:X402_SCREENING_CACHE_TTL')] private readonly int $cacheTtl,
        #[Autowire(env: 'float:X402_SCREENING_TIMEOUT')] float $timeout,
        #[Autowire(env: 'X402_SCREENING_UNAVAILABLE')] private readonly string $unavailable,
        private readonly OfacSdn $ofac,
        HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
    ) {
        if (!\in_array($unavailable, ['reject', 'allow'], true)) {
            throw new \LogicException(\sprintf('X402_SCREENING_UNAVAILABLE="%s": use "reject" or "allow".', $unavailable));
        }
        // OFAC's own redirects are followed by OfacSdn::sync, HTTPS only.
        $this->client = new Psr18Client($http->withOptions(['max_redirects' => 0]));
        $this->api = '' === $apiKey ? null : new ChainalysisApi(
            new Psr18Client($http->withOptions(['max_redirects' => 0, 'timeout' => $timeout, 'max_duration' => $timeout])),
            $this->client,
            $apiKey,
        );
    }

    public function __invoke(AddressCheck $check): void
    {
        $address = $check->address;
        if ($this->listed('allow', $address)) {
            return;
        }
        if ($this->listed('deny', $address)) {
            $check->reject('address_denied');

            return;
        }
        if (null === $this->api) {
            return;
        }
        $item = $this->cached($address);
        if ($item->isHit()) {
            if (true === $item->get()) {
                $check->reject(OfacSdn::REASON);
            }

            return;
        }
        $check->defer(function () use ($check, $item): ?string {
            try {
                $sanctioned = $this->ask($check->address, $item);
            } catch (\Throwable $e) {
                // A 4xx is a key of ours that is wrong: never let it pass.
                if ('allow' !== $this->unavailable || $e instanceof \LogicException) {
                    throw $e;
                }
                $check->skip(ChainalysisApi::class, $e);

                return null;
            }

            return $sanctioned ? OfacSdn::REASON : null;
        });
    }

    /**
     * Everything app:screening check can say, OFAC and the API included (it waits; it throws when the API
     * cannot answer, whatever the policy).
     */
    public function check(string $address): ?string
    {
        return match (true) {
            $this->ofac->listed($address) => OfacSdn::REASON,
            $this->listed('allow', $address) => null,
            $this->listed('deny', $address) => 'address_denied',
            null === $this->api => null,
            default => $this->sanctioned($address) ? OfacSdn::REASON : null,
        };
    }

    private function sanctioned(string $address): bool
    {
        $item = $this->cached($address);

        return $item->isHit() ? true === $item->get() : $this->ask($address, $item);
    }

    /**
     * Puts an address on our allow or deny list (and off the other one), or on neither when $list is null.
     */
    public function set(string $address, ?string $list): void
    {
        $address = AddressList::parse($address)[0] ?? throw new \InvalidArgumentException('No address.');
        (new Filesystem())->mkdir($this->dir, 0755);
        // Read-modify-write: two admins at once must not lose an entry.
        $lock = fopen($this->dir.'/own.lock', 'c') ?: throw new \RuntimeException("Cannot lock {$this->dir}/own.lock.");
        flock($lock, \LOCK_EX);
        try {
            $own = $this->own();
            foreach (self::LISTS as $name) {
                $own[$name] = array_values(array_diff($own[$name], [$address]));
            }
            if (null !== $list) {
                $own[$list][] = $address;
            }
            foreach (self::LISTS as $name) {
                $this->write($name.'.txt', "# Our {$name}list (app:screening $name), one address per line.\n".implode("\n", $own[$name])."\n");
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{allow: list<string>, deny: list<string>}
     */
    public function own(): array
    {
        return ['allow' => $this->read('allow'), 'deny' => $this->read('deny')];
    }

    /**
     * Downloads OFAC's SDN list (the official source) to the snapshot OfacSdn reads.
     */
    public function sync(): SyncResult
    {
        (new Filesystem())->mkdir($this->dir, 0755);

        return (new OfacSdnSync($this->client, $this->client))->sync($this->dir.'/ofac-sdn.json');
    }

    /** The OFAC list in use: published when, and whether it is the copy shipped with the library. */
    public function ofac(): OfacSdn
    {
        return $this->ofac;
    }

    private function listed(string $list, string $address): bool
    {
        $file = $this->dir.'/'.$list.'.txt';

        return file_exists($file) && (new AddressList([$file]))->listed($address);
    }

    /**
     * @return list<string>
     */
    private function read(string $list): array
    {
        $file = $this->dir.'/'.$list.'.txt';
        if (!file_exists($file)) {
            return [];
        }
        $text = @file_get_contents($file);

        return AddressList::parse(false === $text ? throw new \RuntimeException("Cannot read $file.") : $text);
    }

    private function cached(string $address): CacheItemInterface
    {
        return $this->cache->getItem('x402_screening_'.hash('sha256', strtolower($address)));
    }

    /**
     * The API's answer, cached; never caches a failure.
     */
    private function ask(string $address, CacheItemInterface $item): bool
    {
        \assert(null !== $this->api);
        $sanctioned = $this->api->sanctioned($address);
        $this->cache->save($item->set($sanctioned)->expiresAfter($this->cacheTtl));

        return $sanctioned;
    }

    private function write(string $name, string $content): void
    {
        $fs = new Filesystem();
        // Through a unique temp file and a rename: a check never reads half a file. Readable by the web and CLI users.
        $fs->dumpFile($this->dir.'/'.$name, $content);
        $fs->chmod($this->dir.'/'.$name, 0644);
    }
}
