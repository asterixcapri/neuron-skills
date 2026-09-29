<?php

declare(strict_types=1);

$curlLoaded = extension_loaded('curl');
$jsonLoaded = extension_loaded('json');
$procOpenAvailable = function_exists('proc_open');

echo 'PHP_VERSION='.PHP_VERSION.PHP_EOL;
echo 'CURL_EXTENSION='.($curlLoaded ? 'loaded' : 'missing').PHP_EOL;
echo 'JSON_EXTENSION='.($jsonLoaded ? 'loaded' : 'missing').PHP_EOL;
echo 'PROC_OPEN='.($procOpenAvailable ? 'available' : 'unavailable').PHP_EOL;

$checksPassed = PHP_VERSION_ID >= 80100
    && $curlLoaded
    && $jsonLoaded
    && $procOpenAvailable;

exit($checksPassed ? 0 : 1);
