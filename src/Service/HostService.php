<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\SshConfig;
use App\Repository\HostRepository;
use App\Security\SecretCipher;
use App\Ssh\JumpHostChain;
use App\Ssh\SshTarget;
use InvalidArgumentException;
use React\Promise\PromiseInterface;
use RuntimeException;
use function React\Promise\reject;
use function React\Promise\resolve;

final class HostService
{
    public function __construct(
        private readonly HostRepository $hosts,
        private readonly SecretCipher $cipher,
        private readonly SshConfig $sshConfig,
    ) {
    }

    /**
     * @return list<string>
     */
    public function suggestedKeyPaths(): array
    {
        return $this->sshConfig->suggestedKeyPaths();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function validateInput(array $input, bool $isUpdate = false, ?int $currentHostId = null): PromiseInterface
    {
        $name = trim((string) ($input['name'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $port = (int) ($input['port'] ?? 22);
        $username = trim((string) ($input['username'] ?? ''));
        $authType = (string) ($input['auth_type'] ?? 'password');
        $keySource = (string) ($input['private_key_source'] ?? 'path');

        if ($name === '') {
            return resolve(['valid' => false, 'message' => '主机名称不能为空。']);
        }

        if (!$isUpdate && $address === '') {
            return resolve(['valid' => false, 'message' => '主机地址不能为空。']);
        }

        if ($username === '') {
            return resolve(['valid' => false, 'message' => 'SSH 用户名不能为空。']);
        }

        if ($port < 1 || $port > 65535) {
            return resolve(['valid' => false, 'message' => '端口范围为 1-65535。']);
        }

        if (!in_array($authType, ['password', 'private_key'], true)) {
            return resolve(['valid' => false, 'message' => '认证方式无效。']);
        }

        if ($authType === 'password' && trim((string) ($input['password'] ?? '')) === '' && !($input['keep_secret'] ?? false)) {
            return resolve(['valid' => false, 'message' => '密码认证需要填写密码。']);
        }

        if ($authType === 'private_key') {
            if (!in_array($keySource, ['path', 'pem'], true)) {
                return resolve(['valid' => false, 'message' => '私钥来源无效。']);
            }

            if ($keySource === 'path') {
                $path = trim((string) ($input['private_key_path'] ?? ''));
                if ($path === '' && !($input['keep_secret'] ?? false)) {
                    return resolve(['valid' => false, 'message' => '请选择或填写私钥路径。']);
                }

                if ($path !== '') {
                    try {
                        $expanded = SshConfig::expandPath($path);
                    } catch (InvalidArgumentException $exception) {
                        return resolve(['valid' => false, 'message' => $exception->getMessage()]);
                    }

                    if (!is_readable($expanded)) {
                        return resolve(['valid' => false, 'message' => '私钥路径不可读: ' . $path]);
                    }
                }
            } elseif (trim((string) ($input['private_key'] ?? '')) === '' && !($input['keep_secret'] ?? false)) {
                return resolve(['valid' => false, 'message' => '请粘贴或上传私钥 PEM 内容。']);
            } elseif (trim((string) ($input['private_key'] ?? '')) !== '') {
                $key = trim((string) $input['private_key']);
                if (!str_contains($key, 'PRIVATE KEY')) {
                    return resolve(['valid' => false, 'message' => '私钥格式不正确，仅支持 PEM 格式。']);
                }
            }
        }

        return $this->validateJumpHost($input, $currentHostId);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function buildPersistPayload(array $input, string $createdBy, ?array $existing = null): array
    {
        $authType = (string) ($input['auth_type'] ?? ($existing['auth_type'] ?? 'password'));
        $authTypeChanged = $existing !== null && (string) ($existing['auth_type'] ?? '') !== $authType;
        $keySource = (string) ($input['private_key_source'] ?? ($existing['private_key_source'] ?? 'path'));

        if ($authType === 'password') {
            $secret = (string) ($input['password'] ?? '');
        } elseif ($keySource === 'path') {
            $secret = trim((string) ($input['private_key_path'] ?? ''));
        } else {
            $secret = (string) ($input['private_key'] ?? '');
        }

        if ($secret === '' && $existing !== null && !$authTypeChanged) {
            $encryptedSecret = $this->reencryptExistingSecret($existing, $authType);
        } else {
            if ($secret === '') {
                throw new InvalidArgumentException('认证凭据不能为空。');
            }
            $encryptedSecret = $this->cipher->encrypt($secret);
        }

        $passphrase = (string) ($input['passphrase'] ?? '');
        if ($passphrase === '' && $existing !== null && $authType === 'private_key' && !$authTypeChanged) {
            $encryptedPassphrase = ($existing['passphrase'] ?? null) !== null && $existing['passphrase'] !== ''
                ? $this->cipher->encrypt((string) $existing['passphrase'])
                : null;
        } else {
            $encryptedPassphrase = $passphrase !== '' ? $this->cipher->encrypt($passphrase) : null;
        }

        $jumpHostId = array_key_exists('jump_host_id', $input)
            ? $this->nullableInt($input['jump_host_id'])
            : $this->nullableInt($existing['jump_host_id'] ?? null);

        return [
            'name' => trim((string) ($input['name'] ?? $existing['name'] ?? '')),
            'address' => trim((string) ($input['address'] ?? $existing['address'] ?? '')),
            'port' => (int) ($input['port'] ?? $existing['port'] ?? 22),
            'username' => trim((string) ($input['username'] ?? $existing['username'] ?? '')),
            'auth_type' => $authType,
            'private_key_source' => $authType === 'private_key' ? $keySource : 'path',
            'encrypted_secret' => $encryptedSecret,
            'encrypted_passphrase' => $encryptedPassphrase,
            'jump_host_id' => $jumpHostId,
            'group_id' => $this->nullableInt($input['group_id'] ?? $existing['group_id'] ?? null),
            'tags' => trim((string) ($input['tags'] ?? $existing['tags'] ?? '')),
            'remark' => trim((string) ($input['remark'] ?? $existing['remark'] ?? '')),
            'created_by' => $createdBy,
        ];
    }

    /**
     * @param array<string, mixed> $host
     */
    public function toSshTarget(array $host, ?SshTarget $jump = null): SshTarget
    {
        if (($host['auth_type'] ?? '') === 'password') {
            return new SshTarget(
                host: (string) $host['address'],
                port: (int) $host['port'],
                user: (string) $host['username'],
                password: (string) ($host['password'] ?? ''),
                jump: $jump,
            );
        }

        $passphrase = (string) ($host['passphrase'] ?? '');
        if (($host['private_key_source'] ?? 'path') === 'path') {
            $path = SshConfig::expandPath((string) ($host['private_key_path'] ?? ''));

            return new SshTarget(
                host: (string) $host['address'],
                port: (int) $host['port'],
                user: (string) $host['username'],
                password: $passphrase !== '' ? $passphrase : null,
                identityFile: $path,
                jump: $jump,
            );
        }

        return new SshTarget(
            host: (string) $host['address'],
            port: (int) $host['port'],
            user: (string) $host['username'],
            password: $passphrase !== '' ? $passphrase : null,
            privateKeyContent: (string) ($host['private_key'] ?? ''),
            jump: $jump,
        );
    }

    /**
     * @param array<string, mixed> $host
     *
     * @return PromiseInterface<SshTarget>
     */
    public function resolveSshTarget(array $host, int $depth = 0, array $seen = []): PromiseInterface
    {
        $id = (int) ($host['id'] ?? 0);
        if ($id > 0 && isset($seen[$id])) {
            return reject(new RuntimeException('跳板机链路存在循环。'));
        }
        if ($id > 0) {
            $seen[$id] = true;
        }

        if ($depth > JumpHostChain::MAX_DEPTH) {
            return reject(new RuntimeException('跳板机链路超过最大层级。'));
        }

        $jumpId = $this->nullableInt($host['jump_host_id'] ?? null);
        if ($jumpId === null) {
            return resolve($this->toSshTarget($host));
        }

        return $this->hosts->findById($jumpId)->then(function (?array $jumpHost) use ($host, $depth, $seen): PromiseInterface {
            if ($jumpHost === null) {
                throw new RuntimeException('跳板机不存在或已被删除。');
            }

            return $this->resolveSshTarget($jumpHost, $depth + 1, $seen)->then(
                fn (SshTarget $jump) => $this->toSshTarget($host, $jump),
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function buildTargetFromInput(array $input, ?array $existing = null): SshTarget
    {
        $merged = $existing ?? [];
        foreach (['address', 'port', 'username', 'auth_type', 'private_key_source'] as $field) {
            if (array_key_exists($field, $input)) {
                $merged[$field] = $input[$field];
            }
        }

        if (($merged['auth_type'] ?? '') === 'password') {
            $password = (string) ($input['password'] ?? '');
            if ($password === '' && $existing !== null) {
                $password = (string) ($existing['password'] ?? '');
            }
            $merged['password'] = $password;
        } else {
            $keySource = (string) ($input['private_key_source'] ?? ($existing['private_key_source'] ?? 'path'));
            $merged['private_key_source'] = $keySource;

            $passphrase = (string) ($input['passphrase'] ?? '');
            if ($passphrase === '' && $existing !== null) {
                $passphrase = (string) ($existing['passphrase'] ?? '');
            }
            $merged['passphrase'] = $passphrase;

            if ($keySource === 'path') {
                $path = trim((string) ($input['private_key_path'] ?? ''));
                if ($path === '' && $existing !== null) {
                    $path = (string) ($existing['private_key_path'] ?? '');
                }
                $merged['private_key_path'] = $path;
            } else {
                $privateKey = (string) ($input['private_key'] ?? '');
                if ($privateKey === '' && $existing !== null) {
                    $privateKey = (string) ($existing['private_key'] ?? '');
                }
                $merged['private_key'] = $privateKey;
            }
        }

        $merged['address'] = trim((string) ($input['address'] ?? $merged['address'] ?? ''));
        $merged['port'] = (int) ($input['port'] ?? $merged['port'] ?? 22);
        $merged['username'] = trim((string) ($input['username'] ?? $merged['username'] ?? ''));

        return $this->toSshTarget($merged);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return PromiseInterface<SshTarget>
     */
    public function resolveTargetFromInput(array $input, ?array $existing = null): PromiseInterface
    {
        $target = $this->buildTargetFromInput($input, $existing);
        $jumpId = array_key_exists('jump_host_id', $input)
            ? $this->nullableInt($input['jump_host_id'])
            : $this->nullableInt($existing['jump_host_id'] ?? null);

        if ($jumpId === null) {
            return resolve($target);
        }

        return $this->hosts->findById($jumpId)->then(function (?array $jumpHost) use ($target): PromiseInterface {
            if ($jumpHost === null) {
                throw new InvalidArgumentException('跳板机不存在。');
            }

            return $this->resolveSshTarget($jumpHost)->then(
                fn (SshTarget $jump) => $target->withJump($jump),
            );
        });
    }

    public function repository(): HostRepository
    {
        return $this->hosts;
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function reencryptExistingSecret(array $existing, string $authType): string
    {
        if ($authType === 'password') {
            return $this->cipher->encrypt((string) ($existing['password'] ?? ''));
        }

        if (($existing['private_key_source'] ?? 'pem') === 'path') {
            return $this->cipher->encrypt((string) ($existing['private_key_path'] ?? ''));
        }

        return $this->cipher->encrypt((string) ($existing['private_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return PromiseInterface<array{valid: bool, message?: string}>
     */
    private function validateJumpHost(array $input, ?int $currentHostId): PromiseInterface
    {
        $jumpHostId = $this->nullableInt($input['jump_host_id'] ?? null);
        if ($jumpHostId === null) {
            return resolve(['valid' => true]);
        }

        if ($currentHostId !== null && $jumpHostId === $currentHostId) {
            return resolve(['valid' => false, 'message' => '不能将自己设为跳板机。']);
        }

        return $this->hosts->findPublicById($jumpHostId)->then(function (?array $jump) use ($jumpHostId, $currentHostId): PromiseInterface {
            if ($jump === null) {
                return resolve(['valid' => false, 'message' => '所选跳板机不存在。']);
            }

            return $this->hosts->listJumpMap()->then(function (array $map) use ($jumpHostId, $currentHostId): array {
                if (JumpHostChain::wouldCycle($map, $currentHostId ?? 0, $jumpHostId)) {
                    return ['valid' => false, 'message' => '跳板机链路存在循环或超过最大层级（' . JumpHostChain::MAX_DEPTH . '）。'];
                }

                return ['valid' => true];
            });
        });
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
