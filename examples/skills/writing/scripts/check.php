<?php

declare(strict_types=1);

$template = file_get_contents(__DIR__.'/../assets/sample.txt');
if ($template === false) {
    throw new RuntimeException('Could not read the neighboring sample asset.');
}

echo 'Sample: '.trim($template);
