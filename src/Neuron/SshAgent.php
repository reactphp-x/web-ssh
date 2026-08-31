<?php

declare(strict_types=1);

namespace App\Neuron;

use App\Chat\ChatSettings;
use App\Neuron\Agent\Middleware\SshCommandApprovalPrep;
use App\Neuron\Agent\ProvidesSummarizationMiddleware;
use App\Neuron\Agent\Middleware\UserFeedback;
use App\Neuron\HttpClient\ReactHttpClient;
use App\Neuron\Tools\AskUserTool;
use App\Neuron\Tools\GetTerminalContextTool;
use App\Neuron\Tools\RunSshCommandTool;
use App\Ssh\SshSessionBridge;
use App\Ssh\SshToolContext;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;

final class SshAgent extends Agent
{
    use ProvidesSummarizationMiddleware;

    private ?ChatSettings $settings = null;

    private ?HttpClientInterface $http = null;

    private ?SshSessionBridge $bridge = null;

    private string $connId = '';

    private bool $allowFeedback = true;

    public function configure(
        ChatSettings $settings,
        HttpClientInterface $http,
        SshSessionBridge $bridge,
        string $connId,
        bool $allowFeedback = true,
    ): self {
        $this->settings = $settings;
        $this->http = $http;
        $this->bridge = $bridge;
        $this->connId = $connId;
        $this->allowFeedback = $allowFeedback;
        $this->registerSummarizationMiddleware($settings);

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
            '你是 Web SSH 平台的远程运维助手，用户已通过浏览器连接到一台 Linux 服务器。',
            '你只能通过 run_ssh_command 在远程 shell 执行命令，禁止假装已经执行。',
            'get_terminal_context 可读取终端最近输出，无需批准。',
            'run_ssh_command 会暂停等待用户在界面上批准或拒绝。',
        ];
        $steps = [
            '先理解用户目标；必要时用 get_terminal_context 查看当前终端状态。',
            '每次只执行一条明确、非交互式的命令；说明 reason 后再调用 run_ssh_command。',
            '根据命令输出继续推理，直到完成任务或向用户汇报结果。',
            '禁止执行 vim、top、mysql 等交互式/TUI 命令。',
        ];
        if ($this->allowFeedback) {
            $background[] = 'ask_user 用于需求不明确时向用户提问，界面会展示可点选选项。';
            $steps[] = '需求模糊时调用 ask_user，每题用 radio/checkbox 给出 3-6 个选项。';
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
            new SshCommandApprovalPrep(),
            new ToolApproval([
                RunSshCommandTool::class,
            ]),
        ];
        if ($this->allowFeedback) {
            $toolNode[] = new UserFeedback();
        }

        return [
            ToolNode::class => $toolNode,
        ];
    }

    protected function tools(): array
    {
        $tools = [
            new GetTerminalContextTool($this->connId),
            new RunSshCommandTool($this->connId),
        ];
        if ($this->allowFeedback) {
            $tools[] = new AskUserTool();
        }

        return $tools;
    }

    private function requireSettings(): ChatSettings
    {
        if ($this->settings === null) {
            throw new \RuntimeException('SshAgent must be configure()d before use.');
        }

        return $this->settings;
    }

    private function requireBridge(): SshSessionBridge
    {
        if ($this->bridge === null) {
            throw new \RuntimeException('SshAgent requires SshSessionBridge.');
        }

        return $this->bridge;
    }
}
