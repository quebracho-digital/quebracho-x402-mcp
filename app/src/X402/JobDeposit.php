<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Job\Job;
use Quebracho\X402\Job\JobDeposit as LibJobDeposit;
use Quebracho\X402\Job\JobRunner;
use Quebracho\X402\Job\JobSlots;
use Quebracho\X402\Job\JobStore;

/**
 * Adapter: wraps the library's JobDeposit and adapts Symfony-specific Pricing/SensitiveRules
 * to the library's closure-based constructor.
 */
final class JobDeposit
{
    /** The library instance, accessible by JobDesk for construction. */
    public readonly LibJobDeposit $lib;

    public function __construct(
        private readonly JobStore $jobs,
        private readonly JobRunner $runner,
        private readonly JobSlots $slots,
        private readonly Pricing $pricing,
        private readonly SensitiveRules $sensitiveRules,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'app.mcp_tools')] private readonly array $tools,
        private readonly ?\Closure $clock = null,
    ) {
        $roles = self::extractRoles($tools);
        $price = fn (array $params) => $pricing($params);
        $sensitive = fn (array $params) => $sensitiveRules->marks($params['name'], $params['arguments'] ?? []);
        $this->lib = new LibJobDeposit($jobs, $runner, $slots, $price, $roles, $sensitive, $clock, []);
    }

    public function check(array $params): ?string
    {
        return $this->lib->check($params);
    }

    public function payer(array $params, string $from): ?string
    {
        return $this->lib->payer($params, $from);
    }

    public function beforeSettle(array $payment, array $req): ?array
    {
        return $this->lib->beforeSettle($payment, $req);
    }

    public function afterSettle(bool $nothing): void
    {
        $this->lib->afterSettle($nothing);
    }

    public function queue(string $name, array $args, ?array $payment): Job
    {
        return $this->lib->queue($name, $args, $payment);
    }

    public function afterResponse(): void
    {
        $this->lib->afterResponse();
    }

    public function jobIdFor(array $params): ?string
    {
        return $this->lib->jobIdFor($params);
    }

    public function reset(): void
    {
        $this->lib->reset();
    }

    private static function extractRoles(array $tools): array
    {
        $roles = [];
        foreach ($tools as $name => $t) {
            $j = $t['job'] ?? null;
            if (null !== $j) {
                $roles[$name] = ['role' => $j['role'], 'pairedWith' => $j['pairedWith'], 'price' => $j['price'] ?? null];
            }
        }

        return $roles;
    }
}
