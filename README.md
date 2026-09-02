# reactphp-x/web-ssh

**带 AI 的 Web SSH 运维平台** — 用自然语言描述任务，AI 提议命令、你审核批准、自动在远端 exec 并继续推理；同时提供多标签终端、跨主机编排、现场查看与会话回放。

> 在侧边栏 **AI 设置**（`#/settings/ai`）中配置 Provider 与 API Key 并启用后，**AI 助手**（单主机）与 **AI 编排**（跨主机）即可使用。命令执行受 **命令策略**（`#/settings/command-policy`）约束：默认每条命令逐条审批，开启会话自动批准后按策略放行；危险命令直接拒绝。底层仍是自托管 Web SSH 网关：凭据加密、跳板机、审计与 2FA。

## 目录

- [AI 亮点](#ai-highlights)
- [AI 编排](#ai-orchestration)
- [AI 助手（终端页）](#ai-assistant)
- [AI 配置（Web 面板）](#ai-config)
- [命令策略（Web 面板）](#command-policy)
- [快速上手](#quick-start)
- [解决的问题](#problems)
- [功能概览](#features)
  - [系统交互概览](#architecture-diagrams)
  - [终端、AI、现场、实时现场、回放怎么选？](#usage-guide)
- [现场与实时现场](#field-vs-live)
- [会话录像、现场与回放](#recording)
- [技术栈](#tech-stack)
- [要求](#requirements)
- [安装](#install)
- [启动](#start)
- [Docker](#docker)
- [认证说明](#auth)
- [WebSocket 协议](#websocket)
- [配置](#config)
- [架构](#architecture)
- [安全提示](#security)
- [测试](#test)
- [许可证](#license)

<a id="ai-highlights"></a>

## AI 亮点

| 能力 | 一句话 |
|---|---|
| **自然语言运维** | 描述「查磁盘、看 nginx 日志、重启服务」等任务，AI 拆解步骤并给出可执行命令 |
| **人工批准闸门** | 写操作必须点 **批准** 才执行；**只读命令**由策略引擎自动放行；危险命令直接拒绝 |
| **命令策略引擎** | 基于 [unbash](https://github.com/reactphp-x/unbash) 静态解析 Bash AST，按拒绝 / 需审批 / 自动执行三档决策 |
| **双模式覆盖** | **AI 助手** 绑定当前终端主机；**AI 编排** 跨多台机器自动选机、分段执行 |
| **exec 与 PTY 分离** | AI 走独立 SSH exec，输出进 **现场** 流（`[AI]` 标记），不污染交互终端 |
| **可审计可回放** | 命令批准/拒绝写入操作日志；每次 exec 录像 + 现场 transcript，刷新可恢复 |
| **工具链透明** | 消息 timeline 与工具卡片交错展示，每次 `list_hosts` / `run_ssh_command` 可展开查看 |
| **Web 面板配置 AI** | 侧边栏 **AI 设置**：多套 Provider 配置、启用开关、模型列表拉取、连接测试，密钥加密存 SQLite |
| **Web 面板配置策略** | 侧边栏 **命令策略**：查看内置规则、按全局/主机/分组添加覆盖策略 |
| **上下文与缓存用量** | 对话底部实时显示当前上下文占用、本轮缓存与本 session 累计消耗（见 [聊天底部用量条](#ai-token-usage)） |

**典型用法**

```text
你：检查 web-01 和 web-02 的 nginx 是否在运行，磁盘是否超过 80%
AI：list_hosts → 选定主机 → 提议 run_ssh_command（需批准）
你：批准
AI：读取输出 → 继续下一条命令或切换主机 → 汇总结论
```

**两种 AI 怎么选？**

| 场景 | 推荐 |
|---|---|
| 已打开某台机器终端，边操作边让 AI 帮忙 | **AI 助手**（终端页侧栏） |
| 多台巡检、跨机串联任务、无需先开 Web 终端 | **AI 编排**（`#/ai`） |
| 需要亲手跑 vim / htop / Claude Code | **Web 终端** PTY（与 AI 并存） |

详细说明见 [AI 编排](#ai-orchestration) 与 [AI 助手](#ai-assistant)。

<a id="problems"></a>

## 解决的问题

运维或开发经常需要在浏览器可及的环境里操作远端服务器，常见痛点包括：

- **本地终端受限** — 换设备、无 VPN、或防火墙不允许直连 22 端口时，难以快速登录
- **凭据分散** — 多台主机的地址、账号、密钥散落在各处，缺少统一入口
- **跳板机链路复杂** — 经堡垒机/多级跳转连接时，手工维护 `~/.ssh/config` 容易出错
- **协作与监管** — 需要查看「谁连了哪台机器、做了什么」，纯 SSH 客户端难以留痕
- **暴露面风险** — 把 SSH 直接暴露到公网不安全，需要登录鉴权、审计与访问控制
- **重复劳动** — 查日志、看磁盘、改配置等任务，希望用自然语言描述后由 AI 提议命令并代执行

本项目是一个 **自托管、AI 原生的 Web SSH 网关**：

1. **AI 编排 / AI 助手** — 自然语言 → 审核命令 → 自动 exec → 多轮推理（核心能力）
2. **现场与回放** — AI / 终端输出可实时查看、持久化 transcript、asciinema 录像
3. 浏览器多标签 **Web 终端**（PTY），支持 vim、htop、Claude Code 等 TUI
4. 集中管理主机凭据、跳板机；Basic Auth + TOTP 双因子与操作审计

适合内网运维面板、开发跳板、小团队服务器管理等场景。**不应未经防护直接暴露到公网。**

<a id="features"></a>

## 功能概览

### AI 能力

| 模块 | 说明 |
|---|---|
| **AI 编排** | 跨主机会话（`#/ai`）；`list_hosts` 选机、`run_ssh_command` 分段 exec；**无需先开终端** |
| **AI 助手** | 终端页侧栏；绑定当前主机 `conn_id`；自然语言任务 + **策略判定后批准/自动执行** |
| **命令审核** | 写操作暂停等待批准；只读命令自动执行；危险命令拒绝；批准/拒绝与执行记录可审计 |
| **命令策略** | 内置 `deny_binaries` / `require_approval_binaries` + 数据库覆盖；Neuron 中间件 + SSH Bridge 双层门禁 |
| **工具 timeline** | 聊天与工具调用交错展示，默认折叠可展开 |
| **现场** | AI 命令输出实时 SSE + 持久化 transcript（编排）；含 `[AI]` 前缀标记 |
| **AI 会话历史** | 继续对话、查看现场、按分段回放录像 |
| **AI 设置** | Web 面板管理多套 LLM 配置；顶部启用 + 选择生效配置；支持 OpenAI 兼容、Deepseek、Anthropic、Gemini、Ollama 等 |

### 平台基础

| 模块 | 说明 |
|---|---|
| **主机管理** | SQLite 存储主机、分组、标签；密码/私钥加密保存，支持跳板机 |
| **Web 终端** | xterm.js 多标签 **PTY**，OpenSSH 子进程；支持 TUI 交互 |
| **实时现场** | `#/live` 多路 SSE 旁路监控进行中的 SSH 连接 |
| **会话记录** | 连接起止、状态、耗时；asciinema cast（`storage/recordings/Y/m/d/`） |
| **现场查看 / 回放** | 历史输出滚动查看或按时间轴播放录像 |
| **操作审计** | 主机增删改、AI 命令批准/拒绝、命令策略变更、命令执行记录等 |
| **登录鉴权** | HTTP Basic Auth + TOTP 双因子 |

<a id="architecture-diagrams"></a>

### 系统交互概览

GitHub 可直接渲染下方 [Mermaid](https://github.blog/2022-02-14-include-diagrams-markdown-files-mermaid/) 图；本地预览需支持 Mermaid 的 Markdown 编辑器（如 VS Code 插件）。

**整体架构** — 浏览器经 WebSocket 驱动交互终端（PTY），经 HTTP 驱动 AI、现场与实时旁路；AI 命令走独立 SSH exec，与 PTY 互不干扰：

```mermaid
flowchart TB
    subgraph Browser["浏览器"]
        T["交互终端 xterm.js"]
        A["AI 助手 / AI 编排"]
        F["现场（单会话输出）"]
        L["实时现场（多路监控 #/live）"]
        R["会话回放 / 历史现场"]
    end

    subgraph Server["web-ssh 服务端"]
        API["HTTP /api/*"]
        WS["WebSocket /ws"]
        GW["SshTerminalGateway"]
        BR["SshSessionBridge<br/>conn_id"]
        EB["SshExecBridge<br/>ai_session_id"]
        POL["CommandPolicyEngine<br/>unbash AST"]
        LIVE["SshLiveRegistry<br/>SSE 旁路"]
        TX["AiSessionLiveTranscript<br/>现场持久化"]
        AG1["SshAgent<br/>终端 AI"]
        AG2["OrchestratorAgent<br/>编排 AI"]
        REC["Session Recorder"]
        DB[("SQLite")]
    end

    subgraph Remote["远端主机"]
        SSH["OpenSSH"]
    end

    T <-->|input / output| WS
    A -->|chat / approval / feedback| API
    F -->|SSE / transcript| API
    L -->|SSE 多路| API
    R -->|cast / transcript| API
    WS --> GW
    API --> AG1
    API --> AG2
    GW --> BR
    GW <-->|PTY 交互 shell| SSH
    AG1 --> BR
    AG2 --> EB
    AG1 --> POL
    AG2 --> POL
    POL --> BR
    POL --> EB
    BR -->|exec| SSH
    EB -->|exec 分段| SSH
    BR --> LIVE
    EB --> LIVE
    EB --> TX
    BR --> REC
    EB --> REC
    LIVE --> API
    API --> DB
```

**终端页布局** — 登录与 AI 助手进入同一页面；左侧可在 **现场** 与交互终端间切换，右侧为 AI 对话：

```mermaid
flowchart TB
    subgraph Page["终端页 /terminal/hostId/timestamp"]
        SW["左侧顶部：现场 ⇄ 终端"]
        subgraph Left["左栏（二选一）"]
            FIELD["现场 — SSE 只读<br/>手动 PTY 输出 + AI 命令输出"]
            TERM["交互终端 — PTY，可输入 / TUI"]
        end
        AI["右栏：AI 助手 — conn_id，单主机"]
    end
    SW --> FIELD
    SW --> TERM
```

**AI 编排页布局** — 不绑定单主机；左侧 **现场** 持久化 transcript，右侧跨主机对话：

```mermaid
flowchart TB
    subgraph AiPage["AI 编排页 /ai/session/id"]
        FIELD2["左栏：现场 — SSE + live log<br/>按主机 segment 分段输出"]
        AI2["右栏：AI 编排 — ai_session_id<br/>list_hosts / run_ssh_command"]
    end
    FIELD2 --- AI2
```

**终端 AI 工作流** — 绑定 `conn_id`；只读 Neuron 工具自动执行；`run_ssh_command` 经 **命令策略** 判定：未列入审批/拒绝列表则 AutoRun，写操作与敏感命令 RequireApproval，危险命令 Deny：

```mermaid
sequenceDiagram
    actor U as 用户
    participant AI as SshAgent
    participant P as CommandPolicyEngine
    participant S as SshSessionBridge
    participant H as 远端主机

    U->>AI: 描述运维任务
    opt 需要更多上下文
        AI->>S: get_terminal_context
        S-->>AI: 终端最近输出
    end
    AI->>P: 解析并评估 run_ssh_command
    alt 未开启会话自动批准 或 需审批 RequireApproval
        AI->>U: 待审核命令
        alt 用户批准
            U->>AI: 批准（可选开启会话自动批准）
            AI->>S: run_ssh_command
            S->>H: SSH exec
            H-->>AI: 命令结果
        else 拒绝
            U->>AI: 拒绝
        end
    else 已开启会话自动批准且策略 AutoRun/RequireApproval
        AI->>S: run_ssh_command
        S->>H: SSH exec
        H-->>AI: 命令结果
    else 危险 Deny
        P-->>AI: 拒绝原因
        AI->>U: 调整方案
    end
```

**编排 AI 工作流** — 绑定 `ai_session_id`；可跨主机切换 segment，每场独立录像：

```mermaid
sequenceDiagram
    actor U as 用户
    participant AI as OrchestratorAgent
    participant S as SshExecBridge
    participant H as 远端主机

    U->>AI: 描述跨主机任务
    AI->>S: list_hosts
    S-->>AI: 主机列表
    AI->>U: 待审核命令（含 host_id）
    alt 批准
        U->>AI: 批准
        AI->>S: run_ssh_command(host_id, …)
        S->>H: SSH exec（切换主机则新 segment）
        H-->>S: stdout / stderr
        S-->>AI: 命令结果
        Note over S: 写入现场 SSE + live log<br/>+ segment 录像
        AI->>U: 继续推理或总结
    else 拒绝
        U->>AI: 拒绝
    end
```

> AI 命令输出以 `[AI] $ command` 形式写入**现场** SSE 流，不会混入左侧交互终端。

<a id="usage-guide"></a>

### 终端、AI、现场、实时现场、回放怎么选？

| 你想做什么 | 用哪个 |
|---|---|
| 亲手输入命令、跑 TUI 程序 | **Web 终端**（PTY） |
| 用自然语言让 AI 提议并执行 shell 命令 | **AI 助手**（只读命令自动执行，写操作需批准） |
| 当前连接里看手动输入 + AI 命令的实际输出 | 终端页左侧 **现场** |
| 跨主机 AI 编排，刷新后继续看命令输出 | **AI 编排** → 左侧 **现场**（持久化 transcript） |
| 同时监控多路进行中的 SSH 连接 | 菜单 **实时现场**（`#/live` 监控墙） |
| 事后一次性滚动查看某次连接完整输出 | **会话记录 → 现场** |
| 事后按时间轴播放终端录像 | **会话记录 → 回放** |
| AI 跑命令时对照实际输出 | **AI 助手** + 左侧切到 **现场** |

> **PTY 与 AI exec 是两条通道**：你在终端里的交互 shell（`cd`、环境变量、TUI）与 AI 执行的命令**相互独立**。AI 每次命令走独立 SSH exec，输出带 `[AI]` 前缀写入**现场**流，**不会**混进你的交互终端。

### TUI 与远端 AI 开发工具

终端基于 **真实 PTY**，支持键盘交互、颜色与窗口缩放，可在远端 shell 中运行：

- **[Claude Code](https://docs.anthropic.com/en/docs/claude-code)** — `claude` 等命令行 AI 编程助手
- **[Cursor Agent](https://cursor.com)** — 远端通过 SSH 使用 Cursor CLI / Agent 工作流

连接后在**左侧终端**里像本地一样启动即可；窗口大小变化会通过 `resize` 同步到 PTY。内置 **AI 助手**是另一套能力，用于「描述任务 → 审核命令 → 自动 exec」，不替代上述 TUI 工具。

<a id="tech-stack"></a>

## 技术栈

| 层 | 组件 |
|---|---|
| 运行时 | [reactphp-x/framework](https://github.com/reactphp-x/framework) + [reactphp-x/worker](https://github.com/reactphp-x/worker) |
| WebSocket | [reactphp-x/websocket-group](https://github.com/reactphp-x/websocket-group) |
| SSH | 系统 `openssh-client`（PTY + 独立 exec 通道） |
| AI | [neuron-core/neuron-ai](https://github.com/neuron-core/neuron-ai) |
| 命令策略 | [reactphp-x/unbash](https://github.com/reactphp-x/unbash)（Bash AST 静态解析） |
| 前端 | Vue 3 + [xterm.js](https://xtermjs.org/) + [asciinema-player](https://github.com/asciinema/asciinema-player) |
| 存储 | SQLite（`ext-sqlite3`） |
| 可选 | Redis（多 Worker 时 AI 线程锁 / SSE 状态） |
| 2FA | [wpjscc/twofactorauth](https://github.com/wpjscc/twofactorauth) + Bacon QR Code |

<a id="requirements"></a>

## 要求

- PHP 8.2+
- 扩展：`ext-sqlite3`、`pcntl`、`posix`
- 系统：`openssh-client`（Docker 镜像已包含）
- Linux / macOS（Worker 模式不支持 Windows）
- Composer 2.x

<a id="install"></a>

## 安装

```bash
git clone <repo> web-ssh
cd web-ssh
composer install
cp .env.example .env
```

编辑 `.env`，至少设置：

```env
APP_KEY=base64:...          # 32 字节，用于加密主机凭据与 Cookie 签名
BASIC_AUTH_USER=admin
BASIC_AUTH_PASSWORD=change-me
```

生成 `APP_KEY`：

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

首次登录后，在 **AI 设置** 中配置 LLM Provider（详见 [AI 配置](#ai-config)）。

<a id="start"></a>

## 启动

```bash
php start.php start          # 前台
php start.php start -d       # 后台
php start.php stop
php start.php restart
php start.php status
```

默认监听地址由 `HTTP_LISTEN` 决定（`.env.example` 为 `0.0.0.0:8095`）。开发模式下会自动监视 `src/`、`config/`、`public/` 变更并 reload。

<a id="docker"></a>

## Docker

```bash
docker compose up --build
```

默认映射 **宿主机 8097 → 容器 8097**（可通过 `HTTP_PORT` 修改）。

容器内已配置：

- `HTTP_LISTEN=0.0.0.0:8097`
- `HOME=/home/www-data`
- `SSH_IDENTITY_FILE=/home/www-data/.ssh/id_rsa`
- 宿主机 `~/.ssh` 只读挂载到 `/home/www-data/.ssh`

访问：**http://127.0.0.1:8097/login**

> `docker-compose.yml` 中的 `environment` 会覆盖 `.env` 里的 `HTTP_LISTEN`。主机私钥路径请使用容器内路径（如 `~/.ssh/id_rsa`）。

<a id="quick-start"></a>

## 快速上手

### 三步启用 AI

1. 复制 `.env.example` → `.env`，设置 `APP_KEY` 与 `BASIC_AUTH_*`，`composer install` 后 `php start.php start`
2. 浏览器打开 `/login` 并完成 TOTP → 侧边栏 **AI 设置** 新建配置（Provider、API Key、模型），勾选 **启用 AI 助手** 并选择要使用的配置
3. **主机管理** 录入至少一台主机

### 推荐：AI 编排（跨主机）

1. 侧边栏 **AI 编排** → **新建会话**
2. 输入任务，例如：「列出所有主机，检查根分区使用率超过 80% 的机器」
3. AI 调用 `list_hosts`、提议 `run_ssh_command` → 底部 **批准** 或 **拒绝**
4. 左侧 **现场** 查看命令输出；可多轮对话、切换主机继续

### 单主机：终端 + AI 助手

1. 主机列表点 **AI 助手**（或 **登录** 后在终端页打开右侧 AI 侧栏）
2. 描述任务；批准 AI 提议的命令
3. 左侧 **现场** 看 AI 输出，**终端** 标签可手动输入 / 跑 TUI

### 其他

- **实时现场**（`#/live`）— 同时监控多路 SSH 连接
- **会话记录** — 事后 **现场** 滚动查看或 **回放** 录像

未登录访问 `/` 会重定向到 `/login`；点击 **退出** 清除登录与 2FA Cookie。

<a id="ai-assistant"></a>

## AI 助手（终端页）

适用于**已打开某台主机 Web 终端**时的单主机运维辅助。SSH 连接成功后，终端页右侧出现 **AI 助手** 侧栏（移动端为「现场 | AI 助手」或「终端 | AI 助手」标签切换）。从 **主机管理** 点击 **AI 助手** 进入**同一终端页**，默认左侧为 **现场**，右侧为 AI 对话；可在**左侧顶部**切换为 **交互终端** 并手动输入。

绑定 **`conn_id`**（WebSocket `connected` 消息中的 `_id`），复用当前 SSH 连接的凭据与 exec 通道。

### 工作流程

1. 在底部输入框描述任务（如「查看磁盘使用并清理 /tmp」）
2. AI 分析后可调用工具：
   - **`get_terminal_context`** — 读取终端最近输出（只读，**无需审核**）
   - **`run_ssh_command`** — 提议 shell 命令；经 **命令策略** 判定：
     - **AutoRun**（如 `ls`、`cat`、`ps`、`df`）→ 默认逐条审批；开启会话自动批准后自动执行
     - **RequireApproval**（如 `rm`、`systemctl`、`mysql`）→ **始终逐条审批**（会话自动批准无效）
     - **Deny**（如 `vim`、`curl | bash`）→ 直接拒绝
     - 可选 `timeout_sec` 指定超时秒数
   - **`ask_user`** — 需求不明确时弹出选项（单选/多选）
3. 出现 **待审核命令** 时，在侧栏底部点击 **批准** 或 **拒绝**；可勾选 **开启后会话内后续命令按策略自动执行**。审批卡片显示 **策略标签**（自动执行 / 需审批 / 已拒绝）与命令摘要
4. 批准后通过 **独立 SSH exec** 执行；输出回传 AI 继续推理（可多轮）
5. 点击 **工具调用** 可展开查看每次工具的参数与返回（消息 timeline 与工具卡片交错展示）

### API

均需 Basic Auth + 2FA。

```text
GET  /api/ai/bootstrap?conn_id={conn_id}
POST /api/ai/chat/stream           { conn_id, message }
POST /api/ai/chat/approval/stream  { conn_id, approved: 1|0 }
POST /api/ai/chat/feedback/stream  { conn_id, answers: {} }
POST /api/ai/chat/stop             { conn_id }
POST /api/ai/chat/reset            { conn_id }
```

命令审核记录写入 **操作日志**（`ai.command.approved` / `ai.command.rejected`）。

### 说明与限制

- 策略引擎在 Neuron 中间件与 SSH Bridge **双层**校验；即使绕过 UI，Bridge 仍会拦截 Deny 类命令
- 仅 AI exec 路径受策略约束；Web 终端 PTY 手动输入不受限
- AI exec 每次为**新 shell 进程**，工作目录/环境可能与交互 PTY 不一致（例如在 PTY 里 `cd` 后，AI 仍可能从 home 执行）
- AI exec 无 stdin，交互式命令（vim、top、mysql 客户端等）会被策略拒绝或挂起直至超时；请在左侧 PTY 终端手动操作
- Neuron 只读工具（`get_terminal_context` 等）自动执行；`run_ssh_command` 走策略引擎

<a id="ai-orchestration"></a>

## AI 编排

**AI 编排**是独立于 Web 终端的跨主机运维模式：在浏览器里描述任务，由 AI 从平台主机列表中选目标、提议 shell 命令，你审核后自动 SSH exec 执行，并可在多台机器间切换继续推理。**无需先打开某台主机的交互终端**。

典型场景：

- 批量巡检多台服务器（磁盘、负载、服务状态）
- 在 A 机查日志、B 机改配置、C 机重启服务的串联任务
- 离开页面后从历史会话继续，或回看命令输出

<a id="ai-orchestration-compare"></a>

### 与终端 AI 助手的区别

| | **终端 AI 助手** | **AI 编排** |
|---|---|---|
| 入口 | 主机管理 → **AI 助手** / 终端页侧栏 | 侧边栏 **AI 编排** |
| 路由 | `#/terminal/{hostId}/…` | `#/ai`、`#/ai/session/{id}`、`#/ai/sessions` |
| 绑定键 | `conn_id`（须先 WebSocket 连上该主机） | `ai_session_id`（独立会话） |
| 选主机 | 固定为当前终端主机 | `list_hosts` 动态选择，可跨主机切换 |
| 左侧 **现场** | 含手动 PTY 输出 + AI 命令 | 仅 AI 命令输出（按 segment 分段） |
| 现场持久化 | 连接期间 SSE；历史靠会话录像 | live log + transcript，刷新可恢复 |
| 录像 | 单次 SSH 会话一条 | 每个主机 segment 独立录像，回放按段顺序播放 |
| Agent | `SshAgent` | `OrchestratorAgent` |

<a id="ai-orchestration-routes"></a>

### 页面与路由

| 路由 | 说明 |
|---|---|
| `#/ai` | 新建编排会话入口 |
| `#/ai/session/{id}` | 进行中的编排会话（左 **现场** + 右 AI 对话） |
| `#/ai/sessions` | 历史会话列表：继续 / **现场** / **回放** |

**PC 布局**：左侧 **现场**（命令输出）与右侧 AI 对话之间可**拖动分隔线**调整宽度，比例保存在浏览器 `localStorage`。

**移动端**：「现场 | AI 对话」标签切换，无分栏拖动。

<a id="ai-orchestration-concepts"></a>

### 核心概念

```mermaid
flowchart LR
    AS["ai_session<br/>编排会话"] --> SEG1["segment #1<br/>host A"]
    AS --> SEG2["segment #2<br/>host B"]
    SEG1 --> REC1["recording<br/>session_id"]
    SEG2 --> REC2["recording<br/>session_id"]
    AS --> LOG["live transcript<br/>{id}.log"]
    SEG1 --> SSE["现场 SSE"]
    SEG2 --> SSE
```

| 概念 | 说明 |
|---|---|
| **ai_session** | 一次编排对话 thread，对应 SQLite `ai_sessions` 表与聊天文件 `storage/neuron/ai-sessions/Y/m/d/neuron_{id}.chat` |
| **segment** | 在某台主机上的一段 exec 生命周期；切换 `host_id` 时结束旧 segment、开启新 segment |
| **live_key** | segment 对应的 SSE 旁路键，供 **现场** 实时推送 |
| **live transcript** | 编排会话全部命令输出的持久化文本，路径 `storage/neuron/ai-sessions/Y/m/d/live/{id}.log` |
| **segment 录像** | 每个 segment 对应一条 `sessions` 记录（`session_type=ai_exec`），写入 `storage/recordings/Y/m/d/{session_id}/` |

切换主机时，左侧 **现场** 会插入主机分隔线；各 segment 的 shell 环境**相互独立**（cwd、环境变量不共享）。

<a id="ai-orchestration-workflow"></a>

### 工作流程

1. 侧边栏进入 **AI 编排**，点击 **新建会话**（或从历史列表 **继续**）
2. 在右侧输入任务，例如：「检查 web-01 和 web-02 的 nginx 是否在运行」
3. AI 调用工具（见下表）；遇到 **RequireApproval** 的 **`run_ssh_command`** 时在底部 **批准 / 拒绝**（每条需审批命令均须人工确认）
4. 批准后 `SshExecBridge` 对该 `host_id` 建立（或复用）exec 连接并执行；输出写入左侧 **现场** 与 live log
5. AI 根据输出继续推理，可切换主机执行下一条命令，直至任务完成
6. 刷新 `#/ai/session/{id}` 后，**现场** 通过 SSE `replay` 事件或 transcript API 恢复；聊天 timeline 含工具卡片

<a id="ai-orchestration-tools"></a>

### 编排工具

| 工具 | 需审核 | 说明 |
|---|---|---|
| **`list_hosts`** | 否 | 列出平台已保存主机（id、名称、地址），供 AI 选择目标 |
| **`get_command_context`** | 否 | 读取本编排会话中，某主机上最近 AI 命令输出 |
| **`run_ssh_command`** | **视策略** | 在指定 `host_id` 上执行一条 shell 命令；只读自动执行，写操作需批准，危险拒绝 |
| **`ask_user`** | 否 | 需求不明确时向用户弹出选项（单选/多选） |

> 编排模式下 **`run_ssh_command` 必须带 `host_id`**；终端 AI 则固定在当前 `conn_id` 对应主机上执行。

<a id="ai-orchestration-field"></a>

### 现场、回放与历史

| 操作 | 入口 | 数据来源 |
|---|---|---|
| 实时看命令输出 | `#/ai/session/{id}` 左侧 **现场** | SSE `/live/stream`（含 `replay`） |
| 刷新后恢复现场 | 同上 | live log；若无 log 则从 segment 录像回填 |
| 历史滚动查看 | `#/ai/sessions` → **现场** | `GET /live/transcript` |
| 按时间轴回放 | `#/ai/sessions` → **回放** | 各 segment 的 asciinema manifest 顺序播放 |

AI 命令在现场中以 `[AI] $ command` / `[AI] exit N` 标记；切换主机时有 `────────── 主机 · … ──────────` 分隔。

<a id="ai-orchestration-api"></a>

### API

均需 Basic Auth + 2FA。路径中的 `{id}` 为 `ai_session_id`。

```text
GET  /api/ai/sessions                              # 分页列表
POST /api/ai/sessions                              # 新建 { title? }
GET  /api/ai/sessions/{id}                         # 会话详情 + segments
GET  /api/ai/sessions/{id}/bootstrap               # 聊天 bootstrap（含 timeline）
POST /api/ai/sessions/{id}/chat/stream             { message }
POST /api/ai/sessions/{id}/approval/stream         { approved: 1|0 }
POST /api/ai/sessions/{id}/feedback/stream         { answers: {} }
POST /api/ai/sessions/{id}/stop
POST /api/ai/sessions/{id}/reset
GET  /api/ai/sessions/{id}/live/stream             # 现场 SSE（replay / output / segment_switch）
GET  /api/ai/sessions/{id}/live/transcript         # 持久化现场文本
GET  /api/ai/sessions/{id}/recording               # 各 segment 录像 manifest 汇总
```

创建、命令批准/拒绝等操作写入 **操作日志**（如 `ai.session.created`、`ai.command.approved`）。

### 说明与限制

- **无需 Web 终端**：编排 exec 由服务端直接 `openssh` 连接，不占用浏览器 WebSocket PTY
- **每次 exec 是新 shell**：与终端 AI 相同，不要假设 `cd` 或 export 会跨命令保留；需要时用绝对路径或一条命令写完
- **交互式命令**：AI exec 无 stdin，vim、top、mysql 等 TUI 会挂起直至超时；请在 Web 终端 PTY 中手动操作
- **主机须预先录入**：只能对 **主机管理** 中已配置的主机执行；AI 通过 `list_hosts` 获取列表
- **单用户隔离**：会话按登录用户名隔离，只能访问自己的 `ai_session`

<a id="ai-config"></a>

## AI 配置（共用）

终端 **AI 助手** 与 **AI 编排** 共用同一套 AI 配置，通过 Web 面板管理（**不再**依赖 `.env` 中的 `NEURON_AI_*` 变量）。

### 入口

侧边栏 → **AI 设置**（`#/settings/ai`）

### 界面说明

| 区域 | 作用 |
|---|---|
| **顶部** | **启用 AI 助手** 开关；启用后右侧 **使用配置** 下拉框选择当前生效的配置 |
| **配置 Tab** | 仅用于**编辑**各套配置（名称、Provider、模型、密钥等），点击 Tab **不会**切换生效配置 |
| **表单** | Provider、模型、API Key；**拉取列表** 从 Provider API 获取可选模型；**测试连接** 验证密钥与模型 |

可保存多套配置（如 Deepseek、Ollama、OpenAI），切换生效配置时**无需重新输入密钥**。

### 支持的 Provider

OpenAI、Deepseek、Anthropic、Gemini、Ollama、Mistral、Cohere、Grok、ZAI、DashScope、HuggingFace、Azure OpenAI、Bedrock、Gemini Vertex、Anthropic Vertex、OpenAI 兼容接口（`openailike`）等。底层通过 [neuron-core/neuron-ai](https://github.com/neuron-core/neuron-ai) 统一接入。

### 高级选项

可在各配置中调整：HTTP 超时、默认命令超时、命令超时上限、工具调用上限、上下文窗口、对话总结阈值等（原 `NEURON_CHAT_*` / `AI_COMMAND_TIMEOUT` / `AI_COMMAND_TIMEOUT_MAX` 等能力）。

- **默认命令超时**：AI 未指定 `timeout_sec` 时使用（默认 30 秒）
- **命令超时上限**：AI 通过 `run_ssh_command` 的 `timeout_sec` 可请求的最大值（默认 300 秒）；超过上限会自动截断

<a id="ai-token-usage"></a>

### 聊天底部用量条

**AI 助手**（终端页侧栏）与 **AI 编排**（`#/ai/session/{id}`）对话区底部均显示上下文与 prompt 缓存用量（需已启用 AI 并完成 bootstrap 加载）。

| 指标 | 示例 | 说明 |
|---|---|---|
| **上下文占用** | `上下文 已用 12.4k · 上限 50k · 占用率 25%` | 当前对话估算 token 数 / 配置的**上下文窗口**上限；占用率 ≥ 80% 时高亮警告。接近上限时会触发历史裁剪或对话总结（见 [高级选项](#ai-config) 中的上下文窗口与总结阈值） |
| **本轮缓存** | `本轮 缓存命中 8.2k · 缓存命中率 91%` | **最近一次** LLM API 调用的 prompt 缓存 token 与缓存命中率 |
| **累计消耗** | `累计 总消耗 49.6k · 入命中 31.0k · 入未命中 11.2k · 输出 7.5k · 缓存命中率 74%` | 本 session **全部推理轮次**的计费 token 合计，口径与 Provider 后台账单一致（入命中 + 入未命中 + 输出） |

**缓存命中率计算（按 Provider 口径）：**

| 范围 | Provider 类型 | 公式 |
|---|---|---|
| 本轮 | OpenAI 系（OpenAI、DeepSeek、ZAI/GLM、`openailike` 等） | `cached ÷ input × 100`（cached 已含在 input 内） |
| 本轮 | Anthropic | `cached ÷ (input + cached) × 100`（两者分开统计） |
| 累计 | 全部 Provider | `入命中 ÷ (入命中 + 入未命中) × 100`；OpenAI 系未命中 = `input − cached`，Anthropic 未命中 = `input` |

**何时刷新：**

- 打开或切换对话时（bootstrap 的 `token_usage`）
- 多步 tool 循环中，每次 LLM 推理完成（决定调用工具）时，经 SSE **`usage`** 事件即时更新
- 最终文本回复、或弹出命令审批 / 用户反馈时，经 **`done`** 事件再次更新

页面刷新重连 SSE 时会回放已存储的 `usage` 事件，用量条与断线前保持一致。

**与 Provider 后台的对应关系：**

| 用量条 | Provider 后台 tooltip | 说明 |
|---|---|---|
| 上下文 · 已用 | — | 当前 prompt 估算大小，**不是**累计计费 token |
| 累计 · 总消耗 / 入命中 / 入未命中 / 输出 | 同日账单三类分项 | 数值应一致；`总消耗 = 入命中 + 入未命中 + 输出` |
| 累计 · 缓存命中率 | 由入命中与入未命中推算 | `入命中 ÷ (入命中 + 入未命中)`，**不含输出** |

注意：**缓存命中率 ≠ 入命中占总消耗的比例**。例如入命中 74,880、入未命中 15,195、输出 15,605 时，缓存命中率为 83%，而入命中占总消耗仅约 71%（74,880 ÷ 105,680）。后台柱状图按总消耗划分宽度，百分比标签则按 input 计算，二者不应直接对比。

**对话总结后：**

启用 [对话总结](#ai-config) 且上下文超过阈值（默认窗口 × 80%）时，旧消息会被压缩为一条 `## Previous conversation summary:` 摘要，并保留最近 N 条消息（默认 5）。此后各指标变化如下：

| 指标 | 总结后行为 |
|---|---|
| **上下文 · 已用** | 基于「摘要 + 保留消息」重新估算，通常明显下降 |
| **本轮 · 缓存命中** | 仍取总结后**最近一次**推理的 Provider 回报 |
| **累计 · 总消耗** | **从保留消息中的 usage 重新累加**；总结前写入 `.chat` 的旧 usage 已随历史删除，**不会继续计入**。与 Provider 后台当日总账单无直接对应 |

另：生成摘要本身会单独调用一次 LLM，该次 token **会计入 Provider 账单**，但**不会**出现在用量条（摘要结果以 User 消息写入，不带 `usage`）。

**对话总结与 prompt 缓存：**

Provider 的 prompt 缓存按**从前到后的前缀**匹配。总结会用新摘要替换大段旧历史，主对话的下一次请求前缀与总结前不同，因此：

- 总结**不会**发送显式「清缓存」指令，但效果上等同于缓存大幅失效，需重新建立；
- 总结后**首轮**主对话的「本轮 · 缓存命中率」通常会骤降；
- 后续几轮前缀稳定后，命中率会逐步回升。

保留的 N 条消息虽内容未变，但因其前序前缀已改为摘要，**无法**接续总结前的缓存链。

### 存储与安全

- 配置与 API Key 存 SQLite 表 `ai_profiles`，密钥经 `APP_KEY` 加密
- 仅当前**选中的**配置会被 Agent 加载使用
- 修改 AI 设置会写入 **操作日志**

### 从旧版 `.env` 迁移

若数据库中尚无 AI 配置，且 `.env` 里仍留有 `NEURON_AI_KEY`（或 `OPENAI_KEY`），服务启动时会**一次性**导入为名为「默认」的配置并选中。导入完成后请在面板中维护，无需再改 `.env`。

### API

均需 Basic Auth + 2FA。

```text
GET    /api/settings/ai                              # 配置列表 + 当前生效项
GET    /api/settings/ai/profiles/{id}                # 单套配置详情
POST   /api/settings/ai/profiles                       # 新建配置
PUT    /api/settings/ai/profiles/{id}                # 更新配置（支持部分字段）
DELETE /api/settings/ai/profiles/{id}                # 删除配置
POST   /api/settings/ai/profiles/{id}/select         # 切换生效配置
POST   /api/settings/ai/test                         # 测试连接
POST   /api/settings/ai/models                       # 拉取 Provider 模型列表
```

### 多 Worker 与 Redis

`HTTP_WORKERS>1` 时建议配置 `REDIS_URL`，用于 AI 线程锁与 SSE 状态同步（见 [`.env.example`](.env.example)）。

<a id="command-policy"></a>

## 命令策略（Web 面板）

AI 执行 `run_ssh_command` 前，**命令策略引擎**会用 [reactphp-x/unbash](https://github.com/reactphp-x/unbash) 将命令解析为 Bash AST，再按规则给出三种决策：

| 决策 | 策略含义 | 未开启会话自动批准 | 已开启会话自动批准 |
|---|---|---|---|
| **AutoRun** | 策略允许自动执行 | **逐条审批** | 自动执行 |
| **RequireApproval** | 策略标记为需审批 | **逐条审批** | **逐条审批** |
| **Deny** | 拒绝并反馈 AI | 拒绝 | 拒绝 |

**默认（未勾选会话自动批准）优先级最高**：AutoRun 与 RequireApproval 均须逐条审批。勾选后，仅 **AutoRun** 类命令可自动执行；**RequireApproval**（如 `mysql`、`rm`）与会话自动批准无关，始终逐条审批。

### 自动执行：何时发生、风险与建议

#### 什么情况下会**自动执行**（AutoRun）

策略引擎会先给出 AutoRun / RequireApproval / Deny 判定；**是否真正跳过审批**还取决于会话是否已开启自动批准（见上表）。

满足以下**全部**条件时，命令不经人工审批直接 exec：

1. **本会话已开启自动批准**（批准某条命令时勾选，或侧栏显示自动执行横幅）
2. 策略判定为 **AutoRun**（RequireApproval 与会话自动批准无关，始终须审批）
3. 解析出的可执行文件**不在** `deny_binaries` 中
4. 未命中内置硬拒绝（如 `curl | bash` 管道）
5. 命令可被 AST **完整解析**（解析失败时保守降级为 RequireApproval）

典型会自动执行的命令（以当前内置默认为例）：

| 类型 | 示例 | 说明 |
|---|---|---|
| 目录与文件查看 | `ls`、`pwd`、`stat`、`file` | 只读列举，不修改远端状态 |
| 文本读取 | `cat`、`head`、`tail`、`grep`、`awk`（纯读） | 仍可能读到敏感内容（见下） |
| 进程与资源观测 | `ps`、`df`、`du`、`free`、`uptime` | 巡检类只读 |
| 网络观测 | `ss`、`netstat`、`ip addr show` | 未列入拒绝/审批列表时自动执行 |
| 容器只读 | `docker ps`、`docker logs --tail 50` | 须先开启会话自动批准；`docker exec … mysql …` 策略为 RequireApproval |
| Neuron 只读工具 | `get_terminal_context`、`list_hosts`、`get_command_context` | 不发起 SSH exec，始终自动调用 |

#### 自动执行的主要**风险**

| 风险 | 说明 |
|---|---|
| **误读敏感信息** | `cat .env`、`grep password`、读数据库备份等「只读」命令仍可能泄露密钥、Token、PII |
| **AI 理解偏差** | 模型可能选错路径、主机或参数；自动执行意味着你来不及逐条核对 |
| **静态分析局限** | 变量展开（`$CMD`）、别名、间接调用无法完全静态判定；存在漏检或误判 |
| **嵌套与包装命令** | `sh -c` 内脚本会二次解析，但复杂 obfuscation 仍可能逃逸规则 |
| **只读≠无害** | 某些命令虽不改文件，但会触发副作用（如带写参数的 `curl`、读设备节点） |
| **批量放大** | 编排模式跨多机时，一条自动执行的命令会在每台目标上立即运行 |

**结论**：未开启会话自动批准时，**所有**非 Deny 命令均须逐条审批；开启自动批准后，AutoRun 类命令方可无人值守执行，但仍需注意下述风险。

#### 什么情况**不建议**依赖自动执行

以下场景应**保持 RequireApproval 或加入 `require_approval_binaries` / `deny_binaries`**，不要为求快而缩小审批面：

| 场景 | 建议 |
|---|---|
| **任何写操作** | 默认已在 `require_approval_binaries`：`rm`、`mv`、`cp`、`chmod`、`systemctl`、`kill`、数据库客户端、`kubectl` 等 |
| **生产 / 核心环境** | 将 `docker`、`kubectl`、业务脚本名等追加到 `require_approval_binaries`；敏感主机可用单主机覆盖策略 |
| **含密钥与合规要求** | 涉及凭据、用户数据、审计留痕时，宜逐条审批，勿依赖自动执行 |
| **不完全信任模型输出** | 新模型、复杂任务、多步推理时，人工过一遍命令再批准 |
| **交互式 / 长连接** | `vim`、`top`、`mysql` 客户端等已在 `deny_binaries`；AI exec 无 stdin，不应改为 AutoRun |
| **远程脚本注入** | `curl \| bash` 等已在内置硬拒绝；勿从 `deny_binaries` 中移除 |
| **解析不确定** | 含大量变量、here-doc、动态生成的命令会降级为 RequireApproval——这是预期行为 |

#### 配置调优方向

- **默认逐条审批**：新会话未勾选自动批准前，即使 `ls` 也会弹出审批——这是预期行为
- **更保守**：把更多二进制加入 `require_approval_binaries`（例如全局追加 `"docker"`），开启自动批准后仍标记为需审批
- **更严格**：将绝不允许 AI 触达的二进制加入 `deny_binaries`（如 `dd`、`reboot` 已在默认拒绝列表）
- **开启自动批准**：仅 **AutoRun** 类命令（如 `ls`）不再逐条弹窗；**RequireApproval** 仍须每次批准

### 入口

侧边栏 → **命令策略**（`#/settings/command-policy`）

### 界面说明

| 区域 | 作用 |
|---|---|
| **内置默认策略** | 只读展示 `config/command_policy.defaults.php` 中的 `deny_binaries`、`require_approval_binaries` |
| **覆盖策略列表** | 数据库中的增量规则，按作用域与优先级与内置默认合并 |
| **新建 / 编辑** | 名称、作用域（全局 / 主机分组 / 单主机）、优先级、启用开关、规则 JSON |

修改内置默认需编辑 [`config/command_policy.defaults.php`](config/command_policy.defaults.php) 并重启服务；Web 面板仅管理数据库覆盖项。

### 规则 JSON 可用键

均为**增量覆盖**（数组合并、同键追加）：

- `deny_binaries` — 拒绝的可执行文件
- `require_approval_binaries` — 需人工审批的可执行文件（会话自动批准无效）

示例（全局追加需审批命令）：

```json
{
  "require_approval_binaries": ["docker"]
}
```

### 存储与审计

- 覆盖策略存 SQLite 表 `command_policies`
- 每次 AI 命令执行写入 `command_executions`（命令、决策、exit_code 等）
- 策略增删改写入 **操作日志**（`command_policy` 资源）

### API

均需 Basic Auth + 2FA。

```text
GET    /api/settings/command-policy           # 内置默认 + 覆盖策略列表
PUT    /api/settings/command-policy           # 新建或更新覆盖策略
DELETE /api/settings/command-policy/{id}    # 删除覆盖策略
```

### 说明与限制

- **静态分析、不展开变量**：嵌套在 `sh -c` 内的脚本会二次解析；变量路径无法静态确定时可能漏检
- **内置硬拒绝**：`curl \| bash` 等管道组合（不写在配置里）
- **Web 终端 PTY** 手动输入不受策略约束，仅 AI exec 路径生效
- 解析失败时保守降级为 RequireApproval

<a id="field-vs-live"></a>

## 现场与实时现场

「**现场**」和「**实时现场**」是两个不同入口，不要混用：

| 名称 | 入口 | 用途 |
|---|---|---|
| **现场** | 终端页 / AI 编排页左栏；会话记录 / AI 会话历史的「现场」 | 查看**当前或历史**单次会话的输出（只读终端滚动） |
| **实时现场** | 左侧菜单 `#/live` | **进行中**多路 SSH 连接同时旁路监控，支持分屏、拖拽、置顶 |

<a id="field"></a>

### 现场

通过 SSE 旁路推送终端输出（终端页），或持久化 transcript（AI 编排），**只读**，不能代替终端输入。

| 入口 | 内容 |
|---|---|
| 终端页左侧 **现场** | 当前 `conn_id` 的 SSE 流：含**手动 PTY 输出**与 **AI exec 输出**（`[AI]` 前缀） |
| AI 编排页左侧 **现场** | 当前 `ai_session_id` 的命令输出；持久化到 live log，刷新可恢复 |
| **会话记录 → 现场** | 从 asciinema cast 提取完整输出，只读滚动查看 |
| **AI 会话 → 现场** | 从 `/live/transcript` 加载编排会话 transcript |

AI 执行的命令输出会以 `[AI] $ command` / `[AI] exit N` 形式出现在现场流中。

```text
GET /api/live/sessions/{conn_id}/stream          # 终端页现场 SSE
GET /api/ai/sessions/{id}/live/stream            # AI 编排现场 SSE
GET /api/ai/sessions/{id}/live/transcript        # AI 编排持久化现场
```

<a id="live-monitor"></a>

### 实时现场

左侧菜单 **实时现场**（`#/live`）用于运维监控：列出所有进行中的 SSH 连接，多窗口同时观看，会话结束后可保留面板直至手动清除。

```text
GET /api/live/sessions?include_finished=1
GET /api/live/sessions/{conn_id}/stream   # SSE
```

<a id="recording"></a>

## 会话录像、现场与回放

SSH 连接建立后，服务端自动将终端 **输出** 写入 asciinema cast v2（`storage/recordings/Y/m/d/{session_id}/`），会话结束时生成 `manifest.json`。

| 项目 | 说明 |
|---|---|
| 格式 | cast v2（`.cast`），大文件自动分片 `part-001.cast`、`part-002.cast` … |
| 内容 | 录制终端与 AI 命令输出；不录键盘输入与 xterm 焦点/鼠标协议 |
| **现场** | **会话记录** 或 **AI 会话** 页点击 **现场**，只读终端滚动查看完整输出 |
| **回放** | **会话记录** 页点击 **回放**；asciinema 播放器按时间轴播放 |
| AI 编排 | 每个主机分段独立录像；编排会话另有 live transcript 供现场查看 |
| 鉴权 | 与 REST API 相同（Basic Auth + 2FA） |

```text
GET /api/sessions/{id}/recording
GET /api/sessions/{id}/recording/part-001.cast
```

可通过 `SESSION_RECORDING_ENABLED=false` 关闭。录像目录默认不纳入 git。

> btop 等使用 `\033[?2026h` 同步刷新的 TUI，录制端会等待整帧输出后再落盘，以保证回放画面完整。

<a id="auth"></a>

## 认证说明

启用 `BASIC_AUTH_USER` / `BASIC_AUTH_PASSWORD` 后：

| 层 | 机制 | Cookie |
|---|---|---|
| 账号密码 | `/api/login` 或 `Authorization: Basic` | `web_ssh_auth`（滑动空闲 4h，HMAC 签名） |
| 双因子 | `/api/2fa/verify` | `web_ssh_2fa`（服务端 session 滑动空闲 4h） |

- **滑动续期**：持续使用（API 请求或前端每 30 分钟 keepalive）会自动延长登录与 2FA 会话；空闲超过 `AUTH_SESSION_TTL`（默认 4 小时）后才需重新登录/验证
- 同一账号 **2FA 仅保留一个有效会话**（新验证会使旧 session 失效）
- HTTPS 部署时设置 `COOKIE_SECURE=true`
- 公开路径（无需 Basic Auth）：`/health`、`/login`、`/logout`、`/api/login`

<a id="websocket"></a>

## WebSocket 协议

连接（需已通过 Basic Auth + 2FA）：

```text
ws://host:port/ws?hostId=1
```

流程：

1. 服务端返回 `ready`（含主机摘要）
2. 客户端发送 `{"type":"auth","cols":120,"rows":40}` — 使用数据库中已存凭据，**不在 WebSocket 中传输密码**
3. 服务端返回 `connected`（含 `_id` 即 `conn_id`）或 `error`
4. 交互：`input`（终端输入）、`resize`（窗口大小）
5. 输出：`output`（base64 编码的终端数据）

<a id="config"></a>

## 配置

完整列表见 [`.env.example`](.env.example)。常用项：

```env
# 服务
HTTP_LISTEN=0.0.0.0:8095
HTTP_WORKERS=1

# 数据库
DB_PATH=storage/web_ssh.sqlite

# SSH
SSH_IDENTITY_FILE=~/.ssh/id_rsa
SSH_CONNECT_TIMEOUT=10

# 会话录像
SESSION_RECORDING_ENABLED=true
SESSION_RECORDING_DIR=storage/recordings

# AI — 在 Web 面板配置：侧边栏 → AI 设置 (#/settings/ai)
# 命令策略 — 在 Web 面板配置：侧边栏 → 命令策略 (#/settings/command-policy)
# 或编辑 config/command_policy.defaults.php 修改内置默认规则
# HTTP_WORKERS>1 时建议配置 REDIS_URL

# 安全
COOKIE_SECURE=false
AUTH_SESSION_TTL=14400
AUTH_SESSION_RENEW_INTERVAL=1800
BASIC_AUTH_USER=admin
BASIC_AUTH_PASSWORD=change-me
```

全局 SSH 候选密钥见 `config/ssh.php` 的 `identity_candidates`。主机凭据经 `APP_KEY` 加密存储；私钥可存 PEM 内容或服务器路径（路径仅在服务端解析）。

<a id="architecture"></a>

## 架构

文字版与上图一致，便于复制到文档或终端：

```text
Browser (Vue + xterm.js)
  ├── 交互终端 ── WS /ws?hostId=N ── SshTerminalGateway ── PTY ── OpenSSH
  ├── 现场（终端页）── SSE /api/live/sessions/{conn_id}/stream
  ├── 现场（AI 编排）── SSE /api/ai/sessions/{id}/live/stream
  │                      └── transcript /api/ai/sessions/{id}/live/transcript
  ├── 实时现场（#/live）── SSE /api/live/sessions（多路监控）
  ├── 历史现场 / 回放 ── cast manifest + asciinema-player
  └── AI 对话 ── HTTP /api/ai/* 、/api/ai/sessions/*

SshTerminalGateway
  ├── SshTerminalSession → openssh PTY（交互终端，conn_id）
  └── SshSessionBridge → exec + 现场 SSE + Recorder（终端 AI）

SshExecBridge（编排 AI，ai_session_id）
  ├── 按 host 分段 segment，每段独立 SSH exec + 录像
  ├── SshLiveRegistry（现场 SSE 旁路）
  └── AiSessionLiveTranscript（live log 持久化）

Neuron Agents
  ├── SshAgent + RunSshCommandTool（终端，conn_id）
  └── OrchestratorAgent + list_hosts / run_ssh_command（编排，ai_session_id）

CommandPolicyEngine（unbash AST）
  ├── Neuron 中间件：AutoRun / RequireApproval / Deny → 审批或 ToolFeedback
  └── SSH Bridge 硬门禁：Deny 不可绕过

Storage: SQLite（主机、会话、ai_sessions、ai_profiles、command_policies、command_executions、审计）+ storage/recordings/Y/m/d/ + storage/neuron/Y/m/d/
```

> 录像与 neuron 聊天/现场文件按 **`Y/m/d` 日期子目录**落盘；旧版扁平路径（如 `recordings/{id}`、`ai-sessions/live/{id}.log`）仍可读取。

中间件栈：`AccessLog` → `JsonErrorHandler` → `BasicAuthHandler` → `TwoFactorAuthHandler` → 路由。

<a id="security"></a>

## 安全提示

Web SSH 会把 shell 暴露到浏览器，请务必：

- 仅在内网或 VPN 后使用，生产环境加 **HTTPS**
- 启用 Basic Auth + 2FA，设置强密码
- 配置 `COOKIE_SECURE=true`（HTTPS 下）
- 限制网络访问（防火墙 / 反向代理 IP 白名单）
- 妥善保管 `APP_KEY` 与 `.env`，不要提交到版本库
- AI 批准的命令等同你在服务器上执行 shell，务必审阅后再点批准
- **命令策略**会拦截 `curl | bash` 等高危命令；`rm` 等写操作需人工审批，但无法覆盖所有误操作
- 会话「自动批准」开启后，仅 **AutoRun** 命令自动执行；**RequireApproval** 仍须逐条审批；**Deny** 始终拒绝
- Docker 中 SSH 私钥挂载为只读

<a id="test"></a>

## 测试

```bash
composer test
```

<a id="license"></a>

## 许可证

[MIT](LICENSE)
