const { createApp, ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } = Vue;

const AUTH_KEEPALIVE_MS = 30 * 60 * 1000;

let authStateHandler = null;

const handleAuthFailure = (status, code, url) => {
    if (status === 401) {
        if (!url.includes('/api/login')) {
            location.href = '/login';
        }
        return true;
    }
    if (status === 403 && code === 'two_factor_required') {
        if (authStateHandler) {
            authStateHandler.onTwoFactorRequired();
        }
        return true;
    }
    return false;
};

const api = {
    async request(method, url, body) {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json' },
        };
        if (body !== undefined) {
            options.body = JSON.stringify(body);
        }
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.message || `HTTP ${response.status}`);
            error.status = response.status;
            error.code = data.code || '';
            error.payload = data;
            handleAuthFailure(response.status, error.code, url);
            throw error;
        }
        return data;
    },
    get: (url) => api.request('GET', url),
    post: (url, body) => api.request('POST', url, body),
    put: (url, body) => api.request('PUT', url, body),
    delete: (url) => api.request('DELETE', url),
};

const parseRoute = () => {
    const hash = location.hash.replace(/^#/, '') || '/hosts';
    const parts = hash.split('/').filter(Boolean);
    if (parts[0] === 'ai' && parts[1] === 'sessions') {
        return {
            name: 'ai-sessions',
            id: null,
            nonce: null,
            mode: null,
        };
    }
    if (parts[0] === 'ai' && parts[1] === 'session' && parts[2]) {
        return {
            name: 'ai-session',
            id: Number(parts[2]),
            nonce: null,
            mode: 'session',
        };
    }
    if (parts[0] === 'ai' && parts[1] && parts[1] !== 'session' && parts[1] !== 'sessions') {
        const hostId = Number(parts[1]);
        if (!Number.isNaN(hostId) && hostId > 0) {
            return {
                name: 'terminal',
                id: hostId,
                nonce: parts[2] || String(Date.now()),
                mode: 'ai',
            };
        }
    }
    if (parts[0] === 'ai') {
        return {
            name: 'ai-home',
            id: null,
            nonce: null,
            mode: null,
        };
    }
    if (parts[0] === 'settings' && parts[1] === 'ai') {
        return {
            name: 'ai-settings',
            id: null,
            nonce: null,
            mode: null,
        };
    }
    if (parts[0] === 'settings' && parts[1] === 'command-policy') {
        return {
            name: 'command-policy-settings',
            id: null,
            nonce: null,
            mode: null,
        };
    }
    return {
        name: parts[0] || 'hosts',
        id: parts[1] ? Number(parts[1]) : null,
        nonce: parts[2] || null,
        mode: parts[3] === 'ai' ? 'ai' : 'terminal',
    };
};

const openAiOrchestrator = () => {
    location.hash = '#/ai';
};

const openAiSession = (sessionId) => {
    location.hash = '#/ai/session/' + sessionId;
};

const openTerminalTab = (hostId) => {
    location.hash = '#/terminal/' + hostId + '/' + Date.now();
};

const openAiAssistant = (hostId) => {
    location.hash = '#/terminal/' + hostId + '/' + Date.now() + '/ai';
};

const navigate = (path) => {
    location.hash = path;
};

const parseToolJson = (value) => {
    if (value == null || value === '') {
        return null;
    }
    if (typeof value === 'object') {
        return value;
    }
    if (typeof value === 'string') {
        try {
            return JSON.parse(value);
        } catch {
            return value;
        }
    }
    return value;
};

const TOOL_KIND_META = {
    list_hosts: { badge: '主机', tone: 'info' },
    run_ssh_command: { badge: '命令', tone: 'command' },
    ask_user: { badge: '提问', tone: 'question' },
    get_command_context: { badge: '上下文', tone: 'info' },
    get_terminal_context: { badge: '终端', tone: 'info' },
};

const findQuestionOptionLabel = (question, value) => {
    const options = question?.options || [];
    const hit = options.find((opt) => String(opt.value) === String(value));
    return hit?.label || String(value);
};

const resolveAskUserAnswers = (inputs, resultObj) => {
    if (!resultObj || typeof resultObj !== 'object') {
        return [];
    }
    const rows = [];
    for (const question of inputs?.questions || []) {
        const raw = resultObj[question.id];
        if (raw == null || raw === '') {
            continue;
        }
        let answer;
        if (Array.isArray(raw)) {
            answer = raw.map((value) => findQuestionOptionLabel(question, value)).join('、');
        } else {
            answer = findQuestionOptionLabel(question, raw);
        }
        rows.push({ question: question.label || question.id, answer });
    }
    return rows;
};

const formatHostRef = (hostId, hostMap = null) => {
    const id = Number(hostId);
    if (!(id > 0)) {
        return '';
    }
    const host = hostMap?.[id];
    if (host) {
        const name = String(host.name || '').trim();
        const address = String(host.address || '').trim();
        if (name && address && name !== address) {
            return `${name} · ${address} (#${id})`;
        }
        if (name) {
            return `${name} (#${id})`;
        }
        if (address) {
            return `${address} (#${id})`;
        }
    }
    return `主机 #${id}`;
};

const buildHostMapFromTimeline = (items) => {
    const map = {};
    for (const item of items || []) {
        if (item?.kind !== 'tool' || item.name !== 'list_hosts' || item.status !== 'done') {
            continue;
        }
        const parsed = parseToolJson(item.result);
        for (const host of parsed?.hosts || []) {
            const id = Number(host.id);
            if (id > 0) {
                map[id] = host;
            }
        }
    }
    return map;
};

const truncateToolText = (text, max = 1200) => {
    const value = String(text || '');
    if (value.length <= max) {
        return { text: value, truncated: false };
    }
    return { text: value.slice(0, max) + '\n…（输出已截断）', truncated: true };
};

const formatToolJson = (value) => {
    const parsed = parseToolJson(value);
    if (parsed == null || parsed === '') {
        return '';
    }
    if (typeof parsed === 'string') {
        return parsed;
    }
    try {
        return JSON.stringify(parsed, null, 2);
    } catch {
        return String(parsed);
    }
};

const formatToolPresentation = (item, hostMap = null) => {
    const name = item?.name || '';
    const inputs = item?.inputs || {};
    const running = item?.status === 'running';
    const parsed = parseToolJson(item?.result);
    const meta = TOOL_KIND_META[name] || { badge: '工具', tone: 'neutral' };
    const blocks = [];
    let summary = '';

    switch (name) {
        case 'list_hosts':
            if (running) {
                summary = '正在读取平台主机列表…';
                break;
            }
            if (parsed && typeof parsed === 'object' && Array.isArray(parsed.hosts)) {
                const count = parsed.count ?? parsed.hosts.length;
                summary = `找到 ${count} 台主机`;
                blocks.push({
                    kind: 'hosts',
                    hosts: parsed.hosts.map((host) => ({
                        id: host.id,
                        label: host.name && host.address && host.name !== host.address
                            ? `${host.name} · ${host.address}`
                            : (host.name || host.address || `#${host.id}`),
                        meta: [
                            host.username ? `@${host.username}` : '',
                            host.port && Number(host.port) !== 22 ? `:${host.port}` : '',
                        ].filter(Boolean).join(' '),
                    })),
                });
            } else if (typeof parsed === 'string' && parsed.trim()) {
                summary = parsed.trim();
            }
            break;

        case 'run_ssh_command': {
            const hostId = inputs.host_id ?? parsed?.host_id;
            const command = inputs.command || parsed?.command || '';
            const reason = inputs.reason || parsed?.reason || '';
            const timeoutSec = inputs.timeout_sec ?? parsed?.timeout_sec;
            if (hostId) {
                blocks.push({ kind: 'text', title: '目标主机', content: formatHostRef(hostId, hostMap) });
            }
            if (reason) {
                blocks.push({ kind: 'text', title: '说明', content: reason });
            }
            if (timeoutSec != null && timeoutSec !== '') {
                blocks.push({ kind: 'text', title: '超时', content: `${timeoutSec} 秒` });
            }
            if (command) {
                blocks.push({ kind: 'code', title: '命令', content: command });
            }
            if (running) {
                summary = command ? `正在执行：${command}` : '等待执行 SSH 命令…';
                break;
            }
            if (parsed && typeof parsed === 'object') {
                if (parsed.timed_out) {
                    summary = '命令超时（已返回部分输出）';
                } else if (parsed.exit_code === 0) {
                    summary = '命令执行成功';
                } else if (parsed.exit_code != null) {
                    summary = `命令结束，退出码 ${parsed.exit_code}`;
                } else if (parsed.ok === false) {
                    const policyLabel = parsed.policy?.label;
                    const policyReason = parsed.error || parsed.policy?.reason;
                    if (policyLabel === '已拒绝') {
                        summary = policyReason ? `策略已拒绝：${policyReason}` : '策略已拒绝';
                    } else if (policyReason) {
                        summary = policyReason;
                    } else {
                        summary = '命令执行失败';
                    }
                } else {
                    summary = '命令已执行';
                }
                if (parsed.policy) {
                    blocks.push({
                        kind: 'text',
                        title: '策略',
                        content: [parsed.policy.label, parsed.policy.reason].filter(Boolean).join(' · '),
                    });
                }
                if (parsed.output) {
                    const output = truncateToolText(parsed.output, 1200);
                    blocks.push({
                        kind: 'code',
                        title: '输出',
                        content: output.text,
                        muted: output.truncated,
                    });
                }
            }
            break;
        }

        case 'ask_user': {
            const message = inputs.message || '';
            if (message) {
                summary = message;
            } else if (running) {
                summary = '等待用户回答…';
            }
            const questions = inputs.questions || [];
            if (questions.length) {
                blocks.push({
                    kind: 'questions',
                    title: running ? '问题' : '已向用户提问',
                    questions: questions.map((question) => ({
                        label: question.label || question.id,
                        options: (question.options || []).map((opt) => opt.label || opt.value),
                    })),
                });
            }
            if (!running && parsed && typeof parsed === 'object') {
                const answers = resolveAskUserAnswers(inputs, parsed);
                if (answers.length) {
                    blocks.push({ kind: 'answers', title: '用户回答', answers });
                    if (!summary) {
                        summary = '用户已回答';
                    }
                }
            }
            break;
        }

        case 'get_command_context':
        case 'get_terminal_context': {
            const hostId = inputs.host_id;
            if (hostId) {
                blocks.push({ kind: 'text', title: '目标主机', content: formatHostRef(hostId, hostMap) });
            }
            if (running) {
                summary = '正在读取最近输出…';
                break;
            }
            const outputText = typeof parsed === 'string'
                ? parsed
                : (parsed?.output || parsed?.context || '');
            if (String(outputText).trim()) {
                const output = truncateToolText(outputText, 800);
                blocks.push({ kind: 'code', title: '最近输出', content: output.text, muted: output.truncated });
                summary = name === 'get_terminal_context' ? '已读取终端上下文' : '已读取命令上下文';
            } else {
                summary = '暂无可用输出';
            }
            break;
        }

        default:
            summary = running ? '工具执行中…' : '';
            break;
    }

    const known = Object.prototype.hasOwnProperty.call(TOOL_KIND_META, name);
    const showRaw = !known || (blocks.length === 0 && !running);

    return {
        badge: meta.badge,
        tone: meta.tone,
        summary,
        blocks,
        showRaw,
        running,
    };
};

const AiToolCallCard = {
    props: {
        item: { type: Object, required: true },
        hostMap: { type: Object, default: null },
        compact: { type: Boolean, default: false },
    },
    setup(props) {
        const expanded = ref(false);
        const presentation = computed(() => formatToolPresentation(props.item, props.hostMap || null));
        const hasDetails = computed(() => (
            presentation.value.blocks.length > 0 || presentation.value.showRaw
        ));

        watch(
            () => props.item?.key || props.item?.callId || props.item?.name,
            () => {
                expanded.value = false;
            },
        );

        const toggleExpanded = () => {
            if (hasDetails.value) {
                expanded.value = !expanded.value;
            }
        };

        return {
            expanded,
            presentation,
            hasDetails,
            toggleExpanded,
            formatToolJson,
        };
    },
    template: `
        <div
            class="ai-tool-card"
            :class="[
                'ai-tool-tone-' + presentation.tone,
                {
                    compact,
                    expanded,
                    collapsible: hasDetails,
                },
            ]"
        >
            <button
                type="button"
                class="ai-tool-toggle"
                :class="{ 'is-static': !hasDetails }"
                :aria-expanded="hasDetails ? expanded : undefined"
                @click="toggleExpanded"
            >
                <div class="ai-tool-call-head">
                    <div class="ai-tool-head-main">
                        <span class="ai-tool-kind-badge">{{ presentation.badge }}</span>
                        <strong>{{ item.label || item.name || '工具' }}</strong>
                    </div>
                    <div class="ai-tool-head-side">
                        <span class="ai-tool-call-status" :class="item.status">{{ item.status === 'running' ? '执行中' : '已完成' }}</span>
                        <span v-if="hasDetails" class="ai-tool-chevron" aria-hidden="true">{{ expanded ? '▾' : '▸' }}</span>
                    </div>
                </div>
                <p v-if="presentation.summary" class="ai-tool-summary">{{ presentation.summary }}</p>
            </button>
            <div v-if="expanded && hasDetails" class="ai-tool-body">
                <div v-for="(block, blockIdx) in presentation.blocks" :key="blockIdx" class="ai-tool-call-block">
                    <div v-if="block.title" class="ai-tool-call-label">{{ block.title }}</div>
                    <div v-if="block.kind === 'text'" class="ai-tool-text">{{ block.content }}</div>
                    <pre v-else-if="block.kind === 'code'" class="ai-tool-code" :class="{ muted: block.muted }">{{ block.content }}</pre>
                    <ul v-else-if="block.kind === 'hosts'" class="ai-tool-host-list">
                        <li v-for="host in block.hosts" :key="host.id" class="ai-tool-host-item">
                            <span class="ai-tool-host-name">{{ host.label }}</span>
                            <span v-if="host.meta" class="ai-tool-host-meta">{{ host.meta }}</span>
                            <span class="ai-tool-host-id">#{{ host.id }}</span>
                        </li>
                    </ul>
                    <div v-else-if="block.kind === 'questions'" class="ai-tool-questions">
                        <div v-for="(question, qi) in block.questions" :key="qi" class="ai-tool-question">
                            <div class="ai-tool-question-label">{{ question.label }}</div>
                            <div v-if="question.options.length" class="ai-tool-option-list">
                                <span v-for="(option, oi) in question.options" :key="oi" class="ai-tool-option-chip">{{ option }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="block.kind === 'answers'" class="ai-tool-answers">
                        <div v-for="(row, ri) in block.answers" :key="ri" class="ai-tool-answer-row">
                            <span class="ai-tool-answer-q">{{ row.question }}</span>
                            <span class="ai-tool-answer-a">{{ row.answer }}</span>
                        </div>
                    </div>
                </div>
                <details v-if="presentation.showRaw" class="ai-tool-raw">
                    <summary>查看原始数据</summary>
                    <div v-if="Object.keys(item.inputs || {}).length" class="ai-tool-call-block">
                        <div class="ai-tool-call-label">参数</div>
                        <pre>{{ formatToolJson(item.inputs) }}</pre>
                    </div>
                    <div v-if="item.result != null && item.result !== ''" class="ai-tool-call-block">
                        <div class="ai-tool-call-label">结果</div>
                        <pre>{{ formatToolJson(item.result) }}</pre>
                    </div>
                </details>
            </div>
        </div>
    `,
};

const formatReplayHostLabel = (item) => {
    const name = String(item?.host_name || '').trim();
    const addr = String(item?.host_address || '').trim();
    if (name && addr && name !== addr) {
        return name + ' (' + addr + ')';
    }
    return name || addr || (item?.host_id ? ('主机 #' + item.host_id) : '');
};

const parseCastTerminalOutput = (castText) => {
    const lines = String(castText || '').trim().split('\n');
    let output = '';
    for (let i = 2; i < lines.length; i++) {
        try {
            const row = JSON.parse(lines[i]);
            if (Array.isArray(row) && row.length >= 3 && row[1] === 'o') {
                output += row[2];
            }
        } catch (_) {
            // skip malformed cast lines
        }
    }
    return output;
};

const createAsciinemaReplay = () => {
    const replayOpen = ref(false);
    const replayTitle = ref('');
    const replayMeta = ref('');
    const replayHostLabel = ref('');
    const replaySegmentInfo = ref('');
    const replayLoading = ref(false);
    const replayError = ref('');
    const replayHost = ref(null);
    let replayPlayer = null;
    let replayAbort = false;

    const disposePlayer = () => {
        if (replayPlayer) {
            try {
                replayPlayer.dispose();
            } catch (_) {}
            replayPlayer = null;
        }
    };

    const closeReplay = () => {
        replayAbort = true;
        prepareReplayHost();
        replayOpen.value = false;
        replayLoading.value = false;
        replayError.value = '';
        replayHostLabel.value = '';
        replaySegmentInfo.value = '';
    };

    const prepareReplayHost = () => {
        disposePlayer();
        if (replayHost.value) {
            replayHost.value.innerHTML = '';
        }
    };

    const fetchCastText = async (url) => {
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) {
            throw new Error('加载录像失败');
        }
        return res.text();
    };

    const parseCastRecording = (text) => {
        const lines = text.trim().split('\n');
        if (lines.length < 2) {
            return { header: null, events: [], duration: 0 };
        }

        let startIndex = 1;
        try {
            const header = JSON.parse(lines[0]);
            if (header?.version === 1) {
                startIndex = 2;
            }
        } catch (_) {
            startIndex = 1;
        }

        const events = [];
        let duration = 0;
        for (let i = startIndex; i < lines.length; i++) {
            try {
                const row = JSON.parse(lines[i]);
                if (Array.isArray(row) && typeof row[0] === 'number') {
                    events.push(row);
                    duration = Math.max(duration, row[0]);
                }
            } catch (_) {
                // skip malformed cast lines
            }
        }

        return { header: lines[0], events, duration };
    };

    const loadSegmentCast = async (segment, segmentLabel) => {
        const manifestData = await api.get(segment.recording.manifest_url);
        const parts = manifestData.parts || [];
        const manifest = manifestData.manifest || {};
        if (!parts.length) {
            return null;
        }

        let header = null;
        const events = [];
        let offset = 0;

        for (const part of parts) {
            const parsed = parseCastRecording(await fetchCastText(part.url));
            if (!parsed.events.length) {
                continue;
            }
            if (!header) {
                header = parsed.header;
            }
            for (const row of parsed.events) {
                events.push([row[0] + offset, row[1], row[2]]);
            }
            offset += parsed.duration + 0.3;
        }

        if (!header || !events.length) {
            return null;
        }

        return {
            header,
            events,
            duration: offset,
            manifest,
            label: segmentLabel,
        };
    };

    const mergeSegmentCasts = (segments, gapSec = 0.6) => {
        let header = null;
        const mergedEvents = [];
        const boundaries = [];
        let offset = 0;
        let cols = 80;
        let rows = 40;

        for (const segment of segments) {
            if (!segment) {
                continue;
            }
            if (!header) {
                header = segment.header;
                try {
                    const parsedHeader = JSON.parse(header);
                    cols = parsedHeader.width || cols;
                    rows = parsedHeader.height || rows;
                } catch (_) {
                    // keep defaults
                }
            }

            boundaries.push({ start: offset, label: segment.label });
            for (const row of segment.events) {
                mergedEvents.push([row[0] + offset, row[1], row[2]]);
            }
            offset += segment.duration + gapSec;
        }

        if (!header || !mergedEvents.length) {
            return null;
        }

        return {
            mergedText: header + '\n' + mergedEvents.map((row) => JSON.stringify(row)).join('\n'),
            totalDuration: offset,
            boundaries,
            cols,
            rows,
        };
    };

    const fetchCastDurationSec = async (url) => {
        try {
            return parseCastRecording(await fetchCastText(url)).duration;
        } catch (_) {
            return 0;
        }
    };

    const waitForPlaybackEnd = (player, durationHint = 0) => new Promise((resolve) => {
        let settled = false;
        let pollTimer = null;
        let fallbackTimer = null;

        const cleanup = () => {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (fallbackTimer) {
                clearTimeout(fallbackTimer);
                fallbackTimer = null;
            }
            try {
                player.removeEventListener('ended', finish);
            } catch (_) {
                // ignore listener cleanup errors
            }
        };

        const finish = () => {
            if (settled) {
                return;
            }
            settled = true;
            cleanup();
            resolve();
        };

        const safeHint = durationHint > 0
            ? Math.min(Math.max(durationHint, 0.5), 600)
            : 30;
        fallbackTimer = setTimeout(finish, Math.ceil((safeHint + 1) * 1000));

        player.addEventListener('ended', finish);

        pollTimer = setInterval(() => {
            try {
                const dur = player.getDuration?.();
                const cur = player.getCurrentTime?.();
                if (typeof dur === 'number' && dur > 0 && typeof cur === 'number' && cur >= dur - 0.2) {
                    finish();
                }
            } catch (_) {
                // ignore polling errors
            }
        }, 200);
    });

    const playCastSource = async (source, manifest, statusLabel, durationHint = 0, boundaries = []) => {
        if (replayAbort) {
            return;
        }
        if (!replayHost.value || typeof AsciinemaPlayer === 'undefined') {
            throw new Error('回放组件未加载');
        }

        prepareReplayHost();
        await nextTick();
        if (replayAbort || !replayHost.value) {
            return;
        }

        replayMeta.value = statusLabel + ' · 播放中';
        const boundaryTimers = boundaries.map((boundary) => setTimeout(() => {
            if (replayAbort) {
                return;
            }
            replayMeta.value = boundary.label + ' · 播放中';
            const hostLabel = String(boundary.label).split(' · ').pop();
            if (hostLabel) {
                replayHostLabel.value = hostLabel;
            }
            if (boundaries.length > 1) {
                const index = boundaries.indexOf(boundary);
                if (index >= 0) {
                    replaySegmentInfo.value = '分段 ' + (index + 1) + ' / ' + boundaries.length;
                }
            }
        }, Math.max(0, boundary.start * 1000)));

        replayPlayer = AsciinemaPlayer.create(source, replayHost.value, {
            cols: manifest.cols || 80,
            rows: manifest.rows || 40,
            autoPlay: true,
            preload: true,
            speed: 1,
            fit: 'both',
            theme: 'asciinema',
            controls: true,
            idleTimeLimit: 2,
        });

        try {
            await waitForPlaybackEnd(replayPlayer, durationHint);
        } finally {
            boundaryTimers.forEach((timer) => clearTimeout(timer));
        }
    };

    const playCastUrl = async (url, manifest, statusLabel) => {
        const durationHint = await fetchCastDurationSec(url);
        await playCastSource(url, manifest, statusLabel, durationHint);
    };

    const playRecordingParts = async (parts, manifest, segmentLabel = '') => {
        for (let index = 0; index < parts.length; index++) {
            if (replayAbort) {
                return;
            }
            const partLabel = parts.length > 1
                ? ('分片 ' + (index + 1) + ' / ' + parts.length)
                : '播放中';
            const statusLabel = segmentLabel ? (segmentLabel + ' · ' + partLabel) : partLabel;
            await playCastUrl(parts[index].url, manifest, statusLabel);
        }
    };

    const openSessionReplay = async (item) => {
        if (!item.recording_url) {
            return;
        }

        replayAbort = false;
        replayOpen.value = true;
        replayLoading.value = true;
        replayError.value = '';
        replayHostLabel.value = formatReplayHostLabel(item);
        replaySegmentInfo.value = '';
        replayTitle.value = (item.host_name || item.host_address || ('会话 #' + item.id));
        replayMeta.value = '加载回放...';

        try {
            const data = await api.get('/api/sessions/' + item.id + '/recording');
            const parts = data.parts || [];
            const manifest = data.manifest || {};
            if (!parts.length) {
                throw new Error('没有可用的回放分片');
            }

            replayLoading.value = false;
            await nextTick();
            await playRecordingParts(parts, manifest);
            if (!replayAbort) {
                replayMeta.value = '回放结束';
            }
        } catch (error) {
            replayError.value = error.message || '加载回放失败';
            replayMeta.value = '';
        } finally {
            replayLoading.value = false;
        }
    };

    const openAiSessionReplay = async (item) => {
        replayAbort = false;
        replayOpen.value = true;
        replayLoading.value = true;
        replayError.value = '';
        replayHostLabel.value = '';
        replaySegmentInfo.value = '';
        replayTitle.value = item.title || ('AI 会话 #' + item.id);
        replayMeta.value = '加载回放...';

        let blobUrl = null;
        try {
            const data = await api.get('/api/ai/sessions/' + item.id + '/recording');
            const allSegments = data.data?.segments || [];
            const recordable = allSegments.filter((seg) => seg.recording?.manifest_url);
            if (!recordable.length) {
                throw new Error('该 AI 会话没有可用的回放录像');
            }

            const loadedSegments = [];
            let skipped = 0;
            for (let index = 0; index < recordable.length; index++) {
                const segment = recordable[index];
                const hostLabel = formatReplayHostLabel(segment);
                const order = Number.isFinite(segment.order) ? segment.order + 1 : (index + 1);
                const segmentLabel = allSegments.length > 1
                    ? ('分段 ' + order + ' / ' + allSegments.length + ' · ' + hostLabel)
                    : hostLabel;
                try {
                    const loaded = await loadSegmentCast(segment, segmentLabel);
                    if (!loaded) {
                        skipped += 1;
                        continue;
                    }
                    loadedSegments.push(loaded);
                } catch (_) {
                    skipped += 1;
                }
            }

            if (!loadedSegments.length) {
                throw new Error('该 AI 会话没有可用的回放录像');
            }

            replayLoading.value = false;
            await nextTick();

            if (loadedSegments.length === 1) {
                const only = loadedSegments[0];
                blobUrl = URL.createObjectURL(new Blob([
                    only.header + '\n' + only.events.map((row) => JSON.stringify(row)).join('\n'),
                ], { type: 'application/x-asciicast' }));
                replayHostLabel.value = formatReplayHostLabel(recordable[0]);
                replaySegmentInfo.value = allSegments.length > 1 ? ('分段 1 / ' + allSegments.length) : '';
                await playCastSource(
                    blobUrl,
                    only.manifest,
                    only.label,
                    only.duration,
                );
            } else {
                const merged = mergeSegmentCasts(loadedSegments);
                if (!merged) {
                    throw new Error('合并回放失败');
                }
                blobUrl = URL.createObjectURL(new Blob([merged.mergedText], { type: 'application/x-asciicast' }));
                replayHostLabel.value = formatReplayHostLabel(recordable[0]);
                replaySegmentInfo.value = '分段 1 / ' + loadedSegments.length;
                await playCastSource(
                    blobUrl,
                    { cols: merged.cols, rows: merged.rows },
                    merged.boundaries[0]?.label || '播放中',
                    merged.totalDuration,
                    merged.boundaries,
                );
            }

            if (!replayAbort) {
                const missing = allSegments.length - recordable.length;
                const extras = missing + skipped;
                replayMeta.value = extras > 0
                    ? ('回放结束 · 已播放 ' + loadedSegments.length + ' 段，' + extras + ' 段无录像或跳过')
                    : ('回放结束 · 已播放 ' + loadedSegments.length + ' 段');
            }
        } catch (error) {
            replayError.value = error.message || '加载回放失败';
            replayMeta.value = '';
        } finally {
            if (blobUrl) {
                URL.revokeObjectURL(blobUrl);
            }
            replayLoading.value = false;
        }
    };

    return {
        replayOpen,
        replayTitle,
        replayMeta,
        replayHostLabel,
        replaySegmentInfo,
        replayLoading,
        replayError,
        replayHost,
        closeReplay,
        openSessionReplay,
        openAiSessionReplay,
    };
};

