const { createApp, ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } = Vue;

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
    return {
        name: parts[0] || 'hosts',
        id: parts[1] ? Number(parts[1]) : null,
        nonce: parts[2] || null,
    };
};

const openTerminalTab = (hostId) => {
    location.hash = '#/terminal/' + hostId + '/' + Date.now();
};

const navigate = (path) => {
    location.hash = path;
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

        onMounted(async () => {
            window.addEventListener('hashchange', onHashChange);
            try {
                await refreshAuthState();
            } catch (error) {
                setFlash(error.message, 'err');
            } finally {
                twoFactorReady.value = true;
            }
        });

        onBeforeUnmount(() => {
            window.removeEventListener('hashchange', onHashChange);
        });

        const currentView = computed(() => {
            const { name, id } = route.value;
            if (name === 'terminal') return 'terminal';
            if (name === 'live') return 'live';
            if (name === 'sessions') return 'sessions';
            if (name === 'audit-logs') return 'audit';
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

        const SIDEBAR_KEY = 'web-ssh-sidebar-collapsed';
        const sidebarCollapsed = ref(localStorage.getItem(SIDEBAR_KEY) === '1');

        const toggleSidebar = () => {
            sidebarCollapsed.value = !sidebarCollapsed.value;
            localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value ? '1' : '0');
            requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
        };

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
            sidebarCollapsed,
            toggleSidebar,
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
        <div v-else-if="twoFactorReady" class="layout" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
            <aside class="sidebar">
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
                    <a href="#/hosts" title="主机管理" :class="{ active: currentView === 'hosts' || currentView === 'host-form' }">
                        <span class="nav-icon">主</span><span class="nav-label">主机管理</span>
                    </a>
                    <a href="#/terminal" title="终端" :class="{ active: currentView === 'terminal' }">
                        <span class="nav-icon">端</span><span class="nav-label">终端</span>
                    </a>
                    <a href="#/live" title="实时现场" :class="{ active: currentView === 'live' }">
                        <span class="nav-icon">场</span><span class="nav-label">实时现场</span>
                    </a>
                    <a href="#/sessions" title="会话记录" :class="{ active: currentView === 'sessions' }">
                        <span class="nav-icon">话</span><span class="nav-label">会话记录</span>
                    </a>
                    <a href="#/audit-logs" title="操作日志" :class="{ active: currentView === 'audit' }">
                        <span class="nav-icon">志</span><span class="nav-label">操作日志</span>
                    </a>
                </nav>
            </aside>
            <main class="main">
                <div v-if="flash" class="message" :class="flashType">{{ flash }}</div>
                <HostListView v-if="currentView === 'hosts'" @flash="setFlash" />
                <HostFormView v-else-if="currentView === 'host-form'" :host-id="editingHostId" @flash="setFlash" />
                <SessionListView v-else-if="currentView === 'sessions'" />
                <AuditLogView v-else-if="currentView === 'audit'" />
                <TerminalWorkspace
                    v-show="currentView === 'terminal'"
                    :visible="currentView === 'terminal'"
                    :pending-host-id="terminalHostId"
                    :open-nonce="terminalOpenNonce"
                    @flash="setFlash"
                />
                <LiveMonitorView v-if="currentView === 'live'" />
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

                return { items, total, page, perPage, q, loading, load, remove, test, navigate, openTerminalTab };
            },
            template: `
                <div class="panel">
                    <div class="toolbar">
                        <input v-model="q" placeholder="搜索名称/地址/标签" @keyup.enter="load">
                        <button class="primary" @click="load" :disabled="loading">搜索</button>
                        <button class="primary" @click="navigate('#/hosts/0')">新建主机</button>
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
            },
            emits: ['meta'],
            setup(props, { emit, expose }) {
                const terminalRef = ref(null);
                const terminalSessionKey = ref(0);
                const statusMessage = ref('准备连接...');
                const hostInfo = ref(null);
                const connected = ref(false);
                const connecting = ref(false);
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
                    destroyTerminal();
                    terminalSessionKey.value += 1;
                    await nextTick();
                    createTerminal();
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
                    if (connectionAttempted && term) {
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
                    await recreateTerminal();
                    statusMessage.value = '正在连接 WebSocket...';
                    pushMeta();

                    requestAnimationFrame(() => {
                        if (!isCurrentGeneration(generation) || !term) {
                            return;
                        }
                        fitTerminal();
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
                                hostInfo.value = payload.host || hostInfo.value;
                                requestAnimationFrame(() => {
                                    if (!isCurrentGeneration(generation) || socket !== ws) {
                                        return;
                                    }
                                    fitTerminal();
                                    ws.send(JSON.stringify({ type: 'auth', cols: term.cols, rows: term.rows }));
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
                                    if (!isCurrentGeneration(generation) || !term) {
                                        return;
                                    }
                                    fitTerminal();
                                    sendResize();
                                    if (props.active) {
                                        focusTerminal();
                                    }
                                });
                                pushMeta();
                                break;
                            case 'output':
                                if (payload.data && socket === ws) {
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
                                term.writeln('\\r\\n[error] ' + disconnectReason);
                                if (payload.detail) {
                                    String(payload.detail).split('\\n').forEach((line) => term.writeln(line));
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
                    if (term) {
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
                    if (!props.active || !term || !fitAddon) {
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

                expose({ reconnect, disconnect, connecting, connected });

                return {
                    terminalRef,
                    terminalSessionKey,
                    active: computed(() => props.active),
                };
            },
            template: `
                <div class="terminal-pane" :class="{ active }">
                    <div :key="terminalSessionKey" ref="terminalRef" class="terminal-wrap"></div>
                </div>
            `,
        },
        TerminalWorkspace: {
            props: {
                pendingHostId: { type: Number, default: null },
                openNonce: { type: String, default: null },
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

                const openTab = async (hostId) => {
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
                        elapsed: 0,
                    };
                    tabs.value.push(tab);
                    activateTab(tab.id);
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

                watch(() => [props.pendingHostId, props.openNonce], ([hostId]) => {
                    if (hostId) {
                        openTab(hostId);
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

                    <div class="terminal-stack">
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
                            <p>在「主机管理」中点击「登录」，或拖动上方标签栏排序已打开的连接。</p>
                            <button class="primary" @click="openTabFromPicker">前往主机列表</button>
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
                    panel.cols = Math.max(1, cols || panel.cols || 80);
                    panel.rows = Math.max(1, rows || panel.rows || 24);
                    if (panel.term) {
                        panel.term.resize(panel.cols, panel.rows);
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
                    term.open(el);
                    panel.term = term;
                    panel.termEl = el;
                    attachLiveWheelScroll(panel, el);
                    flushBufferedEvents(panel);
                };

                const destroyTerminal = (panel) => {
                    detachLiveWheelScroll(panel);
                    if (panel.term) {
                        panel.term.dispose();
                        panel.term = null;
                        panel.termEl = null;
                    }
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
                            writeStatus(panel, '连接已结束或流不可用。');
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
                };
            },
            template: `
                <div class="panel live-layout">
                    <div class="live-head">
                        <div>
                            <h2>实时窗口</h2>
                            <span class="live-lamp" :class="{ live: lampLive }"><i></i>{{ lampText }}</span>
                        </div>
                        <button v-if="finishedCount > 0" type="button" @click="clearFinishedPanels">
                            清除历史 ({{ finishedCount }})
                        </button>
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
                                                    type="button"
                                                    class="live-pin"
                                                    :class="{ active: panel.pinned }"
                                                    :title="panel.pinned ? '取消置顶' : '置顶'"
                                                    @click="togglePin(panel.id)"
                                                >📌</button>
                                                <button
                                                    v-if="panel.showDismiss"
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

                onMounted(load);

                return { items, total, page, perPage, load };
            },
            template: `
                <div class="panel">
                    <h2>会话记录</h2>
                    <table>
                        <thead><tr><th>用户</th><th>主机</th><th>状态</th><th>开始</th><th>结束</th><th>时长</th><th>错误</th></tr></thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.username }}</td>
                                <td>{{ item.host_name }}<br><small>{{ item.host_address }}</small></td>
                                <td><span class="badge">{{ item.status }}</span></td>
                                <td>{{ item.start_time }}</td>
                                <td>{{ item.end_time || '-' }}</td>
                                <td>{{ item.duration ?? '-' }}</td>
                                <td>{{ item.error_message || '-' }}</td>
                            </tr>
                            <tr v-if="!items.length"><td colspan="7">暂无会话记录</td></tr>
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
    },
};
appOptions.components.TerminalWorkspace.components = { TerminalPane: appOptions.components.TerminalPane };
createApp(appOptions).mount('#app');
