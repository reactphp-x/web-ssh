<?php

declare(strict_types=1);

namespace App\Http;

use App\Bootstrap\DatabaseBootstrap;
use App\Config\BasicAuthConfig;
use App\Config\DatabaseConfig;
use App\Config\SshConfig;
use App\Database\SqliteClientFactory;
use App\Http\JsonErrorHandler;
use App\Middleware\TwoFactorAuthHandler;
use App\Repository\AuditLogRepository;
use App\Repository\AuthRateLimitRepository;
use App\Repository\HostGroupRepository;
use App\Repository\HostRepository;
use App\Repository\SessionRepository;
use App\Repository\TwoFactorRepository;
use App\Repository\TwoFactorSessionRepository;
use App\Security\BasicAuthCookie;
use App\Security\SecretCipher;
use App\Security\TwoFactorCookie;
use App\Service\AuditService;
use App\Service\AuthRateLimiter;
use App\Service\HostService;
use App\Service\SessionService;
use App\Service\SshProbeService;
use App\Service\TwoFactorService;
use App\Ssh\Ssh2Client;
use App\Ssh\SshLiveRegistry;
use App\Ssh\SshTerminalGateway;
use FrameworkX\AccessLogHandler;
use FrameworkX\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use ReactphpX\ConnectionGroup\ConnectionGroup;
use ReactphpX\Framework\Environment;
use ReactphpX\WebsocketGroup\WebsocketGroupComponent;
use ReactphpX\WebsocketGroup\WebsocketGroupMiddleware;
use ReactphpX\WebsocketMiddleware\WebsocketMiddleware;

final class WebAppFactory
{
    private static ?ConnectionGroup $connectionGroup = null;

    public static function connectionGroup(): ConnectionGroup
    {
        return self::$connectionGroup ??= new ConnectionGroup();
    }

