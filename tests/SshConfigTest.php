<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\SshConfig;
use PHPUnit\Framework\TestCase;
use ReactphpX\Framework\Environment;

final class SshConfigTest extends TestCase
{
    public function testResolveIdentityFromDefaultConfig(): void
    {
        $directory = sys_get_temp_dir() . '/web-ssh-config-' . uniqid('', true);
        mkdir($directory . '/config', 0777, true);

        $key = tempnam($directory, 'id_ed25519-');
        self::assertNotFalse($key);
        file_put_contents($key, 'test-key');

        file_put_contents($directory . '/config/ssh.php', <<<PHP
<?php
return [
    'default_identity' => '$key',
    'identity_candidates' => [],
];
PHP);

        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        $environment = Environment::load($directory, '.env');
        $config = SshConfig::load($environment);

        self::assertSame($key, $config->resolveIdentity(null));

        @unlink($key);
        @unlink($directory . '/config/ssh.php');
        @rmdir($directory . '/config');
        @rmdir($directory);
    }

    public function testExpandPathReplacesHomePrefix(): void
    {
        putenv('HOME=/tmp/web-ssh-home');
        $_ENV['HOME'] = '/tmp/web-ssh-home';

        self::assertSame('/tmp/web-ssh-home/.ssh/id_rsa', SshConfig::expandPath('~/.ssh/id_rsa'));
    }

    public function testConnectTimeoutFromEnvironment(): void
    {
        $directory = sys_get_temp_dir() . '/web-ssh-config-' . uniqid('', true);
        mkdir($directory . '/config', 0777, true);

        file_put_contents($directory . '/config/ssh.php', <<<'PHP'
<?php
return [
    'default_identity' => null,
    'identity_candidates' => [],
    'connect_timeout' => 10,
];
PHP);

        putenv('APP_ENV=testing');
        putenv('SSH_CONNECT_TIMEOUT=15');
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['SSH_CONNECT_TIMEOUT'] = '15';
        $_SERVER['APP_ENV'] = 'testing';
        $_SERVER['SSH_CONNECT_TIMEOUT'] = '15';

        $environment = Environment::load($directory, '.env');
        $config = SshConfig::load($environment);

        self::assertSame(15.0, $config->connectTimeout());

        putenv('SSH_CONNECT_TIMEOUT');
        unset($_ENV['SSH_CONNECT_TIMEOUT'], $_SERVER['SSH_CONNECT_TIMEOUT']);

        @unlink($directory . '/config/ssh.php');
        @rmdir($directory . '/config');
        @rmdir($directory);
    }
}
