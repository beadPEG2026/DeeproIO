<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('团队层级') }}</title>
    <link rel="stylesheet" href="{{ asset('css/deepro-theme.css') }}?v={{ filemtime(public_path('css/deepro-theme.css')) }}">
    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            background: var(--ui-page);
            color: var(--ui-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, "PingFang SC", "Microsoft YaHei", sans-serif;
            font-size: 14px;
        }

        body {
            background: var(--ui-page);
        }

        .team-page {
            min-height: 100vh;
            padding: 24px;
        }

        .team-shell {
            width: min(1440px, 100%);
            margin: 0 auto;
        }

        .team-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 18px;
        }

        .team-back {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border: 1px solid var(--ui-line);
            background: var(--ui-surface);
            color: var(--ui-text);
            border-radius: 12px;
            height: 36px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: none;
        }

        .team-back:hover {
            background: var(--ui-field);
            border-color: var(--ui-line);
        }

        .team-title {
            margin: 14px 0 0;
            font-size: 26px;
            line-height: 1.2;
            font-weight: 900;
            color: var(--ui-text);
            letter-spacing: -0.02em;
        }

        .team-subtitle {
            margin-top: 7px;
            color: var(--ui-muted);
            font-size: 13px;
        }

        .team-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .team-button {
            border: 1px solid var(--ui-line);
            background: var(--ui-surface);
            color: var(--ui-text);
            border-radius: 12px;
            height: 36px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: none;
        }

        .team-button:hover {
            background: var(--ui-field);
        }

        .team-path-card {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--ui-surface);
            border: 1px solid var(--ui-line);
            border-radius: 18px;
            padding: 12px 14px;
            margin-bottom: 16px;
            box-shadow: none;
            backdrop-filter: blur(8px);
        }

        .team-path-label {
            color: var(--ui-muted);
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .team-path {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            min-width: 0;
        }

        .team-path button {
            border: 1px solid var(--ui-line);
            background: var(--ui-highlight);
            color: var(--ui-accent-text);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
        }

        .team-path button:last-child {
            color: var(--ui-on-accent);
            border-color: var(--ui-accent-text);
            background: var(--ui-accent);
        }

        .team-summary {
            display: grid;
            grid-template-columns: repeat(5, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .team-stat {
            background: var(--ui-surface);
            border: 1px solid var(--ui-line);
            border-radius: 18px;
            padding: 16px 16px 15px;
            box-shadow: none;
            position: relative;
            overflow: hidden;
        }

        .team-stat:before {
            content: "";
            position: absolute;
            left: 0;
            top: 14px;
            bottom: 14px;
            width: 4px;
            border-radius: 0 999px 999px 0;
            background: var(--ui-accent);
        }

        .team-stat.green:before {
            background: var(--ui-success);
        }

        .team-stat.red:before {
            background: var(--ui-danger);
        }

        .team-stat.orange:before {
            background: var(--ui-accent);
        }

        .team-stat.purple:before {
            background: var(--ui-accent);
        }

        .team-stat.blue:before {
            background: var(--ui-accent);
        }

        .team-stat-label {
            color: var(--ui-muted);
            font-size: 12px;
            font-weight: 800;
        }

        .team-stat-value {
            margin-top: 8px;
            color: var(--ui-text);
            font-size: 22px;
            line-height: 1.15;
            font-weight: 900;
            word-break: break-word;
        }

        .team-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
            align-items: stretch;
        }

        .team-card {
            background: var(--ui-surface);
            border: 1px solid var(--ui-line);
            border-radius: 20px;
            box-shadow: none;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-width: 0;
            height: 100%;
        }

        .team-card-head {
            min-height: 76px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--ui-divider);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            background: linear-gradient(180deg, var(--ui-surface) 0%, var(--ui-surface) 100%);
        }

        .team-card-title {
            color: var(--ui-text);
            font-size: 15px;
            font-weight: 900;
        }

        .team-card-desc {
            margin-top: 4px;
            color: var(--ui-muted);
            font-size: 12px;
        }

        .team-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--ui-field);
            color: var(--ui-text);
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .team-table-wrap {
            overflow-x: auto;
            flex: 1;
        }

        .team-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .team-table th {
            height: 48px;
            padding: 12px 14px;
            background: var(--ui-surface);
            border-bottom: 1px solid var(--ui-divider);
            color: var(--ui-muted);
            font-size: 12px;
            font-weight: 900;
            text-align: left;
            white-space: nowrap;
            vertical-align: middle;
        }

        .team-table td {
            height: 58px;
            padding: 13px 14px;
            border-bottom: 1px solid var(--ui-divider);
            color: var(--ui-text);
            font-size: 12px;
            text-align: left;
            vertical-align: middle;
            white-space: nowrap;
        }

        .team-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .team-row {
            cursor: pointer;
            transition: background 0.16s ease;
        }

        .team-row:hover td {
            background: var(--ui-field);
        }

        .team-row.clickable:hover td {
            background: var(--ui-highlight);
        }

        .team-member {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .team-arrow {
            width: 24px;
            height: 24px;
            border-radius: 9px;
            background: var(--ui-field);
            color: var(--ui-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            flex: 0 0 24px;
        }

        .team-arrow.active {
            color: var(--ui-accent-text);
            background: var(--ui-highlight);
        }

        .team-member-main {
            min-width: 0;
        }

        .team-member-name {
            color: var(--ui-text);
            font-size: 13px;
            font-weight: 900;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-member-tip {
            margin-top: 3px;
            color: var(--ui-muted);
            font-size: 11px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-user-id {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            border-radius: 999px;
            background: var(--ui-field);
            color: var(--ui-muted);
            padding: 5px 9px;
            font-weight: 900;
        }

        .team-clip {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-time {
            color: var(--ui-muted);
        }

        .team-money {
            color: var(--ui-text);
            font-weight: 900;
        }

        .team-money.green {
            color: var(--ui-success);
        }

        .team-money.red {
            color: var(--ui-danger);
        }

        .team-money.orange {
            color: var(--ui-accent-text);
        }

        .team-money.purple {
            color: var(--ui-accent-text);
        }

        .team-money.blue {
            color: var(--ui-accent-text);
        }

        .team-empty {
            padding: 58px 20px !important;
            background: var(--ui-surface) !important;
            text-align: center !important;
            color: var(--ui-muted) !important;
        }

        .team-empty-box {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .team-empty-icon {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            background: var(--ui-field);
            color: var(--ui-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .team-loading {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 30;
            display: none;
            align-items: center;
            background: var(--ui-field);
            color: var(--ui-on-accent);
            border-radius: 999px;
            padding: 10px 16px;
            font-size: 12px;
            font-weight: 900;
            box-shadow: none;
        }

        .team-loading.show {
            display: inline-flex;
        }

        .team-loading-dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: var(--ui-success);
            margin-right: 8px;
            animation: pulse 1s infinite ease-in-out;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 0.35;
                transform: scale(0.86);
            }

            50% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @media (max-width: 1180px) {
            .team-grid {
                grid-template-columns: 1fr;
            }

            .team-summary {
                grid-template-columns: repeat(2, minmax(170px, 1fr));
            }
        }

        @media (max-width: 720px) {
            .team-page {
                padding: 14px;
            }

            .team-header {
                flex-direction: column;
            }

            .team-actions {
                justify-content: flex-start;
            }

            .team-summary {
                grid-template-columns: 1fr;
            }

            .team-path-card {
                align-items: flex-start;
                flex-direction: column;
            }

            .team-title {
                font-size: 21px;
            }

            .team-card-head {
                align-items: flex-start;
                flex-direction: column;
            }
        }
     .team-loading {color:var(--ui-text)}
        .team-title,.team-subtitle,.team-card-title,.team-card-desc,.team-button {overflow-wrap:anywhere}
        .team-table-wrap {max-width:100%;overflow:auto}
        .team-card,.team-grid,.team-shell {min-width:0}
        .team-page {padding-bottom:calc(20px + env(safe-area-inset-bottom))}
    </style>
</head>
<body class="{{ $themeMode }}">
    <div class="team-page">
        <div class="team-shell">
            <div class="team-header">
                <div>
                    <button type="button" class="team-back" onclick="goBack()">
                        <span>←</span>
                        <span>{{ __('返回上一级') }}</span>
                    </button>

                    <h1 class="team-title" id="currentTitle">{{ __('团队层级') }}</h1>
                    <div class="team-subtitle" id="currentSubtitle">{{ __('点击用户行可进入下一层级。') }}</div>
                </div>

                <div class="team-actions">
                    <button type="button" class="team-button" onclick="refreshCurrent()">{{ __('刷新数据') }}</button>
                </div>
            </div>

            <div class="team-path-card">
                <div class="team-path-label">{{ __('当前路径') }}</div>
                <div class="team-path" id="teamPath"></div>
            </div>

            <div class="team-summary">
                <div class="team-stat green">
                    <div class="team-stat-label">{{ __('总入金') }}</div>
                    <div class="team-stat-value" id="summaryDeposit">0</div>
                </div>

                <div class="team-stat red">
                    <div class="team-stat-label">{{ __('总出金') }}</div>
                    <div class="team-stat-value" id="summaryWithdrawal">0</div>
                </div>

                <div class="team-stat purple">
                    <div class="team-stat-label">{{ __('充提差') }}</div>
                    <div class="team-stat-value" id="summaryDepositDiff">0</div>
                </div>

                <div class="team-stat orange">
                    <div class="team-stat-label">{{ __('余额') }}</div>
                    <div class="team-stat-value" id="summaryWallet">0</div>
                </div>

                <div class="team-stat blue">
                    <div class="team-stat-label">{{ __('交易') }}</div>
                    <div class="team-stat-value" id="summaryTrade">0</div>
                </div>
            </div>

            <div class="team-grid">
                <div class="team-card">
                    <div class="team-card-head">
                        <div>
                            <div class="team-card-title">{{ __('团队成员') }}</div>
                            <div class="team-card-desc">{{ __('昵称、用户ID、用户名与注册时间') }}</div>
                        </div>
                        <div class="team-pill" id="memberCount">{{ __('0 人') }}</div>
                    </div>

                    <div class="team-table-wrap">
                        <table class="team-table">
                            <thead>
                                <tr>
                                    <th style="width: 34%;">{{ __('昵称') }}</th>
                                    <th style="width: 14%;">{{ __('用户ID') }}</th>
                                    <th style="width: 30%;">{{ __('用户名') }}</th>
                                    <th style="width: 22%;">{{ __('注册时间') }}</th>
                                </tr>
                            </thead>
                            <tbody id="memberRows"></tbody>
                        </table>
                    </div>
                </div>

                <div class="team-card">
                    <div class="team-card-head">
                        <div>
                            <div class="team-card-title">{{ __('资金数据') }}</div>
                            <div class="team-card-desc">{{ __('总入金、总出金、充提差、余额与交易账户') }}</div>
                        </div>
                        <div class="team-pill">{{ __('财务概览') }}</div>
                    </div>

                    <div class="team-table-wrap">
                        <table class="team-table">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">{{ __('总入金') }}</th>
                                    <th style="width: 20%;">{{ __('总出金') }}</th>
                                    <th style="width: 20%;">{{ __('充提差') }}</th>
                                    <th style="width: 20%;">{{ __('余额') }}</th>
                                    <th style="width: 20%;">{{ __('交易') }}</th>
                                </tr>
                            </thead>
                            <tbody id="financeRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="team-loading" id="teamLoading">
                <span class="team-loading-dot"></span>
                {{ __('加载中...') }}
            </div>
        </div>
    </div>

    <script>
        const teamMessages = {{ Illuminate\Support\Js::from(['0 人' => __('0 人'), '{count} 人' => __('{count} 人'), '交易' => __('交易'), '余额' => __('余额'), '充提差' => __('充提差'), '刷新数据' => __('刷新数据'), '加载中...' => __('加载中...'), '可查看下级' => __('可查看下级'), '团队层级' => __('团队层级'), '团队成员' => __('团队成员'), '当前团队直属下级 {count} 人，点击用户行可进入下一层级。' => __('当前团队直属下级 {count} 人，点击用户行可进入下一层级。'), '当前路径' => __('当前路径'), '总入金' => __('总入金'), '总入金、总出金、充提差、余额与交易账户' => __('总入金、总出金、充提差、余额与交易账户'), '总出金' => __('总出金'), '昵称' => __('昵称'), '昵称、用户ID、用户名与注册时间' => __('昵称、用户ID、用户名与注册时间'), '暂无下级' => __('暂无下级'), '暂无下级用户' => __('暂无下级用户'), '暂无资金数据' => __('暂无资金数据'), '注册时间' => __('注册时间'), '点击用户行可进入下一层级。' => __('点击用户行可进入下一层级。'), '用户ID' => __('用户ID'), '用户名' => __('用户名'), '获取团队数据失败' => __('获取团队数据失败'), '财务概览' => __('财务概览'), '资金数据' => __('资金数据'), '返回上一级' => __('返回上一级')]) }};
        function teamText(key, values = {}) { return (teamMessages[key] || key).replace(/\{(\w+)\}/g, (token, name) => values[name] === undefined ? token : String(values[name])); }

        const initialData = {{ Illuminate\Support\Js::from($initialData) }};

        let current = initialData.parent || null;
        let rows = Array.isArray(initialData.children) ? initialData.children : [];
        let summary = initialData.summary || {};
        let trail = current ? [current] : [];

        function escapeHtml(value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getDisplayName(user) {
            if (!user) {
                return '-';
            }

            return user.nickname
                || user.display_name
                || user.email
                || user.phone
                || user.name
                || user.wallet_id
                || ('UID ' + user.id);
        }

        function getAccountName(user) {
            if (!user) {
                return '-';
            }

            return user.email
                || user.phone
                || user.wallet_id
                || user.name
                || '-';
        }

        function money(value) {
            if (value === null || value === undefined || value === '') {
                return '0';
            }

            const number = Number(String(value).replace(/,/g, ''));

            if (Number.isNaN(number)) {
                return escapeHtml(value);
            }

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
            });
        }

        function setLoading(show) {
            document.getElementById('teamLoading').classList.toggle('show', !!show);
        }

        function render() {
            document.getElementById('currentTitle').innerText = getDisplayName(current);
            document.getElementById('currentSubtitle').innerText = teamText('当前团队直属下级 {count} 人，点击用户行可进入下一层级。', {count: rows.length});

            document.getElementById('summaryDeposit').innerHTML = money(summary.total_deposit || 0);
            document.getElementById('summaryWithdrawal').innerHTML = money(summary.total_withdrawal || 0);
            document.getElementById('summaryDepositDiff').innerHTML = money(summary.deposit_withdrawal_diff || 0);
            document.getElementById('summaryWallet').innerHTML = money(summary.wallet_balance || 0);
            document.getElementById('summaryTrade').innerHTML = money(summary.trade_balance || 0);
            document.getElementById('memberCount').innerText = teamText('{count} 人', {count: rows.length});

            renderPath();
            renderTables();
        }

        function renderPath() {
            const box = document.getElementById('teamPath');

            if (!trail.length) {
                box.innerHTML = '';
                return;
            }

            box.innerHTML = trail.map((item, index) => {
                return '<button type="button" onclick="goTrail(' + index + ')">' + escapeHtml(getDisplayName(item)) + '</button>';
            }).join('');
        }

        function renderTables() {
            const memberRows = document.getElementById('memberRows');
            const financeRows = document.getElementById('financeRows');

            if (!rows.length) {
                memberRows.innerHTML = '<tr><td colspan="4" class="team-empty"><div class="team-empty-box"><div class="team-empty-icon">∅</div><div>' + escapeHtml(teamText('暂无下级用户')) + '</div></div></td></tr>';
                financeRows.innerHTML = '<tr><td colspan="5" class="team-empty"><div class="team-empty-box"><div class="team-empty-icon">∅</div><div>' + escapeHtml(teamText('暂无资金数据')) + '</div></div></td></tr>';
                return;
            }

            memberRows.innerHTML = rows.map(item => {
                const hasChildren = !!item.has_children;

                return ''
                    + '<tr class="team-row ' + (hasChildren ? 'clickable' : '') + '" onclick="loadLevelById(' + Number(item.id) + ')">'
                    + '<td>'
                    + '<div class="team-member">'
                    + '<span class="team-arrow ' + (hasChildren ? 'active' : '') + '">' + (hasChildren ? '›' : '·') + '</span>'
                    + '<div class="team-member-main">'
                    + '<div class="team-member-name">' + escapeHtml(item.nickname || item.name || item.email || item.phone || '-') + '</div>'
                    + '<div class="team-member-tip">' + (hasChildren ? teamText('可查看下级') : teamText('暂无下级')) + '</div>'
                    + '</div>'
                    + '</div>'
                    + '</td>'
                    + '<td><span class="team-user-id">' + escapeHtml(item.id) + '</span></td>'
                    + '<td><div class="team-clip">' + escapeHtml(getAccountName(item)) + '</div></td>'
                    + '<td><span class="team-time">' + escapeHtml(item.created_at || '-') + '</span></td>'
                    + '</tr>';
            }).join('');

            financeRows.innerHTML = rows.map(item => {
                return ''
                    + '<tr class="team-row ' + (item.has_children ? 'clickable' : '') + '" onclick="loadLevelById(' + Number(item.id) + ')">'
                    + '<td><span class="team-money green">' + money(item.total_deposit || 0) + '</span></td>'
                    + '<td><span class="team-money red">' + money(item.total_withdrawal || 0) + '</span></td>'
                    + '<td><span class="team-money purple">' + money(item.deposit_withdrawal_diff || 0) + '</span></td>'
                    + '<td><span class="team-money orange">' + money(item.wallet_balance || 0) + '</span></td>'
                    + '<td><span class="team-money blue">' + money(item.trade_balance || 0) + '</span></td>'
                    + '</tr>';
            }).join('');
        }

        function loadLevelById(id) {
            const user = rows.find(item => Number(item.id) === Number(id));

            if (!user) {
                return;
            }

            loadLevel(user);
        }

        function loadLevel(user) {
            if (!user || !user.id) {
                return;
            }

            setLoading(true);

            const url = initialData.apiUrl + '?parent_id=' + encodeURIComponent(user.id) + '&t=' + Date.now();

            fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            })
                .then(response => response.json())
                .then(data => {
                    current = data.parent || user;
                    rows = Array.isArray(data.children) ? data.children : [];
                    summary = data.summary || {};

                    const existsIndex = trail.findIndex(item => Number(item.id) === Number(user.id));

                    if (existsIndex >= 0) {
                        trail = trail.slice(0, existsIndex + 1);
                        trail[existsIndex] = current;
                    } else {
                        trail.push(current);
                    }

                    render();
                })
                .catch(() => {
                    alert(teamText('获取团队数据失败'));
                })
                .finally(() => {
                    setLoading(false);
                });
        }

        function goTrail(index) {
            if (index < 0 || index >= trail.length) {
                return;
            }

            const user = trail[index];

            trail = trail.slice(0, index + 1);
            loadLevel(user);
        }

        function goBack() {
            if (trail.length <= 1) {
                window.close();

                if (!window.closed) {
                    history.back();
                }

                return;
            }

            trail.pop();

            const user = trail[trail.length - 1];

            loadLevel(user);
        }

        function refreshCurrent() {
            if (!current || !current.id) {
                return;
            }

            loadLevel(current);
        }

        render();
    </script>
</body>
</html>
