<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// APP_ENV=test (phpunit.dist.xml): the committed .env, never .env.local.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
