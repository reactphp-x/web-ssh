#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Ssh\SshAskpass;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "autoload not found\n");
    exit(1);
}

require $autoload;

$mapFile = getenv('WEBSSH_ASKPASS_MAP') ?: '';
if ($mapFile === '' || !is_readable($mapFile)) {
    exit(1);
}

$decoded = json_decode((string) file_get_contents($mapFile), true);
if (!is_array($decoded)) {
    exit(1);
}

fwrite(STDOUT, SshAskpass::secretForPrompt($decoded, $argv[1] ?? ''));