    public static function create(Environment $env): App
    {
        $connectionGroup = self::connectionGroup();
        $dbConfig = DatabaseConfig::load($env);
        DatabaseBootstrap::start($dbConfig, $env->basePath() . '/database/schema.sql');

        $db = SqliteClientFactory::get($dbConfig);
        $cipher = SecretCipher::fromAppKey($env->string('APP_KEY'));
        $hosts = new HostRepository($db, $cipher);
        $groups = new HostGroupRepository($db);
        $sessions = new SessionRepository($db);
        $auditLogs = new AuditLogRepository($db);
        $twoFactorRepo = new TwoFactorRepository($db, $cipher);
        $twoFactorSessions = new TwoFactorSessionRepository($db);
        $authRateLimits = new AuthRateLimitRepository($db);
        $loginRateLimiter = new AuthRateLimiter($authRateLimits, maxFailures: 10, lockSeconds: 900);
        $twoFactorRateLimiter = new AuthRateLimiter($authRateLimits, maxFailures: 5, lockSeconds: 900);
        TwoFactorCookie::configure(filter_var($env->nullableString('COOKIE_SECURE'), FILTER_VALIDATE_BOOL));
        BasicAuthCookie::configure(
            filter_var($env->nullableString('COOKIE_SECURE'), FILTER_VALIDATE_BOOL),
            $cipher->key(),
        );
        $sshConfig = SshConfig::load($env);
        Ssh2Client::setConnectTimeout($sshConfig->connectTimeout());
        $hostService = new HostService($hosts, $cipher, $sshConfig);
        $audit = new AuditService($auditLogs);
        $sessionService = new SessionService($sessions);
        $probe = new SshProbeService();
        $liveRegistry = new SshLiveRegistry();
        $basicAuth = BasicAuthConfig::load($env, $loginRateLimiter)->handler();
        $twoFactorEnabled = $basicAuth !== null;
        $twoFactorService = $twoFactorEnabled
            ? new TwoFactorService(trim($env->string('BASIC_AUTH_REALM', 'Web SSH')))
            : null;
        $api = new ApiController(
            $hostService,
            $hosts,
            $groups,
            $sessions,
            $auditLogs,
            $audit,
            $sessionService,
            $probe,
            $twoFactorEnabled ? $twoFactorRepo : null,
        );
        $twoFactor = $twoFactorEnabled && $twoFactorService !== null
            ? new TwoFactorController($twoFactorRepo, $twoFactorSessions, $twoFactorService, $twoFactorRateLimiter)
            : null;
        $auth = new AuthController(
            $twoFactorEnabled ? $twoFactorSessions : null,
            $twoFactorEnabled ? trim($env->nullableString('BASIC_AUTH_USER') ?? '') : null,
            $twoFactorEnabled ? ($env->nullableString('BASIC_AUTH_PASSWORD') ?? '') : null,
            $twoFactorEnabled ? $loginRateLimiter : null,
        );
        $live = new LiveController($liveRegistry);

        $gateway = new SshTerminalGateway($connectionGroup, $hostService, $sessionService, $liveRegistry);
        $gateway->register();

        $wsMiddleware = new WebsocketGroupMiddleware($connectionGroup);
        $tokens = self::parseTokens($env->nullableString('WS_RPC_TOKENS'));
        if ($tokens !== []) {
            $wsMiddleware->setTokens($tokens);
        }

        $logManager = configureLogging($env);
        $logger = $logManager->channel();

        $accessLog = $env->nullableString('HTTP_ACCESS_LOG')
            ?? $env->basePath() . '/storage/logs/access.log';

        $middleware = [
            new AccessLogHandler($accessLog),
            new JsonErrorHandler($env, $logger),
        ];

        if ($basicAuth !== null) {
            $middleware[] = $basicAuth;
            $middleware[] = new TwoFactorAuthHandler($twoFactorRepo, $twoFactorSessions);
        }

        $app = new App(...$middleware);
        $publicDir = $env->basePath() . '/public';
        $static = new StaticFileHandler($publicDir);

        $app->get('/', static fn () => $static->serve('index.html'));
        $app->get('/app.js', static fn () => $static->serve('app.js'));
        $app->get('/vendor/{path:.+}', static fn (ServerRequestInterface $request) => $static->serve(
            'vendor/' . (string) $request->getAttribute('path'),
        ));
        $app->get('/health', static fn () => Response::json(['status' => 'ok']));
        $app->get('/logout', static fn (ServerRequestInterface $request) => $auth->logoutPage($request));
        $app->get('/login', static fn () => $auth->loginPage());
        $app->post('/api/login', static fn (ServerRequestInterface $request) => $auth->login($request));
        $app->post('/api/logout', static fn (ServerRequestInterface $request) => $auth->logout($request));

        $app->get('/api/me', static fn (ServerRequestInterface $request) => $api->me($request));
        if ($twoFactor !== null) {
            $app->get('/api/2fa/status', static fn (ServerRequestInterface $request) => $twoFactor->status($request));
            $app->post('/api/2fa/setup', static fn (ServerRequestInterface $request) => $twoFactor->setup($request));
            $app->post('/api/2fa/confirm', static fn (ServerRequestInterface $request) => $twoFactor->confirm($request));
            $app->post('/api/2fa/verify', static fn (ServerRequestInterface $request) => $twoFactor->verify($request));
        }
        $app->get('/api/groups', static fn () => $api->listGroups());
        $app->get('/api/ssh/key-paths', static fn () => $api->listKeyPaths());
        $app->get('/api/hosts', static fn (ServerRequestInterface $request) => $api->listHosts($request));
        $app->get('/api/hosts/options', static fn () => $api->listHostOptions());
        $app->post('/api/hosts', static fn (ServerRequestInterface $request) => $api->createHost($request));
        $app->post('/api/hosts/test', static fn (ServerRequestInterface $request) => $api->testHostInput($request));
        $app->get('/api/hosts/{id:\d+}', static fn (ServerRequestInterface $request) => $api->getHost(self::routeInt($request, 'id')));
        $app->put('/api/hosts/{id:\d+}', static fn (ServerRequestInterface $request) => $api->updateHost($request, self::routeInt($request, 'id')));
        $app->delete('/api/hosts/{id:\d+}', static fn (ServerRequestInterface $request) => $api->deleteHost($request, self::routeInt($request, 'id')));
        $app->post('/api/hosts/{id:\d+}/test', static fn (ServerRequestInterface $request) => $api->testHostById($request, self::routeInt($request, 'id')));

        $app->get('/api/sessions', static fn (ServerRequestInterface $request) => $api->listSessions($request));
        $app->get('/api/sessions/{id:\d+}', static fn (ServerRequestInterface $request) => $api->getSession(self::routeInt($request, 'id')));
        $app->get('/api/audit-logs', static fn (ServerRequestInterface $request) => $api->listAuditLogs($request));

        $app->get('/api/live/sessions', static fn (ServerRequestInterface $request) => $live->listSessions($request));
        $app->get('/api/live/sessions/{id:[a-f0-9]+}/stream', static fn (ServerRequestInterface $request) => $live->streamSession($request));

        $app->get(
            '/ws',
            $wsMiddleware,
            new WebsocketMiddleware(new WebsocketGroupComponent($connectionGroup)),
        );

        return $app;
    }

    /**
     * @return list<string>
     */
    private static function parseTokens(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private static function routeInt(ServerRequestInterface $request, string $name): int
    {
        return (int) $request->getAttribute($name);
    }
}
