<?php

// For the library's maintainers: refreshes resources/ofac-sdn.json, the OFAC list shipped with each release.
// Sites keep their own copy fresh with a ListSync (OfacSdnSync) from their cron; this file is not for them.
// Run from packages/x402: php bin/update-ofac.php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Quebracho\X402\OfacSdn;

// The service answers with a redirect to a signed download URL; PHP's stream follows it (HTTPS only here).
$context = stream_context_create(['http' => ['follow_location' => 1, 'max_redirects' => 3, 'timeout' => 120, 'user_agent' => 'quebracho-x402 update-ofac']]);
$csv = @file_get_contents(OfacSdn::URL, false, $context);
if (false === $csv) {
    fwrite(STDERR, "Could not download ".OfacSdn::URL."\n");
    exit(1);
}
$lastModified = '';
foreach ($http_response_header ?? [] as $header) {
    if (0 === stripos($header, 'Last-Modified:')) {
        $lastModified = trim(substr($header, 14));
    }
}
$file = __DIR__.'/../resources/ofac-sdn.json';
$result = OfacSdn::write($csv, $lastModified, $file);
echo "$result->count addresses, published $result->updated".($result->replaced ? '' : ' (the file held a younger list: kept)')."\n";
