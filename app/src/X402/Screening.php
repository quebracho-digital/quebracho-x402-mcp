<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Quebracho\X402\AddressCheck;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Screens every address in a payment: the payer (we sell) and payTo (we buy).
 *
 * In order, the first that matches decides:
 * 1. our allowlist: passes, even if a public list names it (the admin's call);
 * 2. our denylist: our own reports;
 * 3. OFAC's sanctioned addresses, synced to a local file (app:screening sync);
 * 4. Chainalysis' sanctions API, only when X402_SCREENING_API_KEY is set.
 *
 * The API is deferred: the request leaves when the payment is checked and is
 * read just before settling (seller) or signing (buyer), so it overlaps the
 * tool. Answers are cached X402_SCREENING_CACHE_TTL seconds. No answer within
 * X402_SCREENING_TIMEOUT (or a 5xx) follows X402_SCREENING_UNAVAILABLE:
 * "reject" (default) or "allow". Any other error rejects.
 */
#[AsEventListener]
final class Screening
{
    public const OFAC_LISTS = ['ETH', 'USDC', 'USDT', 'ARB', 'BSC'];
    private const OFAC_URL = 'https://raw.githubusercontent.com/0xB10C/ofac-sanctioned-digital-currency-addresses/lists/sanctioned_addresses_%s.txt';
    private const API_URL = 'https://public.chainalysis.com/api/v1/address/%s';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/screening')] private readonly string $dir,
        #[Autowire(env: 'X402_SCREENING_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'int:X402_SCREENING_CACHE_TTL')] private readonly int $cacheTtl,
        #[Autowire(env: 'float:X402_SCREENING_TIMEOUT')] private readonly float $timeout,
        #[Autowire(env: 'X402_SCREENING_UNAVAILABLE')] private readonly string $unavailable,
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
        if (!\in_array($unavailable, ['reject', 'allow'], true)) {
            throw new \LogicException(\sprintf('X402_SCREENING_UNAVAILABLE="%s": use "reject" or "allow".', $unavailable));
        }
    }

    public function __invoke(AddressCheck $check): void
    {
        try {
            $reason = $this->reason($check->address);
        } catch (\Throwable $e) {
            // AddressCheck turns it into address_check_failed; say why here.
            $this->logger->error('x402 screening failed.', ['address' => $check->address, 'exception' => $e]);
            throw $e;
        }
        if (null !== $reason) {
            $this->logger->warning('x402 address rejected.', ['role' => $check->role, 'network' => $check->network, 'address' => $check->address, 'reason' => $reason]);
            $check->reject($reason);
        } elseif ($this->asksApi($check->address)) {
            $answer = $this->lookup($check->address);
            $check->defer(function () use ($answer, $check): ?string {
                try {
                    $reason = $answer();
                } catch (\Throwable $e) {
                    // A 4xx (429, expired key) or a malformed answer: rejected, say why.
                    $this->logger->error('x402 screening failed.', ['address' => $check->address, 'exception' => $e]);
                    throw $e;
                }
                if (null !== $reason) {
                    $this->logger->warning('x402 address rejected.', ['role' => $check->role, 'network' => $check->network, 'address' => $check->address, 'reason' => $reason]);
                }

                return $reason;
            });
        }
    }

    /**
     * Everything app:screening check can say, the API included (it waits).
     */
    public function check(string $address): ?string
    {
        return $this->reason($address) ?? ($this->asksApi($address) ? $this->lookup($address)() : null);
    }

    /**
     * Why our lists and OFAC refuse this address, or null (no API).
     */
    public function reason(string $address): ?string
    {
        $address = strtolower($address);
        $own = $this->own();

        return match (true) {
            \in_array($address, $own['allow'], true) => null,
            \in_array($address, $own['deny'], true) => 'address_denied',
            isset($this->ofac()[$address]) => 'address_sanctioned',
            default => null,
        };
    }

    /**
     * Puts an address on our allow or deny list (and off the other one), or
     * on neither when $list is null.
     */
    public function set(string $address, ?string $list): void
    {
        $address = strtolower($address);
        (new Filesystem())->mkdir($this->dir, 0755);
        // Read-modify-write: two admins at once must not lose an entry.
        $lock = fopen($this->dir.'/own.lock', 'c') ?: throw new \RuntimeException("Cannot lock {$this->dir}/own.lock.");
        flock($lock, \LOCK_EX);
        try {
            $own = $this->own();
            foreach (['allow', 'deny'] as $name) {
                $own[$name] = array_values(array_diff($own[$name], [$address]));
            }
            if (null !== $list) {
                $own[$list][] = $address;
            }
            $this->write('own.json', json_encode($own, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
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
        $text = $this->read('own.json');
        $own = null === $text ? [] : json_decode($text, true, 3, \JSON_THROW_ON_ERROR);
        $own = ['allow' => $own['allow'] ?? [], 'deny' => $own['deny'] ?? []];
        foreach ($own as $name => $list) {
            // A corrupt list must stop payments loudly, not half-work.
            if (!\is_array($list) || !array_is_list($list) || [] !== array_filter($list, static fn ($a) => !\is_string($a))) {
                throw new \UnexpectedValueException("{$this->dir}/own.json: \"$name\" is not a list of addresses.");
            }
        }

        return $own;
    }

    /**
     * Downloads OFAC's lists; returns how many addresses they hold.
     */
    public function sync(): int
    {
        $all = [];
        foreach (self::OFAC_LISTS as $asset) {
            // getContent() throws on a 404: a missing list is an error, not an empty one.
            $text = $this->http->request('GET', \sprintf(self::OFAC_URL, $asset))->getContent();
            // EVM entries only: base58 (BTC, TRON) is case-sensitive and could
            // collide with NEAR account names once lowercased.
            preg_match_all('/^0x[0-9a-f]{40}$/mi', $text, $m);
            foreach ($m[0] as $address) {
                $all[strtolower($address)] = true;
            }
        }
        $this->write('ofac.txt', implode("\n", array_keys($all))."\n");

        return \count($all);
    }

    /**
     * @return array<string, int>
     */
    private function ofac(): array
    {
        $text = $this->read('ofac.txt');
        if (null === $text) {
            // ponytail: never synced passes everyone, so the demo runs without network; fail closed here if compliance needs it.
            $this->logger->warning('x402 screening: OFAC list never synced; run app:screening sync.');

            return [];
        }

        return array_flip(preg_split('/\s+/', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Whether the API has a say: it is on and we did not allow the address.
     */
    private function asksApi(string $address): bool
    {
        return '' !== $this->apiKey && !\in_array(strtolower($address), $this->own()['allow'], true);
    }

    /**
     * Starts the API lookup now (or reads the cache); the closure answers.
     *
     * @return \Closure(): ?string
     */
    private function lookup(string $address): \Closure
    {
        $item = $this->cache->getItem('x402_screening_'.hash('sha256', strtolower($address)));
        if ($item->isHit()) {
            $sanctioned = $item->get();

            return static fn (): ?string => $sanctioned ? 'address_sanctioned' : null;
        }
        // Lazy: the request is on its way while the caller goes on.
        $response = $this->http->request('GET', \sprintf(self::API_URL, rawurlencode($address)), [
            'headers' => ['X-API-Key' => $this->apiKey],
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
        ]);

        // Never read (the tool threw, settle not reached): cancel it instead of
        // letting the response's destructor throw on its status.
        $guard = new class($response) {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function __destruct()
            {
                $this->response->cancel();
            }
        };

        // $guard is captured only to live as long as this closure: do not drop it (cs-fixer would).
        return function () use ($response, $item, $address, $guard): ?string { // @phpstan-ignore closure.unusedUse
            try {
                $body = $response->toArray();
            } catch (TransportExceptionInterface|ServerExceptionInterface $e) {
                // No answer in time, or theirs is down: the configured policy.
                $this->logger->error('x402 screening API unavailable.', ['address' => $address, 'policy' => $this->unavailable, 'exception' => $e]);
                if ('allow' === $this->unavailable) {
                    return null;
                }
                throw $e;
            }
            if (!\is_array($body['identifications'] ?? null)) {
                throw new \UnexpectedValueException('Chainalysis answered without "identifications".');
            }
            $sanctioned = [] !== $body['identifications'];
            $this->cache->save($item->set($sanctioned)->expiresAfter($this->cacheTtl));

            return $sanctioned ? 'address_sanctioned' : null;
        };
    }

    /**
     * A list file's content, or null when it does not exist. One that exists
     * but cannot be read (another user's 0600) throws: never an empty list.
     */
    private function read(string $name): ?string
    {
        $file = $this->dir.'/'.$name;
        if (!file_exists($file)) {
            return null;
        }
        $text = @file_get_contents($file);

        return false === $text ? throw new \RuntimeException("Cannot read $file.") : $text;
    }

    private function write(string $name, string $content): void
    {
        $fs = new Filesystem();
        // Through a unique temp file and a rename: a check never reads half a
        // file. Throws when the write fails. Readable by the web and CLI users.
        $fs->dumpFile($this->dir.'/'.$name, $content);
        $fs->chmod($this->dir.'/'.$name, 0644);
    }
}
