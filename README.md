# reactphp-x/web-ssh

浏览器里的 SSH 终端与管理平台。集中管理主机凭据、多标签 Web 终端、AI 辅助运维、实时旁路与会话回放。

## 解决的问题

运维或开发经常需要在浏览器可及的环境里操作远端服务器，常见痛点包括：

- **本地终端受限** — 换设备、无 VPN、或防火墙不允许直连 22 端口时，难以快速登录
- **凭据分散** — 多台主机的地址、账号、密钥散落在各处，缺少统一入口
- **跳板机链路复杂** — 经堡垒机/多级跳转连接时，手工维护 `~/.ssh/config` 容易出错
- **协作与监管** — 需要查看「谁连了哪台机器、做了什么」，纯 SSH 客户端难以留痕
- **暴露面风险** — 把 SSH 直接暴露到公网不安全，需要登录鉴权、审计与访问控制
- **重复劳动** — 查日志、看磁盘、改配置等常见任务，希望用自然语言描述后由系统提议命令

本项目提供一个 **自托管的 Web SSH 网关**：

1. 在浏览器里打开终端，无需安装本地 SSH 客户端
2. 集中管理主机与加密存储的凭据，支持跳板机一键连接
3. 可选 **AI 助手**：描述任务 → 审核命令 → 自动执行并继续推理
4. 实时旁路观看进行中的会话，结束后可回放 asciinema 录像
5. 通过 Basic Auth + TOTP 双因子、速率限制与 Cookie 策略控制访问

适合内网运维面板、开发跳板、小团队服务器管理等场景。**不应未经防护直接暴露到公网。**

## 功能概览

| 模块 | 说明 |
|---|---|
| **主机管理** | SQLite 存储主机、分组、标签；密码/私钥加密保存，支持跳板机 |
| **Web 终端** | xterm.js 多标签 **PTY**，OpenSSH 子进程连接远端；支持 TUI（vim、htop、Claude Code 等） |
| **AI 助手** | 终端页侧栏；自然语言描述任务，**批准后才执行** `run_ssh_command` |
| **实时现场** | SSE 旁路观看**进行中**的 SSH 会话（只读，不可输入） |
| **会话记录** | 连接起止、状态、耗时；自动写入 asciinema cast（`storage/recordings/`） |
| **会话回放** | 对已结束会话播放终端录像；多分片、进度控制、窗口自适应 |
| **操作审计** | 主机增删改、AI 命令批准/拒绝等 API 操作日志 |
| **登录鉴权** | HTTP Basic Auth + TOTP 双因子（可选 Cookie 登录页） |

### 终端、AI、实时、回放怎么选？

| 你想做什么 | 用哪个 |
|---|---|
| 亲手输入命令、跑 TUI 程序 | **Web 终端**（PTY） |
| 用自然语言让 AI 提议并执行 shell 命令 | **AI 助手**（需逐条批准） |
| 实时看别人/自己的终端输出（会话进行中） | **实时现场** |
| 事后查看某次连接屏幕上显示了什么 | **会话记录 → 回放** |
| AI 跑命令时对照终端实际输出 | **AI 助手** + 侧栏展开 **实时现场** |

> **PTY 与 AI exec 是两条通道**：你在终端里的交互 shell（`cd`、环境变量、TUI）与 AI 执行的命令**相互独立**。AI 每次命令走独立 SSH exec，输出带 `[AI]` 前缀写入实时流，**不会**混进你的交互终端。

### TUI 与远端 AI 开发工具

终端基于 **真实 PTY**，支持键盘交互、颜色与窗口缩放，可在远端 shell 中运行：

