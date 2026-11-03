<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\AddressCheck;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The buyer pays only the wallets listed in X402_PAYTO_ALLOW (comma
 * separated). Default deny: an empty list pays nobody, so a seller (or a
 * listing a prompt-injected agent picked) cannot name its own wallet.
 * Screening still runs after it: this narrows, it never widens.
 */
#[AsEventListener]
final class PayeeAllowlist
{
    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(#[Autowire(env: 'X402_PAYTO_ALLOW')] string $allow)
    {
        $this->allowed = array_values(array_filter(array_map(static fn (string $a) => strtolower(trim($a)), explode(',', $allow))));
    }

    public function __invoke(AddressCheck $check): void
    {
        if (AddressCheck::PAY_TO === $check->role && !\in_array(strtolower($check->address), $this->allowed, true)) {
            $check->reject('payto_not_allowed');
        }
    }
}