const createSessionFieldView = () => {
    const fieldOpen = ref(false);
    const fieldTitle = ref('');
    const fieldHostLabel = ref('');
    const fieldMeta = ref('');
    const fieldLoading = ref(false);
    const fieldError = ref('');
    const fieldHost = ref(null);
    let fieldTerm = null;
    let fieldFitAddon = null;
    let fieldAbort = false;

    const disposeTerm = () => {
        if (fieldTerm) {
            fieldTerm.dispose();
            fieldTerm = null;
            fieldFitAddon = null;
        }
    };

    const closeField = () => {
        fieldAbort = true;
        disposeTerm();
        fieldOpen.value = false;
        fieldLoading.value = false;
        fieldError.value = '';
        fieldHostLabel.value = '';
        fieldMeta.value = '';
    };

    const ensureTerm = () => {
        if (fieldTerm || !fieldHost.value) {
            return;
        }
        fieldTerm = new Terminal({
            disableStdin: true,
            cursorBlink: false,
            fontSize: 13,
            convertEol: true,
            scrollback: 10000,
            theme: {
                background: '#0f111a',
                foreground: '#e6e6e6',
            },
        });
        if (typeof FitAddon !== 'undefined' && FitAddon.FitAddon) {
            fieldFitAddon = new FitAddon.FitAddon();
            fieldTerm.loadAddon(fieldFitAddon);
        }
        fieldTerm.open(fieldHost.value);
        fieldFitAddon?.fit();
    };

    const writeTranscript = (text) => {
        ensureTerm();
        if (!fieldTerm) {
            return;
        }
        if (text) {
            fieldTerm.write(text);
        } else {
            fieldTerm.writeln('\x1b[90m暂无现场输出\x1b[0m');
        }
        fieldTerm.scrollToBottom();
    };

    const loadCastParts = async (parts) => {
        let combined = '';
        for (const part of parts) {
            if (fieldAbort) {
                return combined;
            }
            const res = await fetch(part.url, { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('加载现场记录失败');
            }
            combined += parseCastTerminalOutput(await res.text());
        }
        return combined;
    };

    const openSessionField = async (item) => {
        fieldAbort = false;
        fieldOpen.value = true;
        fieldLoading.value = true;
        fieldError.value = '';
        fieldHostLabel.value = formatReplayHostLabel(item);
        fieldTitle.value = item.host_name || item.host_address || ('会话 #' + item.id);
        fieldMeta.value = item.session_type === 'ai_exec' ? 'AI 命令输出' : '终端与 AI 命令输出';

        try {
            const data = await api.get('/api/sessions/' + item.id + '/recording');
            const parts = data.parts || [];
            if (!parts.length) {
                throw new Error('没有可用的现场记录');
            }
            fieldLoading.value = false;
            await nextTick();
            disposeTerm();
            await nextTick();
            const text = await loadCastParts(parts);
            if (!fieldAbort) {
                writeTranscript(text);
            }
        } catch (error) {
            fieldError.value = error.message || '加载现场失败';
        } finally {
            fieldLoading.value = false;
        }
    };

    const openAiSessionField = async (item) => {
        fieldAbort = false;
        fieldOpen.value = true;
        fieldLoading.value = true;
        fieldError.value = '';
        fieldHostLabel.value = '';
        fieldTitle.value = item.title || ('AI 会话 #' + item.id);
        fieldMeta.value = 'AI 命令输出';

        try {
            const data = await api.get('/api/ai/sessions/' + item.id + '/live/transcript');
            const payload = data.data || data;
            const transcript = payload.transcript || '';
            const segment = payload.active_segment;
            if (segment) {
                fieldHostLabel.value = formatReplayHostLabel(segment);
            }
            fieldLoading.value = false;
            await nextTick();
            disposeTerm();
            await nextTick();
            if (!fieldAbort) {
                writeTranscript(transcript);
            }
        } catch (error) {
            fieldError.value = error.message || '加载现场失败';
        } finally {
            fieldLoading.value = false;
        }
    };

    return {
        fieldOpen,
        fieldTitle,
        fieldHostLabel,
        fieldMeta,
        fieldLoading,
        fieldError,
        fieldHost,
        closeField,
        openSessionField,
        openAiSessionField,
    };
};

const useWorkspaceColumnSplit = (storageKey, defaultSplit = 0.38) => {
    const sidebarSplit = ref(defaultSplit);

    try {
        const saved = localStorage.getItem(storageKey);
        if (saved != null) {
            const value = Number(saved);
            if (Number.isFinite(value) && value >= 0.22 && value <= 0.72) {
                sidebarSplit.value = value;
            }
        }
    } catch (_) {
        // ignore storage errors
    }

    const splitGridStyle = computed(() => {
        const right = sidebarSplit.value;
        const left = 1 - right;
        return {
            gridTemplateColumns: `minmax(280px, ${left}fr) 10px minmax(320px, ${right}fr)`,
        };
    });

    const startSplitResize = (event) => {
        const handle = event.currentTarget;
        const body = handle.parentElement;
        if (!body) {
            return;
        }

        const splitterW = handle.offsetWidth || 10;
        const minRightPx = 320;
        const minLeftPx = 280;

        const onMove = (e) => {
            const rect = body.getBoundingClientRect();
            const usable = rect.width - splitterW;
            if (usable <= 0) {
                return;
            }
            const rightPx = rect.right - e.clientX;
            const split = Math.max(
                minRightPx / usable,
                Math.min((usable - minLeftPx) / usable, rightPx / usable),
            );
            sidebarSplit.value = split;
        };

        const onUp = () => {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            document.body.classList.remove('workspace-resizing');
            try {
                localStorage.setItem(storageKey, String(sidebarSplit.value));
            } catch (_) {
                // ignore storage errors
            }
            window.dispatchEvent(new Event('resize'));
        };

        document.body.classList.add('workspace-resizing');
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
        onMove(event);
    };

    return { splitGridStyle, startSplitResize };
};

const appOptions = {
    setup() {
        const route = ref(parseRoute());
        const me = ref(null);
        const flash = ref('');
        const flashType = ref('ok');
        const twoFactorReady = ref(false);
        const twoFactorVerified = ref(false);
        const twoFactorConfigured = ref(false);

        const setFlash = (message, type = 'ok') => {
            flash.value = message;
            flashType.value = type;
        };

        const refreshAuthState = async () => {
            me.value = await api.get('/api/me');
            const tf = me.value.two_factor || {};
            if (!tf.enabled) {
                twoFactorConfigured.value = false;
                twoFactorVerified.value = true;
                return;
            }
            twoFactorConfigured.value = !!tf.configured;
            twoFactorVerified.value = !!tf.verified;
        };

        const onTwoFactorComplete = async () => {
            twoFactorVerified.value = true;
            try {
                await refreshAuthState();
            } catch (error) {
                setFlash(error.message, 'err');
            }
        };

        const onHashChange = () => {
            route.value = parseRoute();
        };

        let authKeepaliveTimer = null;

        const startAuthKeepalive = () => {
            authKeepaliveTimer = setInterval(async () => {
                try {
                    await api.get('/api/me');
                } catch (error) {
                    if (error.status === 401) {
                        location.href = '/login';
                    } else if (error.status === 403 && error.code === 'two_factor_required') {
                        twoFactorVerified.value = false;
                    }
                }
            }, AUTH_KEEPALIVE_MS);
        };

        onMounted(async () => {
            authStateHandler = {
                onTwoFactorRequired() {
                    twoFactorVerified.value = false;
                },
            };
            window.addEventListener('hashchange', onHashChange);
            try {
                await refreshAuthState();
            } catch (error) {
                if (error.status === 401) {
                    location.href = '/login';
                    return;
                }
                if (!(error.status === 403 && error.code === 'two_factor_required')) {
                    setFlash(error.message, 'err');
                }
            } finally {
                twoFactorReady.value = true;
            }
            startAuthKeepalive();
        });

        onBeforeUnmount(() => {
            authStateHandler = null;
            if (authKeepaliveTimer !== null) {
                clearInterval(authKeepaliveTimer);
                authKeepaliveTimer = null;
            }
            window.removeEventListener('hashchange', onHashChange);
        });

        const currentView = computed(() => {
            const { name, id } = route.value;
            if (name === 'terminal') return 'terminal';
            if (name === 'ai-session') return 'ai-session';
            if (name === 'ai-home') return 'ai-home';
            if (name === 'ai-sessions') return 'ai-sessions';
            if (name === 'live') return 'live';
            if (name === 'sessions') return 'sessions';
            if (name === 'audit-logs') return 'audit';
            if (name === 'ai-settings') return 'ai-settings';
            if (name === 'command-policy-settings') return 'command-policy-settings';
            if (name === 'hosts' && id !== null) return 'host-form';
            return 'hosts';
        });

        const editingHostId = computed(() => {
            const { name, id } = route.value;
            if (name === 'hosts' && id !== null && id > 0) return id;
            return null;
        });

        const terminalHostId = computed(() => (route.value.name === 'terminal' ? route.value.id : null));
        const terminalOpenNonce = computed(() => (route.value.name === 'terminal' ? route.value.nonce : null));
        const terminalOpenMode = computed(() => (route.value.name === 'terminal' ? route.value.mode : 'terminal'));
        const aiSessionId = computed(() => (route.value.name === 'ai-session' ? route.value.id : null));
        const isFullBleedView = computed(() => currentView.value === 'terminal' || currentView.value === 'ai-session' || currentView.value === 'ai-home');

        const SIDEBAR_KEY = 'web-ssh-sidebar-collapsed';
        const sidebarCollapsed = ref(localStorage.getItem(SIDEBAR_KEY) === '1');
        const isMobile = ref(false);
        const mobileNavOpen = ref(false);

        onMounted(() => {
            const mq = window.matchMedia('(max-width: 768px)');
            const syncMobile = () => {
                isMobile.value = mq.matches;
                if (!mq.matches) {
                    mobileNavOpen.value = false;
                }
            };
            syncMobile();
            mq.addEventListener('change', syncMobile);
            onBeforeUnmount(() => mq.removeEventListener('change', syncMobile));
        });

        const toggleSidebar = () => {
            if (isMobile.value && isFullBleedView.value) {
                mobileNavOpen.value = !mobileNavOpen.value;
                return;
            }
            sidebarCollapsed.value = !sidebarCollapsed.value;
            localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value ? '1' : '0');
            requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
        };

        const closeMobileNav = () => {
            mobileNavOpen.value = false;
        };

        watch(currentView, (view) => {
            if (view !== 'terminal') {
                mobileNavOpen.value = false;
            }
        });

        const logout = async () => {
            if (!confirm('确认退出登录？')) {
                return;
            }
            try {
                await api.post('/api/logout');
            } catch (error) {
                // 仍继续退出流程
            }
            window.location.href = '/logout';
        };

        return {
            route,
            me,
            flash,
            flashType,
            setFlash,
            navigate,
            currentView,
            editingHostId,
            terminalHostId,
            terminalOpenNonce,
            terminalOpenMode,
            aiSessionId,
            isFullBleedView,
            sidebarCollapsed,
            toggleSidebar,
            isMobile,
            mobileNavOpen,
            closeMobileNav,
            isActive: (path) => (location.hash || '#/hosts') === path,
            twoFactorReady,
            twoFactorVerified,
            twoFactorConfigured,
            onTwoFactorComplete,
            logout,
            canLogout: computed(() => me.value && me.value.username !== 'anonymous'),
        };
    },
    template: `
        <TwoFactorGate
            v-if="twoFactorReady && !twoFactorVerified"
            :configured="twoFactorConfigured"
            :username="me ? me.username : ''"
            @complete="onTwoFactorComplete"
            @flash="setFlash"
        />
        <div v-else-if="twoFactorReady" class="layout" :class="{ 'sidebar-collapsed': sidebarCollapsed, 'layout-mobile-terminal': isMobile && isFullBleedView, 'layout-ai-workspace': isFullBleedView, 'mobile-nav-open': mobileNavOpen }" @click.self="closeMobileNav">
            <aside class="sidebar" @click.stop>
                <button
                    type="button"
                    class="sidebar-toggle"
                    :title="sidebarCollapsed ? '展开导航' : '折叠导航'"
                    @click="toggleSidebar"
                >{{ sidebarCollapsed ? '»' : '«' }}</button>
                <h1>Web SSH</h1>
                <div class="sub">{{ me ? me.username : '...' }} · Basic Auth{{ me && me.two_factor && me.two_factor.configured ? ' · 2FA' : '' }}</div>
                <button
                    v-if="canLogout"
                    type="button"
                    class="sidebar-logout"
                    title="退出登录"
                    @click="logout"
                >
                    <span class="nav-icon">退</span><span class="nav-label">退出登录</span>
                </button>
                <nav class="nav">
                    <a href="#/hosts" title="主机管理" :class="{ active: currentView === 'hosts' || currentView === 'host-form' }" @click="closeMobileNav">
                        <span class="nav-icon">主</span><span class="nav-label">主机管理</span>
                    </a>
                    <a href="#/terminal" title="终端" :class="{ active: currentView === 'terminal' }" @click="closeMobileNav">
                        <span class="nav-icon">端</span><span class="nav-label">终端</span>
                    </a>
                    <a href="#/ai" title="AI 编排" :class="{ active: currentView === 'ai-home' || currentView === 'ai-session' || currentView === 'ai-sessions' }" @click="closeMobileNav">
                        <span class="nav-icon">编</span><span class="nav-label">AI 编排</span>
                    </a>
                    <a href="#/live" title="实时现场" :class="{ active: currentView === 'live' }" @click="closeMobileNav">
                        <span class="nav-icon">场</span><span class="nav-label">实时现场</span>
                    </a>
                    <a href="#/sessions" title="会话记录" :class="{ active: currentView === 'sessions' }" @click="closeMobileNav">
                        <span class="nav-icon">话</span><span class="nav-label">会话记录</span>
                    </a>
                    <a href="#/audit-logs" title="操作日志" :class="{ active: currentView === 'audit' }" @click="closeMobileNav">
                        <span class="nav-icon">志</span><span class="nav-label">操作日志</span>
                    </a>
                    <a href="#/settings/ai" title="AI 设置" :class="{ active: currentView === 'ai-settings' }" @click="closeMobileNav">
                        <span class="nav-icon">AI</span><span class="nav-label">AI 设置</span>
                    </a>
                    <a href="#/settings/command-policy" title="命令策略" :class="{ active: currentView === 'command-policy-settings' }" @click="closeMobileNav">
                        <span class="nav-icon">策</span><span class="nav-label">命令策略</span>
                    </a>
                </nav>
            </aside>
            <main class="main">
                <button
                    v-if="isMobile && isFullBleedView"
                    type="button"
                    class="mobile-nav-fab"
                    :title="mobileNavOpen ? '关闭菜单' : '打开菜单'"
                    @click="toggleSidebar"
                >{{ mobileNavOpen ? '×' : '☰' }}</button>
                <div v-if="flash" class="message" :class="flashType">{{ flash }}</div>
                <HostListView v-if="currentView === 'hosts'" @flash="setFlash" />
                <HostFormView v-else-if="currentView === 'host-form'" :host-id="editingHostId" @flash="setFlash" />
                <SessionListView v-else-if="currentView === 'sessions'" />
                <AuditLogView v-else-if="currentView === 'audit'" />
                <AiSettingsView v-else-if="currentView === 'ai-settings'" @flash="setFlash" />
                <CommandPolicySettingsView v-else-if="currentView === 'command-policy-settings'" @flash="setFlash" />
                <TerminalWorkspace
                    v-show="currentView === 'terminal'"
                    :visible="currentView === 'terminal'"
                    :pending-host-id="terminalHostId"
                    :open-nonce="terminalOpenNonce"
                    :open-mode="terminalOpenMode"
                    @flash="setFlash"
                />
                <LiveMonitorView v-if="currentView === 'live'" />
                <AiSessionListView v-else-if="currentView === 'ai-sessions'" @flash="setFlash" />
                <AiSessionWorkspace
                    v-show="currentView === 'ai-home' || currentView === 'ai-session'"
                    :visible="currentView === 'ai-home' || currentView === 'ai-session'"
                    :session-id="aiSessionId"
                    @flash="setFlash"
                />
            </main>
        </div>
        <div v-else class="twofa-gate">
            <div class="twofa-card">
                <h2>正在加载...</h2>
                <p>请稍候，正在检查登录状态。</p>
            </div>
        </div>
    `,
    components: {
        TwoFactorGate: {
            props: {
                configured: { type: Boolean, default: false },
                username: { type: String, default: '' },
            },
            emits: ['complete', 'flash'],
            setup(props, { emit }) {
                const step = ref(props.configured ? 'verify' : 'setup-name');
                const label = ref('');
                const code = ref('');
                const qrCode = ref('');
                const loading = ref(false);
                const localError = ref('');

                const startSetup = async () => {
                    localError.value = '';
                    const name = label.value.trim();
                    if (!name) {
                        localError.value = '请输入双因子验证名称。';
                        return;
                    }
                    loading.value = true;
                    try {
                        const data = await api.post('/api/2fa/setup', { label: name });
                        qrCode.value = data.qr_code;
                        step.value = 'setup-confirm';
                    } catch (error) {
                        localError.value = error.message;
                    } finally {
                        loading.value = false;
                    }
                };

                const confirmSetup = async () => {
                    localError.value = '';
                    if (!code.value.trim()) {
                        localError.value = '请输入 6 位验证码。';
                        return;
                    }
                    loading.value = true;
                    try {
                        await api.post('/api/2fa/confirm', { code: code.value.trim() });
                        emit('complete');
                    } catch (error) {
                        localError.value = error.message;
                    } finally {
                        loading.value = false;
                    }
                };

                const verifyLogin = async () => {
                    localError.value = '';
                    if (!code.value.trim()) {
                        localError.value = '请输入 6 位验证码。';
                        return;
                    }
                    loading.value = true;
                    try {
                        await api.post('/api/2fa/verify', { code: code.value.trim() });
                        emit('complete');
                    } catch (error) {
                        localError.value = error.message;
                    } finally {
                        loading.value = false;
                    }
                };

                return {
                    step,
                    label,
                    code,
                    qrCode,
                    loading,
                    localError,
                    startSetup,
                    confirmSetup,
                    verifyLogin,
                };
            },
            template: `
                <div class="twofa-gate">
                    <div class="twofa-card">
                        <template v-if="step === 'setup-name'">
                            <h2>设置双因子验证</h2>
                            <p>当前账号 <strong>{{ username }}</strong> 尚未绑定双因子验证。请先输入一个名称（将显示在验证器应用中），然后扫描二维码完成绑定。</p>
                            <div class="twofa-field">
                                <label for="twofa-label">验证器名称</label>
                                <input id="twofa-label" v-model="label" placeholder="例如：Web SSH 管理平台" @keyup.enter="startSetup">
                            </div>
                            <div v-if="localError" class="message err">{{ localError }}</div>
                            <div class="twofa-actions">
                                <button :disabled="loading" @click="startSetup">{{ loading ? '生成中...' : '生成二维码' }}</button>
                            </div>
                        </template>
                        <template v-else-if="step === 'setup-confirm'">
                            <h2>扫描二维码</h2>
                            <p>请使用 Google Authenticator、1Password 或其他 TOTP 验证器扫描下方二维码，然后输入 6 位验证码完成绑定。</p>
                            <img v-if="qrCode" class="twofa-qr" :src="qrCode" alt="双因子验证二维码">
                            <div class="twofa-field">
                                <label for="twofa-setup-code">验证码</label>
                                <input id="twofa-setup-code" v-model="code" class="twofa-code-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" @keyup.enter="confirmSetup">
                            </div>
                            <div v-if="localError" class="message err">{{ localError }}</div>
                            <div class="twofa-actions">
                                <button :disabled="loading" @click="step = 'setup-name'; code = ''">上一步</button>
                                <button :disabled="loading" @click="confirmSetup">{{ loading ? '验证中...' : '完成绑定' }}</button>
                            </div>
                        </template>
                        <template v-else>
                            <h2>双因子验证</h2>
                            <p>请输入验证器应用中的 6 位动态验证码，验证通过后才能继续使用平台。</p>
                            <div class="twofa-field">
                                <label for="twofa-verify-code">验证码</label>
                                <input id="twofa-verify-code" v-model="code" class="twofa-code-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" @keyup.enter="verifyLogin">
                            </div>
                            <div v-if="localError" class="message err">{{ localError }}</div>
                            <div class="twofa-actions">
                                <button :disabled="loading" @click="verifyLogin">{{ loading ? '验证中...' : '验证并进入' }}</button>
                            </div>
                        </template>
                    </div>
                </div>
            `,
        },
        HostListView: {
            emits: ['flash'],
            setup(_, { emit }) {
                const items = ref([]);
                const total = ref(0);
                const page = ref(1);
                const perPage = 10;
                const q = ref('');
                const loading = ref(false);

                const load = async () => {
                    loading.value = true;
                    try {
                        const params = new URLSearchParams({ page: page.value, per_page: perPage });
                        if (q.value) params.set('q', q.value);
                        const data = await api.get('/api/hosts?' + params);
                        items.value = data.items;
                        total.value = data.total;
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        loading.value = false;
                    }
                };

                const remove = async (item) => {
                    if (!confirm('确认删除主机「' + item.name + '」？此操作不可恢复。')) return;
                    try {
                        await api.delete('/api/hosts/' + item.id);
                        emit('flash', '删除成功');
                        await load();
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    }
                };

                const test = async (item) => {
                    try {
                        const result = await api.post('/api/hosts/' + item.id + '/test');
                        emit('flash', result.message, result.success ? 'ok' : 'err');
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    }
                };

                onMounted(load);

                return { items, total, page, perPage, q, loading, load, remove, test, navigate, openTerminalTab, openAiAssistant, openAiOrchestrator };
            },
            template: `
                <div class="panel">
                    <div class="toolbar">
                        <input v-model="q" placeholder="搜索名称/地址/标签" @keyup.enter="load">
                        <button class="primary" @click="load" :disabled="loading">搜索</button>
                        <button class="primary" @click="navigate('#/hosts/0')">新建主机</button>
                        <button @click="openAiOrchestrator()">AI 编排</button>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>名称</th><th>地址</th><th>端口</th><th>用户</th><th>认证</th><th>分组/标签</th><th>最近连接</th><th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.name }}</td>
                                <td>
                                    {{ item.address }}
                                    <br v-if="item.jump_host_name"><small>via {{ item.jump_host_name }}</small>
                                </td>
                                <td>{{ item.port }}</td>
                                <td>{{ item.username }}</td>
                                <td><span class="badge">{{ item.auth_type === 'private_key' ? '私钥' : '密码' }}</span></td>
                                <td>{{ item.group_name || '-' }}<br><small>{{ item.tags || '' }}</small></td>
                                <td>{{ item.last_connected_at || '-' }}</td>
                                <td class="actions">
                                    <button @click="openTerminalTab(item.id)" title="在新标签页打开 SSH">登录</button>
                                    <button @click="openAiAssistant(item.id)" title="打开终端（AI 主导）">AI 助手</button>
                                    <button @click="navigate('#/hosts/' + item.id)">编辑</button>
                                    <button @click="test(item)">测试</button>
                                    <button class="danger" @click="remove(item)">删除</button>
                                </td>
                            </tr>
                            <tr v-if="!items.length"><td colspan="8">暂无主机，点击「新建主机」添加。</td></tr>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <button :disabled="page <= 1" @click="page--; load()">上一页</button>
                        <span>第 {{ page }} 页 / 共 {{ Math.ceil(total / perPage) || 1 }} 页（{{ total }} 条）</span>
                        <button :disabled="page * perPage >= total" @click="page++; load()">下一页</button>
                    </div>
                </div>
            `,
        },
        HostFormView: {
            props: { hostId: { type: Number, default: null } },
            emits: ['flash'],
            setup(props, { emit }) {
                const isEdit = computed(() => props.hostId !== null && props.hostId > 0);
                const groups = ref([]);
                const hostOptions = ref([]);
                const keyPaths = ref([]);
                const defaultKeyPath = ref('~/.ssh/id_rsa');
                const saving = ref(false);
                const testing = ref(false);
                const showSecret = ref(false);
                const keyFileInput = ref(null);
                const form = reactive({
                    name: '',
                    address: '',
                    port: 22,
                    username: 'root',
                    auth_type: 'password',
                    password: '',
                    private_key_source: 'path',
                    private_key_path: '',
                    private_key: '',
                    passphrase: '',
                    group_id: 1,
                    jump_host_id: 0,
                    tags: '',
                    remark: '',
                });

                const pathOptions = computed(() => {
                    const seen = new Set();
                    const items = [];
                    for (const item of keyPaths.value) {
                        if (!seen.has(item.path)) {
                            seen.add(item.path);
                            items.push(item);
                        }
                    }
                    if (form.private_key_path && !seen.has(form.private_key_path)) {
                        items.unshift({ path: form.private_key_path, readable: null });
                    }
                    return items;
                });

                const jumpOptions = computed(() => hostOptions.value.filter((item) => item.id !== props.hostId));

                const selectedPathStatus = computed(() => {
                    const match = pathOptions.value.find((item) => item.path === form.private_key_path);
                    if (!match || match.readable === null) {
                        return null;
                    }
                    return match.readable ? 'ok' : 'warn';
                });

                const loadGroups = async () => {
                    const data = await api.get('/api/groups');
                    groups.value = data.items;
                };

                const loadHostOptions = async () => {
                    const data = await api.get('/api/hosts/options');
                    hostOptions.value = data.items || [];
                };

                const loadKeyPaths = async () => {
                    const data = await api.get('/api/ssh/key-paths');
                    keyPaths.value = data.items || [];
                    defaultKeyPath.value = data.default || '~/.ssh/id_rsa';
                    if (!form.private_key_path) {
                        form.private_key_path = defaultKeyPath.value;
                    }
                };

                const loadHost = async () => {
                    if (!isEdit.value) return;
                    const host = await api.get('/api/hosts/' + props.hostId);
                    form.name = host.name;
                    form.address = host.address;
                    form.port = host.port;
                    form.username = host.username;
                    form.auth_type = host.auth_type;
                    form.private_key_source = host.private_key_source || 'path';
                    form.private_key_path = host.private_key_path || defaultKeyPath.value;
                    form.group_id = host.group_id || 1;
                    form.jump_host_id = host.jump_host_id || 0;
                    form.tags = host.tags || '';
                    form.remark = host.remark || '';
                };

                const onKeyFileChange = (event) => {
                    const file = event.target.files?.[0];
                    if (!file) {
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = () => {
                        form.private_key_source = 'pem';
                        form.private_key = String(reader.result || '');
                        emit('flash', '已读取私钥文件: ' + file.name);
                    };
                    reader.onerror = () => emit('flash', '读取私钥文件失败', 'err');
                    reader.readAsText(file);
                    event.target.value = '';
                };

                const pickKeyFile = () => keyFileInput.value?.click();

                onMounted(async () => {
                    try {
                        await loadGroups();
                        await loadHostOptions();
                        await loadKeyPaths();
                        await loadHost();
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    }
                });

                watch(() => form.auth_type, (value) => {
                    if (value === 'private_key' && !form.private_key_path) {
                        form.private_key_path = defaultKeyPath.value;
                    }
                });

                const payload = () => {
                    const body = { ...form };
                    if (isEdit.value) {
                        body.id = props.hostId;
                    }
                    return body;
                };

                const save = async () => {
                    saving.value = true;
                    try {
                        if (isEdit.value) {
                            await api.put('/api/hosts/' + props.hostId, payload());
                            emit('flash', '更新成功');
                        } else {
                            await api.post('/api/hosts', payload());
                            emit('flash', '创建成功');
                        }
                        navigate('#/hosts');
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        saving.value = false;
                    }
                };

                const testConnection = async () => {
                    testing.value = true;
                    try {
                        const result = await api.post('/api/hosts/test', payload());
                        emit('flash', result.message, result.success ? 'ok' : 'err');
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        testing.value = false;
                    }
                };

                return {
                    isEdit, groups, form, saving, testing, showSecret, save, testConnection, navigate,
                    pathOptions, selectedPathStatus, keyFileInput, onKeyFileChange, pickKeyFile, jumpOptions,
                };
            },
            template: `
                <div class="panel">
                    <h2>{{ isEdit ? '编辑主机' : '新建主机' }}</h2>
                    <div class="form-grid">
                        <div><label>主机名称 *</label><input v-model="form.name"></div>
                        <div><label>主机地址 *</label><input v-model="form.address" :readonly="isEdit"></div>
                        <div><label>端口 *</label><input v-model.number="form.port" type="number" min="1" max="65535"></div>
                        <div><label>用户名 *</label><input v-model="form.username"></div>
                        <div><label>认证方式 *</label>
                            <select v-model="form.auth_type">
                                <option value="password">密码</option>
                                <option value="private_key">私钥</option>
                            </select>
                        </div>
                        <div><label>所属分组</label>
                            <select v-model.number="form.group_id">
                                <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                            </select>
                        </div>
                        <div class="full">
                            <label>跳板机</label>
                            <select v-model.number="form.jump_host_id">
                                <option :value="0">不使用跳板机（直连）</option>
                                <option v-for="h in jumpOptions" :key="h.id" :value="h.id">
                                    {{ h.name }} ({{ h.username }}@{{ h.address }}:{{ h.port }})
                                </option>
                            </select>
                            <p class="hint">通过跳板机连接时，主机地址应填写跳板机网络内可达的地址（通常是内网 IP）。跳板机本身需先添加为普通主机。</p>
                        </div>
                        <div v-if="form.auth_type === 'password'" class="full">
                            <label>密码 {{ isEdit ? '（留空则保持不变）' : '*' }}</label>
                            <input v-model="form.password" :type="showSecret ? 'text' : 'password'">
                        </div>
                        <div v-if="form.auth_type === 'private_key'" class="full">
                            <label>私钥来源 *</label>
                            <div class="key-source-tabs">
                                <label><input type="radio" v-model="form.private_key_source" value="path"> 服务器路径</label>
                                <label><input type="radio" v-model="form.private_key_source" value="pem"> 上传 / 粘贴 PEM</label>
                            </div>
                            <div v-if="form.private_key_source === 'path'">
                                <div class="key-path-row">
                                    <select v-model="form.private_key_path">
                                        <option v-for="item in pathOptions" :key="item.path" :value="item.path">
                                            {{ item.path }}{{ item.readable === true ? ' (可读)' : item.readable === false ? ' (不可读)' : '' }}
                                        </option>
                                    </select>
                                </div>
                                <div class="key-path-row" style="margin-top:8px">
                                    <input v-model="form.private_key_path" placeholder="或手动输入路径，如 ~/.ssh/id_rsa">
                                </div>
                                <p v-if="selectedPathStatus === 'ok'" class="hint ok">该路径在当前服务器上可读。</p>
                                <p v-else-if="selectedPathStatus === 'warn'" class="hint warn">该路径在当前服务器上不可读，请确认文件存在且进程有权限。</p>
                                <p v-else class="hint">默认使用常用路径；路径相对于运行 Web SSH 的服务器。</p>
                            </div>
                            <div v-else>
                                <div class="toolbar" style="margin-bottom:8px">
                                    <button type="button" @click="pickKeyFile">选择私钥文件</button>
                                    <input ref="keyFileInput" type="file" accept=".pem,.key,text/plain" style="display:none" @change="onKeyFileChange">
                                </div>
                                <textarea v-model="form.private_key" rows="6" :style="{ fontFamily: 'monospace' }"
                                    :placeholder="isEdit ? '留空则保持不变；可粘贴 PEM 或选择文件导入' : '粘贴 PEM 内容，或点击上方按钮选择私钥文件'"></textarea>
                            </div>
                        </div>
                        <div v-if="form.auth_type === 'private_key'" class="full">
                            <label>私钥密码（可选）</label>
                            <input v-model="form.passphrase" :type="showSecret ? 'text' : 'password'">
                        </div>
                        <div class="full"><label>标签（逗号分隔）</label><input v-model="form.tags"></div>
                        <div class="full"><label>备注</label><textarea v-model="form.remark" rows="3"></textarea></div>
                        <div class="full">
                            <label><input type="checkbox" v-model="showSecret"> 显示敏感字段</label>
                        </div>
                    </div>
                    <div class="toolbar" style="margin-top:16px">
                        <button class="primary" @click="save" :disabled="saving">{{ isEdit ? '保存' : '确定' }}</button>
                        <button @click="testConnection" :disabled="testing">测试连接</button>
                        <button @click="navigate('#/hosts')">返回列表</button>
                    </div>
                </div>
            `,
        },
        TerminalPane: {
            props: {
                hostId: { type: Number, required: true },
                active: { type: Boolean, default: false },
                headless: { type: Boolean, default: false },
            },
            emits: ['meta'],
            setup(props, { emit, expose }) {
                const HEADLESS_COLS = 120;
                const HEADLESS_ROWS = 40;
                const terminalRef = ref(null);
                const terminalSessionKey = ref(0);
                const statusMessage = ref('准备连接...');
                const hostInfo = ref(null);
                const connected = ref(false);
                const connecting = ref(false);
                const connId = ref('');
                let term = null;
                let fitAddon = null;
                let socket = null;
                let disconnectReason = '';
                let connectionAttempted = false;
                let resizeHandler = null;
                let elapsedTimer = null;
                let connectionGeneration = 0;
                const elapsed = ref(0);

                const stopElapsedTimer = () => {
                    if (elapsedTimer) {
                        clearInterval(elapsedTimer);
                        elapsedTimer = null;
                    }
                };

                const startElapsedTimer = () => {
                    stopElapsedTimer();
                    elapsed.value = 0;
                    elapsedTimer = setInterval(() => {
                        elapsed.value += 1;
                        pushMeta();
                    }, 1000);
                };

                const destroyTerminal = () => {
                    if (term) {
                        term.dispose();
                        term = null;
                        fitAddon = null;
                    }
                };

                const createTerminal = () => {
                    if (!terminalRef.value) {
                        return;
                    }
                    term = new Terminal({
                        cursorBlink: true,
                        cursorStyle: 'block',
                        fontSize: 14,
                        scrollback: 5000,
                        theme: {
                            background: '#0f111a',
                            foreground: '#e6e6e6',
                            cursor: '#f8f8f2',
                            brightBlack: '#6b7280',
                        },
                    });
                    fitAddon = new FitAddon.FitAddon();
                    term.loadAddon(fitAddon);
                    term.open(terminalRef.value);
                    term.onData((data) => {
                        if (connected.value && socket && socket.readyState === WebSocket.OPEN) {
                            socket.send(JSON.stringify({ type: 'input', data }));
                        }
                    });
                };

                const recreateTerminal = async () => {
                    if (props.headless) {
                        return;
                    }
                    destroyTerminal();
                    terminalSessionKey.value += 1;
                    await nextTick();
                    createTerminal();
                };

                const authColsRows = () => {
                    if (props.headless || !term) {
                        return { cols: HEADLESS_COLS, rows: HEADLESS_ROWS };
                    }
                    return { cols: term.cols, rows: term.rows };
                };

                const focusTerminal = () => {
                    if (!term) {
                        return;
                    }
                    term.focus();
                    term.options.cursorBlink = false;
                    term.options.cursorBlink = true;
                    term.refresh(0, term.rows - 1);
                };

                const teardownSocket = () => {
                    stopElapsedTimer();
                    if (!socket) {
                        return;
                    }
                    const closing = socket;
                    socket = null;
                    closing.close();
                };

                const isCurrentGeneration = (generation) => generation === connectionGeneration;

                const fitTerminal = () => {
                    if (!term || !fitAddon) {
                        return;
                    }
                    if (!props.active) {
                        return;
                    }
                    fitAddon.fit();
                };

                const sendResize = () => {
                    if (!term || !socket || socket.readyState !== WebSocket.OPEN) {
                        return;
                    }
                    socket.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
                };

                const pushMeta = () => {
                    emit('meta', {
                        title: hostInfo.value?.name || ('主机 #' + props.hostId),
                        subtitle: hostInfo.value
                            ? hostInfo.value.username + '@' + hostInfo.value.address + ':' + hostInfo.value.port
                            : '',
                        statusMessage: statusMessage.value,
                        connected: connected.value,
                        connecting: connecting.value,
                        connId: connId.value,
                        elapsed: elapsed.value,
                    });
                };

                const finalizeExit = (reason, generation) => {
                    if (!isCurrentGeneration(generation)) {
                        return;
                    }
                    connected.value = false;
                    connecting.value = false;
                    stopElapsedTimer();
                    if (connectionAttempted && term && !props.headless) {
                        term.writeln('');
                        term.writeln('[已退出] ' + reason);
                    }
                    connectionAttempted = false;
                    disconnectReason = '';
                    socket = null;
                    statusMessage.value = reason;
                    pushMeta();
                };

                const connect = async () => {
                    if (!props.hostId) {
                        statusMessage.value = '无效的主机 ID';
                        pushMeta();
                        return;
                    }

                    connectionGeneration += 1;
                    const generation = connectionGeneration;

                    teardownSocket();
                    connected.value = false;
                    connecting.value = true;
                    disconnectReason = '';
                    connectionAttempted = true;
                    elapsed.value = 0;
                    connId.value = '';
                    await recreateTerminal();
                    statusMessage.value = '正在连接 WebSocket...';
                    pushMeta();

                    requestAnimationFrame(() => {
                        if (!isCurrentGeneration(generation)) {
                            return;
                        }
                        if (!props.headless) {
                            fitTerminal();
                        }
                    });

                    const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
                    const ws = new WebSocket(protocol + '//' + location.host + '/ws?hostId=' + props.hostId);
                    socket = ws;

                    ws.addEventListener('open', () => {
                        if (!isCurrentGeneration(generation)) {
                            return;
                        }
                        statusMessage.value = 'WebSocket 已连接，正在建立 SSH...';
                        pushMeta();
                    });

                    ws.addEventListener('message', (event) => {
                        if (!isCurrentGeneration(generation)) {
                            return;
                        }
                        let payload;
                        try { payload = JSON.parse(event.data); } catch { return; }
                        switch (payload.type) {
                            case 'ready':
                                connId.value = payload._id || connId.value;
                                hostInfo.value = payload.host || hostInfo.value;
                                requestAnimationFrame(() => {
                                    if (!isCurrentGeneration(generation) || socket !== ws) {
                                        return;
                                    }
                                    if (!props.headless) {
                                        fitTerminal();
                                    }
                                    const { cols, rows } = authColsRows();
                                    ws.send(JSON.stringify({ type: 'auth', cols, rows }));
                                });
                                pushMeta();
                                break;
                            case 'connected':
                                connected.value = true;
                                connecting.value = false;
                                hostInfo.value = {
                                    name: payload.name,
                                    address: payload.host,
                                    port: payload.port,
                                    username: payload.user,
                                };
                                statusMessage.value = '已连接';
                                startElapsedTimer();
                                requestAnimationFrame(() => {
                                    if (!isCurrentGeneration(generation)) {
                                        return;
                                    }
                                    if (!props.headless && term) {
                                        fitTerminal();
                                        sendResize();
                                        if (props.active) {
                                            focusTerminal();
                                        }
                                    }
                                });
                                pushMeta();
                                break;
                            case 'output':
                                if (payload.data && socket === ws && term && !props.headless) {
                                    const raw = atob(payload.data);
                                    const bytes = new Uint8Array(raw.length);
                                    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
                                    term.write(bytes);
                                }
                                break;
                            case 'error':
                                connected.value = false;
                                connecting.value = false;
                                stopElapsedTimer();
                                disconnectReason = payload.message || '连接失败';
                                statusMessage.value = disconnectReason;
                                if (term && !props.headless) {
                                    term.writeln('\\r\\n[error] ' + disconnectReason);
                                    if (payload.detail) {
                                        String(payload.detail).split('\\n').forEach((line) => term.writeln(line));
                                    }
                                }
                                pushMeta();
                                break;
                            case 'disconnected':
                                if (socket === ws) {
                                    socket = null;
                                }
                                finalizeExit(payload.message || 'SSH 会话已结束', generation);
                                break;
                        }
                    });

                    ws.addEventListener('close', () => {
                        if (!isCurrentGeneration(generation)) {
                            return;
                        }
                        if (socket === ws) {
                            socket = null;
                        }
                        finalizeExit(disconnectReason || '连接已关闭', generation);
                    });

                    ws.addEventListener('error', () => {
                        if (!isCurrentGeneration(generation)) {
                            return;
                        }
                        if (!disconnectReason) disconnectReason = 'WebSocket 错误';
                        statusMessage.value = disconnectReason;
                        pushMeta();
                    });
                };

                const disconnect = () => {
                    connectionGeneration += 1;
                    teardownSocket();
                    connected.value = false;
                    connecting.value = false;
                    connectionAttempted = false;
                    elapsed.value = 0;
                    disconnectReason = '手动断开';
                    statusMessage.value = '手动断开';
                    if (term && !props.headless) {
                        term.writeln('');
                        term.writeln('[已退出] 手动断开');
                    }
                    pushMeta();
                };

                const reconnect = () => {
                    if (connecting.value) {
                        return;
                    }
                    connect();
                };

                const fitIfActive = () => {
                    if (props.headless || !props.active || !term || !fitAddon) {
                        return;
                    }
                    requestAnimationFrame(() => {
                        const previousCols = term.cols;
                        const previousRows = term.rows;
                        fitAddon.fit();
                        if (connected.value && socket && (term.cols !== previousCols || term.rows !== previousRows)) {
                            sendResize();
                        }
                        focusTerminal();
                    });
                };

                onMounted(() => {
                    resizeHandler = () => {
                        if (!props.active || !term || !fitAddon) return;
                        const previousCols = term.cols;
                        const previousRows = term.rows;
                        fitAddon.fit();
                        if (connected.value && socket && (term.cols !== previousCols || term.rows !== previousRows)) {
                            sendResize();
                        }
                    };
                    window.addEventListener('resize', resizeHandler);
                    connect();
                });

                onBeforeUnmount(() => {
                    connectionGeneration += 1;
                    if (resizeHandler) window.removeEventListener('resize', resizeHandler);
                    teardownSocket();
                    destroyTerminal();
                });

                watch(() => props.active, fitIfActive);

                expose({ reconnect, disconnect, connecting, connected, connId, focus: focusTerminal });

                return {
                    terminalRef,
                    terminalSessionKey,
                    active: computed(() => props.active),
                };
            },
            template: `
                <div class="terminal-pane" :class="{ active, 'terminal-pane-headless': headless }">
                    <div v-if="!headless" :key="terminalSessionKey" ref="terminalRef" class="terminal-wrap"></div>
                </div>
            `,
        },
        LiveSessionPane: {
            props: {
                connId: { type: String, default: '' },
                connected: { type: Boolean, default: false },
                visible: { type: Boolean, default: true },
                paneActive: { type: Boolean, default: true },
                title: { type: String, default: '' },
                embedded: { type: Boolean, default: false },
                collapsed: { type: Boolean, default: false },
            },
            emits: ['live-status'],
            setup(props, { emit, expose }) {
                const termRef = ref(null);
                const statusText = ref('等待连接');
                const live = ref(false);
                let term = null;
                let source = null;
                let reconnectTimer = null;
                let eventBuffer = [];
                let cols = 80;
                let rows = 24;
                let fitAddon = null;
                let wheelEl = null;
                let wheelHandler = null;
                let resizeObserver = null;
                let windowResizeHandler = null;

                const teardownFitObservers = () => {
                    if (windowResizeHandler) {
                        window.removeEventListener('resize', windowResizeHandler);
                        windowResizeHandler = null;
                    }
                    if (resizeObserver) {
                        resizeObserver.disconnect();
                        resizeObserver = null;
                    }
                };

                const setupFitObservers = () => {
                    teardownFitObservers();
                    windowResizeHandler = () => fitLiveTerm();
                    window.addEventListener('resize', windowResizeHandler);
                    if (termRef.value && typeof ResizeObserver !== 'undefined') {
                        resizeObserver = new ResizeObserver(() => fitLiveTerm());
                        resizeObserver.observe(termRef.value);
                    }
                };

                const decodeChunkBytes = (encoded) => {
                    if (!encoded) return null;
                    try {
                        const raw = atob(encoded);
                        const bytes = new Uint8Array(raw.length);
                        for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
                        return bytes;
                    } catch {
                        return null;
                    }
                };

                const attachWheelScroll = (wrapEl) => {
                    if (wheelEl && wheelHandler) {
                        wheelEl.removeEventListener('wheel', wheelHandler, { capture: true });
                    }
                    wheelEl = wrapEl;
                    wheelHandler = (e) => {
                        if (!term?.element) return;
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        const viewport = term.element.querySelector('.xterm-viewport');
                        if (viewport && viewport.scrollHeight > viewport.clientHeight + 1) {
                            viewport.scrollTop += e.deltaY;
                        } else {
                            wrapEl.scrollTop += e.deltaY;
                        }
                    };
                    wrapEl.addEventListener('wheel', wheelHandler, { passive: false, capture: true });
                };

                const destroyTerm = () => {
                    if (wheelEl && wheelHandler) {
                        wheelEl.removeEventListener('wheel', wheelHandler, { capture: true });
                    }
                    wheelEl = null;
                    wheelHandler = null;
                    if (term) {
                        term.dispose();
                        term = null;
                        fitAddon = null;
                    }
                    teardownFitObservers();
                };

                const ensureTerm = () => {
                    if (!termRef.value || term) return;
                    term = new Terminal({
                        cols,
                        rows,
                        disableStdin: true,
                        cursorBlink: false,
                        cursorInactiveStyle: 'none',
                        fontSize: 12,
                        scrollback: 5000,
                        theme: {
                            background: '#0f111a',
                            foreground: '#e6e6e6',
                            cursor: '#f8f8f2',
                            brightBlack: '#6b7280',
                        },
                    });
                    if (typeof FitAddon !== 'undefined' && FitAddon.FitAddon) {
                        fitAddon = new FitAddon.FitAddon();
                        term.loadAddon(fitAddon);
                    }
                    term.open(termRef.value);
                    fitLiveTerm();
                    setupFitObservers();
                    attachWheelScroll(termRef.value);
                    for (const [kind, data] of eventBuffer) {
                        handleEvent(kind, data);
                    }
                    eventBuffer = [];
                    term.scrollToBottom();
                };

                const fitLiveTerm = () => {
                    if (!term || !fitAddon || !termRef.value) return;
                    requestAnimationFrame(() => {
                        try {
                            fitAddon.fit();
                        } catch {
                            // ignore fit while hidden
                        }
                    });
                };

                const writeStatus = (text) => {
                    if (!term || !text) return;
                    term.writeln('\r\n\x1b[90m' + text + '\x1b[0m');
                };

                const handleEvent = (kind, data) => {
                    if (kind === 'status' && data && data.replay) return;
                    if (!term) {
                        eventBuffer.push([kind, data]);
                        return;
                    }
                    if (kind === 'output') {
                        const bytes = decodeChunkBytes(data?.chunk || '');
                        if (bytes) {
                            term.write(bytes);
                            term.scrollToBottom();
                        }
                        return;
                    }
                    if (kind === 'resize') {
                        if (fitAddon) {
                            fitLiveTerm();
                        } else {
                            cols = Math.max(1, data?.cols || cols);
                            rows = Math.max(1, data?.rows || rows);
                            term.resize(cols, rows);
                        }
                        return;
                    }
                    if (kind === 'error') {
                        live.value = false;
                        statusText.value = 'error';
                        writeStatus('[error] ' + (data?.message || '连接失败'));
                    }
                    if (kind === 'disconnected') {
                        live.value = false;
                        statusText.value = '已结束';
                        writeStatus('[disconnected] ' + (data?.message || 'SSH 会话已结束'));
                    }
                };

                const closeStream = () => {
                    if (reconnectTimer) {
                        clearTimeout(reconnectTimer);
                        reconnectTimer = null;
                    }
                    if (source) {
                        source.close();
                        source = null;
                    }
                };

                const openStream = () => {
                    if (!props.connId || !props.connected) return;
                    closeStream();
                    eventBuffer = [];
                    term?.clear();
                    live.value = true;
                    statusText.value = 'live';

                    source = new EventSource('/api/live/sessions/' + encodeURIComponent(props.connId) + '/stream');
                    const events = ['start', 'connected', 'resize', 'output', 'error', 'disconnected', 'status'];
                    for (const name of events) {
                        source.addEventListener(name, (event) => {
                            let data = event.data;
                            try { data = JSON.parse(event.data); } catch { /* keep raw */ }
                            handleEvent(name, data);
                        });
                    }
                    source.onerror = () => {
                        closeStream();
                        if (!props.connected || !props.connId) return;
                        if (reconnectTimer) return;
                        reconnectTimer = setTimeout(() => {
                            reconnectTimer = null;
                            if (props.connected && props.connId) {
                                openStream();
                            }
                        }, 1200);
                    };
                };

                const resetLive = () => {
                    if (!props.connId || !props.connected) return;
                    eventBuffer = [];
                    if (term) {
                        term.clear();
                    }
                    closeStream();
                    live.value = false;
                    statusText.value = '重置中';
                    nextTick(() => {
                        ensureTerm();
                        fitLiveTerm();
                        openStream();
                    });
                };

                const syncStream = () => {
                    if (!props.visible || !props.connId || !props.connected) {
                        closeStream();
                        live.value = false;
                        statusText.value = props.connId ? '未连接' : '等待连接';
                        return;
                    }
                    nextTick(() => {
                        ensureTerm();
                        openStream();
                    });
                };

                watch(() => [props.connId, props.connected, props.visible], syncStream, { immediate: true });
                watch(() => props.paneActive, (active) => {
                    if (active) {
                        nextTick(() => {
                            ensureTerm();
                            fitLiveTerm();
                        });
                    }
                });
                watch(() => props.collapsed, (collapsed) => {
                    if (!collapsed) {
                        nextTick(() => {
                            ensureTerm();
                            fitLiveTerm();
                        });
                    }
                });
                watch([live, statusText], () => {
                    emit('live-status', { live: live.value, statusText: statusText.value });
                }, { immediate: true });

                expose({ live, statusText });

                onBeforeUnmount(() => {
                    closeStream();
                    destroyTerm();
                    teardownFitObservers();
                });

                return { termRef, statusText, live, title: computed(() => props.title), resetLive };
            },
            template: `
                <div
                    class="terminal-live-pane"
                    v-show="visible"
                    :class="{
                        'pane-mobile-active': paneActive,
                        embedded,
                        collapsed,
                    }"
                >
                    <div v-if="!embedded" class="terminal-live-header">
                        <strong>现场</strong>
                        <div class="terminal-live-actions">
                            <button type="button" class="terminal-live-reset" title="清空并重新连接" :disabled="!connected" @click="resetLive">重置</button>
                            <span class="live-lamp terminal-live-lamp" :class="{ live }"><i></i>{{ statusText }}</span>
                        </div>
                    </div>
                    <div v-if="embedded && !collapsed" class="terminal-live-header terminal-live-header-embedded">
                        <strong>现场</strong>
                        <div class="terminal-live-actions">
                            <button type="button" class="terminal-live-reset" title="清空并重新连接" :disabled="!connected" @click="resetLive">重置</button>
                            <span class="live-lamp terminal-live-lamp" :class="{ live }"><i></i>{{ statusText }}</span>
                        </div>
                    </div>
                    <div v-if="!connected && !collapsed" class="ai-chat-hint">SSH 连接成功后显示手动输入与 AI 命令输出</div>
                    <div v-show="connected && !collapsed" ref="termRef" class="terminal-live-term"></div>
                </div>
            `,
        },
        AiChatPanel: {
            components: { AiToolCallCard },
            props: {
                connId: { type: String, default: '' },
                aiSessionId: { type: Number, default: null },
                connected: { type: Boolean, default: false },
                visible: { type: Boolean, default: true },
                paneActive: { type: Boolean, default: true },
                title: { type: String, default: '' },
            },
            setup(props) {
                const isSessionMode = computed(() => props.aiSessionId != null && props.aiSessionId > 0);
                const chatApi = computed(() => {
                    if (isSessionMode.value) {
                        const base = '/api/ai/sessions/' + props.aiSessionId;
                        return {
                            bootstrap: base + '/bootstrap',
                            stream: base + '/chat/stream',
                            subscribe: base + '/chat/stream/subscribe',
                            approval: base + '/approval/stream',
                            approvalMode: base + '/approval-mode',
                            autoApprove: base + '/auto-approve',
                            feedback: base + '/feedback/stream',
                            stop: base + '/stop',
                            reset: base + '/reset',
                        };
                    }
                    return {
                        bootstrap: '/api/ai/bootstrap?conn_id=' + encodeURIComponent(props.connId),
                        stream: '/api/ai/chat/stream',
                        approval: '/api/ai/chat/approval/stream',
                        approvalMode: '/api/ai/chat/approval-mode',
                        autoApprove: '/api/ai/chat/auto-approve',
                        feedback: '/api/ai/chat/feedback/stream',
                        stop: '/api/ai/chat/stop',
                        reset: '/api/ai/chat/reset',
                    };
                });
                const threadReady = computed(() => isSessionMode.value ? !!props.aiSessionId : !!props.connId);
                const toolCallsOpen = ref(false);
                const toolCalls = ref([]);
                const tokenUsage = ref(null);

                const formatTokenCount = (value) => {
                    const n = Number(value) || 0;
                    if (n >= 1000) {
                        const scaled = n / 1000;
                        return (scaled >= 10 ? Math.round(scaled) : scaled.toFixed(1).replace(/\.0$/, '')) + 'k';
                    }
                    return String(n);
                };

                const normalizeToolCalls = (items) => (items || []).map((item, idx) => ({
                    key: item.callId || `${item.name || 'tool'}-${idx}`,
                    callId: item.callId || null,
                    name: item.name || '',
                    label: item.label || item.name || 'tool',
                    inputs: item.inputs || {},
                    result: item.result ?? null,
                    status: item.status || (item.result != null && item.result !== '' ? 'done' : 'running'),
                }));

                const normalizeTimelineItem = (item, idx = 0) => {
                    if (item?.kind === 'tool') {
                        const normalized = normalizeToolCalls([item])[0];
                        return { kind: 'tool', ...normalized };
                    }
                    return {
                        kind: 'message',
                        role: item?.role || 'assistant',
                        content: item?.content || '',
                        html: item?.html || item?.content || '',
                        streaming: false,
                        stopped: !!item?.stopped,
                    };
                };

                const syncToolCallsFromTimeline = () => {
                    toolCalls.value = messages.value
                        .filter((item) => item.kind === 'tool')
                        .map((item, idx) => ({
                            key: item.key || item.callId || `${item.name || 'tool'}-${idx}`,
                            callId: item.callId || null,
                            name: item.name || '',
                            label: item.label || item.name || 'tool',
                            inputs: item.inputs || {},
                            result: item.result ?? null,
                            status: item.status || 'done',
                        }));
                };

                const applyToolCallsFromBootstrap = (payload) => {
                    syncToolCallsFromTimeline();
                    if (toolCalls.value.length) {
                        return;
                    }
                    if (Array.isArray(payload?.tool_calls) && payload.tool_calls.length) {
                        toolCalls.value = normalizeToolCalls(payload.tool_calls);
                    }
                };

                const resetTurnUiState = () => {
                    messages.value = [];
                    toolCalls.value = [];
                    toolCallsOpen.value = false;
                    approval.value = null;
                    feedback.value = null;
                    commandApprovalMode.value = 'always_approve';
                    resetFeedbackForm();
                    errorText.value = '';
                    generationActive.value = false;
                };

                const findToolTarget = (data) => {
                    const callId = data.callId || null;
                    if (callId) {
                        const fromTimeline = [...messages.value].reverse().find(
                            (item) => item.kind === 'tool' && item.callId === callId,
                        );
                        if (fromTimeline) {
                            return fromTimeline;
                        }
                        return [...toolCalls.value].reverse().find((item) => item.callId === callId) || null;
                    }
                    const fromTimeline = [...messages.value].reverse().find(
                        (item) => item.kind === 'tool' && item.name === data.name && item.status === 'running',
                    );
                    if (fromTimeline) {
                        return fromTimeline;
                    }
                    return [...toolCalls.value].reverse().find(
                        (item) => item.name === data.name && item.status === 'running',
                    ) || null;
                };

                const recordToolEvent = (data) => {
                    if (!data) return;
                    const callId = data.callId || null;
                    if (data.phase === 'call') {
                        if (callId) {
                            const existing = messages.value.find(
                                (item) => item.kind === 'tool' && item.callId === callId,
                            );
                            if (existing) {
                                if (existing.status !== 'done') {
                                    existing.status = 'running';
                                    syncToolCallsFromTimeline();
                                }
                                return;
                            }
                        }
                        commitStreamingAssistants();
                        const entry = {
                            kind: 'tool',
                            key: callId || `tmp-${Date.now()}-${messages.value.length}`,
                            callId,
                            name: data.name || '',
                            label: data.label || data.name || 'tool',
                            inputs: data.inputs || {},
                            result: null,
                            status: 'running',
                        };
                        messages.value.push(entry);
                        syncToolCallsFromTimeline();
                        return;
                    }
                    if (data.phase !== 'result') return;

                    const target = findToolTarget(data);
                    if (target) {
                        if (data.inputs && Object.keys(data.inputs).length) {
                            target.inputs = data.inputs;
                        }
                        target.result = data.result ?? null;
                        target.status = 'done';
                        syncToolCallsFromTimeline();
                        return;
                    }
                    if (callId) {
                        const existing = messages.value.find(
                            (item) => item.kind === 'tool' && item.callId === callId,
                        );
                        if (existing) {
                            if (data.inputs && Object.keys(data.inputs).length) {
                                existing.inputs = data.inputs;
                            }
                            existing.result = data.result ?? null;
                            existing.status = 'done';
                            syncToolCallsFromTimeline();
                            return;
                        }
                    }
                    messages.value.push({
                        kind: 'tool',
                        key: callId || `tmp-${Date.now()}-${messages.value.length}`,
                        callId,
                        name: data.name || '',
                        label: data.label || data.name || 'tool',
                        inputs: data.inputs || {},
                        result: data.result ?? null,
                        status: 'done',
                    });
                    syncToolCallsFromTimeline();
                };

                const openToolCalls = () => {
                    toolCallsOpen.value = true;
                };

                const closeToolCalls = () => {
                    toolCallsOpen.value = false;
                };

                let toolCallsEscapeHandler = null;
                watch(toolCallsOpen, (open) => {
                    if (toolCallsEscapeHandler) {
                        window.removeEventListener('keydown', toolCallsEscapeHandler);
                        toolCallsEscapeHandler = null;
                    }
                    if (!open) return;
                    toolCallsEscapeHandler = (event) => {
                        if (event.key === 'Escape') {
                            closeToolCalls();
                        }
                    };
                    window.addEventListener('keydown', toolCallsEscapeHandler);
                });

                onBeforeUnmount(() => {
                    if (toolCallsEscapeHandler) {
                        window.removeEventListener('keydown', toolCallsEscapeHandler);
                    }
                    subscribeAbortController?.abort();
                });

                const messages = ref([]);
                const draft = ref('');
                const toolHostMap = computed(() => buildHostMapFromTimeline(messages.value));
                const busy = ref(false);
                const busyText = ref('');
                const generationActive = ref(false);
                const streamEventIndex = ref(0);
                const stopEnabled = computed(() => busy.value || generationActive.value);
                let subscribeReplayState = null;
                const configured = ref(false);
                const enabled = ref(true);
                const approval = ref(null);
                const commandApprovalMode = ref('always_approve');
                const approvalModeOptions = [
                    {
                        value: 'always_approve',
                        label: '逐条审批',
                        hint: '每条命令都须人工批准',
                        detail: '每条命令执行前均弹出审批；不受策略 AutoRun 影响，Deny 仍拒绝。',
                    },
                    {
                        value: 'policy',
                        label: '按策略执行',
                        hint: 'AutoRun 自动执行，需审批类仍须批准',
                        detail: 'AutoRun（如 ls）自动执行；RequireApproval（如 mysql、rm）须批准；Deny 拒绝。',
                    },
                    {
                        value: 'force_auto',
                        label: '全部自动执行',
                        hint: '跳过所有审批（权限最大）',
                        detail: '跳过全部审批，含策略 Deny；命令直接执行，仅建议在受控环境使用。',
                    },
                ];
                const feedback = ref(null);
                const feedbackAnswers = ref({});
                const feedbackOtherTexts = ref({});
                const errorText = ref('');

                const approvalSubmitLabel = (approved, mode) => {
                    if (!approved) {
                        return '拒绝';
                    }
                    if (mode === 'policy') {
                        return '批准（按策略执行）';
                    }
                    if (mode === 'force_auto') {
                        return '批准（全部自动执行）';
                    }
                    return '批准';
                };

                const normalizeApprovalMode = (value, legacyAutoApprove = false) => {
                    if (value === 'policy' || value === 'force_auto' || value === 'always_approve') {
                        return value;
                    }
                    return legacyAutoApprove ? 'policy' : 'always_approve';
                };

                const setCommandApprovalMode = async (mode) => {
                    if (!threadReady.value) return;
                    if (busy.value && !approval.value && !feedback.value) return;
                    const next = normalizeApprovalMode(mode);
                    commandApprovalMode.value = next;
                    try {
                        const body = isSessionMode.value
                            ? { approval_mode: next }
                            : { conn_id: props.connId, approval_mode: next };
                        const res = await fetch(chatApi.value.approvalMode, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(body),
                        });
                        const json = await res.json();
                        if (json.code !== 0) {
                            errorText.value = json.msg || '更新审批模式失败';
                            return;
                        }
                        commandApprovalMode.value = normalizeApprovalMode(
                            json.data?.command_approval_mode,
                            !!json.data?.command_auto_approve,
                        );
                        errorText.value = '';
                    } catch (e) {
                        errorText.value = e.message || '更新审批模式失败';
                    }
                };

                const OTHER_OPTION_VALUES = new Set(['other', 'other_custom', 'custom']);

                const isOtherOption = (opt) => {
                    const value = String(opt?.value || '').toLowerCase();
                    if (OTHER_OPTION_VALUES.has(value) || value.endsWith('_other')) {
                        return true;
                    }
                    return String(opt?.label || '').includes('其他');
                };

                const isOtherValue = (value) => {
                    const normalized = String(value || '').toLowerCase();
                    return OTHER_OPTION_VALUES.has(normalized) || normalized.endsWith('_other');
                };

                const toggleFeedbackCheckbox = (field, opt, checked) => {
                    const current = Array.isArray(feedbackAnswers.value[field.id])
                        ? [...feedbackAnswers.value[field.id]]
                        : [];
                    feedbackAnswers.value[field.id] = checked
                        ? [...current, opt.value]
                        : current.filter((value) => value !== opt.value);
                    if (!checked && isOtherOption(opt)) {
                        feedbackOtherTexts.value[field.id] = '';
                    }
                };

                const buildFeedbackAnswers = () => {
                    const answers = { ...feedbackAnswers.value };
                    for (const field of feedback.value?.fields || []) {
                        const otherText = String(feedbackOtherTexts.value[field.id] || '').trim();
                        if (field.type === 'radio' && isOtherValue(answers[field.id])) {
                            answers[field.id] = otherText ? `其他：${otherText}` : '其他';
                            continue;
                        }
                        if (field.type === 'checkbox' && Array.isArray(answers[field.id])) {
                            const selected = answers[field.id].filter((value) => !isOtherValue(value));
                            if (answers[field.id].some((value) => isOtherValue(value))) {
                                answers[field.id] = otherText
                                    ? [...selected, `其他：${otherText}`]
                                    : [...selected, '其他'];
                            }
                        }
                    }
                    return answers;
                };

                const resetFeedbackForm = () => {
                    feedbackAnswers.value = {};
                    feedbackOtherTexts.value = {};
                };

                const feedbackOptionLetter = (index) => {
                    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                    return index >= 0 && index < letters.length ? letters[index] : String(index + 1);
                };
                const logRef = ref(null);
                const chatBodyRef = ref(null);
                const composerRef = ref(null);
                const composing = ref(false);
                const COMPOSER_MAX_HEIGHT = 200;

                const resizeComposer = () => {
                    nextTick(() => {
                        const el = composerRef.value;
                        if (!el) {
                            return;
                        }

                        el.style.height = 'auto';
                        const scrollHeight = el.scrollHeight;
                        const nextHeight = Math.min(scrollHeight, COMPOSER_MAX_HEIGHT);
                        el.style.height = `${nextHeight}px`;
                        el.style.overflowY = scrollHeight > COMPOSER_MAX_HEIGHT ? 'auto' : 'hidden';
                    });
                };
                let abortController = null;
                let subscribeAbortController = null;
                let scrollRaf = 0;
                let assistantMessageSeq = 0;

                const commitStreamingAssistants = () => {
                    for (const msg of messages.value) {
                        if (msg.kind === 'message' && msg.role === 'assistant' && msg.streaming) {
                            msg.streaming = false;
                        }
                    }
                };

                const findLastStreamingAssistant = () => {
                    for (let i = messages.value.length - 1; i >= 0; i -= 1) {
                        const msg = messages.value[i];
                        if (msg?.kind === 'message' && msg.role === 'assistant' && msg.streaming) {
                            return msg;
                        }
                    }
                    return null;
                };

                const scrollToBottom = async () => {
                    await nextTick();
                    if (scrollRaf) return;
                    scrollRaf = requestAnimationFrame(() => {
                        scrollRaf = 0;
                        const el = chatBodyRef.value || logRef.value;
                        if (el) {
                            el.scrollTop = el.scrollHeight;
                        }
                    });
                };

                const consumeSse = async (response, onEvent) => {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    while (true) {
                        const { value, done } = await reader.read();
                        if (done) break;
                        buffer += decoder.decode(value, { stream: true });
                        const chunks = buffer.split('\n\n');
                        buffer = chunks.pop() || '';
                        for (const chunk of chunks) {
                            const lines = chunk.split('\n');
                            let event = 'message';
                            let data = '';
                            for (const line of lines) {
                                if (line.startsWith('event: ')) event = line.slice(7);
                                if (line.startsWith('data: ')) data = line.slice(6);
                            }
                            if (data) onEvent(event, JSON.parse(data));
                        }
                    }
                };

                const postJson = async (url, body) => {
                    return fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json, text/event-stream',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(body),
                    });
                };

                const trimCurrentTurnAssistantsForResume = () => {
                    const lastUserIdx = messages.value.findLastIndex(
                        (item) => item.kind === 'message' && item.role === 'user',
                    );
                    if (lastUserIdx < 0) {
                        return;
                    }
                    messages.value = messages.value.filter((item, idx) => {
                        if (idx <= lastUserIdx) {
                            return true;
                        }
                        if (item.kind === 'tool') {
                            return true;
                        }

                        return !(item.kind === 'message' && item.role === 'assistant');
                    });
                    syncToolCallsFromTimeline();
                };

                const findLastAssistantMessage = () => {
                    for (let i = messages.value.length - 1; i >= 0; i -= 1) {
                        const msg = messages.value[i];
                        if (msg?.kind === 'message' && msg.role === 'assistant') {
                            return msg;
                        }
                    }

                    return null;
                };

                const reconcileInterruptUi = () => {
                    if ((approval.value?.actions?.length ?? 0) > 0 || (feedback.value?.fields?.length ?? 0) > 0) {
                        return;
                    }
                    const lastUserIdx = messages.value.findLastIndex(
                        (item) => item.kind === 'message' && item.role === 'user',
                    );
                    const afterUser = lastUserIdx >= 0
                        ? messages.value.slice(lastUserIdx + 1)
                        : messages.value;
                    const hasCompletedSsh = afterUser.some(
                        (item) => item.kind === 'tool'
                            && item.name === 'run_ssh_command'
                            && item.status === 'done',
                    );
                    if (hasCompletedSsh) {
                        approval.value = null;
                    }
                };

                const applyInterruptFromPayload = (payload) => {
                    if (!payload || typeof payload !== 'object') {
                        return;
                    }
                    if (payload.approval && (payload.approval.actions?.length ?? 0) > 0) {
                        approval.value = payload.approval;
                    }
                    if (payload.feedback && (payload.feedback.fields?.length ?? 0) > 0) {
                        feedback.value = payload.feedback;
                        resetFeedbackForm();
                    }
                };

                let bootstrapSeq = 0;

                const bootstrap = async (options = {}) => {
                    if (!threadReady.value) return;
                    const seq = ++bootstrapSeq;
                    try {
                        const res = await fetch(chatApi.value.bootstrap, {
                            credentials: 'same-origin',
                        });
                        const json = await res.json();
                        if (seq !== bootstrapSeq) {
                            return;
                        }
                        if (json.code !== 0) {
                            errorText.value = json.msg || 'AI 初始化失败';
                            return;
                        }
                        configured.value = !!json.data?.configured;
                        enabled.value = json.data?.enabled !== false;
                        if (Array.isArray(json.data?.timeline) && json.data.timeline.length) {
                            messages.value = json.data.timeline.map(normalizeTimelineItem);
                        } else {
                            messages.value = (json.data?.messages || []).map((m) => ({
                                kind: 'message',
                                role: m.role,
                                html: m.html || m.content,
                                content: m.content,
                                streaming: false,
                                stopped: !!m.stopped,
                            }));
                        }
                        applyToolCallsFromBootstrap(json.data);
                        if (seq !== bootstrapSeq) {
                            return;
                        }
                        approval.value = json.data?.approval || null;
                        feedback.value = json.data?.feedback || null;
                        commandApprovalMode.value = normalizeApprovalMode(
                            json.data?.command_approval_mode,
                            !!json.data?.command_auto_approve,
                        );
                        tokenUsage.value = json.data?.token_usage ?? null;
                        resetFeedbackForm();
                        errorText.value = '';

                        const hasPendingInterrupt = (approval.value?.actions?.length ?? 0) > 0
                            || (feedback.value?.fields?.length ?? 0) > 0;
                        const generation = json.data?.generation;
                        generationActive.value = !hasPendingInterrupt
                            && !!(generation?.active && !generation?.manual_stop);
                        if (!hasPendingInterrupt) {
                            reconcileInterruptUi();
                        }
                        if (!options.skipGenerationResume && generationActive.value && isSessionMode.value && chatApi.value.subscribe) {
                            const pendingApproval = approval.value;
                            const pendingFeedback = feedback.value;
                            approval.value = null;
                            feedback.value = null;
                            resetFeedbackForm();
                            trimCurrentTurnAssistantsForResume();
                            streamEventIndex.value = 0;
                            await subscribeGeneration(0, {
                                hadPendingApproval: (pendingApproval?.actions?.length ?? 0) > 0,
                                hadPendingFeedback: (pendingFeedback?.fields?.length ?? 0) > 0,
                                partial: '',
                            });
                        }

                        await scrollToBottom();
                    } catch (e) {
                        errorText.value = e.message || 'AI 初始化失败';
                    }
                };

                const appendAssistantDelta = (text) => {
                    if (!text) return;
                    const last = messages.value[messages.value.length - 1];
                    if (last && last.kind === 'message' && last.role === 'assistant' && last.streaming) {
                        last.content += text;
                        last.html = (last.html || '') + text.replace(/\n/g, '<br>');
                        scrollToBottom();
                        return;
                    }
                    commitStreamingAssistants();
                    assistantMessageSeq += 1;
                    messages.value.push({
                        kind: 'message',
                        role: 'assistant',
                        key: `assistant-${assistantMessageSeq}`,
                        content: text,
                        html: text.replace(/\n/g, '<br>'),
                        streaming: true,
                    });
                    scrollToBottom();
                };

                const finalizeAssistant = (payload) => {
                    const streaming = findLastStreamingAssistant();
                    const target = streaming || findLastAssistantMessage();
                    if (target) {
                        target.streaming = false;
                        const local = String(target.content || '').trim();
                        const remote = String(payload?.content || '').trim();
                        if (remote && (!local || remote.length >= local.length)) {
                            target.html = payload.html || target.html;
                            target.content = payload.content || target.content;
                        }
                        if (payload?.stopped) {
                            target.stopped = true;
                        }
                    } else if (payload?.content?.trim()) {
                        const tail = payload.content.trim();
                        const alreadyShown = messages.value.some(
                            (msg) => msg.kind === 'message'
                                && msg.role === 'assistant'
                                && String(msg.content || '').trim() === tail,
                        );
                        if (!alreadyShown) {
                            assistantMessageSeq += 1;
                            messages.value.push({
                                kind: 'message',
                                role: 'assistant',
                                key: `assistant-${assistantMessageSeq}`,
                                content: payload.content,
                                html: payload.html || payload.content.replace(/\n/g, '<br>'),
                                streaming: false,
                                stopped: !!payload?.stopped,
                            });
                        }
                    } else {
                        commitStreamingAssistants();
                    }
                    if (payload?.approval && (payload.approval.actions?.length ?? 0) > 0) {
                        if (!subscribeReplayState || subscribeReplayState.hadPendingApproval) {
                            approval.value = payload.approval;
                        }
                    } else if (!payload?.approval) {
                        approval.value = null;
                    }
                    if (payload?.feedback && (payload.feedback.fields?.length ?? 0) > 0) {
                        if (!subscribeReplayState || subscribeReplayState.hadPendingFeedback) {
                            feedback.value = payload.feedback;
                            resetFeedbackForm();
                        }
                    } else if (!payload?.feedback) {
                        feedback.value = null;
                    }
                };

                const appendReplayDelta = (text) => {
                    if (!text) return;
                    if (!subscribeReplayState) {
                        appendAssistantDelta(text);
                        return;
                    }
                    subscribeReplayState.replayedText += text;
                    const chunk = subscribeReplayState.replayedText.slice(subscribeReplayState.lastEmittedLength);
                    if (!chunk) {
                        return;
                    }
                    appendAssistantDelta(chunk);
                    subscribeReplayState.lastEmittedLength = subscribeReplayState.replayedText.length;
                };

                const handleStreamEvent = (event, data) => {
                    streamEventIndex.value += 1;
                    if (event === 'delta') {
                        if (subscribeReplayState) {
                            appendReplayDelta(data.text || '');
                        } else {
                            appendAssistantDelta(data.text || '');
                        }
                    }
                    if (event === 'tool') {
                        recordToolEvent(data);
                        busyText.value = '工具: ' + (data.label || data.name || '') + ' (' + (data.phase === 'call' ? '调用' : '完成') + ')';
                        scrollToBottom();
                    }
                    if (event === 'usage') {
                        tokenUsage.value = data;
                    }
                    if (event === 'approval') {
                        if (!subscribeReplayState || subscribeReplayState.hadPendingApproval) {
                            approval.value = data;
                            scrollToBottom();
                        }
                    }
                    if (event === 'feedback') {
                        if (!subscribeReplayState || subscribeReplayState.hadPendingFeedback) {
                            feedback.value = data;
                            resetFeedbackForm();
                            scrollToBottom();
                        }
                    }
                    if (event === 'done') {
                        finalizeAssistant(data);
                        if (data.token_usage) {
                            tokenUsage.value = data.token_usage;
                        }
                        scrollToBottom();
                    }
                    if (event === 'error') {
                        applyInterruptFromPayload(data);
                        errorText.value = data.message || 'AI 错误';
                        if (approval.value || feedback.value) {
                            errorText.value = '';
                        }
                        scrollToBottom();
                    }
                };

                const subscribeGeneration = async (fromIndex = 0, replayContext = {}) => {
                    if (!isSessionMode.value || !chatApi.value.subscribe) return;
                    subscribeAbortController?.abort();
                    subscribeAbortController = new AbortController();
                    generationActive.value = true;
                    busy.value = true;
                    busyText.value = 'AI 思考中...';
                    let refreshAfter = false;
                    let resyncAfter = false;
                    subscribeReplayState = {
                        hadPendingApproval: !!replayContext.hadPendingApproval,
                        hadPendingFeedback: !!replayContext.hadPendingFeedback,
                        replayedText: '',
                        lastEmittedLength: 0,
                    };
                    try {
                        const response = await fetch(chatApi.value.subscribe, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'text/event-stream',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({ from_index: fromIndex }),
                            signal: subscribeAbortController.signal,
                        });
                        const contentType = response.headers.get('content-type') || '';
                        if (!response.ok || !contentType.includes('text/event-stream')) {
                            if (response.status === 404) {
                                resyncAfter = true;
                            } else {
                                const json = await response.json().catch(() => ({}));
                                errorText.value = json.msg || '重连接失败';
                            }
                            generationActive.value = false;
                            return;
                        }
                        try {
                            await consumeSse(response, (event, data) => {
                                handleStreamEvent(event, data);
                                if (event === 'done' || event === 'error') {
                                    refreshAfter = true;
                                }
                            });
                        } catch (streamError) {
                            if (streamError?.name !== 'AbortError') {
                                resyncAfter = true;
                            }
                        }
                    } catch (e) {
                        if (e.name !== 'AbortError') {
                            resyncAfter = true;
                        }
                    } finally {
                        subscribeReplayState = null;
                        busy.value = false;
                        generationActive.value = false;
                        busyText.value = '';
                        commitStreamingAssistants();
                        reconcileInterruptUi();
                        await scrollToBottom();
                        if (refreshAfter || resyncAfter) {
                            errorText.value = '';
                            await bootstrap({ skipGenerationResume: true });
                        }
                    }
                };

                const runStream = async (url, body) => {
                    subscribeAbortController?.abort();
                    abortController?.abort();
                    abortController = new AbortController();
                    busy.value = true;
                    generationActive.value = true;
                    streamEventIndex.value = 0;
                    busyText.value = 'AI 思考中...';
                    errorText.value = '';
                    scrollToBottom();
                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'text/event-stream',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify(body),
                            signal: abortController.signal,
                        });
                        const contentType = response.headers.get('content-type') || '';
                        if (!contentType.includes('text/event-stream')) {
                            const json = await response.json();
                            if (json.code !== 0) {
                                applyInterruptFromPayload(json.data);
                                const err = new Error(json.msg || '请求失败');
                                err.payload = json.data;
                                throw err;
                            }
                            finalizeAssistant(json.data || {});
                            return;
                        }
                        await consumeSse(response, (event, data) => handleStreamEvent(event, data));
                    } catch (e) {
                        if (e.payload) {
                            applyInterruptFromPayload(e.payload);
                        }
                        if (e.name !== 'AbortError') {
                            errorText.value = e.message || 'AI 请求失败';
                            if (approval.value || feedback.value) {
                                errorText.value = '';
                            }
                        }
                    } finally {
                        busy.value = false;
                        generationActive.value = false;
                        busyText.value = '';
                        commitStreamingAssistants();
                        await scrollToBottom();
                    }
                };

                const sendMessage = async () => {
                    const text = draft.value.trim();
                    if (!text || busy.value || generationActive.value || !threadReady.value) return;
                    if (!isSessionMode.value && !props.connected) return;
                    messages.value.push({
                        kind: 'message',
                        role: 'user',
                        content: text,
                        html: text.replace(/\n/g, '<br>'),
                        streaming: false,
                    });
                    draft.value = '';
                    resizeComposer();
                    await scrollToBottom();
                    const body = isSessionMode.value
                        ? { message: text }
                        : { conn_id: props.connId, message: text };
                    await runStream(chatApi.value.stream, body);
                };

                const submitApproval = async (approved) => {
                    if (!threadReady.value || busy.value) return;
                    const mode = commandApprovalMode.value;
                    const label = approvalSubmitLabel(approved, mode);
                    messages.value.push({
                        kind: 'message',
                        role: 'user',
                        content: label,
                        html: label,
                        streaming: false,
                    });
                    approval.value = null;
                    await scrollToBottom();
                    const body = isSessionMode.value
                        ? { approved: approved ? 1 : 0, approval_mode: mode }
                        : { conn_id: props.connId, approved: approved ? 1 : 0, approval_mode: mode };
                    await runStream(chatApi.value.approval, body);
                };

                const submitFeedback = async () => {
                    if (!threadReady.value || busy.value || !feedback.value) return;
                    messages.value.push({
                        kind: 'message',
                        role: 'user',
                        content: '已提交反馈',
                        html: '已提交反馈',
                        streaming: false,
                    });
                    const answers = buildFeedbackAnswers();
                    feedback.value = null;
                    resetFeedbackForm();
                    await scrollToBottom();
                    const body = isSessionMode.value
                        ? { answers }
                        : { conn_id: props.connId, answers };
                    await runStream(chatApi.value.feedback, body);
                };

                const skipFeedback = async () => {
                    if (!threadReady.value || busy.value || !feedback.value) return;
                    messages.value.push({
                        kind: 'message',
                        role: 'user',
                        content: '已跳过反馈',
                        html: '已跳过反馈',
                        streaming: false,
                    });
                    feedback.value = null;
                    resetFeedbackForm();
                    await scrollToBottom();
                    const body = isSessionMode.value ? { skip: true } : { conn_id: props.connId, skip: true };
                    await runStream(chatApi.value.feedback, body);
                };

                const composerDisabled = computed(() => {
                    if (busy.value || generationActive.value || !configured.value || !!approval.value || !!feedback.value) {
                        return true;
                    }
                    if (isSessionMode.value) return false;
                    return !props.connected;
                });

                const stopGeneration = async () => {
                    subscribeAbortController?.abort();
                    if (!threadReady.value) return;
                    const body = isSessionMode.value ? {} : { conn_id: props.connId };
                    await postJson(chatApi.value.stop, body);
                    await bootstrap({ skipGenerationResume: true });
                };

                const resetChat = async () => {
                    if (!threadReady.value || busy.value) return;
                    subscribeAbortController?.abort();
                    const body = isSessionMode.value ? {} : { conn_id: props.connId };
                    const hadActiveGeneration = generationActive.value;
                    try {
                        if (hadActiveGeneration) {
                            await postJson(chatApi.value.stop, body);
                        }
                        const res = await postJson(chatApi.value.reset, body);
                        const json = await res.json();
                        if (json.code !== 0) {
                            errorText.value = json.msg || '重置失败';
                            return;
                        }
                    } catch (e) {
                        errorText.value = e.message || '重置失败';
                        return;
                    }
                    generationActive.value = false;
                    streamEventIndex.value = 0;
                    messages.value = [];
                    toolCalls.value = [];
                    toolCallsOpen.value = false;
                    approval.value = null;
                    feedback.value = null;
                    commandApprovalMode.value = 'always_approve';
                    resetFeedbackForm();
                    errorText.value = '';
                    await bootstrap({ skipGenerationResume: true });
                };

                const onComposerKeydown = (event) => {
                    if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) {
                        return;
                    }
                    if (composing.value || event.isComposing || event.keyCode === 229) {
                        return;
                    }
                    event.preventDefault();
                    sendMessage();
                };

                watch(() => [props.connId, props.aiSessionId], () => {
                    messages.value = [];
                    toolCalls.value = [];
                    toolCallsOpen.value = false;
                    approval.value = null;
                    feedback.value = null;
                    commandApprovalMode.value = 'always_approve';
                    resetFeedbackForm();
                    bootstrap();
                }, { immediate: true });

                watch(draft, resizeComposer);

                watch(() => [approval.value, feedback.value], () => {
                    if (!approval.value && !feedback.value) {
                        resizeComposer();
                    }
                });

                watch(() => props.paneActive, (active) => {
                    if (active) {
                        scrollToBottom();
                    }
                });

                const approvalModeHelpOpen = ref(false);

                const activeApprovalModeOption = computed(() => (
                    approvalModeOptions.find((opt) => opt.value === commandApprovalMode.value)
                    || approvalModeOptions[0]
                ));

                const toggleApprovalModeHelp = (event) => {
                    event?.stopPropagation();
                    approvalModeHelpOpen.value = !approvalModeHelpOpen.value;
                };

                const closeApprovalModeHelp = () => {
                    approvalModeHelpOpen.value = false;
                };

                onMounted(() => {
                    document.addEventListener('click', closeApprovalModeHelp);
                });

                onBeforeUnmount(() => {
                    document.removeEventListener('click', closeApprovalModeHelp);
                });

                return {
                    toolCallsOpen,
                    toolCalls,
                    openToolCalls,
                    closeToolCalls,
                    messages,
                    toolHostMap,
                    draft,
                    busy,
                    busyText,
                    generationActive,
                    stopEnabled,
                    configured,
                    enabled,
                    approval,
                    commandApprovalMode,
                    approvalModeOptions,
                    activeApprovalModeOption,
                    approvalModeHelpOpen,
                    toggleApprovalModeHelp,
                    setCommandApprovalMode,
                    feedback,
                    feedbackAnswers,
                    feedbackOtherTexts,
                    isOtherOption,
                    toggleFeedbackCheckbox,
                    feedbackOptionLetter,
                    errorText,
                    tokenUsage,
                    formatTokenCount,
                    logRef,
                    chatBodyRef,
                    composerRef,
                    composing,
                    resizeComposer,
                    sendMessage,
                    onComposerKeydown,
                    submitApproval,
                    submitFeedback,
                    skipFeedback,
                    composerDisabled,
                    stopGeneration,
                    resetChat,
                    isSessionMode,
                    threadReady,
                };
            },
            template: `
                <div class="ai-chat-panel" v-show="visible" :class="{ 'pane-mobile-active': paneActive }">
                    <div class="ai-chat-header">
                        <div class="ai-chat-title">
                            <strong>AI 助手</strong>
                            <button
                                type="button"
                                class="ai-live-toggle"
                                :class="{ active: toolCallsOpen }"
                                title="查看工具调用"
                                @click="openToolCalls"
                            >
                                工具调用
                                <span v-if="toolCalls.length" class="ai-tool-count">{{ toolCalls.length }}</span>
                            </button>
                        </div>
                        <div class="actions">
                            <button type="button" @click="stopGeneration" :disabled="!stopEnabled">停止</button>
                            <button type="button" @click="resetChat" :disabled="busy">重置</button>
                        </div>
                    </div>
                    <Teleport to="body">
                        <div v-if="toolCallsOpen" class="ai-tool-popup-overlay" @click.self="closeToolCalls">
                            <div class="ai-tool-popup" role="dialog" aria-modal="true" aria-labelledby="ai-tool-popup-title">
                                <div class="ai-tool-popup-head">
                                    <h3 id="ai-tool-popup-title">工具调用</h3>
                                    <button type="button" class="ai-tool-popup-close" title="关闭" @click="closeToolCalls">×</button>
                                </div>
                                <div class="ai-tool-popup-body">
                                    <div v-if="!toolCalls.length" class="ai-chat-hint">暂无工具调用</div>
                                    <AiToolCallCard
                                        v-for="item in toolCalls"
                                        :key="item.key"
                                        :item="item"
                                        :host-map="toolHostMap"
                                        class="ai-tool-call-item"
                                    />
                                </div>
                            </div>
                        </div>
                    </Teleport>
                    <div v-if="!isSessionMode && !connected" class="ai-chat-hint">SSH 连接成功后可用</div>
                    <div v-else-if="isSessionMode && !threadReady" class="ai-chat-hint">正在加载 AI 会话…</div>
                    <div v-else-if="!enabled" class="ai-chat-hint">AI 助手未启用</div>
                    <div v-else-if="!configured" class="ai-chat-hint">请在侧栏「AI 设置」中创建并启用 AI 配置</div>
                    <div class="ai-chat-body" ref="chatBodyRef">
                        <div ref="logRef" class="ai-chat-log">
                            <div v-if="!messages.length" class="ai-chat-empty">描述你想完成的任务，AI 会提议命令，你审核后执行。</div>
                            <template v-for="(msg, idx) in messages" :key="msg.key || idx">
                                <div v-if="msg.kind === 'tool'" class="ai-msg tool">
                                    <AiToolCallCard :item="msg" :host-map="toolHostMap" compact class="ai-tool-inline" />
                                </div>
                                <div v-else class="ai-msg" :class="msg.role">
                                    <div class="ai-bubble" :class="{ streaming: msg.streaming, stopped: msg.stopped }" v-html="msg.html"></div>
                                    <span v-if="msg.stopped" class="ai-stopped-badge">已停止</span>
                                </div>
                            </template>
                            <div v-if="busyText" class="ai-busy">{{ busyText }}</div>
                            <div v-if="errorText" class="ai-error">{{ errorText }}</div>
                        </div>
                    </div>
                    <div class="ai-chat-footer">
                        <div
                            v-if="configured && tokenUsage"
                            class="ai-token-usage"
                            :class="{ 'ai-token-usage-warning': tokenUsage.context_percent >= 80 }"
                        >
                            <div class="ai-token-usage-row">
                                <span class="ai-token-section">上下文</span>
                                <span class="ai-token-metric">已用 {{ formatTokenCount(tokenUsage.context_used) }}</span>
                                <span class="ai-token-metric">上限 {{ formatTokenCount(tokenUsage.context_window) }}</span>
                                <span class="ai-token-metric ai-token-percent">占用率 {{ tokenUsage.context_percent }}%</span>
                            </div>
                            <div v-if="tokenUsage.cached_input_tokens > 0" class="ai-token-usage-row">
                                <span class="ai-token-section">本轮</span>
                                <span class="ai-token-metric ai-token-cache">缓存命中 {{ formatTokenCount(tokenUsage.cached_input_tokens) }}</span>
                                <span class="ai-token-metric ai-token-cache">缓存命中率 {{ tokenUsage.cache_hit_percent }}%</span>
                            </div>
                            <div class="ai-token-bar" title="上下文占用率"><div :style="{ width: tokenUsage.context_percent + '%' }"></div></div>
                            <div v-if="tokenUsage.session_total_tokens > 0" class="ai-token-usage-row ai-token-session">
                                <span class="ai-token-section">累计</span>
                                <span class="ai-token-metric">总消耗 {{ formatTokenCount(tokenUsage.session_total_tokens) }}</span>
                                <template v-if="tokenUsage.session_cached_input_tokens > 0">
                                    <span class="ai-token-metric ai-token-cache">入命中 {{ formatTokenCount(tokenUsage.session_cached_input_tokens) }}</span>
                                    <span class="ai-token-metric ai-token-cache">入未命中 {{ formatTokenCount(tokenUsage.session_uncached_input_tokens) }}</span>
                                    <span class="ai-token-metric ai-token-cache">输出 {{ formatTokenCount(tokenUsage.session_output_tokens) }}</span>
                                    <span class="ai-token-metric ai-token-cache">缓存命中率 {{ tokenUsage.session_cache_hit_percent }}%</span>
                                </template>
                                <template v-else>
                                    <span class="ai-token-metric ai-token-session-detail">输入 {{ formatTokenCount(tokenUsage.session_uncached_input_tokens) }}</span>
                                    <span class="ai-token-metric ai-token-session-detail">输出 {{ formatTokenCount(tokenUsage.session_output_tokens) }}</span>
                                </template>
                            </div>
                        </div>
                        <div v-if="threadReady && configured" class="ai-chat-mode-bar">
                            <div v-if="approvalModeHelpOpen" class="ai-approval-mode-popover" @click.stop>
                                <div
                                    v-for="opt in approvalModeOptions"
                                    :key="opt.value"
                                    class="ai-approval-mode-popover-item"
                                    :class="{ 'ai-approval-mode-popover-item--active': commandApprovalMode === opt.value }"
                                >
                                    <strong>{{ opt.label }}</strong>
                                    <span>{{ opt.detail }}</span>
                                </div>
                            </div>
                            <div class="ai-chat-mode-row">
                                <div class="ai-chat-mode-label-wrap">
                                    <span class="ai-approval-mode-label">命令审批</span>
                                    <button
                                        type="button"
                                        class="ai-approval-mode-help"
                                        :class="{ 'ai-approval-mode-help--active': approvalModeHelpOpen }"
                                        title="查看全部模式说明"
                                        aria-label="查看全部模式说明"
                                        @click="toggleApprovalModeHelp"
                                    >?</button>
                                </div>
                                <div class="ai-approval-mode-segments" role="radiogroup" aria-label="命令审批">
                                    <label
                                        v-for="opt in approvalModeOptions"
                                        :key="opt.value"
                                        class="ai-approval-mode-segment"
                                        :class="{ 'ai-approval-mode-segment--active': commandApprovalMode === opt.value }"
                                        :title="opt.detail"
                                    >
                                        <input
                                            type="radio"
                                            name="approval-mode"
                                            :value="opt.value"
                                            :checked="commandApprovalMode === opt.value"
                                            @change="setCommandApprovalMode(opt.value)"
                                        >
                                        <span>{{ opt.label }}</span>
                                    </label>
                                </div>
                            </div>
                            <p class="ai-approval-mode-caption" :title="activeApprovalModeOption.detail">
                                {{ activeApprovalModeOption.detail }}
                            </p>
                        </div>
                        <div v-if="approval" class="ai-approval">
                            <h4>待审核命令</h4>
                            <div class="ai-approval-list">
                                <div
                                    v-for="(action, actionIdx) in approval.actions || []"
                                    :key="action.id || actionIdx"
                                    class="ai-approval-action"
                                >
                                    <div v-if="action.host?.label" class="ai-approval-host">
                                        <span class="ai-approval-host-label">目标主机</span>
                                        <strong>{{ action.host.label }}</strong>
                                    </div>
                                    <div v-if="action.policy?.label" class="ai-approval-policy">
                                        <span class="ai-approval-policy-label">策略</span>
                                        <strong>{{ action.policy.label }}</strong>
                                        <span v-if="action.policy.summary" class="ai-approval-policy-summary">{{ action.policy.summary }}</span>
                                    </div>
                                    <pre>{{ action.detail || action.description }}</pre>
                                </div>
                            </div>
                            <div class="actions">
                                <div class="ai-panel-action-buttons">
                                    <button type="button" @click="submitApproval(false)" :disabled="busy">拒绝</button>
                                    <button class="primary" type="button" @click="submitApproval(true)" :disabled="busy">批准</button>
                                </div>
                            </div>
                        </div>
                        <div v-else-if="feedback" class="ai-feedback">
                            <h4>{{ feedback.message || '请回答' }}</h4>
                            <p class="ai-feedback-note">可按需填写后提交；不回答可点「跳过」继续对话。</p>
                            <div class="ai-feedback-form">
                            <div v-for="field in feedback.fields || []" :key="field.id" class="ai-field">
                                <label>
                                    {{ field.label }}
                                    <span v-if="field.required" class="ai-field-required">（必填）</span>
                                    <span v-else class="ai-field-optional">（选填）</span>
                                </label>
                                <p class="ai-field-hint">选项以 A、B、C… 标记；选「其他」时可输入如 AB 表示组合选项</p>
                                <div v-if="field.type === 'radio' || field.type === 'select'" class="ai-options">
                                    <label
                                        v-for="(opt, oi) in field.options || []"
                                        :key="opt.value"
                                        class="ai-option"
                                        :class="{ 'ai-option-other': isOtherOption(opt) }"
                                    >
                                        <input
                                            type="radio"
                                            :name="'fb-' + field.id"
                                            :value="opt.value"
                                            v-model="feedbackAnswers[field.id]"
                                            @change="!isOtherOption(opt) && (feedbackOtherTexts[field.id] = '')"
                                        >
                                        <span class="ai-option-index">{{ feedbackOptionLetter(oi) }}</span>
                                        <span class="ai-option-label">{{ opt.label }}</span>
                                        <input
                                            v-if="isOtherOption(opt) && feedbackAnswers[field.id] === opt.value"
                                            type="text"
                                            class="ai-feedback-other"
                                            v-model="feedbackOtherTexts[field.id]"
                                            placeholder="例如 AB，或补充说明"
                                            @click.stop
                                        >
                                    </label>
                                </div>
                                <div v-else-if="field.type === 'checkbox'" class="ai-options">
                                    <label
                                        v-for="(opt, oi) in field.options || []"
                                        :key="opt.value"
                                        class="ai-option"
                                        :class="{ 'ai-option-other': isOtherOption(opt) }"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="opt.value"
                                            :checked="(feedbackAnswers[field.id] || []).includes(opt.value)"
                                            @change="toggleFeedbackCheckbox(field, opt, $event.target.checked)"
                                        >
                                        <span class="ai-option-index">{{ feedbackOptionLetter(oi) }}</span>
                                        <span class="ai-option-label">{{ opt.label }}</span>
                                        <input
                                            v-if="isOtherOption(opt) && (feedbackAnswers[field.id] || []).includes(opt.value)"
                                            type="text"
                                            class="ai-feedback-other"
                                            v-model="feedbackOtherTexts[field.id]"
                                            placeholder="例如 AB，或补充说明"
                                            @click.stop
                                        >
                                    </label>
                                </div>
                            </div>
                            </div>
                            <div class="actions">
                                <div class="ai-panel-action-buttons">
                                    <button type="button" @click="skipFeedback" :disabled="busy">跳过</button>
                                    <button class="primary" type="button" @click="submitFeedback" :disabled="busy">提交</button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="ai-composer">
                            <textarea
                                ref="composerRef"
                                v-model="draft"
                                rows="2"
                                placeholder="描述任务，例如：查看磁盘使用情况并清理临时文件"
                                enterkeyhint="send"
                                autocapitalize="sentences"
                                autocomplete="off"
                                @input="resizeComposer"
                                @compositionstart="composing = true"
                                @compositionend="composing = false; resizeComposer()"
                                @keydown="onComposerKeydown"
                                :disabled="composerDisabled"
                            ></textarea>
                            <div class="ai-composer-actions">
                                <button class="primary ai-send-btn" type="button" @click="sendMessage" :disabled="composerDisabled || !draft.trim()">发送</button>
                            </div>
                        </div>
                    </div>
                </div>
            `,
        },
        AiSessionLivePane: {
            props: {
                aiSessionId: { type: Number, default: null },
                activeHost: { type: Object, default: null },
                visible: { type: Boolean, default: true },
                paneActive: { type: Boolean, default: true },
            },
            setup(props) {
                const termRef = ref(null);
                const live = ref(false);
                const statusText = ref('等待输出');
                const hostLabel = ref('');
                let term = null;
                let fitAddon = null;
                let source = null;
                let reconnectTimer = null;
                let resizeObserver = null;

                const fitTerm = () => {
                    nextTick(() => fitAddon?.fit());
                };

                const teardownFitObservers = () => {
                    if (resizeObserver) {
                        resizeObserver.disconnect();
                        resizeObserver = null;
                    }
                };

                const setupFitObservers = () => {
                    teardownFitObservers();
                    if (termRef.value && typeof ResizeObserver !== 'undefined') {
                        resizeObserver = new ResizeObserver(() => fitTerm());
                        resizeObserver.observe(termRef.value);
                    }
                };

                const decodeChunkBytes = (b64) => {
                    try {
                        const binary = atob(b64);
                        const bytes = new Uint8Array(binary.length);
                        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                        return new TextDecoder().decode(bytes);
                    } catch { return ''; }
                };

                const ensureTerm = () => {
                    if (term || !termRef.value) return;
                    term = new Terminal({
                        disableStdin: true,
                        cursorBlink: false,
                        fontSize: 13,
                        theme: { background: '#0f111a', foreground: '#e6e6e6' },
                        convertEol: true,
                    });
                    if (window.FitAddon?.FitAddon) {
                        fitAddon = new window.FitAddon.FitAddon();
                        term.loadAddon(fitAddon);
                    }
                    term.open(termRef.value);
                    fitAddon?.fit();
                    setupFitObservers();
                };

                const writeStatus = (text) => {
                    if (!term) return;
                    term.writeln('\r\n\x1b[90m' + text + '\x1b[0m');
                };

                const formatHostLabel = (data) => {
                    if (!data) return '';
                    const name = String(data.host_name || '').trim();
                    const addr = String(data.host_address || '').trim();
                    if (name && addr && name !== addr) {
                        return name + ' · ' + addr;
                    }
                    return name || addr || '';
                };

                const writeSegmentSeparator = (data) => {
                    const label = formatHostLabel(data) || String(data?.host_name || data?.host_address || '').trim();
                    if (!label) return;
                    writeStatus('────────── 切换主机 · ' + label + ' ──────────');
                };

                const applyHostFromData = (data) => {
                    const label = formatHostLabel(data);
                    if (label) {
                        hostLabel.value = label;
                    }
                };

                const handleEvent = (kind, data) => {
                    ensureTerm();
                    if (kind === 'replay') {
                        const bytes = decodeChunkBytes(data?.chunk || '');
                        if (bytes && term) {
                            term.write(bytes);
                            term.scrollToBottom();
                        }
                        applyHostFromData(data);
                        live.value = false;
                        statusText.value = '已恢复';
                        return;
                    }
                    if (kind === 'status') {
                        if (data?.state === 'idle') {
                            live.value = false;
                            statusText.value = '等待输出';
                            if (!hostLabel.value) {
                                applyHostFromData(data);
                            }
                            if (!term || term.buffer.active.length === 0) {
                                writeStatus(data?.message || '等待命令执行…');
                            }
                        }
                        return;
                    }
                    if (kind === 'start' || kind === 'connected' || kind === 'segment_switch') {
                        applyHostFromData(data);
                    }
                    if (kind === 'segment_switch') {
                        writeSegmentSeparator(data);
                        return;
                    }
                    if (kind === 'output') {
                        const bytes = decodeChunkBytes(data?.chunk || '');
                        if (bytes && term) {
                            term.write(bytes);
                            term.scrollToBottom();
                        }
                        return;
                    }
                    if (kind === 'connected') {
                        live.value = true;
                        statusText.value = 'live';
                    }
                    if (kind === 'error') {
                        live.value = false;
                        statusText.value = 'error';
                        writeStatus('[error] ' + (data?.message || '输出异常'));
                    }
                    if (kind === 'disconnected') {
                        live.value = false;
                        statusText.value = '等待输出';
                        writeStatus(data?.message || '命令已结束，等待下一次执行…');
                    }
                };

                let idleStream = false;

                const closeStream = () => {
                    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
                    if (source) { source.close(); source = null; }
                    idleStream = false;
                };

                const openStream = (clearTerm = false) => {
                    if (!props.aiSessionId) return;
                    closeStream();
                    if (clearTerm) {
                        term?.clear();
                        hostLabel.value = '';
                    }
                    statusText.value = '连接中';
                    source = new EventSource('/api/ai/sessions/' + props.aiSessionId + '/live/stream');
                    ['replay', 'start', 'connected', 'segment_switch', 'output', 'error', 'disconnected', 'status'].forEach((name) => {
                        source.addEventListener(name, (event) => {
                            let data = event.data;
                            try { data = JSON.parse(event.data); } catch { /* raw */ }
                            if (name === 'status' && data?.state === 'idle') {
                                idleStream = true;
                            }
                            handleEvent(name, data);
                        });
                    });
                    source.onerror = () => {
                        closeStream();
                        if (!props.aiSessionId) return;
                        statusText.value = '重连中';
                        reconnectTimer = setTimeout(() => openStream(), idleStream ? 5000 : 2000);
                    };
                };

                const resetLive = () => {
                    closeStream();
                    term?.clear();
                    hostLabel.value = '';
                    nextTick(() => { ensureTerm(); fitTerm(); openStream(false); });
                };

                watch(() => props.aiSessionId, (id, prev) => {
                    if (id !== prev && term) {
                        term.clear();
                        hostLabel.value = '';
                    }
                });

                watch(() => [props.aiSessionId, props.visible], () => {
                    if (!props.visible || !props.aiSessionId) {
                        closeStream();
                        return;
                    }
                    nextTick(() => { ensureTerm(); fitTerm(); openStream(false); });
                }, { immediate: true });

                watch(() => props.activeHost, (host) => {
                    if (host) {
                        applyHostFromData(host);
                    }
                }, { immediate: true });

                watch(() => props.paneActive, (active) => {
                    if (active) fitTerm();
                });

                onBeforeUnmount(() => {
                    teardownFitObservers();
                    closeStream();
                });

                return { termRef, live, statusText, hostLabel, resetLive };
            },
            template: `
                <div class="terminal-live-pane embedded">
                    <div class="terminal-live-header terminal-live-header-embedded">
                        <div class="terminal-live-title">
                            <strong>现场</strong>
                            <span v-if="hostLabel" class="terminal-live-host" :title="hostLabel">{{ hostLabel }}</span>
                            <span v-else class="terminal-live-host muted">等待命令执行</span>
                        </div>
                        <div class="terminal-live-actions">
                            <button type="button" class="terminal-live-reset" title="重新连接" @click="resetLive">重置</button>
                            <span class="live-lamp terminal-live-lamp" :class="{ live }"><i></i>{{ statusText }}</span>
                        </div>
                    </div>
                    <div v-if="!aiSessionId" class="ai-chat-hint">创建会话后显示命令输出</div>
                    <div v-show="aiSessionId" ref="termRef" class="terminal-live-term"></div>
                </div>
            `,
        },
        AiSessionListView: {
            emits: ['flash'],
            setup(props, { emit }) {
                const items = ref([]);
                const total = ref(0);
                const page = ref(1);
                const perPage = ref(20);
                const loading = ref(false);

                const load = async () => {
                    loading.value = true;
                    try {
                        const data = await api.get('/api/ai/sessions?page=' + page.value + '&per_page=' + perPage.value);
                        items.value = data.data?.items || [];
                        total.value = data.data?.total || 0;
                    } catch (e) {
                        emit('flash', e.message, 'err');
                    } finally {
                        loading.value = false;
                    }
                };

                const createSession = async () => {
                    try {
                        const data = await api.post('/api/ai/sessions', {});
                        openAiSession(data.data.id);
                    } catch (e) {
                        emit('flash', e.message, 'err');
                    }
                };

                const replay = createAsciinemaReplay();
                const field = createSessionFieldView();

                onMounted(load);
                onBeforeUnmount(() => {
                    replay.closeReplay();
                    field.closeField();
                });

                return {
                    items,
                    total,
                    page,
                    perPage,
                    loading,
                    load,
                    createSession,
                    openAiSession,
                    openReplay: replay.openAiSessionReplay,
                    closeReplay: replay.closeReplay,
                    replayOpen: replay.replayOpen,
                    replayTitle: replay.replayTitle,
                    replayMeta: replay.replayMeta,
                    replayHostLabel: replay.replayHostLabel,
                    replaySegmentInfo: replay.replaySegmentInfo,
                    replayLoading: replay.replayLoading,
                    replayError: replay.replayError,
                    replayHost: replay.replayHost,
                    openField: field.openAiSessionField,
                    closeField: field.closeField,
                    fieldOpen: field.fieldOpen,
                    fieldTitle: field.fieldTitle,
                    fieldHostLabel: field.fieldHostLabel,
                    fieldMeta: field.fieldMeta,
                    fieldLoading: field.fieldLoading,
                    fieldError: field.fieldError,
                    fieldHost: field.fieldHost,
                };
            },
            template: `
                <div class="panel">
                    <div class="toolbar">
                        <h2>AI 会话</h2>
                        <button class="primary" @click="createSession">新建会话</button>
                        <button @click="load" :disabled="loading">刷新</button>
                    </div>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>标题</th><th>状态</th><th>主机数</th><th>分段</th><th>开始</th><th>操作</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.id }}</td>
                                <td>{{ item.title || '（未命名）' }}</td>
                                <td>{{ item.status }}</td>
                                <td>{{ item.host_count || 0 }}</td>
                                <td>{{ item.segment_count || 0 }}</td>
                                <td>{{ item.created_at }}</td>
                                <td class="actions">
                                    <button @click="openAiSession(item.id)">继续</button>
                                    <button
                                        type="button"
                                        class="btn-link"
                                        @click="openField(item)"
                                    >现场</button>
                                    <button
                                        v-if="item.segment_count > 0"
                                        type="button"
                                        class="btn-link"
                                        @click="openReplay(item)"
                                    >回放</button>
                                </td>
                            </tr>
                            <tr v-if="!items.length"><td colspan="7">暂无 AI 会话，点击「新建会话」开始。</td></tr>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <button :disabled="page <= 1" @click="page--; load()">上一页</button>
                        <span>第 {{ page }} 页 / 共 {{ Math.ceil(total / perPage) || 1 }} 页</span>
                        <button :disabled="page * perPage >= total" @click="page++; load()">下一页</button>
                    </div>

                    <div v-if="replayOpen" class="replay-overlay" @click.self="closeReplay">
                        <div class="replay-dialog">
                            <div class="replay-head">
                                <div>
                                    <h3>AI 会话回放 · {{ replayTitle }}</h3>
                                    <div class="replay-subline">
                                        <span v-if="replayHostLabel" class="replay-host-badge" :title="replayHostLabel">{{ replayHostLabel }}</span>
                                        <span v-if="replaySegmentInfo" class="replay-segment-badge">{{ replaySegmentInfo }}</span>
                                        <span class="replay-status">{{ replayMeta }}</span>
                                    </div>
                                </div>
                                <button type="button" class="replay-close" @click="closeReplay">关闭</button>
                            </div>
                            <div class="replay-body">
                                <div v-if="replayLoading" class="live-empty">正在加载回放...</div>
                                <div v-else-if="replayError" class="message err">{{ replayError }}</div>
                                <div ref="replayHost" class="replay-host"></div>
                            </div>
                        </div>
                    </div>

                    <div v-if="fieldOpen" class="replay-overlay" @click.self="closeField">
                        <div class="replay-dialog">
                            <div class="replay-head">
                                <div>
                                    <h3>现场 · {{ fieldTitle }}</h3>
                                    <div class="replay-subline">
                                        <span v-if="fieldHostLabel" class="replay-host-badge" :title="fieldHostLabel">{{ fieldHostLabel }}</span>
                                        <span class="replay-status">{{ fieldMeta }}</span>
                                    </div>
                                </div>
                                <button type="button" class="replay-close" @click="closeField">关闭</button>
                            </div>
                            <div class="replay-body">
                                <div v-if="fieldLoading" class="live-empty">正在加载现场...</div>
                                <div v-else-if="fieldError" class="message err">{{ fieldError }}</div>
                                <div ref="fieldHost" class="field-host"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `,
        },
        AiSessionWorkspace: {
            props: {
                sessionId: { type: Number, default: null },
                visible: { type: Boolean, default: true },
            },
            emits: ['flash'],
            setup(props, { emit }) {
                const resolvedId = ref(props.sessionId);
                const sessionMeta = ref(null);
                const segments = ref([]);
                const activeHost = ref(null);
                const mobilePane = ref('ai');
                const isMobile = ref(false);
                const loading = ref(false);
                const LIVE_PANE_KEY = 'web-ssh-ai-session-live-visible';
                const livePaneVisible = ref(true);

                try {
                    const saved = localStorage.getItem(LIVE_PANE_KEY);
                    if (saved === '0') {
                        livePaneVisible.value = false;
                    }
                } catch (_) {
                    // ignore storage errors
                }

                const showLivePane = computed(() => livePaneVisible.value);

                const toggleLivePane = () => {
                    livePaneVisible.value = !livePaneVisible.value;
                    try {
                        localStorage.setItem(LIVE_PANE_KEY, livePaneVisible.value ? '1' : '0');
                    } catch (_) {
                        // ignore storage errors
                    }
                    if (!livePaneVisible.value && isMobile.value) {
                        mobilePane.value = 'ai';
                    }
                };

                const createSession = async () => {
                    if (loading.value) return null;
                    loading.value = true;
                    try {
                        const data = await api.post('/api/ai/sessions', {});
                        const id = data.data?.id;
                        if (!id) {
                            throw new Error('创建 AI 会话失败');
                        }
                        openAiSession(id);
                        return id;
                    } catch (e) {
                        emit('flash', e.message || '创建 AI 会话失败', 'err');
                        return null;
                    } finally {
                        loading.value = false;
                    }
                };

                const loadMeta = async () => {
                    if (!resolvedId.value) return;
                    try {
                        const data = await api.get('/api/ai/sessions/' + resolvedId.value);
                        sessionMeta.value = data.data?.session || null;
                        segments.value = data.data?.segments || [];
                        activeHost.value = data.data?.active_segment || null;
                    } catch (e) {
                        emit('flash', e.message, 'err');
                    }
                };

                const initSession = async () => {
                    if (props.sessionId != null && props.sessionId > 0) {
                        resolvedId.value = props.sessionId;
                        await loadMeta();
                    }
                };

                onMounted(async () => {
                    const mq = window.matchMedia('(max-width: 768px)');
                    isMobile.value = mq.matches;
                    mq.addEventListener('change', () => { isMobile.value = mq.matches; });
                    await initSession();
                });

                watch(() => props.sessionId, async (id) => {
                    if (id != null && id > 0) {
                        resolvedId.value = id;
                        await loadMeta();
                        return;
                    }
                    resolvedId.value = null;
                    sessionMeta.value = null;
                    segments.value = [];
                    activeHost.value = null;
                });

                const title = computed(() => sessionMeta.value?.title || 'AI 编排会话');
                const columnSplit = useWorkspaceColumnSplit('web-ssh-ai-session-sidebar-split');
                const bodyGridStyle = computed(() => {
                    if (isMobile.value || !showLivePane.value) {
                        return null;
                    }
                    return columnSplit.splitGridStyle.value;
                });

                return {
                    resolvedId,
                    sessionMeta,
                    segments,
                    activeHost,
                    mobilePane,
                    isMobile,
                    loading,
                    title,
                    livePaneVisible,
                    showLivePane,
                    toggleLivePane,
                    loadMeta,
                    createSession,
                    splitGridStyle: columnSplit.splitGridStyle,
                    bodyGridStyle,
                    startSplitResize: columnSplit.startSplitResize,
                };
            },
            template: `
                <div v-if="loading" class="ai-assistant-layout"><p>正在创建 AI 会话…</p></div>
                <div v-else-if="!resolvedId" class="ai-assistant-layout ai-session-layout">
                    <div class="ai-assistant-header">
                        <div class="ai-assistant-meta">
                            <h2>AI 编排</h2>
                            <p>跨主机 AI 编排 · 新建或继续历史会话</p>
                        </div>
                        <div class="actions">
                            <a href="#/ai/sessions">历史会话</a>
                        </div>
                    </div>
                    <div class="ai-session-init-hint ai-assistant-empty">
                        <p>描述任务后，AI 会自动选择主机、提议命令，你审核后执行。</p>
                        <button class="primary" type="button" @click="createSession" :disabled="loading">新建会话</button>
                        <a href="#/ai/sessions">查看历史会话</a>
                    </div>
                </div>
                <div v-else class="ai-assistant-layout ai-session-layout">
                    <div class="ai-assistant-header">
                        <div class="ai-assistant-meta">
                            <h2>{{ title }}</h2>
                            <p>跨主机 AI 编排 · 会话 #{{ resolvedId }}</p>
                        </div>
                        <div class="actions">
                            <button
                                type="button"
                                @click="toggleLivePane"
                            >{{ showLivePane ? '隐藏现场' : '显示现场' }}</button>
                            <a href="#/ai/sessions">历史会话</a>
                        </div>
                    </div>
                    <div v-if="isMobile && showLivePane" class="terminal-mobile-tabs" role="tablist">
                        <button type="button" :class="{ active: mobilePane === 'live' }" @click="mobilePane = 'live'">现场</button>
                        <button type="button" :class="{ active: mobilePane === 'ai' }" @click="mobilePane = 'ai'">AI 对话</button>
                    </div>
                    <div
                        class="terminal-body ai-session-body"
                        :class="{
                            'is-mobile': isMobile,
                            'has-column-splitter': !isMobile && showLivePane,
                            'live-pane-hidden': !showLivePane,
                        }"
                        :style="bodyGridStyle"
                    >
                        <div
                            v-if="showLivePane"
                            class="terminal-left-column"
                            :class="{ 'pane-mobile-active': !isMobile || mobilePane === 'live' }"
                        >
                            <AiSessionLivePane
                                :ai-session-id="resolvedId"
                                :active-host="activeHost"
                                :visible="!!resolvedId"
                                :pane-active="!isMobile || mobilePane === 'live'"
                            />
                            <div v-if="segments.length" class="ai-session-segments">
                                <span v-for="seg in segments" :key="seg.id" class="ai-segment-chip">
                                    {{ seg.host_name || seg.host_address }}
                                </span>
                            </div>
                        </div>
                        <div
                            v-if="!isMobile && showLivePane"
                            class="workspace-column-splitter live-row-splitter"
                            title="拖动调整左右宽度"
                            @pointerdown.prevent="startSplitResize"
                        ></div>
                        <div class="terminal-sidebar" :class="{ 'pane-mobile-active': !isMobile || mobilePane === 'ai' }">
                            <AiChatPanel
                                :ai-session-id="resolvedId"
                                :visible="true"
                                :pane-active="!isMobile || mobilePane === 'ai'"
                                :title="title"
                            />
                        </div>
                    </div>
                </div>
            `,
        },
        TerminalWorkspace: {
            props: {
                pendingHostId: { type: Number, default: null },
                openNonce: { type: String, default: null },
                openMode: { type: String, default: 'terminal' },
                visible: { type: Boolean, default: true },
            },
            emits: ['flash'],
            setup(props) {
                const tabs = ref([]);
                const activeTabId = ref(null);
                const dragFromIndex = ref(null);
                const dragOverIndex = ref(null);
                const paneRefs = ref({});
                let nextTabSerial = 1;

                const activeTab = computed(() => tabs.value.find((tab) => tab.id === activeTabId.value) || null);

                const formatElapsed = (seconds) => {
                    const m = Math.floor(seconds / 60);
                    const s = seconds % 60;
                    return m + '分' + String(s).padStart(2, '0') + '秒';
                };

                const activateTab = (tabId) => {
                    activeTabId.value = tabId;
                    if (location.hash !== '#/terminal') {
                        navigate('#/terminal');
                    }
                };

                const openTab = async (hostId, mode = 'terminal') => {
                    if (!hostId) return;

                    let title = '主机 #' + hostId;
                    try {
                        const host = await api.get('/api/hosts/' + hostId);
                        title = host.name || title;
                    } catch {
                        // keep fallback title
                    }

                    const tab = {
                        id: 'tab-' + nextTabSerial++,
                        hostId,
                        title,
                        subtitle: '',
                        statusMessage: '准备连接...',
                        connected: false,
                        connecting: true,
                        connId: '',
                        elapsed: 0,
                        leftPane: mode === 'ai' ? 'live' : 'terminal',
                    };
                    tabs.value.push(tab);
                    activateTab(tab.id);
                    if (mode === 'ai') {
                        mobilePane.value = 'ai';
                    }
                    navigate('#/terminal');
                };

                const setPaneRef = (tabId, instance) => {
                    if (instance) {
                        paneRefs.value[tabId] = instance;
                    } else {
                        delete paneRefs.value[tabId];
                    }
                };

                const activePane = computed(() => {
                    if (!activeTabId.value) return null;
                    return paneRefs.value[activeTabId.value] || null;
                });

                const activeConnId = computed(() => activeTab.value?.connId || '');
                const activeConnected = computed(() => !!activeTab.value?.connected);
                const liveOnline = ref(false);
                const mobilePane = ref('terminal');
                const isMobile = ref(false);

                const activeLeftPane = computed({
                    get: () => activeTab.value?.leftPane || 'terminal',
                    set: (pane) => {
                        if (activeTab.value) {
                            activeTab.value.leftPane = pane;
                        }
                    },
                });

                const setActiveLeftPane = (pane) => {
                    activeLeftPane.value = pane;
                    requestAnimationFrame(() => {
                        window.dispatchEvent(new Event('resize'));
                        if (pane === 'terminal') {
                            activePane.value?.focus?.();
                        }
                    });
                };

                const onLiveStatus = ({ live }) => {
                    liveOnline.value = !!live;
                };

                const setMobilePane = (pane) => {
                    mobilePane.value = pane;
                    requestAnimationFrame(() => {
                        window.dispatchEvent(new Event('resize'));
                        if (pane === 'terminal' && activeLeftPane.value === 'terminal') {
                            activePane.value?.focus?.();
                        }
                    });
                };

                const mobileLeftTabLabel = computed(() => (
                    activeLeftPane.value === 'live' ? '现场' : '终端'
                ));

                onMounted(() => {
                    const mq = window.matchMedia('(max-width: 768px)');
                    const syncMobile = () => {
                        isMobile.value = mq.matches;
                    };
                    syncMobile();
                    mq.addEventListener('change', syncMobile);
                    onBeforeUnmount(() => mq.removeEventListener('change', syncMobile));
                });

                const reconnectActive = () => activePane.value?.reconnect?.();
                const disconnectActive = () => activePane.value?.disconnect?.();

                const closeTab = (tabId) => {
                    const index = tabs.value.findIndex((tab) => tab.id === tabId);
                    if (index === -1) return;

                    tabs.value.splice(index, 1);
                    if (activeTabId.value === tabId) {
                        const next = tabs.value[index] || tabs.value[index - 1] || null;
                        activeTabId.value = next ? next.id : null;
                    }
                    if (!tabs.value.length) {
                        navigate('#/terminal');
                    }
                };

                const updateTabMeta = (tabId, meta) => {
                    const tab = tabs.value.find((item) => item.id === tabId);
                    if (!tab) return;
                    Object.assign(tab, meta);
                };

                const onDragStart = (index, event) => {
                    dragFromIndex.value = index;
                    dragOverIndex.value = index;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(index));
                };

                const onDragOver = (index, event) => {
                    event.preventDefault();
                    dragOverIndex.value = index;
                };

                const onDrop = (index) => {
                    const from = dragFromIndex.value;
                    dragFromIndex.value = null;
                    dragOverIndex.value = null;
                    if (from === null || from === index) return;

                    const [moved] = tabs.value.splice(from, 1);
                    tabs.value.splice(index, 0, moved);
                };

                const onDragEnd = () => {
                    dragFromIndex.value = null;
                    dragOverIndex.value = null;
                };

                watch(() => [props.pendingHostId, props.openNonce, props.openMode], ([hostId, , mode]) => {
                    if (hostId) {
                        openTab(hostId, mode || 'terminal');
                    }
                }, { immediate: true });

                return {
                    tabs,
                    activeTabId,
                    activeTab,
                    dragFromIndex,
                    dragOverIndex,
                    activateTab,
                    closeTab,
                    updateTabMeta,
                    setPaneRef,
                    reconnectActive,
                    disconnectActive,
                    openTabFromPicker: () => navigate('#/hosts'),
                    onDragStart,
                    onDragOver,
                    onDrop,
                    onDragEnd,
                    formatElapsed,
                    navigate,
                    visible: computed(() => props.visible),
                    activeConnId,
                    activeConnected,
                    activeLeftPane,
                    setActiveLeftPane,
                    liveOnline,
                    onLiveStatus,
                    mobilePane,
                    isMobile,
                    setMobilePane,
                    mobileLeftTabLabel,
                };
            },
            template: `
                <div class="terminal-layout panel">
                    <div class="terminal-header">
                        <div class="tab-bar" @dragend="onDragEnd">
                            <div
                                v-for="(tab, index) in tabs"
                                :key="tab.id"
                                class="tab-item"
                                :class="{
                                    active: tab.id === activeTabId,
                                    dragging: dragFromIndex === index,
                                    'drag-over': dragOverIndex === index && dragFromIndex !== null && dragFromIndex !== index,
                                }"
                                draggable="true"
                                @dragstart="onDragStart(index, $event)"
                                @dragover="onDragOver(index, $event)"
                                @drop="onDrop(index)"
                                @click="activateTab(tab.id)"
                            >
                                <span class="tab-status" :class="{ online: tab.connected, busy: tab.connecting && !tab.connected }"></span>
                                <span class="tab-title">{{ tab.title }}</span>
                                <button class="tab-close" type="button" title="关闭标签" @click.stop="closeTab(tab.id)">×</button>
                            </div>
                            <button class="tab-add" type="button" title="从主机列表打开" @click="openTabFromPicker">+</button>
                        </div>
                        <div class="actions">
                            <button @click="navigate('#/hosts')">主机列表</button>
                        </div>
                    </div>

                    <div v-if="activeTab" class="terminal-meta">
                        <div>
                            <strong>{{ activeTab.title }}</strong>
                            <span v-if="activeTab.subtitle"> · {{ activeTab.subtitle }}</span>
                            <span v-if="activeTab.connected"> · {{ formatElapsed(activeTab.elapsed) }}</span>
                            <span class="status-inline">{{ activeTab.statusMessage }}</span>
                        </div>
                        <div class="actions">
                            <button @click="reconnectActive" :disabled="activeTab.connecting">重新连接</button>
                            <button @click="disconnectActive">断开</button>
                        </div>
                    </div>

                    <div v-if="isMobile && activeTab" class="terminal-mobile-tabs" role="tablist" aria-label="终端视图切换">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="mobilePane === 'terminal'"
                            :class="{ active: mobilePane === 'terminal' }"
                            @click="setMobilePane('terminal')"
                        >{{ mobileLeftTabLabel }}</button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="mobilePane === 'ai'"
                            :class="{ active: mobilePane === 'ai' }"
                            @click="setMobilePane('ai')"
                        >AI 助手</button>
                    </div>

                    <div class="terminal-body" :class="{ 'is-mobile': isMobile, 'no-active-tab': !activeTab }">
                        <div
                            class="terminal-left-column"
                            :class="{ 'pane-mobile-active': !isMobile || mobilePane === 'terminal' }"
                        >
                            <div
                                v-if="activeTab && (!isMobile || mobilePane === 'terminal')"
                                class="left-pane-switch"
                                role="tablist"
                                aria-label="左侧视图切换"
                            >
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeLeftPane === 'live'"
                                    :class="{ active: activeLeftPane === 'live' }"
                                    @click="setActiveLeftPane('live')"
                                >
                                    <span class="live-lamp terminal-live-lamp" :class="{ live: liveOnline }"><i></i></span>
                                    现场
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeLeftPane === 'terminal'"
                                    :class="{ active: activeLeftPane === 'terminal' }"
                                    @click="setActiveLeftPane('terminal')"
                                >终端</button>
                            </div>
                            <div class="terminal-left-stack">
                                <div v-show="activeLeftPane === 'live' && activeTab" class="terminal-left-live">
                                    <LiveSessionPane
                                        :conn-id="activeConnId"
                                        :connected="activeConnected"
                                        :visible="!!activeTab"
                                        :pane-active="!!activeTab && activeLeftPane === 'live' && (!isMobile || mobilePane === 'terminal')"
                                        :title="activeTab?.title || ''"
                                        @live-status="onLiveStatus"
                                    />
                                </div>
                                <div v-show="activeLeftPane === 'terminal'" class="terminal-stack">
                                    <TerminalPane
                                        v-for="tab in tabs"
                                        :key="tab.id"
                                        :ref="(instance) => setPaneRef(tab.id, instance)"
                                        :host-id="tab.hostId"
                                        :active="tab.id === activeTabId && visible"
                                        @meta="updateTabMeta(tab.id, $event)"
                                    />
                                    <div v-if="!tabs.length" class="terminal-empty">
                                        <p>暂无打开的终端标签。</p>
                                        <p>在「主机管理」中点击「登录」或「AI 助手」，或拖动上方标签栏排序已打开的连接。</p>
                                        <button class="primary" @click="openTabFromPicker">前往主机列表</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="activeTab" class="terminal-sidebar" :class="{ 'is-mobile': isMobile }">
                            <AiChatPanel
                                :conn-id="activeConnId"
                                :connected="activeConnected"
                                :visible="true"
                                :pane-active="!isMobile || mobilePane === 'ai'"
                                :title="activeTab?.title || ''"
                            />
                        </div>
                    </div>
                </div>
            `,
        },
        LiveMonitorView: {
            setup() {
                const DISMISSED_KEY = 'web-ssh-live-dismissed';
                const PINNED_KEY = 'web-ssh-live-pinned';
                const SIZES_KEY = 'web-ssh-live-panel-sizes';
                const SPLITS_KEY = 'web-ssh-live-row-splits';
                const sessions = ref([]);
                const lampText = ref('未连接');
                const lampLive = ref(false);
                const panelItems = ref([]);
                let refreshTimer = null;
                let ageTimer = null;

                const loadDismissedIds = () => {
                    try {
                        const raw = localStorage.getItem(DISMISSED_KEY);
                        if (!raw) return new Set();
                        const ids = JSON.parse(raw);
                        return new Set(Array.isArray(ids) ? ids.filter(Boolean) : []);
                    } catch {
                        return new Set();
                    }
                };

                const dismissedIds = loadDismissedIds();

                const loadPinnedIds = () => {
                    try {
                        const raw = localStorage.getItem(PINNED_KEY);
                        if (!raw) return [];
                        const ids = JSON.parse(raw);
                        return Array.isArray(ids) ? ids.filter(Boolean) : [];
                    } catch {
                        return [];
                    }
                };

                const pinnedIds = ref(loadPinnedIds());

                const persistPinnedIds = () => {
                    localStorage.setItem(PINNED_KEY, JSON.stringify(pinnedIds.value.slice(0, 50)));
                };

                const isPinned = (id) => pinnedIds.value.includes(id);

                const syncPanelPinState = (panel) => {
                    panel.pinned = isPinned(panel.id);
                };

                const unpinnedSortKey = (panel) => -(panel.startedAt || 0);

                const loadHostSizes = () => {
                    try {
                        const raw = localStorage.getItem(SIZES_KEY);
                        const data = raw ? JSON.parse(raw) : {};
                        return data && typeof data === 'object' ? data : {};
                    } catch {
                        return {};
                    }
                };

                const hostSizes = loadHostSizes();

                const loadRowSplits = () => {
                    try {
                        const raw = localStorage.getItem(SPLITS_KEY);
                        const data = raw ? JSON.parse(raw) : {};
                        return data && typeof data === 'object' ? data : {};
                    } catch {
                        return {};
                    }
                };

                const rowSplits = ref(loadRowSplits());

                const persistRowSplits = () => {
                    localStorage.setItem(SPLITS_KEY, JSON.stringify(rowSplits.value));
                };

                const rowSplitKey = (row) => (
                    row.panels.length === 2 ? `${row.panels[0].id}:${row.panels[1].id}` : ''
                );

                const getRowSplit = (row) => {
                    const key = rowSplitKey(row);
                    if (!key) return 0.5;
                    const saved = rowSplits.value[key];
                    return typeof saved === 'number' && saved > 0 && saved < 1 ? saved : 0.5;
                };

                const persistHostSize = (panel) => {
                    if (!panel.hostKey) return;
                    hostSizes[panel.hostKey] = {
                        w: panel.customWidth || null,
                        h: panel.customHeight || null,
                    };
                    localStorage.setItem(SIZES_KEY, JSON.stringify(hostSizes));
                };

                const applySavedSize = (panel) => {
                    const saved = hostSizes[panel.hostKey];
                    if (!saved) return;
                    if (saved.w) panel.customWidth = saved.w;
                    if (saved.h) panel.customHeight = saved.h;
                };

                const applyPanelWidth = (panel, row, positionInRow, rowEl, newW) => {
                    const rowRect = rowEl.getBoundingClientRect();
                    const splitter = rowEl.querySelector('.live-row-splitter');
                    const splitterW = splitter ? splitter.offsetWidth : 0;
                    const minPanel = 320;

                    if (row.panels.length === 1) {
                        panel.customWidth = Math.round(Math.max(minPanel, Math.min(rowRect.width, newW)));
                        return;
                    }

                    const key = rowSplitKey(row);
                    if (!key) return;
                    const usable = rowRect.width - splitterW;
                    if (usable <= minPanel * 2) return;

                    const minSplit = minPanel / usable;
                    const maxSplit = 1 - minSplit;
                    const split = positionInRow === 0
                        ? newW / usable
                        : 1 - newW / usable;
                    rowSplits.value = {
                        ...rowSplits.value,
                        [key]: Math.max(minSplit, Math.min(maxSplit, split)),
                    };
                };

                const getPanelStyle = (panel, row, positionInRow) => {
                    const style = {};
                    if (panel.customHeight) style.height = panel.customHeight + 'px';
                    if (row.panels.length === 1 && panel.customWidth) {
                        style.flex = '0 0 auto';
                        style.width = panel.customWidth + 'px';
                        style.minWidth = '320px';
                    } else if (row.panels.length === 2) {
                        const split = getRowSplit(row);
                        const weight = positionInRow === 0 ? split : (1 - split);
                        style.flex = `${weight} 1 0`;
                        style.minWidth = '320px';
                    }
                    return style;
                };

                const startPanelResize = (panel, event) => {
                    const handle = event.currentTarget;
                    const root = handle.closest('.live-panel');
                    if (!root) return;

                    event.preventDefault();
                    event.stopPropagation();
                    if (handle.setPointerCapture) {
                        handle.setPointerCapture(event.pointerId);
                    }

                    const rect = root.getBoundingClientRect();
                    const startY = event.clientY;
                    const startH = rect.height;

                    panel.customHeight = Math.round(startH);
                    document.body.classList.add('live-resizing', 'live-resizing-ns');

                    const onMove = (e) => {
                        panel.customHeight = Math.round(Math.max(280, startH + (e.clientY - startY)));
                    };

                    const onUp = (e) => {
                        if (handle.releasePointerCapture) {
                            try {
                                handle.releasePointerCapture(e.pointerId);
                            } catch (_) {}
                        }
                        document.body.classList.remove('live-resizing', 'live-resizing-ns');
                        handle.removeEventListener('pointermove', onMove);
                        handle.removeEventListener('pointerup', onUp);
                        handle.removeEventListener('pointercancel', onUp);
                        persistHostSize(panel);
                        fitLivePanel(panel);
                    };

                    handle.addEventListener('pointermove', onMove);
                    handle.addEventListener('pointerup', onUp);
                    handle.addEventListener('pointercancel', onUp);
                };

                const startPanelCornerResize = (panel, row, positionInRow, event) => {
                    const handle = event.currentTarget;
                    const root = handle.closest('.live-panel');
                    const rowEl = handle.closest('.live-row');
                    if (!root || !rowEl) return;

                    event.preventDefault();
                    event.stopPropagation();
                    if (handle.setPointerCapture) {
                        handle.setPointerCapture(event.pointerId);
                    }

                    const rect = root.getBoundingClientRect();
                    const startX = event.clientX;
                    const startY = event.clientY;
                    const startW = rect.width;
                    const startH = rect.height;

                    panel.customHeight = Math.round(startH);
                    if (row.panels.length === 1) {
                        panel.customWidth = Math.round(startW);
                    }

                    document.body.classList.add('live-resizing', 'live-resizing-nwse');

                    const onMove = (e) => {
                        panel.customHeight = Math.round(Math.max(280, startH + (e.clientY - startY)));
                        applyPanelWidth(panel, row, positionInRow, rowEl, startW + (e.clientX - startX));
                    };

                    const onUp = (e) => {
                        if (handle.releasePointerCapture) {
                            try {
                                handle.releasePointerCapture(e.pointerId);
                            } catch (_) {}
                        }
                        document.body.classList.remove('live-resizing', 'live-resizing-ns', 'live-resizing-nwse');
                        handle.removeEventListener('pointermove', onMove);
                        handle.removeEventListener('pointerup', onUp);
                        handle.removeEventListener('pointercancel', onUp);
                        persistHostSize(panel);
                        if (row.panels.length === 2) {
                            persistRowSplits();
                        }
                        fitLivePanel(panel);
                    };

                    handle.addEventListener('pointermove', onMove);
                    handle.addEventListener('pointerup', onUp);
                    handle.addEventListener('pointercancel', onUp);
                };

                const startRowSplitResize = (row, event) => {
                    const handle = event.currentTarget;
                    const rowEl = handle.closest('.live-row');
                    if (!rowEl || row.panels.length !== 2) return;

                    const key = rowSplitKey(row);
                    if (!key) return;

                    event.preventDefault();
                    event.stopPropagation();
                    if (handle.setPointerCapture) {
                        handle.setPointerCapture(event.pointerId);
                    }

                    const splitterW = handle.offsetWidth || 10;
                    const minPanel = 320;

                    document.body.classList.add('live-resizing');

                    const onMove = (e) => {
                        const rect = rowEl.getBoundingClientRect();
                        const usable = rect.width - splitterW;
                        if (usable <= minPanel * 2) return;
                        const minSplit = minPanel / usable;
                        const maxSplit = 1 - minSplit;
                        const leftW = e.clientX - rect.left - splitterW / 2;
                        const split = Math.max(minSplit, Math.min(maxSplit, leftW / usable));
                        rowSplits.value = { ...rowSplits.value, [key]: split };
                    };

                    const onUp = (e) => {
                        if (handle.releasePointerCapture) {
                            try {
                                handle.releasePointerCapture(e.pointerId);
                            } catch (_) {}
                        }
                        document.body.classList.remove('live-resizing');
                        handle.removeEventListener('pointermove', onMove);
                        handle.removeEventListener('pointerup', onUp);
                        handle.removeEventListener('pointercancel', onUp);
                        persistRowSplits();
                        fitRowPanels(row);
                    };

                    handle.addEventListener('pointermove', onMove);
                    handle.addEventListener('pointerup', onUp);
                    handle.addEventListener('pointercancel', onUp);
                };

                const persistDismissedIds = () => {
                    const ids = [...dismissedIds].slice(-200);
                    localStorage.setItem(DISMISSED_KEY, JSON.stringify(ids));
                };

                const dismissSession = (id) => {
                    if (!id) return;
                    dismissedIds.add(id);
                    pinnedIds.value = pinnedIds.value.filter((item) => item !== id);
                    persistPinnedIds();
                    persistDismissedIds();
                };

                const togglePin = (id) => {
                    if (!id) return;
                    const list = [...pinnedIds.value];
                    const index = list.indexOf(id);
                    if (index >= 0) {
                        list.splice(index, 1);
                    } else {
                        list.unshift(id);
                    }
                    pinnedIds.value = list.slice(0, 50);
                    persistPinnedIds();
                    const panel = findPanel(id);
                    if (panel) {
                        panel.pinned = isPinned(id);
                    }
                };

                const findPanel = (id) => panelItems.value.find((p) => p.id === id);

                const isSessionFinished = (status) => ['finished', 'failed'].includes(status);

                const finishedCount = computed(() => {
                    return panelItems.value.filter((panel) => panel.ended).length;
                });

                const placeholderText = computed(() => {
                    if (panelItems.value.length === 0) {
                        return '还没有进行中的 SSH 连接。在「终端」或「主机管理」中登录后会自动弹出窗口。';
                    }
                    return '';
                });

                const elapsed = (unix) => {
                    if (!unix) return '';
                    const seconds = Math.max(0, Math.floor(Date.now() / 1000 - unix));
                    const m = Math.floor(seconds / 60);
                    const s = seconds % 60;
                    if (m >= 60) {
                        return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
                    }
                    return m ? m + 'm ' + s + 's' : s + 's';
                };

                const statusLabel = (status) => {
                    if (status === 'connected') return 'connected';
                    if (status === 'connecting') return 'connecting';
                    if (status === 'failed') return 'failed';
                    return status || 'ssh';
                };

                const decodeChunkBytes = (encoded) => {
                    if (!encoded) return null;
                    try {
                        const raw = atob(encoded);
                        const bytes = new Uint8Array(raw.length);
                        for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
                        return bytes;
                    } catch {
                        return null;
                    }
                };

                const applyTerminalSize = (panel, cols, rows) => {
                    if (panel.fitAddon) {
                        fitLivePanel(panel);
                        return;
                    }
                    panel.cols = Math.max(1, cols || panel.cols || 80);
                    panel.rows = Math.max(1, rows || panel.rows || 24);
                    if (panel.term) {
                        panel.term.resize(panel.cols, panel.rows);
                    }
                };

                const fitLivePanel = (panel) => {
                    if (!panel.term || !panel.fitAddon || !panel.termEl) return;
                    requestAnimationFrame(() => {
                        try {
                            panel.fitAddon.fit();
                        } catch (_) {}
                    });
                };

                const teardownPanelFitObservers = (panel) => {
                    if (panel._windowResizeHandler) {
                        window.removeEventListener('resize', panel._windowResizeHandler);
                        panel._windowResizeHandler = null;
                    }
                    if (panel._resizeObserver) {
                        panel._resizeObserver.disconnect();
                        panel._resizeObserver = null;
                    }
                };

                const setupPanelFitObservers = (panel) => {
                    teardownPanelFitObservers(panel);
                    panel._windowResizeHandler = () => fitLivePanel(panel);
                    window.addEventListener('resize', panel._windowResizeHandler);
                    if (panel.termEl && typeof ResizeObserver !== 'undefined') {
                        panel._resizeObserver = new ResizeObserver(() => fitLivePanel(panel));
                        panel._resizeObserver.observe(panel.termEl);
                    }
                };

                const fitRowPanels = (row) => {
                    for (const p of row.panels) {
                        fitLivePanel(p);
                    }
                };

                const attachLiveWheelScroll = (panel, wrapEl) => {
                    detachLiveWheelScroll(panel);
                    const onWheel = (e) => {
                        const term = panel.term;
                        if (!term?.element) return;
                        if (!term.element.classList.contains('enable-mouse-events')) return;

                        e.preventDefault();
                        e.stopImmediatePropagation();

                        const viewport = term.element.querySelector('.xterm-viewport');
                        let scrolled = false;
                        if (viewport && viewport.scrollHeight > viewport.clientHeight + 1) {
                            const prev = viewport.scrollTop;
                            viewport.scrollTop += e.deltaY;
                            scrolled = viewport.scrollTop !== prev;
                        }
                        if (!scrolled) {
                            wrapEl.scrollTop += e.deltaY;
                        }
                        if (e.deltaX) {
                            wrapEl.scrollLeft += e.deltaX;
                        }
                    };
                    panel._wheelEl = wrapEl;
                    panel._wheelHandler = onWheel;
                    wrapEl.addEventListener('wheel', onWheel, { passive: false, capture: true });
                };

                const detachLiveWheelScroll = (panel) => {
                    if (panel._wheelEl && panel._wheelHandler) {
                        panel._wheelEl.removeEventListener('wheel', panel._wheelHandler, { capture: true });
                    }
                    panel._wheelEl = null;
                    panel._wheelHandler = null;
                };

                const createTerminal = (panel, el) => {
                    if (!el || panel.term) return;
                    const cols = panel.cols || 80;
                    const rows = panel.rows || 24;
                    const term = new Terminal({
                        cols,
                        rows,
                        disableStdin: true,
                        cursorBlink: false,
                        cursorInactiveStyle: 'none',
                        fontSize: 14,
                        scrollback: 5000,
                        theme: {
                            background: '#0f111a',
                            foreground: '#e6e6e6',
                            cursor: '#f8f8f2',
                            brightBlack: '#6b7280',
                        },
                    });
                    panel.fitAddon = null;
                    if (typeof FitAddon !== 'undefined' && FitAddon.FitAddon) {
                        panel.fitAddon = new FitAddon.FitAddon();
                        term.loadAddon(panel.fitAddon);
                    }
                    term.open(el);
                    panel.term = term;
                    panel.termEl = el;
                    attachLiveWheelScroll(panel, el);
                    fitLivePanel(panel);
                    setupPanelFitObservers(panel);
                    flushBufferedEvents(panel);
                };

                const destroyTerminal = (panel) => {
                    detachLiveWheelScroll(panel);
                    teardownPanelFitObservers(panel);
                    if (panel.term) {
                        panel.term.dispose();
                        panel.term = null;
                        panel.termEl = null;
                        panel.fitAddon = null;
                    }
                };

                const resetLivePanel = (panel) => {
                    if (panel.ended) return;
                    closeStream(panel);
                    panel.eventBuffer = [];
                    if (panel.term) {
                        panel.term.clear();
                    }
                    openStream(panel);
                    fitLivePanel(panel);
                };

                const writeStatus = (panel, text) => {
                    if (!panel.term || !text) return;
                    panel.term.writeln('\r\n\x1b[90m' + text + '\x1b[0m');
                };

                const handleEvent = (panel, kind, data) => {
                    if (kind === 'status' && data && data.replay) return;

                    if (!panel.term) {
                        panel.eventBuffer.push([kind, data]);
                        return;
                    }

                    if (kind === 'output') {
                        const bytes = decodeChunkBytes(data?.chunk || '');
                        if (bytes) {
                            panel.term.write(bytes);
                            panel.term.scrollToBottom();
                        }
                        return;
                    }

                    if (kind === 'resize') {
                        applyTerminalSize(panel, data?.cols, data?.rows);
                        return;
                    }

                    if (kind === 'start' || kind === 'connected') {
                        return;
                    }

                    if (kind === 'error') {
                        const message = data?.message || '连接失败';
                        writeStatus(panel, '[error] ' + message);
                        if (data?.detail) {
                            String(data.detail).split('\n').forEach((line) => writeStatus(panel, line));
                        }
                        return;
                    }

                    if (kind === 'disconnected') {
                        writeStatus(panel, '[disconnected] ' + (data?.message || 'SSH 会话已结束'));
                    }
                };

                const flushBufferedEvents = (panel) => {
                    if (!panel.term || !panel.eventBuffer.length) return;
                    for (const [kind, data] of panel.eventBuffer) {
                        handleEvent(panel, kind, data);
                    }
                    panel.eventBuffer = [];
                    panel.term.scrollToBottom();
                };

                const closeStream = (panel, clearTimer = true) => {
                    if (clearTimer && panel.reconnectTimer) {
                        clearTimeout(panel.reconnectTimer);
                        panel.reconnectTimer = null;
                    }
                    if (panel.source) {
                        panel.source.close();
                        panel.source = null;
                    }
                };

                const markFinished = (panel, kind, data) => {
                    if (panel.ended) return;
                    panel.ended = true;
                    closeStream(panel);
                    if (kind === 'disconnected' || kind === 'done') {
                        panel.badgeText = 'finished';
                        panel.badgeBad = false;
                    } else if (kind === 'removed') {
                        panel.badgeText = 'finished';
                        panel.badgeBad = false;
                    } else {
                        panel.badgeText = (data && data.message) ? String(data.message).slice(0, 80) : 'failed';
                        panel.badgeBad = true;
                    }
                    panel.showDismiss = true;
                    panel.age = elapsed(panel.startedAt);
                };

                const openStream = (panel) => {
                    closeStream(panel, false);
                    if (panel.term) {
                        panel.term.clear();
                    }
                    panel.eventBuffer = [];
                    const source = new EventSource('/api/live/sessions/' + encodeURIComponent(panel.id) + '/stream');
                    panel.source = source;

                    const events = ['start', 'connected', 'resize', 'output', 'error', 'disconnected', 'status'];
                    for (const name of events) {
                        source.addEventListener(name, (event) => {
                            let data = event.data;
                            try { data = JSON.parse(event.data); } catch { /* keep raw */ }
                            handleEvent(panel, name, data);
                            if (name === 'disconnected' || name === 'error') {
                                markFinished(panel, name, data);
                            }
                        });
                    }

                    source.onerror = () => {
                        closeStream(panel, false);
                        if (panel.ended) return;
                        const stillRunning = sessions.value.some((item) => item.id === panel.id && !item._finished);
                        if (!stillRunning) {
                            markFinished(panel, 'disconnected', { message: '连接已结束或流不可用。' });
                            return;
                        }
                        if (panel.reconnectTimer) return;
                        panel.reconnectTimer = setTimeout(() => {
                            panel.reconnectTimer = null;
                            if (!panel.ended && sessions.value.some((item) => item.id === panel.id && !item._finished)) {
                                openStream(panel);
                            }
                        }, 1200);
                    };
                };

                const ensureStream = (panel) => {
                    if (panel.ended) return;
                    if (panel.source) {
                        const state = panel.source.readyState;
                        if (state === EventSource.OPEN || state === EventSource.CONNECTING) return;
                    }
                    openStream(panel);
                };

                const createPanel = (session) => {
                    const hostKey = session.host_address || session.title || session.id;
                    const panel = reactive({
                        id: session.id,
                        hostKey,
                        title: session.title || session.host_name || session.host_address,
                        hint: [
                            session.ssh_user ? session.ssh_user + '@' + session.host_address : session.host_address,
                            session.platform_user ? 'by ' + session.platform_user : '',
                            session.status || '',
                        ].filter(Boolean).join(' · '),
                        typeLabel: statusLabel(session.status),
                        age: elapsed(session.started_at_unix),
                        startedAt: session.started_at_unix || 0,
                        cols: session.cols || 80,
                        rows: session.rows || 24,
                        ended: false,
                        badgeText: '',
                        badgeBad: false,
                        showDismiss: false,
                        pinned: isPinned(session.id),
                        customWidth: null,
                        customHeight: null,
                        term: null,
                        termEl: null,
                        eventBuffer: [],
                        source: null,
                        reconnectTimer: null,
                    });
                    applySavedSize(panel);
                    panelItems.value = [...panelItems.value, panel];
                    return panel;
                };

                const updatePanelMeta = (panel, session) => {
                    panel.title = session.title || session.host_name || session.host_address;
                    panel.hint = [
                        session.ssh_user ? session.ssh_user + '@' + session.host_address : session.host_address,
                        session.platform_user ? 'by ' + session.platform_user : '',
                        session.status || '',
                    ].filter(Boolean).join(' · ');
                    panel.typeLabel = statusLabel(session.status);
                    if (!panel.ended) {
                        panel.age = elapsed(session.started_at_unix);
                    }
                    panel.startedAt = session.started_at_unix || panel.startedAt || 0;
                    syncPanelPinState(panel);
                };

                const syncPanels = () => {
                    const activeIds = new Set();
                    for (const session of sessions.value) {
                        if (dismissedIds.has(session.id)) {
                            continue;
                        }
                        activeIds.add(session.id);
                        session._finished = isSessionFinished(session.status);
                        let panel = findPanel(session.id);
                        if (!panel) {
                            panel = createPanel(session);
                        } else {
                            updatePanelMeta(panel, session);
                        }
                        if (!panel.ended && !session._finished) {
                            ensureStream(panel);
                        } else if (session._finished && !panel.ended) {
                            markFinished(panel, session.status === 'failed' ? 'error' : 'disconnected', {
                                message: session.status === 'failed' ? '连接失败' : 'SSH 会话已结束',
                            });
                        }
                    }

                    for (const panel of panelItems.value) {
                        if (!activeIds.has(panel.id) && !panel.ended) {
                            markFinished(panel, 'removed', { message: '连接已结束' });
                        }
                    }

                    panelItems.value = panelItems.value.filter((panel) => !dismissedIds.has(panel.id));
                };

                const removePanel = (id) => {
                    dismissSession(id);
                    const panel = findPanel(id);
                    if (panel) {
                        closeStream(panel);
                        destroyTerminal(panel);
                    }
                    panelItems.value = panelItems.value.filter((p) => p.id !== id);
                };

                const clearFinishedPanels = () => {
                    const removeIds = new Set();
                    for (const session of sessions.value) {
                        if (isSessionFinished(session.status)) {
                            removeIds.add(session.id);
                        }
                    }
                    for (const panel of panelItems.value) {
                        if (panel.ended) {
                            removeIds.add(panel.id);
                        }
                    }
                    for (const id of removeIds) {
                        dismissSession(id);
                        const panel = findPanel(id);
                        if (panel) {
                            closeStream(panel);
                            destroyTerminal(panel);
                        }
                    }
                    panelItems.value = panelItems.value.filter((p) => !removeIds.has(p.id));
                };

                const setTermRef = (panel, el) => {
                    if (!el) {
                        panel.termEl = null;
                        return;
                    }
                    if (panel.termEl === el) return;

                    const needsReplay = !!panel.source;
                    if (panel.term) {
                        destroyTerminal(panel);
                    }
                    createTerminal(panel, el);
                    if (panel.ended) return;
                    if (needsReplay) {
                        openStream(panel);
                    } else if (!panel.source) {
                        ensureStream(panel);
                    }
                };

                const refresh = async () => {
                    try {
                        const data = await api.get('/api/live/sessions?include_finished=1');
                        sessions.value = data.sessions || [];
                        const running = sessions.value.filter((s) => !isSessionFinished(s.status)).length;
                        lampLive.value = true;
                        lampText.value = running ? running + ' 进行中' : '现场空闲';
                        syncPanels();
                    } catch {
                        lampLive.value = false;
                        lampText.value = '无法连接';
                        sessions.value = [];
                    }
                };

                onMounted(() => {
                    refresh();
                    refreshTimer = setInterval(refresh, 2000);
                    ageTimer = setInterval(() => {
                        for (const panel of panelItems.value) {
                            if (!panel.ended) {
                                panel.age = elapsed(panel.startedAt);
                            }
                        }
                    }, 1000);
                });

                onBeforeUnmount(() => {
                    if (refreshTimer) clearInterval(refreshTimer);
                    if (ageTimer) clearInterval(ageTimer);
                    for (const panel of panelItems.value) {
                        closeStream(panel);
                        destroyTerminal(panel);
                    }
                    panelItems.value = [];
                });

                const orderedPanels = computed(() => {
                    const pinOrder = new Map(pinnedIds.value.map((id, index) => [id, index]));
                    return [...panelItems.value].sort((a, b) => {
                        const aPin = pinOrder.has(a.id) ? pinOrder.get(a.id) : Number.MAX_SAFE_INTEGER;
                        const bPin = pinOrder.has(b.id) ? pinOrder.get(b.id) : Number.MAX_SAFE_INTEGER;
                        if (aPin !== bPin) return aPin - bPin;
                        return unpinnedSortKey(a) - unpinnedSortKey(b);
                    });
                });

                const panelRows = computed(() => {
                    const rows = [];
                    const panels = orderedPanels.value;
                    for (let i = 0; i < panels.length; i += 2) {
                        const slice = panels.slice(i, i + 2);
                        rows.push({
                            index: rows.length,
                            key: 'row-' + rows.length,
                            panels: slice,
                        });
                    }
                    return rows;
                });

                return {
                    lampText,
                    lampLive,
                    panelRows,
                    orderedPanels,
                    placeholderText,
                    finishedCount,
                    clearFinishedPanels,
                    removePanel,
                    togglePin,
                    getPanelStyle,
                    startPanelResize,
                    startPanelCornerResize,
                    startRowSplitResize,
                    setTermRef,
                    resetLivePanel,
                };
            },
            template: `
                <div class="panel live-layout">
                    <div class="live-head">
                        <div>
                            <h2>实时现场</h2>
                            <span class="live-lamp" :class="{ live: lampLive }"><i></i>{{ lampText }}</span>
                        </div>
                        <div class="live-head-actions">
                            <button type="button" :disabled="finishedCount === 0" @click="clearFinishedPanels">
                                清除历史<span v-if="finishedCount > 0"> ({{ finishedCount }})</span>
                            </button>
                        </div>
                    </div>
                    <div v-if="placeholderText && !orderedPanels.length" class="live-empty">{{ placeholderText }}</div>
                    <div v-else class="live-streams">
                        <div
                            v-for="row in panelRows"
                            :key="row.key"
                            class="live-row"
                        >
                            <template v-for="(panel, pi) in row.panels" :key="panel.id">
                                <article
                                    class="live-panel"
                                    :class="{
                                        finished: panel.ended,
                                        pinned: panel.pinned,
                                        'sized-w': !!panel.customWidth,
                                        'sized-h': !!panel.customHeight,
                                    }"
                                    :style="getPanelStyle(panel, row, pi)"
                                >
                                    <div class="live-panel-head">
                                        <div class="row">
                                            <span class="type">{{ panel.typeLabel }}</span>
                                            <span class="row-actions">
                                                <span class="age">{{ panel.age }}</span>
                                                <button
                                                    v-if="!panel.ended"
                                                    type="button"
                                                    class="live-reset"
                                                    title="清空并重新连接"
                                                    @click="resetLivePanel(panel)"
                                                >↻</button>
                                                <button
                                                    type="button"
                                                    class="live-pin"
                                                    :class="{ active: panel.pinned }"
                                                    :title="panel.pinned ? '取消置顶' : '置顶'"
                                                    @click="togglePin(panel.id)"
                                                >📌</button>
                                                <button
                                                    type="button"
                                                    class="live-dismiss"
                                                    title="关闭"
                                                    @click="removePanel(panel.id)"
                                                >&times;</button>
                                            </span>
                                        </div>
                                        <h3>{{ panel.title }}</h3>
                                        <p>{{ panel.hint }}</p>
                                        <span v-if="panel.badgeText" class="live-badge" :class="{ bad: panel.badgeBad }">{{ panel.badgeText }}</span>
                                    </div>
                                    <div class="live-clipboard">
                                        <div class="live-term-wrap" :ref="(el) => setTermRef(panel, el)"></div>
                                    </div>
                                    <div
                                        class="live-resize-handle live-resize-s"
                                        title="拖动加高"
                                        @pointerdown.prevent="startPanelResize(panel, $event)"
                                    ></div>
                                    <div
                                        class="live-resize-handle live-resize-se"
                                        title="拖动调整宽高"
                                        @pointerdown.prevent="startPanelCornerResize(panel, row, pi, $event)"
                                    ></div>
                                </article>
                                <div
                                    v-if="pi === 0 && row.panels.length === 2"
                                    class="live-row-splitter"
                                    title="拖动分配宽度"
                                    @pointerdown.prevent="startRowSplitResize(row, $event)"
                                ></div>
                            </template>
                        </div>
                    </div>
                </div>
            `,
        },
        SessionListView: {
            setup() {
                const items = ref([]);
                const total = ref(0);
                const page = ref(1);
                const perPage = 10;

                const load = async () => {
                    const data = await api.get('/api/sessions?page=' + page.value + '&per_page=' + perPage);
                    items.value = data.items;
                    total.value = data.total;
                };

                const replay = createAsciinemaReplay();
                const field = createSessionFieldView();

                onMounted(load);
                onBeforeUnmount(() => {
                    replay.closeReplay();
                    field.closeField();
                });

                return {
                    items,
                    total,
                    page,
                    perPage,
                    load,
                    replayOpen: replay.replayOpen,
                    replayTitle: replay.replayTitle,
                    replayMeta: replay.replayMeta,
                    replayHostLabel: replay.replayHostLabel,
                    replaySegmentInfo: replay.replaySegmentInfo,
                    replayLoading: replay.replayLoading,
                    replayError: replay.replayError,
                    replayHost: replay.replayHost,
                    openReplay: replay.openSessionReplay,
                    closeReplay: replay.closeReplay,
                    openField: field.openSessionField,
                    closeField: field.closeField,
                    fieldOpen: field.fieldOpen,
                    fieldTitle: field.fieldTitle,
                    fieldHostLabel: field.fieldHostLabel,
                    fieldMeta: field.fieldMeta,
                    fieldLoading: field.fieldLoading,
                    fieldError: field.fieldError,
                    fieldHost: field.fieldHost,
                };
            },
            template: `
                <div class="panel">
                    <h2>会话记录</h2>
                    <table>
                        <thead><tr><th>用户</th><th>主机</th><th>状态</th><th>开始</th><th>结束</th><th>时长</th><th>现场</th><th>回放</th><th>错误</th></tr></thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.username }}</td>
                                <td>{{ item.host_name }}<br><small>{{ item.host_address }}</small></td>
                                <td><span class="badge">{{ item.status }}</span></td>
                                <td>{{ item.start_time }}</td>
                                <td>{{ item.end_time || '-' }}</td>
                                <td>{{ item.duration ?? '-' }}</td>
                                <td>
                                    <button
                                        v-if="item.recording_url"
                                        type="button"
                                        class="btn-link"
                                        @click="openField(item)"
                                    >查看</button>
                                    <span v-else>-</span>
                                </td>
                                <td>
                                    <button
                                        v-if="item.recording_url"
                                        type="button"
                                        class="btn-link"
                                        @click="openReplay(item)"
                                    >回放</button>
                                    <span v-else>-</span>
                                </td>
                                <td>{{ item.error_message || '-' }}</td>
                            </tr>
                            <tr v-if="!items.length"><td colspan="9">暂无会话记录</td></tr>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <button :disabled="page <= 1" @click="page--; load()">上一页</button>
                        <span>第 {{ page }} 页 / 共 {{ Math.ceil(total / perPage) || 1 }} 页</span>
                        <button :disabled="page * perPage >= total" @click="page++; load()">下一页</button>
                    </div>

                    <div v-if="replayOpen" class="replay-overlay" @click.self="closeReplay">
                        <div class="replay-dialog">
                            <div class="replay-head">
                                <div>
                                    <h3>会话回放 · {{ replayTitle }}</h3>
                                    <div class="replay-subline">
                                        <span v-if="replayHostLabel" class="replay-host-badge" :title="replayHostLabel">{{ replayHostLabel }}</span>
                                        <span class="replay-status">{{ replayMeta }}</span>
                                    </div>
                                </div>
                                <button type="button" class="replay-close" @click="closeReplay">关闭</button>
                            </div>
                            <div class="replay-body">
                                <div v-if="replayLoading" class="live-empty">正在加载回放...</div>
                                <div v-else-if="replayError" class="message err">{{ replayError }}</div>
                                <div ref="replayHost" class="replay-host"></div>
                            </div>
                        </div>
                    </div>

                    <div v-if="fieldOpen" class="replay-overlay" @click.self="closeField">
                        <div class="replay-dialog">
                            <div class="replay-head">
                                <div>
                                    <h3>现场 · {{ fieldTitle }}</h3>
                                    <div class="replay-subline">
                                        <span v-if="fieldHostLabel" class="replay-host-badge" :title="fieldHostLabel">{{ fieldHostLabel }}</span>
                                        <span class="replay-status">{{ fieldMeta }}</span>
                                    </div>
                                </div>
                                <button type="button" class="replay-close" @click="closeField">关闭</button>
                            </div>
                            <div class="replay-body">
                                <div v-if="fieldLoading" class="live-empty">正在加载现场...</div>
                                <div v-else-if="fieldError" class="message err">{{ fieldError }}</div>
                                <div ref="fieldHost" class="field-host"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `,
        },
        AuditLogView: {
            setup() {
                const items = ref([]);
                const total = ref(0);
                const page = ref(1);
                const perPage = 20;

                const load = async () => {
                    const data = await api.get('/api/audit-logs?page=' + page.value + '&per_page=' + perPage);
                    items.value = data.items;
                    total.value = data.total;
                };

                onMounted(load);

                return { items, total, page, perPage, load };
            },
            template: `
                <div class="panel">
                    <h2>操作日志</h2>
                    <table>
                        <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>资源</th><th>详情</th><th>IP</th></tr></thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.created_at }}</td>
                                <td>{{ item.username }}</td>
                                <td>{{ item.action }}</td>
                                <td>{{ item.resource }}#{{ item.resource_id || '-' }}</td>
                                <td>{{ item.detail || '-' }}</td>
                                <td>{{ item.ip }}</td>
                            </tr>
                            <tr v-if="!items.length"><td colspan="6">暂无操作日志</td></tr>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <button :disabled="page <= 1" @click="page--; load()">上一页</button>
                        <span>第 {{ page }} 页 / 共 {{ Math.ceil(total / perPage) || 1 }} 页</span>
                        <button :disabled="page * perPage >= total" @click="page++; load()">下一页</button>
                    </div>
                </div>
            `,
        },
        CommandPolicySettingsView: {
            emits: ['flash'],
            setup(_props, { emit }) {
                const loading = ref(true);
                const saving = ref(false);
                const defaults = ref({});
                const policies = ref([]);
                const hosts = ref([]);
                const groups = ref([]);
                const editingId = ref(null);

                const emptyForm = () => ({
                    name: '',
                    scope_type: 'global',
                    scope_id: '',
                    priority: 0,
                    enabled: true,
                    rules_json: '{\n  "deny_binaries": [],\n  "require_approval_binaries": []\n}',
                });

                const form = reactive(emptyForm());
                const isNew = computed(() => editingId.value === 'new');

                const scopeLabel = (policy) => {
                    if (policy.scope_type === 'host') {
                        const host = hosts.value.find((h) => h.id === policy.scope_id);
                        return host ? `主机 · ${host.name}` : `主机 #${policy.scope_id}`;
                    }
                    if (policy.scope_type === 'host_group') {
                        const group = groups.value.find((g) => g.id === policy.scope_id);
                        return group ? `分组 · ${group.name}` : `分组 #${policy.scope_id}`;
                    }
                    return '全局';
                };

                const formatRuleList = (items) => {
                    if (!Array.isArray(items) || items.length === 0) {
                        return '（无）';
                    }
                    return items.map((item) => {
                        if (Array.isArray(item)) {
                            return item.join(' ');
                        }
                        return String(item);
                    }).join('、');
                };

                const loadOptions = async () => {
                    const [hostData, groupData] = await Promise.all([
                        api.get('/api/hosts/options'),
                        api.get('/api/groups'),
                    ]);
                    hosts.value = hostData.items || [];
                    groups.value = groupData.items || [];
                };

                const load = async () => {
                    loading.value = true;
                    try {
                        const [data] = await Promise.all([
                            api.get('/api/settings/command-policy'),
                            loadOptions(),
                        ]);
                        defaults.value = data.defaults || {};
                        policies.value = data.policies || [];
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        loading.value = false;
                    }
                };

                const startNew = () => {
                    editingId.value = 'new';
                    Object.assign(form, emptyForm());
                };

                const editPolicy = (policy) => {
                    editingId.value = policy.id;
                    form.name = policy.name || '';
                    form.scope_type = policy.scope_type || 'global';
                    form.scope_id = policy.scope_id ?? '';
                    form.priority = policy.priority ?? 0;
                    form.enabled = !!policy.enabled;
                    form.rules_json = JSON.stringify(policy.rules || {}, null, 2);
                };

                const cancelEdit = () => {
                    editingId.value = null;
                    Object.assign(form, emptyForm());
                };

                const save = async () => {
                    let rules;
                    try {
                        rules = JSON.parse(form.rules_json || '{}');
                        if (rules === null || typeof rules !== 'object' || Array.isArray(rules)) {
                            throw new Error('规则必须是 JSON 对象');
                        }
                    } catch (error) {
                        emit('flash', '规则 JSON 无效：' + error.message, 'err');
                        return;
                    }

                    saving.value = true;
                    try {
                        const payload = {
                            name: form.name.trim(),
                            scope_type: form.scope_type,
                            scope_id: form.scope_type === 'global' || form.scope_id === '' ? null : Number(form.scope_id),
                            priority: Number(form.priority) || 0,
                            enabled: !!form.enabled,
                            rules,
                        };
                        if (typeof editingId.value === 'number') {
                            payload.id = editingId.value;
                        }
                        await api.put('/api/settings/command-policy', payload);
                        emit('flash', isNew.value ? '已创建命令策略' : '命令策略已保存', 'ok');
                        editingId.value = null;
                        Object.assign(form, emptyForm());
                        await load();
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        saving.value = false;
                    }
                };

                const removePolicy = async (policy) => {
                    if (!confirm('确定删除策略「' + policy.name + '」？')) {
                        return;
                    }
                    saving.value = true;
                    try {
                        await api.delete('/api/settings/command-policy/' + policy.id);
                        if (editingId.value === policy.id) {
                            cancelEdit();
                        }
                        emit('flash', '已删除命令策略', 'ok');
                        await load();
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        saving.value = false;
                    }
                };

                onMounted(load);

                return {
                    loading,
                    saving,
                    defaults,
                    policies,
                    hosts,
                    groups,
                    editingId,
                    form,
                    isNew,
                    scopeLabel,
                    formatRuleList,
                    load,
                    startNew,
                    editPolicy,
                    cancelEdit,
                    save,
                    removePolicy,
                };
            },
            template: `
                <div class="panel command-policy-panel">
                    <h2>命令策略</h2>
                    <p class="hint">控制 AI 执行 SSH 命令时的自动批准、人工审批与拒绝规则。内置默认策略来自配置文件；下方可添加数据库覆盖策略（按作用域与优先级合并）。</p>
                    <p v-if="loading" class="hint">加载中…</p>
                    <template v-else>
                        <details class="command-policy-defaults" open>
                            <summary>内置默认策略（只读）</summary>
                            <div class="command-policy-default-grid">
                                <div class="full">
                                    <h4>拒绝命令（deny_binaries）</h4>
                                    <p class="command-policy-tags">{{ formatRuleList(defaults.deny_binaries) }}</p>
                                </div>
                                <div class="full">
                                    <h4>需审批命令（require_approval_binaries）</h4>
                                    <p class="command-policy-tags">{{ formatRuleList(defaults.require_approval_binaries) }}</p>
                                </div>
                                <p class="hint full">未出现在上述列表中的命令将自动执行；命中「需审批」时，会话自动批准无效。</p>
                            </div>
                        </details>

                        <div class="toolbar" style="margin-top:16px">
                            <button class="primary" type="button" @click="startNew" :disabled="saving">新建覆盖策略</button>
                            <button type="button" @click="load" :disabled="saving">刷新</button>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th>名称</th>
                                    <th>作用域</th>
                                    <th>优先级</th>
                                    <th>状态</th>
                                    <th>规则摘要</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="policy in policies" :key="policy.id">
                                    <td>{{ policy.name }}</td>
                                    <td>{{ scopeLabel(policy) }}</td>
                                    <td>{{ policy.priority }}</td>
                                    <td>{{ policy.enabled ? '启用' : '禁用' }}</td>
                                    <td class="command-policy-rule-summary">{{ Object.keys(policy.rules || {}).join(', ') || '—' }}</td>
                                    <td class="actions">
                                        <button type="button" @click="editPolicy(policy)" :disabled="saving">编辑</button>
                                        <button type="button" class="danger" @click="removePolicy(policy)" :disabled="saving">删除</button>
                                    </td>
                                </tr>
                                <tr v-if="!policies.length"><td colspan="6">暂无数据库覆盖策略</td></tr>
                            </tbody>
                        </table>

                        <div v-if="editingId !== null" class="command-policy-editor">
                            <h3>{{ isNew ? '新建覆盖策略' : '编辑覆盖策略' }}</h3>
                            <div class="form-grid">
                                <div class="full"><label>名称 *</label><input v-model="form.name" placeholder="例如：生产环境加强"></div>
                                <div>
                                    <label>作用域</label>
                                    <select v-model="form.scope_type">
                                        <option value="global">全局</option>
                                        <option value="host_group">主机分组</option>
                                        <option value="host">单主机</option>
                                    </select>
                                </div>
                                <div v-if="form.scope_type === 'host_group'">
                                    <label>主机分组</label>
                                    <select v-model="form.scope_id">
                                        <option value="">请选择</option>
                                        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                                    </select>
                                </div>
                                <div v-else-if="form.scope_type === 'host'">
                                    <label>主机</label>
                                    <select v-model="form.scope_id">
                                        <option value="">请选择</option>
                                        <option v-for="h in hosts" :key="h.id" :value="h.id">{{ h.name }} ({{ h.address }})</option>
                                    </select>
                                </div>
                                <div v-else>
                                    <label>作用域 ID</label>
                                    <input value="—" disabled>
                                </div>
                                <div><label>优先级</label><input v-model.number="form.priority" type="number"></div>
                                <div class="full">
                                    <label class="checkbox-label">
                                        <input type="checkbox" v-model="form.enabled"> 启用
                                    </label>
                                </div>
                                <div class="full">
                                    <label>规则 JSON（增量覆盖内置默认）</label>
                                    <textarea v-model="form.rules_json" rows="12" class="command-policy-json"></textarea>
                                    <p class="hint">可用键：deny_binaries、require_approval_binaries</p>
                                </div>
                            </div>
                            <div class="actions" style="margin-top:12px">
                                <button class="primary" type="button" @click="save" :disabled="saving">{{ isNew ? '创建' : '保存' }}</button>
                                <button type="button" @click="cancelEdit" :disabled="saving">取消</button>
                            </div>
                        </div>
                    </template>
                </div>
            `,
        },
        AiSettingsView: {
            emits: ['flash'],
            setup(_props, { emit }) {
                const loading = ref(true);
                const saving = ref(false);
                const testing = ref(false);
                const loadingModels = ref(false);
                const switching = ref(false);
                const profiles = ref([]);
                const configured = ref(false);
                const selectedProfileId = ref(null);
                const editingId = ref(null);
                const providers = ref([]);
                const modelOptions = ref([]);
                const modelListMessage = ref('');
                const modelManual = ref(false);
                const aiEnabled = ref(false);
                const secrets = reactive({ api_key_set: false, aws_access_key_set: false, aws_secret_key_set: false });
                const showApiKey = ref(false);
                const showAwsSecret = ref(false);

                const defaultForm = () => ({
                    name: '',
                    provider: 'openai',
                    model: 'gpt-4o-mini',
                    api_key: '',
                    keep_api_key: true,
                    base_url: '',
                    ollama_url: 'http://localhost:11434/api',
                    anthropic_version: '2023-06-01',
                    anthropic_max_tokens: 8192,
                    azure_endpoint: '',
                    azure_api_version: '2024-10-21',
                    hf_inference_provider: 'hf-inference/models',
                    aws_region: 'us-east-1',
                    aws_access_key: '',
                    aws_secret_key: '',
                    keep_aws_access_key: true,
                    keep_aws_secret_key: true,
                    vertex_credentials: '',
                    vertex_project: '',
                    vertex_location: '',
                    http_timeout: 120,
                    command_timeout: 30,
                    command_timeout_max: 300,
                    tool_max_runs: 30,
                    context_window: 50000,
                    summarization_enabled: true,
                    summarization_max_tokens: '',
                    summarization_keep: 5,
                });

                const form = reactive(defaultForm());
                const isNew = computed(() => editingId.value === 'new');
                const hasProfiles = computed(() => profiles.value.length > 0);

                const needsBaseUrl = computed(() => {
                    const p = form.provider;
                    return p === 'openailike' || p === 'openailike-responses' || p === 'kimi';
                });
                const needsOllama = computed(() => form.provider === 'ollama');
                const needsAnthropic = computed(() => form.provider === 'anthropic');
                const needsAzure = computed(() => form.provider === 'azure');
                const needsBedrock = computed(() => form.provider === 'bedrock');
                const needsVertex = computed(() => ['gemini-vertex', 'anthropic-vertex'].includes(form.provider));
                const needsHf = computed(() => form.provider === 'huggingface');

                const applySettingsToForm = (name, s, sec) => {
                    Object.assign(form, defaultForm(), {
                        name: name || '',
                        provider: s.provider || 'openai',
                        model: s.model || 'gpt-4o-mini',
                        base_url: s.base_url || '',
                        ollama_url: s.ollama_url || 'http://localhost:11434/api',
                        anthropic_version: s.anthropic_version || '2023-06-01',
                        anthropic_max_tokens: s.anthropic_max_tokens ?? 8192,
                        azure_endpoint: s.azure_endpoint || '',
                        azure_api_version: s.azure_api_version || '2024-10-21',
                        hf_inference_provider: s.hf_inference_provider || 'hf-inference/models',
                        aws_region: s.aws_region || 'us-east-1',
                        vertex_credentials: s.vertex_credentials || '',
                        vertex_project: s.vertex_project || '',
                        vertex_location: s.vertex_location || '',
                        http_timeout: s.http_timeout ?? 120,
                        command_timeout: s.command_timeout ?? 30,
                        command_timeout_max: s.command_timeout_max ?? 300,
                        tool_max_runs: s.tool_max_runs ?? 30,
                        context_window: s.context_window ?? 50000,
                        summarization_enabled: !!s.summarization_enabled,
                        summarization_max_tokens: s.summarization_max_tokens ?? '',
                        summarization_keep: s.summarization_keep ?? 5,
                        api_key: '',
                        keep_api_key: true,
                        aws_access_key: '',
                        aws_secret_key: '',
                        keep_aws_access_key: true,
                        keep_aws_secret_key: true,
                    });
                    Object.assign(secrets, sec || {});
                    modelOptions.value = [];
                    modelListMessage.value = '';
                    modelManual.value = false;
                };

                watch(() => form.provider, () => {
                    modelOptions.value = [];
                    modelListMessage.value = '';
                    modelManual.value = false;
                });

                const applyIndex = async (data) => {
                    configured.value = !!data.configured;
                    selectedProfileId.value = data.selected_profile_id ?? null;
                    profiles.value = data.profiles || [];
                    providers.value = data.providers || [];
                    aiEnabled.value = !!data.settings?.enabled;

                    if (editingId.value === 'new') {
                        return;
                    }

                    if (profiles.value.length === 0) {
                        editingId.value = 'new';
                        startNew();
                        return;
                    }

                    const keepEditing = typeof editingId.value === 'number'
                        && profiles.value.some((p) => p.id === editingId.value);
                    const editId = keepEditing
                        ? editingId.value
                        : (selectedProfileId.value ?? profiles.value[0].id);
                    await loadProfileForm(editId);
                };

                const loadProfileForm = async (id) => {
                    const data = await api.get('/api/settings/ai/profiles/' + id);
                    editingId.value = id;
                    applySettingsToForm(data.name, data.settings || {}, data.secrets || {});
                };

                const load = async () => {
                    loading.value = true;
                    try {
                        const data = await api.get('/api/settings/ai');
                        await applyIndex(data);
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        loading.value = false;
                    }
                };

                const payload = () => ({ ...form, profile_id: typeof editingId.value === 'number' ? editingId.value : undefined });

                const save = async () => {
                    saving.value = true;
                    try {
                        let data;
                        if (isNew.value) {
                            data = await api.post('/api/settings/ai/profiles', payload());
                            editingId.value = data.selected_profile_id ?? editingId.value;
                            emit('flash', '已创建 AI 配置', 'ok');
                        } else if (typeof editingId.value === 'number') {
                            data = await api.put('/api/settings/ai/profiles/' + editingId.value, payload());
                            emit('flash', 'AI 配置已保存', 'ok');
                        }
                        if (data) await applyIndex(data);
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        saving.value = false;
                    }
                };

                const testConnection = async () => {
                    testing.value = true;
                    try {
                        const result = await api.post('/api/settings/ai/test', payload());
                        emit('flash', result.message, result.success ? 'ok' : 'err');
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        testing.value = false;
                    }
                };

                const fetchModels = async () => {
                    loadingModels.value = true;
                    modelListMessage.value = '';
                    try {
                        const result = await api.post('/api/settings/ai/models', payload());
                        if (result.models?.length) {
                            modelOptions.value = result.models;
                            modelManual.value = false;
                            if (!form.model || !result.models.includes(form.model)) {
                                form.model = result.models[0];
                            }
                        } else {
                            modelOptions.value = [];
                        }
                        modelListMessage.value = result.message || '';
                        if (!result.success) {
                            emit('flash', result.message || '获取模型列表失败', 'err');
                        }
                    } catch (error) {
                        modelOptions.value = [];
                        modelListMessage.value = error.message;
                        emit('flash', error.message, 'err');
                    } finally {
                        loadingModels.value = false;
                    }
                };

                const toggleAiEnabled = async () => {
                    if (!selectedProfileId.value) {
                        aiEnabled.value = false;
                        return;
                    }
                    const profile = profiles.value.find((p) => p.id === selectedProfileId.value);
                    switching.value = true;
                    try {
                        const data = await api.put('/api/settings/ai/profiles/' + selectedProfileId.value, {
                            name: profile?.name || form.name || '默认',
                            enabled: aiEnabled.value,
                            keep_api_key: true,
                            keep_aws_access_key: true,
                            keep_aws_secret_key: true,
                        });
                        await applyIndex(data);
                        emit('flash', aiEnabled.value ? 'AI 助手已启用' : 'AI 助手已关闭', 'ok');
                    } catch (error) {
                        aiEnabled.value = !aiEnabled.value;
                        emit('flash', error.message, 'err');
                    } finally {
                        switching.value = false;
                    }
                };

                const selectActiveProfile = async (id) => {
                    if (!id || selectedProfileId.value === id) {
                        return;
                    }
                    switching.value = true;
                    try {
                        const data = await api.post('/api/settings/ai/profiles/' + id + '/select', {});
                        await applyIndex(data);
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        switching.value = false;
                    }
                };

                const editProfile = async (id) => {
                    if (editingId.value === id) {
                        return;
                    }
                    await loadProfileForm(id);
                };

                const startNew = () => {
                    editingId.value = 'new';
                    applySettingsToForm('新配置', {
                        provider: 'openai',
                        model: 'gpt-4o-mini',
                    }, {});
                };

                const removeProfile = async (id) => {
                    const profile = profiles.value.find((p) => p.id === id);
                    if (!confirm('确定删除配置「' + (profile?.name || id) + '」？')) {
                        return;
                    }
                    switching.value = true;
                    try {
                        const data = await api.delete('/api/settings/ai/profiles/' + id);
                        await applyIndex(data);
                        emit('flash', '已删除 AI 配置', 'ok');
                    } catch (error) {
                        emit('flash', error.message, 'err');
                    } finally {
                        switching.value = false;
                    }
                };

                onMounted(load);

                return {
                    loading, saving, testing, loadingModels, switching, profiles, configured, selectedProfileId, editingId,
                    providers, secrets, form, isNew, hasProfiles, modelOptions, modelListMessage, modelManual, aiEnabled,
                    showApiKey, showAwsSecret, needsBaseUrl, needsOllama, needsAnthropic,
                    needsAzure, needsBedrock, needsVertex, needsHf,
                    save, testConnection, fetchModels, toggleAiEnabled, selectActiveProfile, editProfile,
                    startNew, removeProfile, loadProfileForm,
                };
            },
            template: `
                <div class="panel ai-settings-panel">
                    <h2>AI 设置</h2>
                    <p v-if="loading" class="hint">加载中…</p>
                    <template v-else>
                        <div class="ai-active-bar">
                            <label class="checkbox-label">
                                <input
                                    type="checkbox"
                                    v-model="aiEnabled"
                                    :disabled="!hasProfiles || switching"
                                    @change="toggleAiEnabled"
                                >
                                启用 AI 助手
                            </label>
                            <div v-if="hasProfiles && aiEnabled" class="ai-active-profile">
                                <label>使用配置</label>
                                <select
                                    :value="selectedProfileId"
                                    :disabled="switching"
                                    @change="selectActiveProfile(Number($event.target.value))"
                                >
                                    <option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </div>
                        </div>

                        <p v-if="!hasProfiles" class="hint" style="margin-bottom:12px">
                            尚未创建 AI 配置，请先添加一套 Provider 与 API Key。
                        </p>

                        <div class="ai-profile-tabs">
                            <button
                                v-for="p in profiles"
                                :key="p.id"
                                type="button"
                                class="ai-profile-tab"
                                :class="{ editing: editingId === p.id }"
                                :disabled="switching"
                                @click="editProfile(p.id)"
                            >
                                {{ p.name }}
                            </button>
                            <button type="button" class="ai-profile-tab ai-profile-add" :disabled="switching" @click="startNew">+ 新建</button>
                        </div>

                        <template v-if="isNew || typeof editingId === 'number'">
                            <div class="form-grid">
                                <div class="full">
                                    <label>配置名称 *</label>
                                    <input v-model="form.name" placeholder="如 Deepseek、Kimi">
                                </div>
                                <div class="full">
                                    <label>Provider *</label>
                                    <select v-model="form.provider">
                                        <option v-for="p in providers" :key="p" :value="p">{{ p }}</option>
                                    </select>
                                </div>
                                <div class="full">
                                    <label>模型 *</label>
                                    <div class="model-input-row">
                                        <select v-if="modelOptions.length && !modelManual" v-model="form.model" class="model-select">
                                            <option v-for="m in modelOptions" :key="m" :value="m">{{ m }}</option>
                                        </select>
                                        <input v-else v-model="form.model" placeholder="gpt-4o-mini">
                                        <button type="button" @click="fetchModels" :disabled="loadingModels || saving || testing || switching">
                                            {{ loadingModels ? '加载中…' : '拉取列表' }}
                                        </button>
                                    </div>
                                    <p v-if="modelListMessage" class="hint">{{ modelListMessage }}</p>
                                    <label v-if="modelOptions.length" class="hint checkbox-label">
                                        <input type="checkbox" v-model="modelManual"> 手动输入模型名称
                                    </label>
                                </div>
                                <div class="full">
                                    <label>API Key {{ secrets.api_key_set ? '（已保存，留空保持不变）' : '*' }}</label>
                                    <input v-model="form.api_key" :type="showApiKey ? 'text' : 'password'" placeholder="sk-...">
                                    <label class="hint checkbox-label"><input type="checkbox" v-model="showApiKey"> 显示</label>
                                </div>
                                <div v-if="needsBaseUrl" class="full">
                                    <label>Base URL *</label>
                                    <input v-model="form.base_url" placeholder="https://api.example.com/v1">
                                </div>
                                <div v-if="needsOllama" class="full">
                                    <label>Ollama URL</label>
                                    <input v-model="form.ollama_url" placeholder="http://localhost:11434/api">
                                </div>
                                <template v-if="needsAnthropic">
                                    <div><label>Anthropic Version</label><input v-model="form.anthropic_version"></div>
                                    <div><label>Max Tokens</label><input v-model.number="form.anthropic_max_tokens" type="number" min="1"></div>
                                </template>
                                <template v-if="needsAzure">
                                    <div class="full"><label>Azure Endpoint *</label><input v-model="form.azure_endpoint"></div>
                                    <div><label>Azure API Version</label><input v-model="form.azure_api_version"></div>
                                </template>
                                <template v-if="needsBedrock">
                                    <div><label>AWS Region</label><input v-model="form.aws_region"></div>
                                    <div class="full">
                                        <label>AWS Access Key {{ secrets.aws_access_key_set ? '（留空保持不变）' : '' }}</label>
                                        <input v-model="form.aws_access_key" type="password">
                                    </div>
                                    <div class="full">
                                        <label>AWS Secret Key {{ secrets.aws_secret_key_set ? '（留空保持不变）' : '' }}</label>
                                        <input v-model="form.aws_secret_key" :type="showAwsSecret ? 'text' : 'password'">
                                        <label class="hint checkbox-label"><input type="checkbox" v-model="showAwsSecret"> 显示</label>
                                    </div>
                                </template>
                                <template v-if="needsVertex">
                                    <div class="full"><label>Vertex 凭据 JSON 路径 *</label><input v-model="form.vertex_credentials"></div>
                                    <div><label>Vertex Project *</label><input v-model="form.vertex_project"></div>
                                    <div><label>Vertex Location</label><input v-model="form.vertex_location" placeholder="留空为 global"></div>
                                </template>
                                <div v-if="needsHf" class="full">
                                    <label>HuggingFace Inference Provider</label>
                                    <input v-model="form.hf_inference_provider">
                                </div>
                            </div>

                            <details class="ai-settings-advanced">
                                <summary>高级选项</summary>
                                <div class="form-grid" style="margin-top:12px">
                                    <div><label>HTTP 超时（秒）</label><input v-model.number="form.http_timeout" type="number" min="1" step="0.1"></div>
                                    <div><label>默认命令超时（秒）</label><input v-model.number="form.command_timeout" type="number" min="5"></div>
                                    <div><label>命令超时上限（秒）</label><input v-model.number="form.command_timeout_max" type="number" min="5"></div>
                                    <div><label>工具调用上限</label><input v-model.number="form.tool_max_runs" type="number" min="1"></div>
                                    <div><label>上下文窗口（tokens）</label><input v-model.number="form.context_window" type="number" min="1000"></div>
                                    <div class="full"><label class="checkbox-label"><input type="checkbox" v-model="form.summarization_enabled"> 启用对话总结</label></div>
                                    <div><label>总结阈值（tokens，留空为 80%）</label><input v-model="form.summarization_max_tokens" type="number" min="0"></div>
                                    <div><label>总结后保留消息数</label><input v-model.number="form.summarization_keep" type="number" min="1"></div>
                                </div>
                            </details>

                            <div class="actions" style="margin-top:16px">
                                <button class="primary" type="button" @click="save" :disabled="saving || testing || switching">{{ isNew ? '创建配置' : '保存' }}</button>
                                <button type="button" @click="testConnection" :disabled="saving || testing || switching">测试连接</button>
                                <button
                                    v-if="!isNew && typeof editingId === 'number'"
                                    type="button"
                                    @click="removeProfile(editingId)"
                                    :disabled="saving || testing || switching"
                                >删除</button>
                            </div>
                        </template>
                    </template>
                </div>
            `,
        },
    },
};
appOptions.components.TerminalWorkspace.components = {
    TerminalPane: appOptions.components.TerminalPane,
    LiveSessionPane: appOptions.components.LiveSessionPane,
    AiChatPanel: appOptions.components.AiChatPanel,
    AiToolCallCard,
};
appOptions.components.AiSessionWorkspace.components = {
    AiChatPanel: appOptions.components.AiChatPanel,
    AiSessionLivePane: appOptions.components.AiSessionLivePane,
    AiToolCallCard,
};
createApp(appOptions).mount('#app');
