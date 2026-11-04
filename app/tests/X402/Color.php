<?php

declare(strict_types=1);

namespace App\Tests\X402;

/** An enum argument, for PricingTest's schema checks. */
enum Color: string
{
    case Red = 'red';
    case Blue = 'blue';
}
