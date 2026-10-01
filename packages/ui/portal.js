(() => {
    'use strict';

    const root = document.querySelector('#portal-root');
    if (!root) return;

    class PortalApiError extends Error {
        constructor(status, code, message) {
            super(message);
            this.name = 'PortalApiError';
            this.status = status;
            this.code = code;
        }
    }

    const labels = {
        titles: {
            overview: '개요',
            vms: '가상 머신',
            jobs: '작업 기록',
            request: 'VM 신청',
        },
        states: {
            queued: '접수됨',
            dispatching: '전달 중',
            running: '실행 중',
            succeeded: '완료',
            failed: '실패',
            reconciliation_required: '확인 필요',
            fresh: '최신',
            stale: '오래됨',
            unavailable: '관찰 불가',
            no_discrepancy: '차이 없음',
            completion_unconfirmed: '완료 확인 중',
            confirmed_state_mismatch: '관찰과 기록 불일치',
            provisioning: '생성 진행 중',
            active: '사용 가능',
            stopped: '중지됨',
            unknown: '미확인',
            null: '미확인',
        },
    };

    function make(tag, className = '', text = null) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== null && text !== undefined) element.textContent = String(text);
        return element;
    }

    function button(text, className, action, data = {}) {
        const item = make('button', className, text);
        item.type = 'button';
        if (action) item.dataset.action = action;
        for (const [key, value] of Object.entries(data)) item.dataset[key] = String(value);
        return item;
    }

    function statusTone(state) {
        if (['succeeded', 'active', 'running'].includes(state)) return 'status-good';
        if (['failed'].includes(state)) return 'status-bad';
        if (['queued', 'dispatching', 'provisioning', 'reconciliation_required'].includes(state)) return 'status-wait';
        return 'status-info';
    }

    function stateName(state) {
        if (state === null || state === undefined || state === '') return labels.states.null;
        return labels.states[state] || String(state);
    }

    function formatDate(value) {
        if (!value) return '기록 없음';
        const text = String(value);
        const utcDateTime = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/;
        const normalized = utcDateTime.test(text) ? `${text.replace(' ', 'T')}Z` : text;
        const parsed = new Date(normalized);
        if (Number.isNaN(parsed.getTime())) return String(value);
        return new Intl.DateTimeFormat('ko-KR', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone: 'Asia/Seoul',
        }).format(parsed);
    }

    function displayValue(value) {
        if (value === null || value === undefined || value === '') return '미확인';
        return String(value);
    }

    function specDescription(snapshot) {
        if (!snapshot || typeof snapshot !== 'object') return '사양 정보 없음';
        const bits = [];
        if (snapshot.vcpus !== undefined) bits.push(`${snapshot.vcpus} vCPU`);
        if (snapshot.memory_mb !== undefined) bits.push(`${snapshot.memory_mb} MB 메모리`);
        if (snapshot.disk_gb !== undefined) bits.push(`${snapshot.disk_gb} GB 디스크`);
        return bits.length ? bits.join(' · ') : '사양 정보 없음';
    }

    class PortalApp {
        constructor(element, consoleRole) {
            this.root = element;
            this.consoleRole = consoleRole === 'admin' ? 'admin' : 'member';
            this.isAdmin = this.consoleRole === 'admin';
            this.expectedGroup = this.consoleRole;
            this.csrfHeaderName = 'X-CSRF-TOKEN';
            this.csrfToken = '';
            this.writeQueue = Promise.resolve();
            this.csrfChannel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('cloud-portal-csrf') : null;
            this.csrfChannel?.addEventListener('message', event => {
                const message = event.data;
                if (message && typeof message.token === 'string' && typeof message.header === 'string') {
                    this.csrfToken = message.token;
                    this.csrfHeaderName = message.header;
                }
            });
            this.actor = null;
            this.page = 'overview';
            this.pendingCreate = this.loadPendingCreate();
            this.profileCache = [];
            this.shellReady = false;
            this.root.addEventListener('click', event => this.handleClick(event));
        }

        async start() {
            try {
                await this.refreshCsrf();
                const response = await this.api('/auth/me');
                this.actor = response.user_context || null;
                if (!this.actor) throw new PortalApiError(401, 'authentication_required', '로그인이 필요합니다.');
                this.showAuthenticatedApp();
            } catch (error) {
                if (error instanceof PortalApiError && error.status === 401) {
                    this.showLogin();
                    return;
                }
                this.showStartupError(error);
            }
        }

        async refreshCsrf() {
            const { response, body } = await this.send('GET', '/auth/csrf');
            this.updateCsrf(response, body);
            if (!this.csrfToken) {
                throw new PortalApiError(response.status, 'csrf_unavailable', '보안 토큰을 준비할 수 없습니다. 새로고침 후 다시 시도해 주세요.');
            }
            return body;
        }

        api(path, options = {}) {
            const method = String(options.method || 'GET').toUpperCase();
            if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
                const run = () => this.withBrowserMutationLock(async () => {
                    await this.refreshCsrf();
                    return this.send(method, path, options);
                });
                const pending = this.writeQueue.then(run, run);
                this.writeQueue = pending.then(() => undefined, () => undefined);
                return pending.then(({ body }) => body);
            }
            return this.send(method, path, options).then(({ body }) => body);
        }

        async send(method, path, options = {}) {
            const headers = { Accept: 'application/json' };
            const init = { method, headers, credentials: 'same-origin', cache: 'no-store' };
            if (options.body !== undefined) {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(options.body);
            }
            if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;
            if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
                if (!this.csrfToken) {
                    throw new PortalApiError(403, 'csrf_unavailable', '보안 토큰을 준비한 뒤 다시 시도해 주세요.');
                }
                headers[this.csrfHeaderName] = this.csrfToken;
            }

            let response;
            try {
                response = await fetch(`/api/v1${path}`, init);
            } catch (_error) {
                throw new PortalApiError(0, 'network_error', '서버에 연결할 수 없습니다. 연결 상태를 확인해 주세요.');
            }

            let body = {};
            try {
                const text = await response.text();
                body = text ? JSON.parse(text) : {};
            } catch (_error) {
                throw new PortalApiError(response.status, 'invalid_response', '서버 응답을 읽을 수 없습니다.');
            }

            this.updateCsrf(response, body);

            if (!response.ok) {
                const detail = body && body.error ? body.error : {};
                const code = String(detail.code || 'request_failed');
                const message = String(detail.message || '요청을 처리하지 못했습니다.');
                if (response.status === 403 && ['csrf_invalid', 'CSRF_REJECTED', 'csrf_rejected'].includes(code)) {
                    try { await this.refreshCsrf(); } catch (_refreshError) { /* Keep the safe original failure. */ }
                    throw new PortalApiError(403, 'csrf_invalid', '보안 토큰을 새로 받았습니다. 요청을 확인한 뒤 다시 제출해 주세요.');
                }
                throw new PortalApiError(response.status, code, message);
            }

            return { response, body };
        }

        updateCsrf(response, body) {
            const headerValue = response.headers.get('X-CSRF-TOKEN');
            if (headerValue) this.csrfToken = headerValue;
            if (body && body.csrf_metadata) {
                if (body.csrf_metadata.header_name) this.csrfHeaderName = body.csrf_metadata.header_name;
                if (!headerValue && body.csrf_metadata.token_value) this.csrfToken = body.csrf_metadata.token_value;
            }
            if (headerValue || (body && body.csrf_metadata && body.csrf_metadata.token_value)) {
                this.csrfChannel?.postMessage({ header: this.csrfHeaderName, token: this.csrfToken });
            }
        }

        withBrowserMutationLock(operation) {
            if (navigator.locks && typeof navigator.locks.request === 'function') {
                return navigator.locks.request('cloud-portal-csrf-write', operation);
            }
            return operation();
        }

        showLogin(message = '') {
            this.shellReady = false;
            this.root.innerHTML = `
                <main class="login-page">
                    <section class="login-card" aria-labelledby="login-title">
                        <div class="portal-brand">
                            <div class="brand-mark" aria-hidden="true">C</div>
                            <div class="brand-copy"><p class="brand-title">Cloud Portal</p><p class="brand-subtitle">가상 머신 관리</p></div>
                        </div>
                        <span class="console-pill" data-login-role></span>
                        <h1 id="login-title">계정으로 로그인</h1>
                        <p class="login-copy">등록된 계정으로 접속해 권한에 맞는 VM과 작업을 확인하세요.</p>
                        <div class="notice-area" data-login-notice aria-live="polite"></div>
                        <form data-login-form>
                            <div class="form-field">
                                <label class="form-label" for="login-identifier">이메일</label>
                                <input class="form-control" id="login-identifier" name="login_identifier" type="email" autocomplete="username" inputmode="email" maxlength="254" required>
                            </div>
                            <div class="form-field">
                                <label class="form-label" for="login-credential">비밀번호</label>
                                <input class="form-control" id="login-credential" name="credential" type="password" autocomplete="current-password" required>
                            </div>
                            <button class="primary-button w-100" type="submit" data-login-submit>로그인</button>
                        </form>
                        <p class="login-security">이 화면은 모의 환경입니다. 가입·비밀번호 재설정은 제공하지 않습니다.</p>
                    </section>
                </main>`;
            this.root.querySelector('[data-login-role]').textContent = this.isAdmin ? '관리자 콘솔' : '고객 콘솔';
            const form = this.root.querySelector('[data-login-form]');
            form.addEventListener('submit', event => {
                event.preventDefault();
                this.login(form);
            });
            if (message) this.showLoginNotice(message, 'error');
        }

        async login(form) {
            const submit = form.querySelector('[data-login-submit]');
            const identifier = form.elements.login_identifier.value.trim();
            const credential = form.elements.credential.value;
            submit.disabled = true;
            submit.textContent = '확인 중…';
            this.clearLoginNotice();
            try {
                await this.refreshCsrf();
                const result = await this.api('/auth/login', {
                    method: 'POST',
                    body: { login_identifier: identifier, credential },
                });
                form.elements.credential.value = '';
                this.actor = result.user_context || null;
                if (!this.actor) throw new PortalApiError(503, 'identity_unavailable', '로그인 정보를 확인할 수 없습니다.');
                if (!this.actor.groups || !this.actor.groups.includes(this.expectedGroup)) {
                    await this.api('/auth/logout', { method: 'POST', body: {} }).catch(() => undefined);
                    this.actor = null;
                    this.pendingCreate = null;
                    this.clearPendingCreate();
                    throw new PortalApiError(403, 'console_role_denied', '이 콘솔을 사용할 권한이 없습니다.');
                }
                this.showAuthenticatedApp();
            } catch (error) {
                const message = error instanceof PortalApiError ? error.message : '로그인 요청을 처리하지 못했습니다.';
                this.showLoginNotice(message, 'error');
                submit.disabled = false;
                submit.textContent = '로그인';
            }
        }

        showLoginNotice(message, tone) {
            const target = this.root.querySelector('[data-login-notice]');
            if (!target) return;
            target.replaceChildren();
            const alert = make('div', `alert-box ${tone === 'error' ? 'alert-error' : 'alert-success'}`, message);
            alert.setAttribute('role', tone === 'error' ? 'alert' : 'status');
            target.append(alert);
        }

        clearLoginNotice() {
            const target = this.root.querySelector('[data-login-notice]');
            if (target) target.replaceChildren();
        }

        showAuthenticatedApp() {
            const requiredGroup = this.isAdmin ? 'admin' : 'member';
            if (!this.actor.groups || !this.actor.groups.includes(requiredGroup)) {
                this.actor = null;
                this.pendingCreate = null;
                this.clearPendingCreate();
                this.showLogin('현재 계정은 이 콘솔을 사용할 수 없습니다.');
                return;
            }

            this.shellReady = true;
            this.root.innerHTML = `
                <div class="portal-shell">
                    <header class="portal-header">
                        <div class="portal-brand">
                            <div class="brand-mark" aria-hidden="true">C</div>
                            <div class="brand-copy"><p class="brand-title">Cloud Portal</p><p class="brand-subtitle">모의 VM 관리 콘솔</p></div>
                        </div>
                        <div class="header-right">
                            <span class="user-label">로그인 상태</span>
                            <span class="console-pill" data-console-label></span>
                            <button type="button" class="secondary-button" data-action="logout">로그아웃</button>
                        </div>
                    </header>
                    <div class="portal-body">
                        <aside class="portal-sidebar" aria-label="주 메뉴">
                            <p class="sidebar-label">작업 공간</p>
                            <button class="nav-button" data-view="overview" aria-current="page"><span class="nav-icon" aria-hidden="true">⌂</span><span>개요</span></button>
                            <button class="nav-button" data-view="vms"><span class="nav-icon" aria-hidden="true">▣</span><span>가상 머신</span></button>
                            <button class="nav-button" data-view="jobs"><span class="nav-icon" aria-hidden="true">≡</span><span>작업 기록</span></button>
                            <button class="nav-button" data-view="request"><span class="nav-icon" aria-hidden="true">＋</span><span>VM 신청</span></button>
                            <div class="sidebar-bottom">실제 VM 접속이나 전원 제어는 연결되어 있지 않습니다.</div>
                        </aside>
                        <main class="portal-main">
                            <div class="notice-area" data-global-notice aria-live="polite"></div>
                            <div data-page-content><div class="loading-state" role="status">화면 정보를 불러오는 중…</div></div>
                            <footer class="portal-footer">가상 머신과 작업 상태는 현재 모의 실행환경에서 제공됩니다.</footer>
                        </main>
                    </div>
                </div>`;
            this.root.querySelector('[data-console-label]').textContent = this.isAdmin ? '관리자 콘솔' : '고객 콘솔';
            this.root.querySelectorAll('[data-view]').forEach(item => {
                item.addEventListener('click', () => this.navigate(item.dataset.view));
            });
            this.navigate('overview');
        }

        handleClick(event) {
            const action = event.target.closest('[data-action]');
            if (!action) return;
            if (action.dataset.action === 'logout') this.logout();
            if (action.dataset.action === 'open-vm') this.openVm(action.dataset.ref);
            if (action.dataset.action === 'open-job') this.openJob(action.dataset.ref);
            if (action.dataset.action === 'retry-request') this.retryPendingRequest();
        }

        async logout() {
            const logoutButton = this.root.querySelector('[data-action="logout"]');
            if (logoutButton) { logoutButton.disabled = true; logoutButton.textContent = '로그아웃 중…'; }
            try {
                await this.api('/auth/logout', { method: 'POST', body: {} });
                this.actor = null;
                this.pendingCreate = null;
                this.clearPendingCreate();
                this.showLogin('로그아웃했습니다.');
            } catch (error) {
                this.showNotice(error.message || '로그아웃 요청을 처리하지 못했습니다.', 'error');
                if (logoutButton) { logoutButton.disabled = false; logoutButton.textContent = '로그아웃'; }
            }
        }

        async navigate(page) {
            if (!this.shellReady || !labels.titles[page]) return;
            this.page = page;
            this.root.querySelectorAll('[data-view]').forEach(item => {
                if (item.dataset.view === page) item.setAttribute('aria-current', 'page');
                else item.removeAttribute('aria-current');
            });
            const container = this.root.querySelector('[data-page-content]');
            container.replaceChildren(make('div', 'loading-state', '정보를 불러오는 중…'));
            try {
                if (page === 'overview') await this.renderOverview(container);
                else if (page === 'vms') await this.renderVmList(container);
                else if (page === 'jobs') await this.renderJobList(container);
                else if (page === 'request') await this.renderRequest(container);
            } catch (error) {
                this.renderLoadError(container, error);
            }
        }

        setHeading(container, title, description, action = null) {
            container.replaceChildren();
            const heading = make('div', 'page-heading');
            const copy = make('div');
            copy.append(make('h1', '', title), make('p', '', description));
            heading.append(copy);
            if (action) {
                const actions = make('div', 'heading-actions');
                actions.append(action);
                heading.append(actions);
            }
            container.append(heading);
        }

        modeBanner() {
            const banner = make('div', 'mode-banner');
            const badge = make('span', 'simulated-pill', '모의 환경');
            banner.append(badge, make('span', '', '화면의 VM·작업 정보는 실제 인프라와 연결되지 않은 시험 데이터입니다.'));
            return banner;
        }

        async renderOverview(container) {
            const [summary, vms] = await Promise.all([
                this.api('/summary'),
                this.api('/vms'),
            ]);
            this.setHeading(container, '개요', '가상 머신 상태와 최근 작업을 확인합니다.', button('VM 신청', 'primary-button', 'navigate-request'));
            container.querySelector('[data-action="navigate-request"]')?.addEventListener('click', () => this.navigate('request'));
            container.append(this.modeBanner());

            const metrics = make('section', 'card-grid');
            metrics.append(
                this.metric('가상 머신', summary.vm_count ?? (vms.vms || []).length, '내 권한 범위의 VM'),
                this.metric('진행 중 작업', summary.pending_jobs ?? 0, '접수·실행·확인 중'),
                this.metric('실패 작업', summary.failed_jobs ?? 0, '결과 확인이 필요한 작업'),
                this.metric('최근 확인', formatDate(summary.observed_at), '서버 조회 기준 시각', 'date'),
            );
            container.append(metrics);

            const vmCard = this.section('가상 머신', '현재 확인 가능한 가상 머신입니다.');
            const vmRows = Array.isArray(vms.vms) ? vms.vms.slice(0, 5) : [];
            vmCard.content.append(this.vmTable(vmRows, true));
            if (!vmRows.length) vmCard.content.append(this.empty('VM이 아직 없습니다.', '새 VM을 신청하면 작업 기록에 접수 상태가 표시됩니다.'));
            container.append(vmCard.card);

            const jobCard = this.section('최근 작업', '접수와 실행 결과는 서로 다른 상태로 표시됩니다.');
            const jobRows = Array.isArray(summary.recent_jobs) ? summary.recent_jobs.slice(0, 6) : [];
            jobCard.content.append(this.jobTable(jobRows, true));
            if (!jobRows.length) jobCard.content.append(this.empty('작업 기록이 없습니다.', 'VM 신청으로 첫 작업을 시작할 수 있습니다.'));
            container.append(jobCard.card);
        }

        metric(label, value, foot, kind = '') {
            const card = make('article', 'portal-card metric-card');
            card.append(make('div', 'metric-label', label), make('div', `metric-value${kind === 'date' ? ' metric-value-date' : ''}`, value), make('div', 'metric-foot', foot));
            return card;
        }

        section(title, description) {
            const card = make('section', 'portal-card section-card');
            const head = make('div', 'section-head');
            const copy = make('div');
            copy.append(make('h2', 'section-title', title), make('p', 'section-note', description));
            head.append(copy);
            const content = make('div', 'section-content');
            card.append(head, content);
            return { card, content };
        }

        empty(title, description) {
            const item = make('div', 'empty-state');
            item.append(make('div', 'empty-icon', '◇'), make('p', 'empty-title', title), make('p', 'empty-copy', description));
            return item;
        }

        async renderVmList(container) {
            const result = await this.api('/vms');
            this.setHeading(container, '가상 머신', '사양, 최근 작업, 마지막 상태 관찰을 확인합니다.');
            container.append(this.modeBanner());
            const section = this.section('VM 목록', `${Array.isArray(result.vms) ? result.vms.length : 0}개 항목`);
            const rows = Array.isArray(result.vms) ? result.vms : [];
            section.content.append(this.vmTable(rows, false));
            if (!rows.length) section.content.append(this.empty('VM이 아직 없습니다.', 'VM 신청을 제출하면 여기에 목록이 표시됩니다.'));
            container.append(section.card);
        }

        vmTable(rows, compact) {
            const wrap = make('div', 'table-wrap');
            const table = make('table', 'portal-table');
            table.append(this.tableHead(compact
                ? ['VM', '사양', '상태', '최근 작업', '보기']
                : (this.isAdmin
                    ? ['VM', '소유자', '프로필', '사양', '상태', '전원 관찰', '최근 작업', '생성 시각', '보기']
                    : ['VM', '프로필', '사양', '상태', '전원 관찰', '최근 작업', '생성 시각', '보기'])));
            const body = make('tbody');
            for (const vm of rows) {
                const row = make('tr');
                const id = displayValue(vm.vm_id);
                const state = displayValue(vm.lifecycle_state);
                const status = make('span', `status-pill ${statusTone(vm.lifecycle_state)}`, stateName(vm.lifecycle_state));
                const detail = button('상세', 'text-button', 'open-vm', { ref: id });
                const latestJob = vm.latest_job && vm.latest_job.job_id
                    ? button(stateName(vm.latest_job.state), 'text-button', 'open-job', { ref: vm.latest_job.job_id })
                    : make('span', 'muted', '없음');
                const profileRef = `${displayValue(vm.profile_id)} · ${displayValue(vm.profile_version)}`;
                if (compact) {
                    row.append(this.cell(id, 'mono-ref'), this.cell(specDescription(vm.profile)), this.cellNode(status), this.cellNode(latestJob), this.cellNode(detail));
                } else {
                    row.append(
                        this.cell(id, 'mono-ref'),
                        ...(this.isAdmin ? [this.cell(`회원 #${displayValue(vm.owner_user_id)}`)] : []),
                        this.cell(profileRef),
                        this.cell(specDescription(vm.profile)),
                        this.cellNode(status),
                        this.cell(stateName(vm.power_state)),
                        this.cellNode(latestJob),
                        this.cell(formatDate(vm.created_at)),
                        this.cellNode(detail),
                    );
                }
                body.append(row);
            }
            table.append(body);
            wrap.append(table);
            return wrap;
        }

        async renderJobList(container) {
            const result = await this.api('/jobs');
            this.setHeading(container, '작업 기록', 'VM 요청의 접수·처리 결과와 마지막 상태를 확인합니다.');
            container.append(this.modeBanner());
            const rows = Array.isArray(result.jobs) ? result.jobs : [];
            const section = this.section('작업 목록', `${rows.length}개 항목`);
            section.content.append(this.jobTable(rows, false));
            if (!rows.length) section.content.append(this.empty('작업 기록이 없습니다.', '새 VM 신청은 별도의 작업으로 추적됩니다.'));
            container.append(section.card);
        }

        jobTable(rows, compact) {
            const wrap = make('div', 'table-wrap');
            const table = make('table', 'portal-table');
            table.append(this.tableHead(compact
                ? ['작업', '상태', '요청 시각', '보기']
                : ['작업', 'VM', '동작', '상태', '시도', '요청 시각', '보기']));
            const body = make('tbody');
            for (const job of rows) {
                const row = make('tr');
                const id = displayValue(job.job_id);
                const status = make('span', `status-pill ${statusTone(job.state)}`, stateName(job.state));
                const detail = button('상세', 'text-button', 'open-job', { ref: id });
                if (compact) {
                    row.append(this.cell(id, 'mono-ref'), this.cellNode(status), this.cell(formatDate(job.created_at)), this.cellNode(detail));
                } else {
                    row.append(
                        this.cell(id, 'mono-ref'),
                        this.cell(displayValue(job.vm_id), 'mono-ref'),
                        this.cell(displayValue(job.operation)),
                        this.cellNode(status),
                        this.cell(displayValue(job.current_attempt_no)),
                        this.cell(formatDate(job.created_at)),
                        this.cellNode(detail),
                    );
                }
                body.append(row);
            }
            table.append(body);
            wrap.append(table);
            return wrap;
        }

        tableHead(names) {
            const head = make('thead');
            const row = make('tr');
            for (const name of names) row.append(make('th', '', name));
            head.append(row);
            return head;
        }

        cell(value, className = '') {
            const td = make('td');
            td.append(make('span', className, value));
            return td;
        }

        cellNode(child) {
            const td = make('td');
            td.append(child);
            return td;
        }

        async openVm(id) {
            this.page = 'vm-detail';
            const container = this.root.querySelector('[data-page-content]');
            container.replaceChildren(make('div', 'loading-state', 'VM 정보를 불러오는 중…'));
            try {
                const response = await this.api(`/vms/${encodeURIComponent(id)}`);
                const vm = response.vm;
                container.replaceChildren();
                const back = button('목록으로', 'back-link', 'navigate-vms');
                back.addEventListener('click', () => this.navigate('vms'));
                this.setHeading(container, 'VM 상세', displayValue(vm.vm_id), back);
                container.append(this.modeBanner());
                const card = this.section('기본 정보', '저장된 VM 정보와 관찰 결과를 구분해 표시합니다.');
                const grid = make('div', 'detail-grid');
                this.addDetail(grid, 'VM ID', vm.vm_id, true);
                if (this.isAdmin && vm.owner_user_id !== undefined) this.addDetail(grid, '소유자', `회원 #${vm.owner_user_id}`);
                this.addDetail(grid, '프로필', `${displayValue(vm.profile_id)} · ${displayValue(vm.profile_version)}`);
                this.addDetail(grid, '사양', specDescription(vm.profile));
                this.addDetail(grid, '생명주기', stateName(vm.lifecycle_state));
                this.addDetail(grid, '전원 관찰', stateName(vm.power_state));
                this.addDetail(grid, '실행환경', '모의 환경');
                this.addDetail(grid, '생성 시각', formatDate(vm.created_at));
                this.addDetail(grid, '마지막 갱신', formatDate(vm.updated_at));
                this.addDetail(grid, '마지막 관찰', formatDate(vm.observed_at));
                card.content.append(grid);
                container.append(card.card);
                if (vm.latest_job) {
                    const latest = this.section('최근 작업', 'VM과 연결된 가장 최근 작업입니다.');
                    const row = make('div', 'detail-grid');
                    this.addDetail(row, '작업 ID', vm.latest_job.job_id, true);
                    this.addDetail(row, '동작', vm.latest_job.operation);
                    this.addDetail(row, '상태', stateName(vm.latest_job.state));
                    this.addDetail(row, '요청 시각', formatDate(vm.latest_job.created_at));
                    latest.content.append(row, button('작업 상세', 'secondary-button mt-3', 'open-job', { ref: vm.latest_job.job_id }));
                    container.append(latest.card);
                }
            } catch (error) {
                this.renderLoadError(container, error, () => this.navigate('vms'));
            }
        }

        async openJob(id) {
            this.page = 'job-detail';
            const container = this.root.querySelector('[data-page-content]');
            container.replaceChildren(make('div', 'loading-state', '작업 정보를 불러오는 중…'));
            try {
                const response = await this.api(`/jobs/${encodeURIComponent(id)}`);
                const job = response.job;
                container.replaceChildren();
                const back = button('목록으로', 'back-link', 'navigate-jobs');
                back.addEventListener('click', () => this.navigate('jobs'));
                this.setHeading(container, '작업 상세', displayValue(job.job_id), back);
                container.append(this.modeBanner());
                const card = this.section('작업 정보', '접수 결과와 모의 실행 상태를 확인합니다.');
                const grid = make('div', 'detail-grid');
                this.addDetail(grid, '작업 ID', job.job_id, true);
                this.addDetail(grid, 'VM ID', job.vm_id, true);
                this.addDetail(grid, '동작', job.operation);
                this.addDetail(grid, '상태', stateName(job.state));
                this.addDetail(grid, '시도', job.current_attempt_no);
                this.addDetail(grid, '접수 시각', formatDate(job.created_at));
                this.addDetail(grid, '갱신 시각', formatDate(job.updated_at));
                this.addDetail(grid, '완료 시각', formatDate(job.finished_at));
                this.addDetail(grid, '실행환경', '모의 환경');
                if (job.error_code) this.addDetail(grid, '오류 분류', job.error_code);
                card.content.append(grid);
                container.append(card.card);

                const observed = this.section('마지막 상태 관찰', '작업 기록과 외부 모의 상태 관찰은 각각의 기준으로 표시됩니다.');
                if (response.observation) {
                    const observation = response.observation;
                    const observedGrid = make('div', 'detail-grid');
                    this.addDetail(observedGrid, '관찰 상태', stateName(observation.status));
                    this.addDetail(observedGrid, '전원 관찰', stateName(observation.power_state));
                    this.addDetail(observedGrid, '게스트 준비', stateName(observation.guest_readiness));
                    this.addDetail(observedGrid, '관찰 시각', formatDate(observation.observed_at));
                    this.addDetail(observedGrid, '관찰 신선도', stateName(response.freshness));
                    this.addDetail(observedGrid, '차이 확인', stateName(response.discrepancy));
                    if (observation.error_code || response.error_code) this.addDetail(observedGrid, '오류 분류', observation.error_code || response.error_code);
                    observed.content.append(observedGrid);
                } else {
                    observed.content.append(this.empty(
                        response.freshness === 'unavailable' ? '현재 상태를 확인할 수 없습니다.' : '외부 상태 관찰이 아직 없습니다.',
                        '마지막 성공 관찰과 작업 상태는 별도로 표시됩니다.',
                    ));
                    if (response.error_code) observed.content.append(make('p', 'form-hint', `오류 분류 · ${displayValue(response.error_code)}`));
                }
                container.append(observed.card);

                if (Array.isArray(job.attempts) && job.attempts.length) {
                    const timeline = this.section('시도 이력', '각 시도는 API 작업 기록에서 제공한 순서로 표시됩니다.');
                    const list = make('div', 'timeline');
                    for (const attempt of job.attempts) {
                        const item = make('div', 'timeline-row');
                        item.append(make('span', 'timeline-mark'));
                        const copy = make('div', 'timeline-copy');
                        copy.append(make('strong', '', `시도 ${displayValue(attempt.attempt_no)} · ${stateName(attempt.state)}`));
                        copy.append(make('span', '', formatDate(attempt.started_at || attempt.created_at)));
                        item.append(copy);
                        list.append(item);
                    }
                    timeline.content.append(list);
                    container.append(timeline.card);
                }
            } catch (error) {
                this.renderLoadError(container, error, () => this.navigate('jobs'));
            }
        }

        addDetail(grid, label, value, mono = false) {
            const item = make('div', 'detail-item');
            item.append(make('div', 'detail-label', label), make('div', `detail-value${mono ? ' mono-ref' : ''}`, displayValue(value)));
            grid.append(item);
        }

        async renderRequest(container) {
            const result = await this.api('/profiles');
            this.profileCache = Array.isArray(result.profiles) ? result.profiles : [];
            this.setHeading(container, 'VM 신청', '서버에서 제공한 사용 가능한 프로필을 선택합니다.');
            container.append(this.modeBanner());

            const layout = make('div', 'form-grid');
            const formCard = this.section('신청 정보', 'VM 이름이나 접속 주소는 입력하지 않습니다.');
            const notice = make('div', 'notice-area');
            notice.dataset.requestNotice = '';
            const form = make('form');
            form.dataset.requestForm = '';
            const field = make('div', 'form-field');
            const label = make('label', 'form-label', 'VM 프로필');
            label.htmlFor = 'profile-selection';
            const select = make('select', 'form-select');
            select.id = 'profile-selection';
            select.name = 'profile_id';
            select.required = true;
            select.append(new Option('프로필을 선택하세요', ''));
            for (const profile of this.profileCache) {
                const option = new Option(`${displayValue(profile.profile_id)} · ${specDescription(profile.snapshot)}`, profile.profile_id);
                option.dataset.version = displayValue(profile.profile_version);
                select.append(option);
            }
            field.append(label, select, make('p', 'form-hint', '현재 선택 가능한 모의 사양만 신청할 수 있습니다.'));
            const submit = make('button', 'primary-button w-100', '신청 접수');
            submit.type = 'submit';
            submit.dataset.submitRequest = '';
            form.append(notice, field, submit);
            formCard.content.append(form);

            const infoCard = this.section('신청 전 안내', '요청이 접수되면 작업 기록에서 진행 결과를 확인할 수 있습니다.');
            const info = make('div', 'timeline');
            info.append(
                this.timelineItem('서버 검증', '프로필과 요청 권한을 서버에서 확인합니다.'),
                this.timelineItem('작업 접수', '접수 ID와 VM ID를 서버가 발급합니다.'),
                this.timelineItem('모의 실행', '실제 VM 없이 모의 실행 결과를 기록합니다.'),
            );
            infoCard.content.append(info);
            layout.append(formCard.card, infoCard.card);
            container.append(layout);
            if (this.pendingCreate) {
                select.value = this.pendingCreate.body.profile_id;
                this.showRequestNotice('이전 신청의 결과가 확인되지 않았습니다. 같은 요청 키로 다시 확인할 수 있습니다.', 'info');
                form.querySelector('[data-request-notice]')?.append(button('같은 요청 확인', 'secondary-button mt-3', 'retry-request'));
                submit.textContent = '동일한 요청 다시 확인';
            }
            form.addEventListener('submit', event => {
                event.preventDefault();
                this.submitRequest(form, submit);
            });
        }

        timelineItem(title, description) {
            const item = make('div', 'timeline-row');
            item.append(make('span', 'timeline-mark'));
            const copy = make('div', 'timeline-copy');
            copy.append(make('strong', '', title), make('span', '', description));
            item.append(copy);
            return item;
        }

        async submitRequest(form, submit) {
            const profileId = form.elements.profile_id.value;
            if (!profileId) {
                this.showRequestNotice('사용 가능한 프로필을 선택해 주세요.', 'error');
                return;
            }
            if (!this.pendingCreate) {
                this.pendingCreate = {
                    body: { profile_id: profileId },
                    key: this.newIdempotencyKey(),
                };
                this.persistPendingCreate();
            } else if (this.pendingCreate.body.profile_id !== profileId) {
                this.showRequestNotice('이전 요청 결과가 확인되지 않았습니다. 작업 기록을 확인한 뒤 새 신청을 시작해 주세요.', 'error');
                return;
            }

            submit.disabled = true;
            submit.textContent = '요청 접수 중…';
            this.showRequestNotice('서버에 신청을 전송하고 있습니다.', 'info');
            try {
                const receipt = await this.api('/vms', {
                    method: 'POST',
                    body: this.pendingCreate.body,
                    idempotencyKey: this.pendingCreate.key,
                });
                this.pendingCreate = null;
                this.clearPendingCreate();
                this.showReceipt(receipt);
            } catch (error) {
                if (error instanceof PortalApiError && error.status >= 400 && error.status < 500 && error.status !== 403 && error.status !== 0) {
                    this.pendingCreate = null;
                    this.clearPendingCreate();
                }
                this.showRequestFailure(error, form, submit);
            } finally {
                submit.disabled = false;
                submit.textContent = this.pendingCreate ? '동일한 요청 다시 확인' : '새 신청 접수';
            }
        }

        retryPendingRequest() {
            const form = this.root.querySelector('[data-request-form]');
            const submit = this.root.querySelector('[data-submit-request]');
            if (form && submit && this.pendingCreate) this.submitRequest(form, submit);
        }

        newIdempotencyKey() {
            if (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function') return `web-${globalThis.crypto.randomUUID()}`;
            return `web-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        }

        loadPendingCreate() {
            try {
                const saved = JSON.parse(sessionStorage.getItem('portal.pendingCreate') || 'null');
                if (saved && typeof saved.key === 'string' && typeof saved.profile_id === 'string') {
                    return { key: saved.key, body: { profile_id: saved.profile_id } };
                }
            } catch (_error) { /* Storage is optional; do not block the console. */ }
            return null;
        }

        persistPendingCreate() {
            try {
                if (this.pendingCreate) {
                    sessionStorage.setItem('portal.pendingCreate', JSON.stringify({
                        key: this.pendingCreate.key,
                        profile_id: this.pendingCreate.body.profile_id,
                    }));
                }
            } catch (_error) { /* Keep the request key in memory if storage is unavailable. */ }
        }

        clearPendingCreate() {
            try { sessionStorage.removeItem('portal.pendingCreate'); } catch (_error) { /* Storage is optional. */ }
        }

        showRequestFailure(error, form, submit) {
            const message = error instanceof PortalApiError ? error.message : '요청을 처리하지 못했습니다.';
            this.showRequestNotice(message, 'error');
            if (this.pendingCreate) {
                const retry = button('같은 요청 다시 확인', 'secondary-button mt-3', 'retry-request');
                form.querySelector('[data-request-notice]')?.append(retry);
            }
            if (error instanceof PortalApiError && error.status === 409) {
                const note = make('p', 'form-hint', '한도 또는 기존 요청과 충돌했습니다. 새 시도는 별도의 신청으로 제출해 주세요.');
                form.querySelector('[data-request-notice]')?.append(note);
            }
        }

        showReceipt(receipt) {
            const target = this.root.querySelector('[data-request-notice]');
            if (!target) return;
            target.replaceChildren();
            const panel = make('div', 'alert-box alert-success');
            panel.setAttribute('role', 'status');
            panel.append(make('strong', '', '신청이 접수되었습니다.'));
            const refs = make('div', 'mt-2');
            refs.append(make('div', 'mono-ref', `작업 ID · ${displayValue(receipt.job_id)}`));
            refs.append(make('div', 'mono-ref', `VM ID · ${displayValue(receipt.vm_id)}`));
            refs.append(make('div', '', `현재 상태 · ${stateName(receipt.status)}`));
            panel.append(refs);
            target.append(panel);
        }

        showRequestNotice(message, tone) {
            const target = this.root.querySelector('[data-request-notice]');
            if (!target) return;
            target.replaceChildren();
            const alert = make('div', `alert-box${tone === 'error' ? ' alert-error' : tone === 'success' ? ' alert-success' : ''}`, message);
            alert.setAttribute('role', tone === 'error' ? 'alert' : 'status');
            target.append(alert);
        }

        showNotice(message, tone = 'info') {
            const target = this.root.querySelector('[data-global-notice]');
            if (!target) return;
            target.replaceChildren();
            const alert = make('div', `alert-box${tone === 'error' ? ' alert-error' : tone === 'success' ? ' alert-success' : ''}`, message);
            alert.setAttribute('role', tone === 'error' ? 'alert' : 'status');
            target.append(alert);
        }

        renderLoadError(container, error, back = null) {
            container.replaceChildren();
            this.setHeading(container, '정보를 불러오지 못했습니다', '잠시 후 다시 확인할 수 있습니다.', back ? button('돌아가기', 'secondary-button', 'back') : null);
            const panel = make('div', 'alert-box alert-error');
            panel.setAttribute('role', 'alert');
            panel.textContent = error instanceof PortalApiError ? error.message : '요청을 처리하지 못했습니다.';
            container.append(panel, button('다시 불러오기', 'secondary-button', 'reload-view'));
            const retryButton = container.querySelector('[data-action="reload-view"]');
            retryButton?.addEventListener('click', () => this.navigate(this.page));
            const backButton = container.querySelector('[data-action="back"]');
            backButton?.addEventListener('click', back);
        }

        showStartupError(error) {
            this.root.innerHTML = '<main class="login-page"><section class="login-card" data-startup-error></section></main>';
            const card = this.root.querySelector('[data-startup-error]');
            card.append(make('div', 'brand-title', 'Cloud Portal'), make('h1', '', '화면을 열지 못했습니다'));
            const message = error instanceof PortalApiError ? error.message : 'API에 연결할 수 없습니다. 잠시 뒤 다시 시도해 주세요.';
            const alert = make('div', 'alert-box alert-error', message);
            alert.setAttribute('role', 'alert');
            card.append(alert, button('다시 시도', 'primary-button', 'restart'));
            card.querySelector('[data-action="restart"]')?.addEventListener('click', () => this.start());
        }
    }

    const app = new PortalApp(root, root.dataset.consoleRole || 'member');
    app.start();
})();
