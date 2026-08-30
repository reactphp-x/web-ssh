<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\RequestAuth;
use App\Security\BasicAuthCookie;
use App\Service\AuthRateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class BasicAuthHandler
{
    /** @param list<string> $publicPaths */
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $realm = 'Web SSH',
        private readonly array $publicPaths = ['/health'],
        private readonly ?AuthRateLimiter $loginRateLimiter = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface|PromiseInterface
    {
        if ($this->isPublicPath($request->getUri()->getPath())) {
            return $next($request);
        }

        $credentials = $this->parseBasicCredentials($request);
        if ($credentials !== null) {
            if (!$this->checkCredentials($credentials[0], $credentials[1])) {
                if ($this->loginRateLimiter !== null) {
                    return $this->rejectWithLoginRateLimit($request);
                }

                if ($this->shouldRedirectToLogin($request)) {
                    return new Response(302, ['Location' => '/login'], '');
                }

                return $this->unauthorizedResponse();
            }

            return $this->acceptAuthenticated($request, $next, $credentials[0]);
        }

        $cookieUser = BasicAuthCookie::read($request);
        if ($cookieUser !== null && hash_equals($this->username, $cookieUser)) {
            return $this->acceptAuthenticated($request, $next, $cookieUser);
        }

        if ($this->shouldRedirectToLogin($request)) {
            return new Response(302, ['Location' => '/login'], '');
        }

        return $this->unauthorizedResponse();
    }

    private function rejectWithLoginRateLimit(ServerRequestInterface $request): PromiseInterface
    {
        $bucket = $this->loginBucket($request);

        return $this->loginRateLimiter
            ->ensureAllowed($bucket)
            ->then(function (array $check) use ($request, $bucket): ResponseInterface|PromiseInterface {
                if (!$check['allowed']) {
                    return new Response(429, ['Content-Type' => 'text/plain; charset=utf-8'], (string) $check['message']);
                }

                return $this->loginRateLimiter
                    ->recordFailure($bucket)
                    ->then(fn () => $this->shouldRedirectToLogin($request)
                        ? new Response(302, ['Location' => '/login'], '')
                        : $this->unauthorizedResponse());
            });
    }

    private function loginBucket(ServerRequestInterface $request): string
    {
        return 'login:' . RequestAuth::clientIp($request);
    }

    private function acceptAuthenticated(
        ServerRequestInterface $request,
        callable $next,
        string $username,
    ): ResponseInterface|PromiseInterface {
        return $next($request->withAttribute('auth_user', $username));
    }

    private function unauthorizedResponse(): Response
    {
        return new Response(
            401,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'WWW-Authenticate' => 'Basic realm="' . $this->realm . '"',
            ],
            'Unauthorized',
        );
    }

    private function isPublicPath(string $path): bool
    {
        return in_array($path, $this->publicPaths, true);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseBasicCredentials(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        return explode(':', $decoded, 2);
    }

    private function checkCredentials(string $username, string $password): bool
    {
        return hash_equals($this->username, $username) && hash_equals($this->password, $password);
    }

    private function shouldRedirectToLogin(ServerRequestInterface $request): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        $path = $request->getUri()->getPath();
        if ($path === '/login' || str_starts_with($path, '/api/')) {
            return false;
        }

        if ($request->getHeaderLine('Authorization') !== '') {
            return false;
        }

        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
            return false;
        }

        return true;
    }
}
