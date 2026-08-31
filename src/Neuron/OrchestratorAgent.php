<?php

declare(strict_types=1);

namespace App\Neuron;

use App\Chat\ChatSettings;
use App\Neuron\Agent\Middleware\OrchestratorCommandApprovalPrep;
use App\Neuron\Agent\ProvidesSummarizationMiddleware;
use App\Neuron\Agent\Middleware\UserFeedback;
use App\Neuron\HttpClient\ReactHttpClient;
use App\Neuron\Tools\AskUserTool;
use App\Neuron\Tools\GetCommandContextTool;
use App\Neuron\Tools\ListHostsTool;
use App\Neuron\Tools\OrchestratorRunSshCommandTool;
use App\Ssh\OrchestratorToolContext;
use App\Ssh\SshExecBridge;
use App\Repository\HostRepository;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;

final class OrchestratorAgent extends Agent
{
    use ProvidesSummarizationMiddleware;

    private ?ChatSettings $settings = null;

    private ?HttpClientInterface $http = null;

    private ?SshExecBridge $execBridge = null;

    private ?HostRepository $hosts = null;

    private int $aiSessionId = 0;

    private string $username = '';

    private bool $allowFeedback = true;

    public function configure(
        ChatSettings $settings,
        HttpClientInterface $http,
        SshExecBridge $execBridge,
        HostRepository $hosts,
        int $aiSessionId,
        string $username,
        bool $allowFeedback = true,
    ): self {
        $this->settings = $settings;
        $this->http = $http;
        $this->execBridge = $execBridge;
        $this->hosts = $hosts;
        $this->aiSessionId = $aiSessionId;
        $this->username = $username;
        $this->allowFeedback = $allowFeedback;

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        $settings = $this->requireSettings();
        $http = $this->http ?? new ReactHttpClient(timeout: $settings->httpTimeout());
        $key = $settings->apiKey();
        $model = $settings->model();
        $base = $settings->baseUri();

        if ($base !== '') {
            return new OpenAILike(
                baseUri: $base,
                key: $key,
                model: $model,
                httpClient: $http,
            );
        }

        if ($settings->provider() === 'deepseek' && class_exists(Deepseek::class)) {
            return new Deepseek(
                key: $key,
                model: $model,
                httpClient: $http,
            );
        }

        return new OpenAI(
            key: $key,
            model: $model,
            httpClient: $http,
        );
    }

    protected function instructions(): string
    {
        $background = [
            '你是 Web SSH 平台的跨主机运维编排助手。用户未指定主机时，必须先调用 list_hosts 选择合适目标。',
            'run_ssh_command 需要 host_id、command、reason；写操作会暂停等待用户批准。',
            'get_command_context 可读取某主机上最近 AI 命令输出，无需批准。',
            '切换主机时会开启新的 exec 分段；各主机 shell 环境相互独立（cwd/env 不共享）。',
        ];
        $steps = [
            '理解用户目标；若未明确主机，先 list_hosts 再选择 host_id。',
            '每次只执行一条明确、非交互式命令；说明 reason 后调用 run_ssh_command。',
            '根据输出继续推理，可在多台主机间切换完成任务。',
            '禁止执行 vim、top、mysql 等交互式/TUI 命令。',
        ];
        if ($this->allowFeedback) {
            $background[] = 'ask_user 用于需求不明确时向用户提问。';
            $steps[] = '需求模糊时调用 ask_user。';
        }

        return (string) new SystemPrompt(
            background: $background,
            steps: $steps,
            output: ['用简洁中文回答，命令输出用代码块展示。'],
        );
    }

    protected function middleware(): array
    {
        $toolNode = [
            new OrchestratorCommandApprovalPrep(),
            new ToolApproval([
                OrchestratorRunSshCommandTool::class,
            ]),
        ];
        if ($this->allowFeedback) {
            $toolNode[] = new UserFeedback();
        }

        return array_merge(
            $this->summarizationMiddlewareEntries($this->requireSettings()),
            [
                ToolNode::class => $toolNode,
            ],
        );
    }

    protected function tools(): array
    {
        OrchestratorToolContext::configure(
            $this->requireExecBridge(),
            $this->requireHosts(),
            $this->aiSessionId,
            $this->username,
            $this->requireSettings()->commandTimeout(),
        );

        $tools = [
            new ListHostsTool(),
            new GetCommandContextTool(),
            new OrchestratorRunSshCommandTool($this->aiSessionId),
        ];
        if ($this->allowFeedback) {
            $tools[] = new AskUserTool();
        }

        return $tools;
    }

    private function requireSettings(): ChatSettings
    {
        if ($this->settings === null) {
            throw new \RuntimeException('OrchestratorAgent must be configure()d before use.');
        }

        return $this->settings;
    }

    private function requireExecBridge(): SshExecBridge
    {
        if ($this->execBridge === null) {
            throw new \RuntimeException('OrchestratorAgent requires SshExecBridge.');
        }

        return $this->execBridge;
    }

    private function requireHosts(): HostRepository
    {
        if ($this->hosts === null) {
            throw new \RuntimeException('OrchestratorAgent requires HostRepository.');
        }

        return $this->hosts;
    }
}
