<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Job\Job;
use Quebracho\X402\Job\JobDelivery as LibJobDelivery;
use Quebracho\X402\Job\JobJanitor;
use Quebracho\X402\Job\JobReconciler;
use Quebracho\X402\Job\JobStore;

/**
 * Adapter: wraps the library's JobDelivery and adapts Symfony-specific SensitiveRules
 * to the library's closure-based constructor.
 */
final class JobDelivery
{
    /** The library instance, accessible by JobDesk for construction. */
    public readonly LibJobDelivery $lib;

    public function __construct(
        private readonly JobStore $jobs,
        private readonly SensitiveRules $sensitiveRules,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'app.mcp_tools')] private readonly array $tools,
        private readonly JobReconciler $reconciler,
        private readonly JobJanitor $janitor,
        private readonly ?\Closure $clock = null,
    ) {
        $roles = self::extractRoles($tools);
        $sensitive = fn(array $params) => $sensitiveRules->marks($params['name'], $params['arguments'] ?? []);
        $this->lib = new LibJobDelivery($jobs, $roles, $reconciler, $janitor, $sensitive, $clock, []);
    }

    public function payer(array $params, string $from): ?string { return $this->lib->payer($params, $from); }
    public function deliver(string $jobId): Job { return $this->lib->deliver($jobId); }
    public function beforeSettle(array $req): ?array { return $this->lib->beforeSettle($req); }
    public function afterSettle(bool $moved, bool $nothing): void { $this->lib->afterSettle($moved, $nothing); }
    public function afterResponse(): void { $this->lib->afterResponse(); }
    public function sensitive(array $params): bool { return $this->lib->sensitive($params); }
    public function status(string $jobId, string $payer): array { return $this->lib->status($jobId, $payer); }
    public function reset(): void { $this->lib->reset(); }

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
