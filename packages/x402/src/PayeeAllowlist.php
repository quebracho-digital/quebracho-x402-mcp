<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * An AddressCheck listener for the buyer: pays only the wallets listed. Default deny: an empty list pays nobody,
 * so a seller (or a listing a prompt-injected agent picked) cannot name its own wallet. Recommended for any
 * buyer that pays known sellers; subscribe it to AddressCheck on the dispatcher given to PayingHttpClient or
 * PayingMcpClient.
 *
 * Seller side (role payer) it does nothing. OFAC is asked first and always, so it narrows, it never widens:
 * a listed wallet that is sanctioned is still refused. A 0x… address matches in any case; any other form
 * (Solana's base58 has case) exactly.
 */
final class PayeeAllowlist
{
    public const string REASON = 'payto_not_allowed';

    /** @var array<string, true> */
    private readonly array $allowed;

    /**
     * @param iterable<mixed> $allowed the payTo addresses; blanks and non-strings are skipped
     */
    public function __construct(iterable $allowed)
    {
        $own = [];
        foreach ($allowed as $address) {
            if (\is_string($address) && '' !== trim($address)) {
                $own[self::normalize(trim($address))] = true;
            }
        }
        $this->allowed = $own;
    }

    /**
     * From a comma-separated list (an environment variable).
     */
    public static function fromCsv(string $allowed): self
    {
        return new self(explode(',', $allowed));
    }

    public function __invoke(AddressCheck $check): void
    {
        if (AddressCheck::PAY_TO === $check->role && !isset($this->allowed[self::normalize($check->address)])) {
            $check->reject(self::REASON);
        }
    }

    private static function normalize(string $address): string
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $address) ? strtolower($address) : $address;
    }
}
