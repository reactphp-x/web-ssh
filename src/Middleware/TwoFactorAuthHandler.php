<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\AuthSessionConfig;
use App\Http\JsonResponse;
use App\Http\RequestAuth;
use App\Repository\TwoFactorRepository;
use App\Repository\TwoFactorSessionRepository;
use App\Security\SessionRenewal;
use App\Security\TwoFactorCookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class TwoFactorAuthHandler
{
    /** @param list<string> $exemptPaths */
    public function __construct(
        private readonly TwoFactorRepository $twoFactor,
        private readonly TwoFactorSessionRepository $sessions,
        private readonly array $exemptPaths = [],
        private readonly ?AuthSessionConfig $sessionConfig = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface|PromiseInterface
    {
        $path = $request->getUri()->getPath();
        if ($this->isPublicPath($path)) {
            return $next($request);
        }

        $username = RequestAuth::username($request);
        if ($username === 'anonymous') {
            return $next($request);
        }

        $token = TwoFactorCookie::read($request);
        if ($token === null) {
            if ($this->allowsWithoutSession($path)) {
                return $next($request);
            }

            return $this->deny($username);
        }

        return $this->sessions
            ->findValid($token, $username)
            ->then(function (?array $session) use ($request, $next, $path, $username, $token): ResponseInterface|PromiseInterface {
                if ($session === null) {
                    if ($this->allowsWithoutSession($path)) {
                        return $next($request);
                    }

                    return $this->deny($username);
                }

                $shouldRenew = $this->sessionConfig !== null
                    && SessionRenewal::shouldRenew(
                        strtotime($session['expires_at']),
                        $this->sessionConfig->ttl(),
                        $this->sessionConfig->renewInterval(),
                    );

                $renewPromise = $shouldRenew
                    ? $this->sessions->extend($token, $username, $this->sessionConfig->ttl())
                    : resolve(null);

                return $renewPromise->then(
                    function () use ($request, $next, $token, $shouldRenew): ResponseInterface|PromiseInterface {
                        $responsePromise = resolve($next($request->withAttribute('2fa_verified', true)));

                        if (!$shouldRenew) {
                            return $responsePromise;
                        }

                        return $responsePromise->then(
                            static fn (ResponseInterface $response): ResponseInterface => TwoFactorCookie::attach($response, $token),
                        );
                    },
                );
            });
    }

    private function deny(string $username): PromiseInterface
    {
        return $this->twoFactor
            ->findByUsername($username)
            ->then(static fn (?array $record) => JsonResponse::error(
                $record === null ? '请先完成双因子验证设置。' : '请先完成双因子验证。',
                403,
                [
                    'code' => 'two_factor_required',
                    'configured' => $record !== null,
                ],
            ));
    }

    private function isPublicPath(string $path): bool
    {
        if (in_array($path, $this->exemptPaths, true)) {
            return true;
        }

        if ($path === '/' || $path === '/app.js' || $path === '/logout' || $path === '/login') {
            return true;
        }

        return str_starts_with($path, '/vendor/');
    }

    private function allowsWithoutSession(string $path): bool
    {
        if (str_starts_with($path, '/api/2fa/')) {
            return true;
        }

        return $path === '/api/me' || $path === '/api/logout';
    }
}
