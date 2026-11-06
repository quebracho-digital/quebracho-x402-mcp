<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * OFAC's Specially Designated Nationals list, as the digital currency addresses it names. On by default:
 * every AddressCheck (the payer when we sell, payTo when we buy) asks it first, with or without a PSR-14
 * dispatcher, and a listed address is rejected with `address_sanctioned` before any other listener runs.
 *
 * The source is OFAC's own Sanctions List Service (SDN.CSV, https://ofac.treasury.gov/sanctions-list-service),
 * never a third-party mirror. Each "Digital Currency Address - <CUR> <address>" in the remarks is kept, every
 * currency included. A 0x… address (an EVM key) is matched in any case on every EVM network, since the same
 * key holds it on all of them; any other form (Bitcoin, Tron, Solana…) is matched exactly.
 *
 * Two snapshots, the newer one wins:
 * - the one shipped with this library (resources/ofac-sdn.json), so the check works offline and from the
 *   first request, as of the library's release;
 * - the site's own, written by sync() from a cron (daily is enough: OFAC publishes a few times a week).
 *   Without it the list is as old as the installed release: sync it.
 *
 * Fails closed: a snapshot that cannot be read throws, and AddressCheck turns that into a rejection.
 *
 * Not legal advice: this screens the addresses OFAC publishes, nothing else (no names, no countries, no
 * 50 % rule). A seller with obligations of its own adds what they require as AddressCheck listeners.
 */
final class OfacSdn
{
    public const string URL = 'https://sanctionslistservice.ofac.treas.gov/api/PublicationPreview/exports/SDN.CSV';
    public const string REASON = 'address_sanctioned';
    /** The SDN.CSV of 2026-11 is 5.8 MB; a larger answer is not that file. */
    private const int MAX_BYTES = 64 * 1024 * 1024;
    /** The list of 2026-11 names over 500 addresses: a parse that finds fewer read the wrong file. */
    private const int MIN_ADDRESSES = 100;
    private const int MAX_REDIRECTS = 3;
    private const string BUNDLED = __DIR__.'/../resources/ofac-sdn.json';

    private static ?self $bundled = null;

    /** @var ?array<string, true> normalized address => true, loaded on first use */
    private ?array $index = null;
    private ?string $published = null;

    /**
     * @param ?string $snapshot the file sync() writes; NULL (or a file not written yet) uses the bundled one only
     */
    public function __construct(private readonly ?string $snapshot = null)
    {
    }

    /** The list shipped with the library, shared by every check that was given none. */
    public static function bundled(): self
    {
        return self::$bundled ??= new self();
    }

    /**
     * The AddressCheck listener: rejects a listed address. Also callable on its own.
     */
    public function __invoke(AddressCheck $check): void
    {
        if ($this->listed($check->address)) {
            $check->reject(self::REASON);
        }
    }

    public function listed(string $address): bool
    {
        return isset($this->index()[self::normalize($address)]);
    }

    /** When OFAC published the list in use (its Last-Modified, or the bundled file's date). */
    public function published(): string
    {
        $this->index();

        return (string) $this->published;
    }

    /**
     * Downloads SDN.CSV from OFAC, parses it, and replaces $file only when the parse looks like the real list.
     * Follows OFAC's redirects (to its signed download URL) itself, HTTPS only, so a client that does not
     * follow redirects works too.
     *
     * @return int the number of addresses written
     *
     * @throws \RuntimeException when the download or the parse fails; $file is left as it was
     */
    public static function sync(ClientInterface $http, RequestFactoryInterface $requests, string $file): int
    {
        $url = self::URL;
        for ($hops = 0;; ++$hops) {
            $response = $http->sendRequest($requests->createRequest('GET', $url));
            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400) {
                break;
            }
            $url = $response->getHeaderLine('Location');
            if ($hops >= self::MAX_REDIRECTS || !str_starts_with($url, 'https://')) {
                throw new \RuntimeException("OFAC SDN: redirect to '$url' refused.");
            }
        }
        if (200 !== $status) {
            throw new \RuntimeException("OFAC SDN: HTTP $status.");
        }
        $body = $response->getBody();
        $csv = '';
        while (!$body->eof()) {
            $csv .= $body->read(1 << 20);
            if (\strlen($csv) > self::MAX_BYTES) {
                throw new \RuntimeException('OFAC SDN: answer over '.self::MAX_BYTES.' bytes.');
            }
        }
        $addresses = self::parse($csv);
        $count = array_sum(array_map('count', $addresses));
        if ($count < self::MIN_ADDRESSES) {
            throw new \RuntimeException("OFAC SDN: only $count addresses parsed; the file was kept.");
        }
        $published = $response->getHeaderLine('Last-Modified');
        $json = json_encode([
            'source' => self::URL,
            'published' => '' !== $published ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($published)) : gmdate('Y-m-d\TH:i:s\Z'),
            'addresses' => $addresses,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === @file_put_contents($tmp, $json."\n") || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("OFAC SDN: cannot write $file.");
        }

        return $count;
    }

    /**
     * The digital currency addresses in an SDN.CSV, by currency, sorted.
     *
     * @return array<string, list<string>>
     */
    public static function parse(string $csv): array
    {
        $in = fopen('php://memory', 'r+');
        if (false === $in) {
            throw new \RuntimeException('OFAC SDN: no memory stream.');
        }
        fwrite($in, $csv);
        rewind($in);
        $found = [];
        // The remarks are the 12th column; a remark may span lines inside its quotes.
        while (false !== ($row = fgetcsv($in, null, ',', '"', ''))) {
            $remarks = (string) ($row[11] ?? '');
            if (preg_match_all('/Digital Currency Address - ([A-Z0-9]+)\s+([^\s;]+)/', $remarks, $m, \PREG_SET_ORDER)) {
                foreach ($m as [, $currency, $address]) {
                    $found[$currency][rtrim($address, '.')] = true;
                }
            }
        }
        fclose($in);
        ksort($found);

        return array_map(static function (array $a): array {
            $list = array_map('strval', array_keys($a));
            sort($list);

            return $list;
        }, $found);
    }

    /** A 0x… EVM address in lower case (EIP-55 case is a checksum, not part of the key); others as they are. */
    private static function normalize(string $address): string
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $address) ? strtolower($address) : $address;
    }

    /** @return array<string, true> */
    private function index(): array
    {
        if (null !== $this->index) {
            return $this->index;
        }
        $data = self::read(self::BUNDLED);
        if (null !== $this->snapshot && is_file($this->snapshot)) {
            $own = self::read($this->snapshot);
            if ($own['published'] >= $data['published']) {
                $data = $own;
            }
        }
        $index = [];
        foreach ($data['addresses'] as $list) {
            foreach ($list as $address) {
                $index[self::normalize($address)] = true;
            }
        }
        $this->published = $data['published'];

        return $this->index = $index;
    }

    /** @return array{published: string, addresses: array<string, list<string>>} */
    private static function read(string $file): array
    {
        $text = @file_get_contents($file);
        $data = false === $text ? null : json_decode($text, true);
        if (!\is_array($data) || !\is_string($data['published'] ?? null) || !\is_array($data['addresses'] ?? null)) {
            throw new \RuntimeException("OFAC SDN: $file is not a snapshot.");
        }
        $addresses = [];
        foreach ($data['addresses'] as $currency => $list) {
            if (!\is_array($list)) {
                throw new \RuntimeException("OFAC SDN: $file is not a snapshot.");
            }
            $addresses[(string) $currency] = array_values(array_filter($list, 'is_string'));
        }

        return ['published' => $data['published'], 'addresses' => $addresses];
    }
}
