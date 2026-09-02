<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\TwoFactorSessionRepository;
use App\Security\BasicAuthCookie;
use App\Security\TwoFactorCookie;
use App\Service\AuthRateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class AuthController
{
    public function __construct(
        private readonly ?TwoFactorSessionRepository $sessions,
        private readonly ?string $basicAuthUser = null,
        private readonly ?string $basicAuthPassword = null,
        private readonly ?AuthRateLimiter $loginRateLimiter = null,
        private readonly bool $twoFactorRequired = true,
    ) {
    }

    public function login(ServerRequestInterface $request): PromiseInterface|ResponseInterface
    {
        if ($this->basicAuthUser === null || $this->basicAuthPassword === null) {
            return JsonResponse::error('未启用登录认证。', 404);
        }

        $body = HttpJson::body($request);
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            return JsonResponse::error('请输入用户名和密码。', 422);
        }

        $valid = hash_equals($this->basicAuthUser, $username)
            && hash_equals($this->basicAuthPassword, $password);

        if (!$valid) {
            return $this->rejectLogin($request);
        }

        if ($this->loginRateLimiter === null) {
            return BasicAuthCookie::attach(
                JsonResponse::json(['message' => '登录成功']),
                $username,
            );
        }

        return $this->loginRateLimiter
            ->clear($this->loginBucket($request))
            ->then(static fn () => BasicAuthCookie::attach(
                JsonResponse::json(['message' => '登录成功']),
                $username,
            ));
    }

    public function logout(ServerRequestInterface $request): PromiseInterface|ResponseInterface
    {
        return $this->clearSession($request)->then(static fn () => BasicAuthCookie::clear(
            TwoFactorCookie::clear(
                JsonResponse::json(['message' => '已退出登录']),
            ),
        ));
    }

    public function logoutPage(ServerRequestInterface $request): PromiseInterface|ResponseInterface
    {
        return $this->clearSession($request)->then(static fn () => BasicAuthCookie::clear(
            TwoFactorCookie::clear(
                new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], self::logoutHtml()),
            ),
        ));
    }

    private function clearSession(ServerRequestInterface $request): PromiseInterface
    {
        $token = TwoFactorCookie::read($request);
        if ($token === null || $this->sessions === null) {
            return resolve(null);
        }

        return $this->sessions->deleteByToken($token);
    }

    private function rejectLogin(ServerRequestInterface $request): PromiseInterface|ResponseInterface
    {
        if ($this->loginRateLimiter === null) {
            return JsonResponse::error('用户名或密码错误。', 401);
        }

        $bucket = $this->loginBucket($request);

        return $this->loginRateLimiter
            ->ensureAllowed($bucket)
            ->then(function (array $check) use ($request, $bucket): ResponseInterface|PromiseInterface {
                if (!$check['allowed']) {
                    return JsonResponse::error((string) $check['message'], 429);
                }

                return $this->loginRateLimiter
                    ->recordFailure($bucket)
                    ->then(static fn () => JsonResponse::error('用户名或密码错误。', 401));
            });
    }

    private function loginBucket(ServerRequestInterface $request): string
    {
        return 'login:' . RequestAuth::clientIp($request);
    }

    public function loginPage(): ResponseInterface
    {
        $subtitle = $this->twoFactorRequired
            ? '请输入平台账号密码，登录后还需完成双因子验证。'
            : '请输入平台账号密码。';

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            str_replace('__LOGIN_SUBTITLE__', $subtitle, <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 · Web SSH</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #0f111a;
            color: #e6e6e6;
            font-family: ui-sans-serif, system-ui, sans-serif;
        }
        .card {
            width: min(420px, calc(100% - 48px));
            padding: 32px;
            border-radius: 14px;
            border: 1px solid #2a3142;
            background: #151821;
        }
        h1 { margin: 0 0 8px; font-size: 24px; text-align: center; }
        .sub { margin: 0 0 24px; color: #9aa4b2; font-size: 14px; text-align: center; line-height: 1.6; }
        label { display: block; margin-bottom: 6px; font-size: 13px; color: #c5ced9; }
        input {
            width: 100%;
            box-sizing: border-box;
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #3a4254;
            background: #0f111a;
            color: #f2f2f2;
            font-size: 15px;
        }
        input:focus { outline: none; border-color: #5b8def; }
        button {
            width: 100%;
            padding: 10px 18px;
            border: 0;
            border-radius: 8px;
            background: #5b8def;
            color: #fff;
            font-size: 15px;
            cursor: pointer;
        }
        button:hover { background: #4a7de0; }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        .err { margin-top: 12px; color: #ffb4b4; font-size: 14px; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Web SSH 登录</h1>
        <p class="sub">__LOGIN_SUBTITLE__</p>
        <form id="login-form">
            <label for="username">用户名</label>
            <input id="username" name="username" autocomplete="username" required>
            <label for="password">密码</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit" id="submit-btn">登录</button>
            <div id="error" class="err" hidden></div>
        </form>
    </div>
    <script>
        const form = document.getElementById('login-form');
        const errorEl = document.getElementById('error');
        const submitBtn = document.getElementById('submit-btn');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            errorEl.hidden = true;
            submitBtn.disabled = true;
            submitBtn.textContent = '登录中...';

            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            try {
                const response = await fetch('/api/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password }),
                });
                const data = await response.json().catch(() => ({}));

                if (response.ok) {
                    window.location.replace('/');
                    return;
                }

                if (response.status === 429) {
                    errorEl.textContent = data.message || '尝试次数过多，请稍后再试。';
                } else if (response.status === 401) {
                    errorEl.textContent = '用户名或密码错误。';
                } else {
                    errorEl.textContent = data.message || '登录失败，请稍后重试。';
                }
                errorEl.hidden = false;
            } catch (error) {
                errorEl.textContent = '网络错误，请稍后重试。';
                errorEl.hidden = false;
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = '登录';
            }
        });
    </script>
</body>
</html>
HTML),
        );
    }

    private static function logoutHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>已退出 · Web SSH</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #0f111a;
            color: #e6e6e6;
            font-family: ui-sans-serif, system-ui, sans-serif;
        }
        .card {
            width: min(420px, calc(100% - 48px));
            padding: 32px;
            border-radius: 14px;
            border: 1px solid #2a3142;
            background: #151821;
            text-align: center;
        }
        h1 { margin: 0 0 12px; font-size: 24px; }
        p { margin: 0 0 24px; color: #9aa4b2; line-height: 1.6; }
        a {
            display: inline-block;
            padding: 10px 18px;
            border-radius: 8px;
            background: #5b8def;
            color: #fff;
            text-decoration: none;
        }
        a:hover { background: #4a7de0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>已退出登录</h1>
        <p>登录会话已清除。请点击下方按钮重新登录。</p>
        <a href="/login">重新登录</a>
    </div>
</body>
</html>
HTML;
    }
}
