<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * A list of addresses to refuse: your own, or one published by another site. An AddressCheck listener;
 * it runs after OFAC's list (OfacSdn), for the addresses that list passed.
 *
 * The format is plain text, one address per line (resources/address-list.example.txt):
 *
 *     # comments and blank lines are ignored
 *     0x000000000000000000000000000000000000dEaD  a note after the address is ignored
 *     9xQeWvG816bUx9EPjHmaT23yvVM2ZWbrrpZb9PusVFin
 *
 * A 0x… address is matched in any case on every EVM network; any other form exactly as written.
 *
 * A file you keep by hand is read as it is. A list another site publishes is downloaded with sync() from a
 * cron, to a file this class then reads.
 *
 * Fails closed: a file that does not exist or cannot be read throws, and AddressCheck turns that into a
 * rejection, so a list never synced refuses everyone until it is. An empty file is an empty list.
 */
final class AddressList
{
    /** A list is a few thousand lines; an answer larger than this is not one. */
    private const int MAX_BYTES = 16 * 1024 * 1024;

    /** @var ?array<string, true> */
    private ?array $index = null;

    /**
     * @param list<string> $files  the list files, all of them refused
     * @param string       $reason what a listed address is refused with
     */
    public function __construct(
        private readonly array $files,
        private readonly string $reason = 'address_denied',
    ) {
    }

    public function __invoke(AddressCheck $check): void
    {
        if ($this->listed($check->address)) {
            $check->reject($this->reason);
        }
    }

    public function listed(string $address): bool
    {
        if (null === $this->index) {
            $index = [];
            foreach ($this->files as $file) {
                $text = is_file($file) ? @file_get_contents($file) : false;
                if (false === $text) {
                    throw new \RuntimeException("Address list $file cannot be read.");
                }
                foreach (self::parse($text) as $listed) {
                    $index[$listed] = true;
                }
            }
            $this->index = $index;
        }

        return isset($this->index[self::normalize($address)]);
    }

    /**
     * The addresses in a list, normalized (0x… in lower case), in order, without repeats.
     *
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $found[self::normalize((string) preg_split('/\s/', $line, 2)[0])] = true;
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * Downloads a list another site publishes (HTTPS only, no redirects), checks that it parses into at
     * least $min addresses, and replaces $file with it. On any failure $file is left as it was.
     *
     * @return int the number of addresses written
     *
     * @throws \RuntimeException
     */
    public static function sync(ClientInterface $http, RequestFactoryInterface $requests, string $url, string $file, int $min = 1): int
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException("Address list: $url is not HTTPS.");
        }
        $response = $http->sendRequest($requests->createRequest('GET', $url));
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException("Address list $url: HTTP {$response->getStatusCode()}.");
        }
        $body = $response->getBody();
        $text = '';
        while (!$body->eof()) {
            $text .= $body->read(1 << 20);
            if (\strlen($text) > self::MAX_BYTES) {
                throw new \RuntimeException("Address list $url: over ".self::MAX_BYTES.' bytes.');
            }
        }
        $addresses = self::parse($text);
        if (\count($addresses) < $min) {
            throw new \RuntimeException("Address list $url: ".\count($addresses)." addresses, $min expected; the file was kept.");
        }
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === @file_put_contents($tmp, "# $url, ".gmdate('Y-m-d\TH:i:s\Z')."\n".implode("\n", $addresses)."\n") || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Address list: cannot write $file.");
        }

        return \count($addresses);
    }

    private static function normalize(string $address): string
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $address) ? strtolower($address) : $address;
    }
}
