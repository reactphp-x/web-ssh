<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\TwoFactorRepository;
use App\Repository\TwoFactorSessionRepository;
use App\Security\TwoFactorCookie;
use App\Service\AuthRateLimiter;
use App\Service\TwoFactorService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class TwoFactorController
{
    private const SESSION_TTL = 43200;

    public function __construct(
        private readonly TwoFactorRepository $twoFactor,
        private readonly TwoFactorSessionRepository $sessions,
        private readonly TwoFactorService $service,
        private readonly AuthRateLimiter $rateLimiter,
    ) {
    }

    public function status(ServerRequestInterface $request): PromiseInterface
    {
        $username = RequestAuth::username($request);
        $token = TwoFactorCookie::read($request);

        return $this->twoFactor
            ->findByUsername($username)
            ->then(function (?array $record) use ($token, $username): PromiseInterface {
                if ($record === null) {
                    return resolve(JsonResponse::json([
                        'configured' => false,
                        'verified' => false,
                    ]));
                }

                if ($token === null) {
                    return resolve(JsonResponse::json([
                        'configured' => true,
                        'verified' => false,
                        'label' => $record['label'],
                    ]));
                }

                return $this->sessions
                    ->findValid($token, $username)
                    ->then(static fn (?array $session) => JsonResponse::json([
                        'configured' => true,
                        'verified' => $session !== null,
                        'label' => $record['label'],
                    ]));
            });
    }

    public function setup(ServerRequestInterface $request): PromiseInterface
    {
        $username = RequestAuth::username($request);
        $input = HttpJson::body($request);
        $label = trim((string) ($input['label'] ?? ''));

        if ($label === '') {
            return resolve(JsonResponse::error('请输入双因子验证名称。'));
        }

        if (strlen($label) > 120) {
            return resolve(JsonResponse::error('名称过长，请控制在 120 个字符以内。'));
        }

        return $this->twoFactor
            ->findByUsername($username)
            ->then(function (?array $existing) use ($username, $label): PromiseInterface {
                if ($existing !== null) {
                    return resolve(JsonResponse::error('双因子验证已设置，无需重复创建。'));
                }

                $secret = $this->service->createSecret();
                $qrLabel = $username . ':' . $label;

                return $this->twoFactor
                    ->savePending($username, $label, $secret)
                    ->then(fn () => JsonResponse::json([
                        'label' => $label,
                        'qr_code' => $this->service->qrCodeDataUri($qrLabel, $secret),
                        'message' => '请使用验证器应用扫描二维码，然后输入 6 位验证码完成绑定。',
                    ]));
            });
    }

    public function confirm(ServerRequestInterface $request): PromiseInterface
    {
        $username = RequestAuth::username($request);
        $input = HttpJson::body($request);
        $code = trim((string) ($input['code'] ?? ''));

        if ($code === '') {
            return resolve(JsonResponse::error('请输入验证码。'));
        }

        return $this->guardRateLimit($username)->then(function (?ResponseInterface $blocked) use ($username, $code, $request): PromiseInterface {
            if ($blocked !== null) {
                return resolve($blocked);
            }

            return $this->twoFactor
                ->findPendingByUsername($username)
                ->then(function (?array $pending) use ($username, $code, $request): PromiseInterface {
                    if ($pending === null) {
                        return resolve(JsonResponse::error('未找到待绑定的双因子验证，请重新创建。'));
                    }

                    $secret = $this->twoFactor->decryptSecret($pending['secret']);
                    if (!$this->service->verifyCode($secret, $code)) {
                        return $this->rateLimitFailure($username, '验证码无效，请重试。');
                    }

                    return $this->twoFactor
                        ->confirm($username, $pending['label'], $secret)
                        ->then(fn () => $this->createVerifiedResponse($request, $username, '双因子验证绑定成功。'));
                });
        });
    }

    public function verify(ServerRequestInterface $request): PromiseInterface
    {
        $username = RequestAuth::username($request);
        $input = HttpJson::body($request);
        $code = trim((string) ($input['code'] ?? ''));

        if ($code === '') {
            return resolve(JsonResponse::error('请输入验证码。'));
        }

        return $this->guardRateLimit($username)->then(function (?ResponseInterface $blocked) use ($username, $code, $request): PromiseInterface {
            if ($blocked !== null) {
                return resolve($blocked);
            }

            return $this->twoFactor
                ->findByUsername($username)
                ->then(function (?array $record) use ($username, $code, $request): PromiseInterface {
                    if ($record === null) {
                        return resolve(JsonResponse::error('尚未设置双因子验证，请先完成绑定。'));
                    }

                    $secret = $this->twoFactor->decryptSecret($record['secret']);
                    if (!$this->service->verifyCode($secret, $code)) {
                        return $this->rateLimitFailure($username, '验证码无效，请重试。');
                    }

                    return $this->createVerifiedResponse($request, $username, '双因子验证通过。');
                });
        });
    }

    private function guardRateLimit(string $username): PromiseInterface
    {
        return $this->rateLimiter
            ->ensureAllowed($this->rateBucket($username))
            ->then(static function (array $check): ?ResponseInterface {
                if ($check['allowed']) {
                    return null;
                }

                return JsonResponse::error((string) $check['message'], 429);
            });
    }

    private function rateLimitFailure(string $username, string $message): PromiseInterface
    {
        return $this->rateLimiter
            ->recordFailure($this->rateBucket($username))
            ->then(static fn () => JsonResponse::error($message));
    }

    private function createVerifiedResponse(
        ServerRequestInterface $request,
        string $username,
        string $message,
    ): PromiseInterface {
        $token = TwoFactorCookie::generateToken();

        return $this->sessions
            ->deleteByUsername($username)
            ->then(fn () => $this->sessions->purgeExpired())
            ->then(fn () => $this->sessions->create($username, $token, self::SESSION_TTL))
            ->then(fn () => $this->rateLimiter->clear($this->rateBucket($username)))
            ->then(static fn () => TwoFactorCookie::attach(
                JsonResponse::json(['message' => $message, 'verified' => true]),
                $token,
            ));
    }

    private function rateBucket(string $username): string
    {
        return '2fa:' . $username;
    }
}
