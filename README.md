# reactphp-x/web-ssh

浏览器里的 SSH 终端与管理平台。通过 Web 管理主机、打开多标签终端、查看实时会话与操作审计。

## 解决的问题

运维或开发经常需要在浏览器可及的环境里操作远端服务器，常见痛点包括：

- **本地终端受限** — 换设备、无 VPN、或防火墙不允许直连 22 端口时，难以快速登录
- **凭据分散** — 多台主机的地址、账号、密钥散落在各处，缺少统一入口
- **跳板机链路复杂** — 经堡垒机/多级跳转连接时，手工维护 `~/.ssh/config` 容易出错
- **协作与监管** — 需要查看「谁连了哪台机器、做了什么配置变更」，纯 SSH 客户端难以留痕
- **暴露面风险** — 把 SSH 直接暴露到公网不安全，需要登录鉴权、审计与访问控制
- **远端 AI 开发** — 希望在服务器上跑 Claude Code、Cursor Agent 等 TUI 工具，但缺少可用的 Web 终端

本项目提供一个 **自托管的 Web SSH 网关**：

1. 在浏览器里打开终端，无需安装本地 SSH 客户端
2. 集中管理主机与加密存储的凭据，支持跳板机一键连接
3. 记录会话与 API 操作，支持实时旁路观看
4. 通过 Basic Auth + TOTP 双因子、速率限制与 Cookie 策略控制访问

适合内网运维面板、开发跳板、小团队服务器管理等场景。**不应未经防护直接暴露到公网。**

### TUI 与 AI Agent

终端基于 **真实 PTY**（非纯 stdout 管道），支持键盘交互、颜色与窗口缩放，可在远端 shell 中运行 TUI 类工具，例如：