- **[Claude Code](https://docs.anthropic.com/en/docs/claude-code)** — `claude` 等命令行 AI 编程助手
- **[Cursor Agent](https://cursor.com)** — 远端通过 SSH 使用 Cursor CLI / Agent 工作流

连接后在**左侧终端**里像本地一样启动即可；窗口大小变化会通过 `resize` 同步到 PTY。内置 **AI 助手**是另一套能力，用于「描述任务 → 审核命令 → 自动 exec」，不替代上述 TUI 工具。

## 技术栈

| 层 | 组件 |
|---|---|
| 运行时 | [reactphp-x/framework](https://github.com/reactphp-x/framework) + [reactphp-x/worker](https://github.com/reactphp-x/worker) |
| WebSocket | [reactphp-x/websocket-group](https://github.com/reactphp-x/websocket-group) |
| SSH | 系统 `openssh-client`（PTY + 独立 exec 通道） |
| AI | [neuron-core/neuron-ai](https://github.com/neuron-core/neuron-ai) |
| 前端 | Vue 3 + [xterm.js](https://xtermjs.org/) + [asciinema-player](https://github.com/asciinema/asciinema-player) |
| 存储 | SQLite（`ext-sqlite3`） |
| 可选 | Redis（多 Worker 时 AI 线程锁 / SSE 状态） |
| 2FA | [wpjscc/twofactorauth](https://github.com/wpjscc/twofactorauth) + Bacon QR Code |

## 要求

- PHP 8.2+
- 扩展：`ext-sqlite3`、`pcntl`、`posix`
- 系统：`openssh-client`（Docker 镜像已包含）
- Linux / macOS（Worker 模式不支持 Windows）
- Composer 2.x

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

## 启动

```bash
php start.php start          # 前台
php start.php start -d       # 后台
php start.php stop
php start.php restart
php start.php status
```

默认监听地址由 `HTTP_LISTEN` 决定（`.env.example` 为 `0.0.0.0:8095`）。开发模式下会自动监视 `src/`、`config/`、`public/` 变更并 reload。

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

## 快速上手

1. 打开 `/login`，输入平台账号密码
2. 首次使用绑定 TOTP（扫码）；之后每次登录输入 6 位验证码
3. 在 **主机管理** 中新建主机（地址、端口、用户名、密码或私钥、可选跳板机）
4. 进入 **终端** 建立 SSH 会话；主机列表 **登录** 默认左侧为交互终端，**AI 助手** 进入同一终端页但默认左侧为 **实时现场**
5. 终端页右侧 **AI 助手**（需配置 `NEURON_AI_KEY`）可描述运维任务；**左侧顶部**可切换 **实时现场 / 终端**（终端可手动输入）
6. **实时现场**（菜单或 AI 侧栏内）可旁路观看输出；**会话记录** 中可 **回放** 已结束的录像

未登录访问 `/` 会重定向到 `/login`；点击 **退出** 清除登录与 2FA Cookie。

## AI 助手

SSH 连接成功后，终端页右侧出现 **AI 助手** 侧栏（移动端为「终端 | AI 助手」标签切换）。从 **主机管理** 点击 **AI 助手** 进入**同一终端页**，默认左侧为 **实时现场**，右侧为 AI 对话；可在**左侧顶部**切换为 **交互终端** 并手动输入。

### 工作流程

1. 在底部输入框描述任务（如「查看磁盘使用并清理 /tmp」）
2. AI 分析后可调用工具：
   - **`get_terminal_context`** — 读取终端最近输出（只读，**无需审核**）
   - **`run_ssh_command`** — 提议 shell 命令（**必须审核**）
   - **`ask_user`** — 需求不明确时弹出选项（单选/多选）
3. 出现 **待审核命令** 时，在侧栏底部点击 **批准** 或 **拒绝**
4. 批准后通过 **独立 SSH exec** 执行；输出回传 AI 继续推理（可多轮）
5. 点击 **工具调用** 可弹窗查看每次工具的参数与返回

### 配置

```env
AI_ENABLED=true
NEURON_AI_KEY=sk-...
NEURON_AI_MODEL=gpt-4o-mini
# NEURON_AI_PROVIDER=openai
# NEURON_AI_BASE_URL=https://api.deepseek.com/v1
NEURON_AI_HTTP_TIMEOUT=120
AI_COMMAND_TIMEOUT=30
NEURON_AI_TOOL_MAX_RUNS=30    # 单轮对话工具调用上限（默认 30）
REDIS_URL=127.0.0.1:6379      # HTTP_WORKERS>1 时建议配置
```

### API

均需 Basic Auth + 2FA。`conn_id` 为 WebSocket `connected` 消息中的 `_id`，与当前 SSH 会话绑定。

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

- AI exec 每次为**新 shell 进程**，工作目录/环境可能与交互 PTY 不一致（例如在 PTY 里 `cd` 后，AI 仍可能从 home 执行）
- 不支持交互式命令（vim、top、mysql 客户端等）；请在左侧 PTY 终端手动操作
- 仅 `run_ssh_command` 会触发待审核；只读工具自动执行

## 实时现场

通过 SSE 旁路推送终端输出，**只读**，不能代替终端输入。

| 入口 | 用途 |
|---|---|
| 左侧菜单 **实时现场** | 多路连接同时观看，支持分屏、拖拽调整 |
| 终端页左侧切换 | 左侧顶部在 **实时现场** 与 **交互终端** 间切换 |
| 菜单 **实时现场** | 多连接监控墙 |

AI 执行的命令输出会以 `[AI] $ command` / `[AI] exit N` 形式出现在实时流中。

```text
GET /api/live/sessions
GET /api/live/sessions/{conn_id}/stream   # SSE
```

## 会话录像与回放

SSH 连接建立后，服务端自动将终端 **输出** 写入 asciinema cast v2（`storage/recordings/{session_id}/`），会话结束时生成 `manifest.json`。

| 项目 | 说明 |
|---|---|
| 格式 | cast v2（`.cast`），大文件自动分片 `part-001.cast`、`part-002.cast` … |
| 内容 | 仅录制终端输出；不录键盘输入与 xterm 焦点/鼠标协议 |
| 回放 | **会话记录** 页点击 **回放**；播放器自适应窗口 |
| 鉴权 | 与 REST API 相同（Basic Auth + 2FA） |

```text
GET /api/sessions/{id}/recording
GET /api/sessions/{id}/recording/part-001.cast
```

可通过 `SESSION_RECORDING_ENABLED=false` 关闭。录像目录默认不纳入 git。

> btop 等使用 `\033[?2026h` 同步刷新的 TUI，录制端会等待整帧输出后再落盘，以保证回放画面完整。

## 认证说明

启用 `BASIC_AUTH_USER` / `BASIC_AUTH_PASSWORD` 后：

| 层 | 机制 | Cookie |
|---|---|---|
| 账号密码 | `/api/login` 或 `Authorization: Basic` | `web_ssh_auth`（12h，HMAC 签名） |
| 双因子 | `/api/2fa/verify` | `web_ssh_2fa`（服务端 session 12h） |

- 同一账号 **2FA 仅保留一个有效会话**（新验证会使旧 session 失效）
- HTTPS 部署时设置 `COOKIE_SECURE=true`
- 公开路径（无需 Basic Auth）：`/health`、`/login`、`/logout`、`/api/login`

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

# AI 助手
AI_ENABLED=true
NEURON_AI_KEY=
NEURON_AI_MODEL=gpt-4o-mini
AI_COMMAND_TIMEOUT=30
NEURON_AI_TOOL_MAX_RUNS=30

# 安全
COOKIE_SECURE=false
BASIC_AUTH_USER=admin
BASIC_AUTH_PASSWORD=change-me
```

全局 SSH 候选密钥见 `config/ssh.php` 的 `identity_candidates`。主机凭据经 `APP_KEY` 加密存储；私钥可存 PEM 内容或服务器路径（路径仅在服务端解析）。

## 架构

```text
Browser (Vue + xterm.js)
  ├── HTTP  /api/*     REST（主机、会话、AI、回放、实时）
  └── WS    /ws?hostId=N
        └── SshTerminalGateway
              ├── SshTerminalSession → Ssh2Client → openssh PTY（交互终端）
              └── SshSessionBridge
                    ├── Live SSE（旁路输出）
                    ├── Session Recorder（asciinema）
                    └── SshExecRunner → openssh exec（AI 命令，独立通道）
                          └── Neuron SshAgent（工具 + 审核工作流）
```

中间件栈：`AccessLog` → `JsonErrorHandler` → `BasicAuthHandler` → `TwoFactorAuthHandler` → 路由。

## 安全提示

Web SSH 会把 shell 暴露到浏览器，请务必：

- 仅在内网或 VPN 后使用，生产环境加 **HTTPS**
- 启用 Basic Auth + 2FA，设置强密码
- 配置 `COOKIE_SECURE=true`（HTTPS 下）
- 限制网络访问（防火墙 / 反向代理 IP 白名单）
- 妥善保管 `APP_KEY` 与 `.env`，不要提交到版本库
- AI 批准的命令等同你在服务器上执行 shell，务必审阅后再点批准
- Docker 中 SSH 私钥挂载为只读

## 测试

```bash
composer test
```

## 许可证

[MIT](LICENSE)
