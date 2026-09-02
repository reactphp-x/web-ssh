<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\AuthSessionConfig;
use App\Bootstrap\DatabaseBootstrap;
use App\Config\BasicAuthConfig;
use App\Config\TwoFactorConfig;
use App\Config\DatabaseConfig;
use App\Config\SshConfig;
use App\Database\SqliteClientFactory;
use App\Http\JsonErrorHandler;
use App\Middleware\TwoFactorAuthHandler;
use App\Recording\SessionRecordingConfig;
use App\Recording\SessionRecorder;
use App\Http\CommandPolicyController;
use App\Http\AiSessionController;
use App\Http\AiSettingsController;
use App\Repository\AiSessionRepository;
use App\Repository\AiSettingsRepository;
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
use App\Service\AiSettingsService;
use App\Service\AuditService;
use App\Service\AuthRateLimiter;
use App\Service\HostService;
use App\Service\SessionService;
use App\Service\SshProbeService;
use App\Service\TwoFactorService;
use App\Chat\AiSettingsEnvImporter;
use App\Chat\AiSessionChatService;
use App\Chat\AiSettingsStore;
use App\Chat\ChatService;
use App\Chat\ChatSettings;
use App\Chat\ChatStreamSession;
use App\Chat\CommandApprovalTrust;
use App\Chat\StoppedTurnWriter;
use App\Chat\StreamChunkMapper;
use App\Chat\ThreadLock;
use App\Middleware\FiberHandler;
use App\Neuron\HttpClient\ReactHttpClient;
use App\Storage\AiSessionStoragePaths;
use App\Ssh\AiSessionLiveTranscript;
use App\Ssh\SshExecBridge;
use App\Ssh\SshSessionBridge;
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
use ReactphpX\Redis\Pool as RedisPool;

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
        $aiSessionRepo = new AiSessionRepository($db);
        $auditLogs = new AuditLogRepository($db);
        $commandPolicyRepo = new \App\Repository\CommandPolicyRepository($db);
        $commandExecutions = new \App\Repository\CommandExecutionRepository($db);
        $policyRuleLoader = new \App\Policy\PolicyRuleLoader(
            $commandPolicyRepo,
            $env->basePath() . '/config/command_policy.defaults.php',
        );
        $commandPolicyEngine = new \App\Policy\CommandPolicyEngine(
            new \App\Policy\BashCommandInspector(),
            $policyRuleLoader,
        );
        $commandPolicyService = new \App\Service\CommandPolicyService($commandPolicyRepo, $policyRuleLoader);
        $aiSettingsRepo = new AiSettingsRepository($db);
        $aiSettingsStore = new AiSettingsStore($dbConfig, $cipher);
        (new AiSettingsEnvImporter($env, $dbConfig, $cipher))->importIfEmpty();
        $aiSettingsStore->loadSync();
        $twoFactorRepo = new TwoFactorRepository($db, $cipher);
        $twoFactorSessions = new TwoFactorSessionRepository($db);
        $authRateLimits = new AuthRateLimitRepository($db);
        $loginRateLimiter = new AuthRateLimiter($authRateLimits, maxFailures: 10, lockSeconds: 900);
        $twoFactorRateLimiter = new AuthRateLimiter($authRateLimits, maxFailures: 5, lockSeconds: 900);
        $authSessionConfig = AuthSessionConfig::load($env);
        TwoFactorCookie::configure(filter_var($env->nullableString('COOKIE_SECURE'), FILTER_VALIDATE_BOOL));
        BasicAuthCookie::configure(
            filter_var($env->nullableString('COOKIE_SECURE'), FILTER_VALIDATE_BOOL),
            $cipher->key(),
            $authSessionConfig->ttl(),
        );
        $sshConfig = SshConfig::load($env);
        Ssh2Client::setConnectTimeout($sshConfig->connectTimeout());
        $hostService = new HostService($hosts, $cipher, $sshConfig);
        $audit = new AuditService($auditLogs);
        $commandPolicy = new CommandPolicyController($commandPolicyService, $audit);
        $sessionService = new SessionService($sessions);
        $probe = new SshProbeService();
        $recordingConfig = SessionRecordingConfig::load($env);
        $sessionRecorder = new SessionRecorder($recordingConfig, $sessions);
        $liveRegistry = new SshLiveRegistry();
        $aiSessionStoragePaths = new AiSessionStoragePaths($env->basePath() . '/storage/neuron/ai-sessions');
        $aiLiveTranscript = new AiSessionLiveTranscript($aiSessionStoragePaths);
        $redisPool = self::redisPool($env);
        $commandApprovalTrust = new CommandApprovalTrust($redisPool);
        $sessionBridge = new SshSessionBridge($liveRegistry, $sessionRecorder, $commandPolicyEngine, $commandExecutions, null, $commandApprovalTrust);
        $execBridge = new SshExecBridge($hostService, $hosts, $aiSessionRepo, $sessionService, $liveRegistry, $sessionRecorder, $aiLiveTranscript, $commandPolicyEngine, $commandExecutions, $commandApprovalTrust);
        $basicAuth = BasicAuthConfig::load($env, $loginRateLimiter, $authSessionConfig)->handler();
        $basicAuthEnabled = $basicAuth !== null;
        $twoFactorEnabled = TwoFactorConfig::load($env, $basicAuthEnabled)->enabled();
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
            ? new TwoFactorController($twoFactorRepo, $twoFactorSessions, $twoFactorService, $twoFactorRateLimiter, $authSessionConfig)
            : null;
        $auth = new AuthController(
            $twoFactorEnabled ? $twoFactorSessions : null,
            $basicAuthEnabled ? trim($env->nullableString('BASIC_AUTH_USER') ?? '') : null,
            $basicAuthEnabled ? ($env->nullableString('BASIC_AUTH_PASSWORD') ?? '') : null,
            $basicAuthEnabled ? $loginRateLimiter : null,
            $twoFactorEnabled,
        );
        $live = new LiveController($liveRegistry);
        $recording = new RecordingController($sessions, $sessionRecorder);

        $gateway = new SshTerminalGateway($connectionGroup, $hostService, $sessionService, $liveRegistry, $sessionRecorder, $sessionBridge);
        $gateway->register();

        $logManager = configureLogging($env);
        $logger = $logManager->channel();

        $chatSettings = new ChatSettings($env, $aiSettingsStore);
        $aiSettingsService = new AiSettingsService($env, $dbConfig, $cipher, $aiSettingsStore, $aiSettingsRepo);
        $aiSettings = new AiSettingsController($aiSettingsService, $audit);
        $chatStreamSession = new ChatStreamSession($redisPool);
        $stoppedTurnWriter = new StoppedTurnWriter();
        $chatService = new ChatService(
            $chatSettings,
            $sessionBridge,
            new ReactHttpClient(timeout: $chatSettings->httpTimeout()),
            new StreamChunkMapper(),
            $chatStreamSession,
            $commandApprovalTrust,
            $logger,
            $commandPolicyEngine,
        );
        $aiChat = new AiChatController(
            $chatService,
            $chatSettings,
            $aiSettingsStore,
            new ThreadLock($redisPool),
            $chatStreamSession,
            $sessionBridge,
            $audit,
            $logger,
        );
        $aiSessionChat = new AiSessionChatService(
            $chatSettings,
            $execBridge,
            $hosts,
            $aiSessionRepo,
            new ReactHttpClient(timeout: $chatSettings->httpTimeout()),
            new StreamChunkMapper(),
            $chatStreamSession,
            $stoppedTurnWriter,
            $commandApprovalTrust,
            $logger,
            $commandPolicyEngine,
        );
        $aiSessions = new AiSessionController(
            $aiSessionChat,
            $aiSessionRepo,
            $chatSettings,
            $aiSettingsStore,
            new ThreadLock($redisPool),
            $chatStreamSession,
            $execBridge,
            $liveRegistry,
            $sessionRecorder,
            $audit,
            $logger,
        );

        $accessLog = $env->nullableString('HTTP_ACCESS_LOG')
            ?? $env->basePath() . '/storage/logs/access.log';

        $wsMiddleware = new WebsocketGroupMiddleware($connectionGroup);
        $tokens = self::parseTokens($env->nullableString('WS_RPC_TOKENS'));
        if ($tokens !== []) {
            $wsMiddleware->setTokens($tokens);
        }

        $middleware = [
            new AccessLogHandler($accessLog),
            new JsonErrorHandler($env, $logger),
            new FiberHandler(),
        ];

        if ($basicAuth !== null) {
            $middleware[] = $basicAuth;
            if ($twoFactorEnabled) {
                $middleware[] = new TwoFactorAuthHandler($twoFactorRepo, $twoFactorSessions, [], $authSessionConfig);
            }
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
        $app->get('/api/sessions/{id:\d+}/recording', static fn (ServerRequestInterface $request) => $recording->manifest($request));
        $app->get('/api/sessions/{id:\d+}/recording/{part:part-\d+\.cast}', static fn (ServerRequestInterface $request) => $recording->part($request));
        $app->get('/api/audit-logs', static fn (ServerRequestInterface $request) => $api->listAuditLogs($request));

        $app->get('/api/settings/ai', static fn () => $aiSettings->show());
        $app->get('/api/settings/ai/profiles/{id:\d+}', static fn (ServerRequestInterface $request) => $aiSettings->showProfile($request));
        $app->post('/api/settings/ai/profiles', static fn (ServerRequestInterface $request) => $aiSettings->create($request));
        $app->put('/api/settings/ai/profiles/{id:\d+}', static fn (ServerRequestInterface $request) => $aiSettings->update($request));
        $app->delete('/api/settings/ai/profiles/{id:\d+}', static fn (ServerRequestInterface $request) => $aiSettings->delete($request));
        $app->post('/api/settings/ai/profiles/{id:\d+}/select', static fn (ServerRequestInterface $request) => $aiSettings->select($request));
        $app->post('/api/settings/ai/test', static fn (ServerRequestInterface $request) => $aiSettings->test($request));
        $app->post('/api/settings/ai/models', static fn (ServerRequestInterface $request) => $aiSettings->models($request));

        $app->get('/api/settings/command-policy', static fn () => $commandPolicy->show());
        $app->put('/api/settings/command-policy', static fn (ServerRequestInterface $request) => $commandPolicy->save($request));
        $app->delete('/api/settings/command-policy/{id:\d+}', static fn (ServerRequestInterface $request) => $commandPolicy->delete($request));

        $app->get('/api/live/sessions', static fn (ServerRequestInterface $request) => $live->listSessions($request));
        $app->get('/api/live/sessions/{id:[a-f0-9]+}/stream', static fn (ServerRequestInterface $request) => $live->streamSession($request));

        $app->get('/api/ai/bootstrap', static fn (ServerRequestInterface $request) => $aiChat->bootstrap($request));
        $app->post('/api/ai/chat/stream', static fn (ServerRequestInterface $request) => $aiChat->stream($request));
        $app->post('/api/ai/chat/approval/stream', static fn (ServerRequestInterface $request) => $aiChat->approvalStream($request));
        $app->post('/api/ai/chat/approval-mode', static fn (ServerRequestInterface $request) => $aiChat->setApprovalMode($request));
        $app->post('/api/ai/chat/auto-approve', static fn (ServerRequestInterface $request) => $aiChat->disableAutoApprove($request));
        $app->post('/api/ai/chat/feedback/stream', static fn (ServerRequestInterface $request) => $aiChat->feedbackStream($request));
        $app->post('/api/ai/chat/stop', static fn (ServerRequestInterface $request) => $aiChat->stop($request));
        $app->post('/api/ai/chat/reset', static fn (ServerRequestInterface $request) => $aiChat->reset($request));

        $app->get('/api/ai/sessions', static fn (ServerRequestInterface $request) => $aiSessions->list($request));
        $app->post('/api/ai/sessions', static fn (ServerRequestInterface $request) => $aiSessions->create($request));
        $app->get('/api/ai/sessions/{id:\d+}', static fn (ServerRequestInterface $request) => $aiSessions->show($request));
        $app->get('/api/ai/sessions/{id:\d+}/bootstrap', static fn (ServerRequestInterface $request) => $aiSessions->bootstrap($request));
        $app->post('/api/ai/sessions/{id:\d+}/chat/stream', static fn (ServerRequestInterface $request) => $aiSessions->stream($request));
        $app->post('/api/ai/sessions/{id:\d+}/chat/stream/subscribe', static fn (ServerRequestInterface $request) => $aiSessions->subscribeStream($request));
        $app->post('/api/ai/sessions/{id:\d+}/approval/stream', static fn (ServerRequestInterface $request) => $aiSessions->approvalStream($request));
        $app->post('/api/ai/sessions/{id:\d+}/approval-mode', static fn (ServerRequestInterface $request) => $aiSessions->setApprovalMode($request));
        $app->post('/api/ai/sessions/{id:\d+}/auto-approve', static fn (ServerRequestInterface $request) => $aiSessions->disableAutoApprove($request));
        $app->post('/api/ai/sessions/{id:\d+}/feedback/stream', static fn (ServerRequestInterface $request) => $aiSessions->feedbackStream($request));
        $app->post('/api/ai/sessions/{id:\d+}/stop', static fn (ServerRequestInterface $request) => $aiSessions->stop($request));
        $app->post('/api/ai/sessions/{id:\d+}/reset', static fn (ServerRequestInterface $request) => $aiSessions->reset($request));
        $app->get('/api/ai/sessions/{id:\d+}/live/stream', static fn (ServerRequestInterface $request) => $aiSessions->liveStream($request));
        $app->get('/api/ai/sessions/{id:\d+}/live/transcript', static fn (ServerRequestInterface $request) => $aiSessions->liveTranscript($request));
        $app->get('/api/ai/sessions/{id:\d+}/recording', static fn (ServerRequestInterface $request) => $aiSessions->recording($request));

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

    private static function redisPool(Environment $env): ?RedisPool
    {
        $url = trim($env->nullableString('REDIS_URL') ?? '');
        if ($url === '') {
            return null;
        }

        try {
            return new RedisPool(uri: $url);
        } catch (\Throwable) {
            return null;
        }
    }
}