- **[Claude Code](https://docs.anthropic.com/en/docs/claude-code)** — `claude` 等命令行 AI 编程助手
- **[Cursor Agent](https://cursor.com)** — 远端通过 SSH 使用 Cursor CLI / Agent 工作流

连接后在终端里像本地一样启动对应命令即可；窗口大小变化会通过 `resize` 同步到 PTY。

## 功能

- **主机管理** — SQLite 存储主机、分组、标签；密码/私钥加密保存，支持跳板机
- **Web 终端** — xterm.js 多标签 PTY，OpenSSH 子进程连接远端；支持 **TUI 交互程序**（如 Claude Code、Cursor Agent 等）
- **实时现场** — SSE 旁路观看正在进行的 SSH 会话
- **会话记录** — 连接起止、状态、耗时；SSH 期间自动写入 asciinema cast 录像（`storage/recordings/`）
- **会话回放** — 会话记录页点击 **回放**，浏览器内播放终端录像；支持多分片、进度控制与窗口自适应缩放
- **操作审计** — 主机增删改等 API 操作日志
- **登录鉴权** — HTTP Basic Auth + TOTP 双因子（可选 Cookie 登录页）
- **速率限制** — 登录与 TOTP 失败锁定

## 技术栈

| 层 | 组件 |
|---|---|
| 运行时 | [reactphp-x/framework](https://github.com/reactphp-x/framework) + [reactphp-x/worker](https://github.com/reactphp-x/worker) |
| WebSocket | [reactphp-x/websocket-group](https://github.com/reactphp-x/websocket-group) |
| SSH | 系统 `ssh` / `openssh-client`（`react/child-process`） |
| 前端 | Vue 3 + [xterm.js](https://xtermjs.org/) |
| 存储 | SQLite（`ext-sqlite3`） |
| 2FA | [wpjscc/twofactorauth](https://github.com/wpjscc/twofactorauth) + Bacon QR Code |
| 日志 | [reactphp-x/log](https://github.com/reactphp-x/log) |

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

生成 `APP_KEY` 示例：

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

默认监听地址由 `HTTP_LISTEN` 决定（`.env.example` 为 `0.0.0.0:8095`）。

开发模式下会自动监视 `src/`、`config/`、`public/` 变更并 reload。

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

> 注意：`docker-compose.yml` 中的 `environment` 会覆盖 `.env` 里的 `HTTP_LISTEN`。主机私钥路径请使用容器内路径（如 `~/.ssh/id_rsa`），不要写 macOS 绝对路径。

## 使用

1. 打开 `/login`，输入平台账号密码
2. 首次使用需绑定 TOTP（扫码）；之后每次登录输入 6 位验证码
3. 在 **主机管理** 中新建/编辑主机（地址、端口、用户名、密码或私钥、可选跳板机）
4. 进入 **终端**，选择主机建立 SSH 会话；右侧 **AI 助手** 可描述任务，AI 提议命令后需你审核才会发送到终端
5. **实时现场** 可观看当前进行中的连接；**会话记录** / **操作日志** 查看历史
6. 在 **会话记录** 中，若该次连接有录像，点击 **回放** 可在浏览器内播放 asciinema 终端录像

也可在 shell 中手动运行 `claude`、`cursor agent` 等 TUI 程序。

未登录访问 `/` 会重定向到 `/login`；点击 **退出** 清除登录与 2FA Cookie。

## 会话录像与回放

SSH 连接建立后，服务端会自动将终端 **输出** 写入 asciinema cast v2 文件（`storage/recordings/{session_id}/`），会话结束时生成 `manifest.json` 并在数据库中标记 `recording_url`。

| 项目 | 说明 |
|---|---|
| 格式 | asciinema cast v2（`.cast`），大文件自动分片 `part-001.cast`、`part-002.cast` … |
| 内容 | 仅录制终端输出（含 echo）；不录键盘输入与 xterm 焦点/鼠标协议，避免回放卡顿 |
| 时间轴 | 每条事件为距会话开始的绝对秒数，与列表中的会话时长一致 |
| 回放 | 会话记录页点击 **回放**；播放器自动缩放适配窗口（`fit: both`） |
| 鉴权 | 与 REST API 相同，需 Basic Auth + 2FA Cookie |

相关 API（均需登录）：

```text
GET /api/sessions/{id}/recording              # manifest + 分片 URL 列表
GET /api/sessions/{id}/recording/part-001.cast  # 单个 cast 分片
```

可通过环境变量关闭或调整存储路径（见下方 **配置**）。录像文件默认不纳入 git（`storage/recordings/`）。

> **说明**：btop 等使用 `\033[?2026h` 同步刷新的 TUI，录制端会等待整帧输出后再落盘，以保证回放画面完整。

## AI 助手（Neuron）

SSH 连接成功后，终端页右侧出现 **AI 助手** 侧栏。基于 [reactphp-x-ai-v2](https://github.com/reactphp-x/ai-v2) 的 Neuron AI 能力嵌入：

1. 在侧栏描述任务（如「查看磁盘使用并清理 /tmp」）
2. AI 分析后调用 `run_ssh_command` 提议命令
3. 侧栏弹出 **待审核命令**，你点击 **批准** 或 **拒绝**
4. 批准后命令写入 PTY 执行，输出回传 AI 继续推理
5. 需求不明确时 AI 可能通过 `ask_user` 弹出选项让你选择

配置（`.env`）：

```env
AI_ENABLED=true
NEURON_AI_KEY=sk-...
NEURON_AI_MODEL=gpt-4o-mini
# NEURON_AI_BASE_URL=https://api.deepseek.com/v1
NEURON_AI_HTTP_TIMEOUT=120
AI_COMMAND_TIMEOUT=30
REDIS_URL=127.0.0.1:6379   # 可选，HTTP_WORKERS>1 时建议配置
```

API（均需 Basic Auth + 2FA）：

```text
GET  /api/ai/bootstrap?conn_id={ws_conn_id}
POST /api/ai/chat/stream           { conn_id, message }
POST /api/ai/chat/approval/stream  { conn_id, approved: 1|0 }
POST /api/ai/chat/feedback/stream  { conn_id, answers: {} }
POST /api/ai/chat/stop             { conn_id }
POST /api/ai/chat/reset            { conn_id }
```

`conn_id` 为 WebSocket `ready` 消息中的 `_id`，与当前 SSH 会话绑定。命令审核记录写入 **操作日志**（`ai.command.approved` / `ai.command.rejected`）。

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

连接（需已通过 Basic Auth + 2FA，浏览器会自动带 Cookie）：

```text
ws://host:port/ws?hostId=1
```

流程：

1. 服务端返回 `ready`（含主机摘要）
2. 客户端发送 `{"type":"auth","cols":120,"rows":40}` — 使用数据库中该主机的已存凭据，**不在 WebSocket 中传输密码**
3. 服务端返回 `connected` 或 `error`
4. 交互：`input`（终端输入）、`resize`（窗口大小）
5. 输出：`output`（base64 编码的终端数据）

## 配置

主要环境变量（完整列表见 `.env.example`）：

```env
# 服务
HTTP_LISTEN=0.0.0.0:8095
HTTP_WORKERS=1

# 数据库
DB_PATH=storage/web_ssh.sqlite
DB_AUTO_MIGRATE=true

# SSH 默认私钥（主机未单独指定时使用）
SSH_IDENTITY_FILE=~/.ssh/id_rsa
SSH_CONNECT_TIMEOUT=10

# 日志
LOG_PATH=storage/logs/app.log
HTTP_ACCESS_LOG=storage/logs/access.log
LOG_ROTATION_MAX_FILES=14

# 安全
COOKIE_SECURE=false
BASIC_AUTH_USER=admin
BASIC_AUTH_PASSWORD=change-me
BASIC_AUTH_PUBLIC_PATHS=/health,/logout,/login

# 会话录像（asciinema cast，默认开启）
SESSION_RECORDING_ENABLED=true
SESSION_RECORDING_DIR=storage/recordings
SESSION_RECORDING_PART_BYTES=5242880

# AI 助手
AI_ENABLED=true
NEURON_AI_KEY=
NEURON_AI_MODEL=gpt-4o-mini
AI_COMMAND_TIMEOUT=30
```

全局 SSH 候选密钥列表见 `config/ssh.php` 的 `identity_candidates`。

主机凭据保存在 SQLite，经 `APP_KEY` 加密；私钥可存 PEM 内容或服务器路径（路径仅在服务端解析，不返回浏览器）。

## 架构

```text
Browser (Vue + xterm.js)
  ├── HTTP  /api/*  REST
  └── WS    /ws?hostId=N
        └── websocket-group
              └── SshTerminalGateway
                    └── SshTerminalSession
                          └── Ssh2Client → openssh (PTY)
                                └── OpenSshWorkspace (ssh_config + jump hosts)
```

中间件栈：`AccessLog` → `JsonErrorHandler` → `BasicAuthHandler` → `TwoFactorAuthHandler` → 路由。

## 安全提示

Web SSH 会把 shell 暴露到浏览器，请务必：

- 仅在内网或 VPN 后使用，生产环境加 **HTTPS**
- 启用 Basic Auth + 2FA，设置强密码
- 配置 `COOKIE_SECURE=true`（HTTPS 下）
- 限制网络访问（防火墙 / 反向代理 IP 白名单）
- 妥善保管 `APP_KEY` 与 `.env`，不要提交到版本库
- Docker 中 SSH 私钥挂载为只读

## 测试

```bash
composer test
```

## 许可证

[MIT](LICENSE)
