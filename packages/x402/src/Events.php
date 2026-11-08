<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches an event that only reports (a log line, a metric): a listener that throws must never change a
 * payment, so its error is dropped here.
 *
 * @internal
 */
final class Events
{
    public static function report(?EventDispatcherInterface $events, object $event): void
    {
        try {
            $events?->dispatch($event);
        } catch (\Throwable) {
            // ponytail: nowhere to report a broken reporter; the payment goes on as if it had listened.
        }
    }
}
