/**
 * 主页在线工具交互脚本（原生 JavaScript，零依赖）。
 *
 * 职责：
 *   - 单台查询（/api/ping）与批量查询（/api/ping/batch）切换与提交；
 *   - 结果渲染：单查卡片（favicon / 彩色 MOTD / 人数 / 版本 / 延迟色阶 / 玩家列表 / 徽章）、
 *     批量紧凑卡片与顶部汇总、加载骨架屏、友好错误卡片；
 *   - 导航健康徽章（/health）轮询；
 *   - 代码块复制按钮（主页快速开始 + 文档页共用）。
 *
 * XSS 安全约定（务必遵守）：
 *   - 唯一允许 innerHTML 的是 motd.html 字段（服务端已做完整 XSS 转义）；
 *   - 其余所有动态文本一律使用 textContent 或 DOM API 赋值，禁止拼接 innerHTML；
 *   - 创建元素使用 document.createElement + 属性赋值，不解析用户输入字符串。
 */
'use strict';

(function () {
  /* ---------- 小工具 ---------- */
  const $ = (selector, root) => (root || document).querySelector(selector);
  const $$ = (selector, root) => Array.from((root || document).querySelectorAll(selector));

  // 子目录部署支持：由页面注入的基础路径前缀（如 '/mcstatus'），为空表示部署在域名根目录。
  const BASE = (window.MCAPI_BASE || '').replace(/\/+$/, '');

  /** 创建元素并设置文本（XSS 安全）。 */
  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  /** 创建 SVG 图标（通过 use 引用页面内联 defs）。 */
  function icon(name, className) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    if (className) {
      svg.setAttribute('class', className);
    }
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#icon-' + name);
    svg.appendChild(use);
    return svg;
  }

  /* ---------- 错误码提示表（hover 展示） ---------- */
  const ERROR_CODES = {
    1001: '参数无效：host 为空、超长或含非法字符，或端口不在 1-65535',
    1002: '连接超时：目标服务器无响应，请检查地址或稍后重试',
    1003: '连接被拒绝：端口未开放或服务器拒绝连接',
    1004: 'DNS 解析失败：域名不存在或无法解析',
    1005: '协议错误或响应异常：目标不是 Minecraft Java 版服务器',
    1006: '服务器离线：发生未知错误',
    1007: '请求过于频繁：触发限流，请稍后再试',
    1008: 'API Key 无效或缺失：需要携带合法密钥访问',
    1009: '批量参数无效：服务器数量超限或格式错误',
  };

  /* ---------- DOM 引用 ---------- */
  const resultArea = $('#result-area');
  const tabButtons = $$('.tab-btn');
  const singleForm = $('#single-form');
  const singleHost = $('#single-host');
  const singlePort = $('#single-port');
  const singleSubmit = $('#single-submit');
  const batchForm = $('#batch-form');
  const batchInput = $('#batch-input');
  const batchSubmit = $('#batch-submit');
  const batchClear = $('#batch-clear');
  const navToggle = $('.nav-toggle');
  const siteNav = $('.site-nav');
  const autoRefresh = $('#auto-refresh');
  const favList = $('#fav-list');
  const favClear = $('#fav-clear');
  const favView = $('#fav-view');

  /* ---------- 通用状态控制 ---------- */

  /** 设置查询按钮的加载态。 */
  function setLoading(button, loading) {
    if (!button) {
      return;
    }
    if (loading) {
      button.dataset.originalHtml = button.innerHTML;
      button.disabled = true;
      button.innerHTML = '';
      button.appendChild(icon('loader', 'spin'));
      button.appendChild(document.createTextNode('查询中'));
    } else {
      button.disabled = false;
      if (button.dataset.originalHtml) {
        button.innerHTML = button.dataset.originalHtml;
      }
    }
  }

  /** 清空结果区。 */
  function clearResults() {
    if (resultArea) {
      resultArea.innerHTML = '';
    }
  }

  /** 渲染骨架屏（加载占位）。 */
  function showSkeleton() {
    if (!resultArea) {
      return;
    }
    resultArea.innerHTML = '';
    for (let i = 0; i < 3; i++) {
      const card = el('div', 'skeleton-card');
      card.appendChild(el('div', 'skeleton-line short'));
      card.appendChild(el('div', 'skeleton-line mid'));
      card.appendChild(el('div', 'skeleton-line'));
      resultArea.appendChild(card);
    }
  }

  /**
   * 渲染友好错误卡片（业务错误与 HTTP 错误统一）。
   * @param {Object} err { httpStatus?, code?, message? }
   */
  function showError(err) {
    if (!resultArea) {
      return;
    }
    clearResults();
    const card = el('div', 'error-card');
    card.appendChild(icon('alert', 'error-icon'));

    const body = el('div');
    const title = el('h3');
    title.textContent = err.httpStatus ? '请求失败（HTTP ' + err.httpStatus + '）' : '查询失败';
    body.appendChild(title);

    const message = el('p');
    message.textContent = err.message || '未知错误';
    body.appendChild(message);

    if (err.code && ERROR_CODES[err.code]) {
      const codeHint = el('span', 'error-code', '错误码 ' + err.code);
      codeHint.setAttribute('data-hint', ERROR_CODES[err.code]);
      message.appendChild(codeHint);
    }

    card.appendChild(body);
    resultArea.appendChild(card);
  }

  /* ---------- 延迟色阶 ---------- */

  /** 延迟分级：<50 绿 / <150 黄 / 其余红；无法测量为未知。 */
  function latencyClass(latency) {
    if (latency === null || latency === undefined) {
      return 'unknown';
    }
    if (latency < 50) {
      return 'good';
    }
    if (latency < 150) {
      return 'mid';
    }
    return 'bad';
  }

  function latencyText(latency) {
    if (latency === null || latency === undefined) {
      return '延迟未知';
    }
    return String(latency) + ' ms';
  }

  /* ---------- 单台查询 ---------- */

  /** 提交单台查询。 */
  async function querySingle(host, port) {
    const params = new URLSearchParams({ host: host });
    if (port) {
      params.set('port', String(port));
    }
    let res;
    try {
      res = await fetch(BASE + '/api/ping?' + params.toString());
    } catch (networkError) {
      throw { message: '网络请求失败：' + networkError.message };
    }
    let body = null;
    try {
      body = await res.json();
    } catch (parseError) {
      body = null;
    }
    if (!res.ok || !body || body.success !== true) {
      const payload = body && typeof body === 'object' ? body : {};
      throw {
        httpStatus: res.status,
        code: payload.code,
        message: payload.message || '服务器返回异常响应',
      };
    }
    return body.data || {};
  }

  /**
   * 渲染单台查询结果卡片。
   * @param {Object} data /api/ping 成功响应的 data
   */
  function renderSingleResult(data) {
    clearResults();
    const card = el('div', 'result-card result-single');
    const online = data.online === true;

    /* MC 多人游戏列表式行：图标 | 标题(核心+版本) ... 在线/最大 右对齐，第二行 motd 缩进 */
    const rowEl = el('div', 'mc-server-row');

    const faviconBox = el('div', 'favicon-box mc-icon');
    const faviconUrl = data.favicon && data.favicon.url ? data.favicon.url : null;
    if (faviconUrl) {
      const img = document.createElement('img');
      img.src = faviconUrl;
      img.alt = '';
      img.addEventListener('error', function () {
        // 图片加载失败时替换为 CSS 草方块占位
        faviconBox.innerHTML = '';
        faviconBox.appendChild(el('div', 'grass-placeholder'));
      });
      faviconBox.appendChild(img);
    } else {
      faviconBox.appendChild(el('div', 'grass-placeholder'));
    }
    rowEl.appendChild(faviconBox);

    const info = el('div', 'mc-info');
    const top = el('div', 'mc-top');
    const hostText = data.host
      ? String(data.host) + (data.port ? ':' + String(data.port) : '')
      : '未知服务器';
    const verName = data.version && data.version.name ? String(data.version.name) : '';
    const brand = data.version && data.version.brand ? String(data.version.brand) : '';
    top.appendChild(el('div', 'mc-title', verName || brand || hostText));

    const side = el('div', 'mc-side');
    if (online) {
      const onl = data.players && data.players.online !== null && data.players.online !== undefined ? String(data.players.online) : '?';
      const mx = data.players && data.players.max !== null && data.players.max !== undefined ? String(data.players.max) : '?';
      side.appendChild(el('span', 'mc-players', onl + '/' + mx));
    } else {
      const status = el('span', 'mc-status offline');
      status.appendChild(icon('alert', ''));
      status.appendChild(document.createTextNode('离线'));
      side.appendChild(status);
    }
    side.appendChild(el('span', 'mc-latency ' + (online ? latencyClass(data.latency_ms) : 'unknown'), latencyText(data.latency_ms)));
    top.appendChild(side);
    info.appendChild(top);

    /* MOTD（第二行，缩进与标题对齐）服务端已 XSS 转义 */
    const motd = el('div', 'mc-motd');
    const motdHtml = data.motd && data.motd.html ? data.motd.html : '';
    const motdPlain = data.motd && data.motd.plain_text ? data.motd.plain_text : '';
    if (motdHtml) {
      motd.innerHTML = motdHtml;
    } else if (motdPlain) {
      motd.appendChild(el('span', '', motdPlain));
    } else {
      motd.appendChild(el('span', 'motd-empty', '（该服务器未提供 MOTD）'));
    }
    info.appendChild(motd);

    rowEl.appendChild(info);
    card.appendChild(rowEl);

    /* 统计：在线/最大/版本/协议/核心 */
    const stats = el('div', 'stats-row');
    stats.appendChild(makeStat('在线人数', data.players && data.players.online !== null && data.players.online !== undefined ? String(data.players.online) : '未知', 'accent', 'users'));
    stats.appendChild(makeStat('最大人数', data.players && data.players.max !== null && data.players.max !== undefined ? String(data.players.max) : '未知', 'accent', 'group'));
    stats.appendChild(makeStat('版本', data.version && data.version.name ? String(data.version.name) : '未知', '', 'layers'));
    stats.appendChild(makeStat('协议号', data.version && data.version.protocol !== null && data.version.protocol !== undefined ? String(data.version.protocol) : '未知', '', 'tag'));
    stats.appendChild(makeStat('核心', data.version && data.version.brand ? String(data.version.brand) : '未知', '', 'badge'));
    card.appendChild(stats);

    /* 徽章行 */
    const badgeRow = el('div', 'badge-row');

    if (data.protocol_used) {
      badgeRow.appendChild(makeBadge(String(data.protocol_used), 'accent'));
    }
    if (data.srv_used === true) {
      const srv = data.srv_record;
      const srvText = srv && srv.target
        ? 'SRV: ' + String(srv.target) + ':' + String(srv.port)
        : 'SRV 自动解析';
      badgeRow.appendChild(makeBadge(srvText, 'accent'));
    } else if (data.srv_used === false && data.srv_record === null) {
      badgeRow.appendChild(makeBadge('未使用 SRV', ''));
    }
    if (data.secure_chat && data.secure_chat.enforces === true) {
      badgeRow.appendChild(makeBadge('Secure Chat 强制', 'good'));
    } else if (data.secure_chat && data.secure_chat.enforces === false) {
      badgeRow.appendChild(makeBadge('Secure Chat 未强制', ''));
    }
    if (data.secure_chat && data.secure_chat.previews === true) {
      badgeRow.appendChild(makeBadge('聊天预览', ''));
    }
    card.appendChild(badgeRow);

    /* 缓存提示 */
    if (data.cached === true) {
      const cacheNote = el('span', 'cache-note');
      cacheNote.appendChild(icon('zap', ''));
      cacheNote.appendChild(document.createTextNode('本次结果来自缓存'));
      card.appendChild(cacheNote);
    }

    /* 玩家列表 */
    const sample = data.players && Array.isArray(data.players.sample) ? data.players.sample : [];
    if (sample.length > 0) {
      const listBox = el('div', 'player-list');
      listBox.appendChild(el('div', 'player-list-title', '玩家列表（' + String(sample.length) + '）'));
      const rows = el('div', 'player-rows');
      sample.forEach(function (player) {
        const name = player && player.name ? String(player.name) : '未知玩家';
        const uuid = player && player.uuid ? String(player.uuid) : '';
        const row = el('div', 'player-row');
        const avatar = document.createElement('img');
        avatar.className = 'player-avatar';
        avatar.alt = '';
        if (uuid) {
          avatar.src = BASE + '/avatar/' + encodeURIComponent(uuid) + '.png';
          avatar.addEventListener('error', function () {
            avatar.style.display = 'none';
          });
        } else {
          avatar.style.display = 'none';
        }
        row.appendChild(avatar);
        row.appendChild(el('span', 'player-name', name));
        if (uuid) {
          row.appendChild(el('span', 'player-uuid', uuid));
        }
        rows.appendChild(row);
      });
      listBox.appendChild(rows);
      card.appendChild(listBox);
    }

    /* 操作按钮：收藏当前 + 复制分享链接 */
    const actions = el('div', 'card-actions');
    const favBtn = el('button', 'btn btn-ghost btn-xs' + (isFavorite(data.host, data.port) ? ' fav-on' : ''), isFavorite(data.host, data.port) ? '已收藏' : '收藏');
    favBtn.type = 'button';
    favBtn.addEventListener('click', function () {
      if (isFavorite(data.host, data.port)) {
        removeFavorite(data.host, data.port);
        favBtn.textContent = '收藏';
        favBtn.classList.remove('fav-on');
      } else {
        addFavorite(data.host, data.port);
        favBtn.textContent = '已收藏';
        favBtn.classList.add('fav-on');
      }
    });
    actions.appendChild(favBtn);
    const shareBtn = el('button', 'btn btn-ghost btn-xs', '复制分享链接');
    shareBtn.type = 'button';
    shareBtn.addEventListener('click', function () {
      const url = buildShareUrl('single', data.host, data.port);
      copyText(url).then(function () {
        shareBtn.textContent = '已复制';
        window.setTimeout(function () { shareBtn.textContent = '复制分享链接'; }, 1500);
      });
    });
    actions.appendChild(shareBtn);
    const trendBtn = el('button', 'btn btn-ghost btn-xs', '在线人数趋势');
    trendBtn.type = 'button';
    trendBtn.addEventListener('click', function () {
      renderTrendChart(data.host, data.port);
    });
    actions.appendChild(trendBtn);
    card.appendChild(actions);

    resultArea.appendChild(card);
  }

  /** 构造单个统计块（可选图标名）。 */
  function makeStat(label, value, extraClass, iconName) {
    const stat = el('div', 'stat');
    if (iconName) {
      stat.appendChild(icon(iconName, 'stat-icon'));
    }
    stat.appendChild(el('span', 'stat-label', label));
    const valueNode = el('span', 'stat-value' + (extraClass ? ' ' + extraClass : ''), value);
    stat.appendChild(valueNode);
    return stat;
  }

  /** 构造小徽章。 */
  function makeBadge(text, tone) {
    const badge = el('span', 'mini-badge' + (tone ? ' ' + tone : ''));
    if (tone === 'accent') {
      badge.appendChild(icon('check', ''));
    }
    badge.appendChild(document.createTextNode(text));
    return badge;
  }

  /* ---------- 批量查询 ---------- */

  /**
   * 解析单行服务器地址：host 或 host:port；支持 [IPv6]:port 与裸 IPv6。
   * @returns {{host: string, port?: number}|null}
   */
  function parseServerLine(line) {
    const trimmed = line.trim();
    if (!trimmed) {
      return null;
    }
    // [IPv6]:port 或 [IPv6]
    const bracketed = /^\[([^\]]+)\](?::(\d{1,5}))?$/.exec(trimmed);
    if (bracketed) {
      return {
        host: bracketed[1],
        port: bracketed[2] ? Number(bracketed[2]) : undefined,
      };
    }
    const colonCount = (trimmed.match(/:/g) || []).length;
    if (colonCount === 0) {
      return { host: trimmed, port: undefined };
    }
    if (colonCount >= 2) {
      // 裸 IPv6：整体作为 host，不带端口
      return { host: trimmed, port: undefined };
    }
    // host:port
    const idx = trimmed.lastIndexOf(':');
    const host = trimmed.slice(0, idx);
    const portStr = trimmed.slice(idx + 1);
    if (host && /^\d{1,5}$/.test(portStr)) {
      const port = Number(portStr);
      if (port >= 1 && port <= 65535) {
        return { host: host, port: port };
      }
    }
    // 端口非法：整行按 host 处理
    return { host: trimmed, port: undefined };
  }

  /** 提交批量查询。 */
  async function queryBatch(servers) {
    let res;
    try {
      res = await fetch(BASE + '/api/ping/batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ servers: servers }),
      });
    } catch (networkError) {
      throw { message: '网络请求失败：' + networkError.message };
    }
    let body = null;
    try {
      body = await res.json();
    } catch (parseError) {
      body = null;
    }
    if (!res.ok || !body || body.success !== true) {
      const payload = body && typeof body === 'object' ? body : {};
      throw {
        httpStatus: res.status,
        code: payload.code,
        message: payload.message || '服务器返回异常响应',
      };
    }
    return body.data || {};
  }

  /**
   * 渲染批量查询结果。
   * @param {Object} data /api/ping/batch 成功响应的 data
   */
  function renderBatchResult(data) {
    clearResults();

    const results = Array.isArray(data.results) ? data.results : [];
    const total = data.total !== undefined ? data.total : results.length;
    const success = data.success !== undefined ? data.success : 0;
    const failed = data.failed !== undefined ? data.failed : 0;

    /* 汇总条 */
    const summary = el('div', 'batch-summary');
    summary.appendChild(makeSummaryChip('total', '总计', String(total)));
    summary.appendChild(makeSummaryChip('ok', '成功', String(success)));
    summary.appendChild(makeSummaryChip('fail', '失败', String(failed)));
    resultArea.appendChild(summary);

    /* 操作按钮：导出 CSV + 复制分享链接 */
    const bactions = el('div', 'card-actions');
    const csvBtn = el('button', 'btn btn-ghost btn-xs', '导出 CSV');
    csvBtn.type = 'button';
    csvBtn.addEventListener('click', function () { exportCsv(results); });
    bactions.appendChild(csvBtn);
    const bshareBtn = el('button', 'btn btn-ghost btn-xs', '复制分享链接');
    bshareBtn.type = 'button';
    bshareBtn.addEventListener('click', function () {
      const url = buildShareUrl('batch');
      copyText(url).then(function () {
        bshareBtn.textContent = '已复制';
        window.setTimeout(function () { bshareBtn.textContent = '复制分享链接'; }, 1500);
      });
    });
    bactions.appendChild(bshareBtn);
    resultArea.appendChild(bactions);

    if (results.length === 0) {
      resultArea.appendChild(el('div', 'result-card', '没有可展示的结果'));
      return;
    }

    /* 每台服务器一张紧凑卡片 */
    results.forEach(function (item, index) {
      const itemOnline = item && item.online === true;
      const hasError = !itemOnline && item && item.error;
      const card = el('div', 'result-card batch-item ' + (itemOnline ? 'batch-ok' : 'batch-fail'));

      const hostText = item && item.host
        ? String(item.host) + (item.port ? ':' + String(item.port) : '')
        : '未知服务器';

      /* MC 多人游戏列表式行：图标 | 标题(核心+版本) ... 在线/最大 右对齐，第二行 motd 缩进 */
      const rowEl = el('div', 'mc-server-row');

      const faviconBox = el('div', 'favicon-box mc-icon');
      const faviconUrl = itemOnline && item.favicon && item.favicon.url ? item.favicon.url : null;
      if (faviconUrl) {
        const im = document.createElement('img');
        im.src = faviconUrl;
        im.alt = '';
        im.addEventListener('error', function () {
          faviconBox.innerHTML = '';
          faviconBox.appendChild(el('div', 'grass-placeholder'));
        });
        faviconBox.appendChild(im);
      } else {
        faviconBox.appendChild(el('div', 'grass-placeholder'));
      }
      rowEl.appendChild(faviconBox);

      const binfo = el('div', 'mc-info');
      const btop = el('div', 'mc-top');
      const bVerName = itemOnline && item.version && item.version.name ? String(item.version.name) : '';
      const bBrand = itemOnline && item.version && item.version.brand ? String(item.version.brand) : '';
      btop.appendChild(el('div', 'mc-title', bVerName || bBrand || hostText));

      const bside = el('div', 'mc-side');
      if (itemOnline) {
        const bOnl = item.players && item.players.online !== null && item.players.online !== undefined ? String(item.players.online) : '?';
        const bMax = item.players && item.players.max !== null && item.players.max !== undefined ? String(item.players.max) : '?';
        bside.appendChild(el('span', 'mc-players', bOnl + '/' + bMax));
        if (item.latency_ms !== null && item.latency_ms !== undefined) {
          bside.appendChild(el('span', 'mc-latency ' + latencyClass(item.latency_ms), latencyText(item.latency_ms)));
        }
        if (item.cached === true) {
          bside.appendChild(el('span', 'mc-cache', '缓存'));
        }
      } else if (hasError && item.error) {
        bside.appendChild(el('span', 'mc-status offline', '错误 ' + String(item.error.code)));
      } else {
        bside.appendChild(el('span', 'mc-status offline', '离线'));
      }
      btop.appendChild(bside);
      binfo.appendChild(btop);

      /* MOTD（第二行，缩进与标题对齐）服务端已转义 */
      if (itemOnline && item.motd && item.motd.html) {
        const motd = el('div', 'mc-motd');
        motd.innerHTML = item.motd.html;
        binfo.appendChild(motd);
      }

      rowEl.appendChild(binfo);
      card.appendChild(rowEl);

      /* 失败信息 */
      if (hasError && item.error) {
        const errorBox = el('div', 'batch-error');
        const code = el('span', 'batch-code', '错误码 ' + String(item.error.code) + '：');
        if (ERROR_CODES[item.error.code]) {
          code.setAttribute('data-hint', ERROR_CODES[item.error.code]);
        }
        errorBox.appendChild(code);
        errorBox.appendChild(document.createTextNode(item.error.message || '未知错误'));
        card.appendChild(errorBox);
      }

      resultArea.appendChild(card);
    });
  }

  /** 构造汇总块。 */
  function makeSummaryChip(kind, label, value) {
    const chip = el('span', 'summary-chip ' + kind);
    if (kind === 'ok') {
      chip.appendChild(icon('check', ''));
    } else if (kind === 'fail') {
      chip.appendChild(icon('alert', ''));
    } else {
      chip.appendChild(icon('server', ''));
    }
    chip.appendChild(document.createTextNode(label + ' '));
    chip.appendChild(el('span', 'chip-num', value));
    return chip;
  }

  /* ---------- 表单提交 ---------- */

  /** 单台查询提交。 */
  async function handleSingleSubmit(event) {
    if (event) {
      event.preventDefault();
    }
    const host = singleHost ? singleHost.value.trim() : '';
    if (!host) {
      showError({ message: '请输入服务器地址（例如 mc.goldenapplepie.xyz）' });
      if (singleHost) {
        singleHost.focus();
      }
      return;
    }
    const portRaw = singlePort ? singlePort.value.trim() : '';
    let port = '';
    if (portRaw) {
      const portNum = Number(portRaw);
      if (!Number.isInteger(portNum) || portNum < 1 || portNum > 65535) {
        showError({ message: '端口必须是 1-65535 的整数，或留空使用默认端口 25565' });
        if (singlePort) {
          singlePort.focus();
        }
        return;
      }
      port = String(portNum);
    }

    setLoading(singleSubmit, true);
    showSkeleton();
    try {
      const data = await querySingle(host, port);
      renderSingleResult(data);
      recordLastQuery(function () { handleSingleSubmit(); });
    } catch (err) {
      showError(err);
    } finally {
      setLoading(singleSubmit, false);
    }
  }

  /** 批量查询提交。 */
  async function handleBatchSubmit() {
    const raw = batchInput ? batchInput.value : '';
    const lines = raw.split('\n');
    const servers = [];
    lines.forEach(function (line) {
      const parsed = parseServerLine(line);
      if (parsed) {
        servers.push(parsed);
      }
    });
    if (servers.length === 0) {
      showError({ message: '请至少输入一个服务器地址，每行一个（支持 host 或 host:port）' });
      if (batchInput) {
        batchInput.focus();
      }
      return;
    }

    setLoading(batchSubmit, true);
    showSkeleton();
    try {
      lastBatchServers = servers;
      const data = await queryBatch(servers);
      renderBatchResult(data);
      recordLastQuery(function () { handleBatchSubmit(); });
    } catch (err) {
      showError(err);
    } finally {
      setLoading(batchSubmit, false);
    }
  }

  /* ---------- Tab 切换 ---------- */

  function switchTab(mode) {
    tabButtons.forEach(function (btn) {
      const isActive = btn.dataset.mode === mode;
      btn.classList.toggle('active', isActive);
    });
    if (singleForm) {
      singleForm.classList.toggle('hidden', mode !== 'single');
    }
    if (batchForm) {
      batchForm.classList.toggle('hidden', mode !== 'batch');
    }
    if (favView) {
      favView.classList.toggle('hidden', mode !== 'fav');
    }
    renderFavorites();
    clearResults();
  }

  /* ---------- 收藏夹（localStorage） ---------- */

  const FAV_KEY = 'mcapi.favorites';

  function loadFavorites() {
    try {
      const raw = localStorage.getItem(FAV_KEY);
      const arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr)
        ? arr.filter(function (f) { return f && typeof f.host === 'string' && f.host.trim() !== ''; })
        : [];
    } catch (ignore) {
      return [];
    }
  }

  function saveFavorites(list) {
    try {
      localStorage.setItem(FAV_KEY, JSON.stringify(list));
    } catch (ignore) {
      /* localStorage 不可用时静默降级为不持久 */
    }
  }

  function favKeyOf(host, port) {
    return String(host || '').toLowerCase() + ':' + String(port || '');
  }

  function isFavorite(host, port) {
    return loadFavorites().some(function (f) { return favKeyOf(f.host, f.port) === favKeyOf(host, port); });
  }

  function addFavorite(host, port) {
    const list = loadFavorites();
    if (!list.some(function (f) { return favKeyOf(f.host, f.port) === favKeyOf(host, port); })) {
      list.push({ host: String(host || ''), port: String(port || '') });
      saveFavorites(list);
    }
    renderFavorites();
  }

  function removeFavorite(host, port) {
    const key = favKeyOf(host, port);
    saveFavorites(loadFavorites().filter(function (f) { return favKeyOf(f.host, f.port) !== key; }));
    renderFavorites();
  }

  function renderFavorites() {
    if (!favList) {
      return;
    }
    favList.innerHTML = '';
    const list = loadFavorites();
    if (list.length === 0) {
      favList.appendChild(el('div', 'fav-empty', '暂无收藏。在单台查询结果里点击「收藏」即可加入本列表。'));
      return;
    }
    list.forEach(function (fav) {
      const chip = el('span', 'fav-chip');
      const label = el('span', 'fav-chip-label', fav.host + (fav.port ? ':' + fav.port : ''));
      chip.appendChild(label);
      const go = el('button', 'fav-chip-btn', '查询');
      go.type = 'button';
      go.addEventListener('click', function () { queryFavorite(fav.host, fav.port); });
      chip.appendChild(go);
      const del = el('button', 'fav-chip-del', '×');
      del.type = 'button';
      del.title = '移除收藏';
      del.addEventListener('click', function () { removeFavorite(fav.host, fav.port); });
      chip.appendChild(del);
      favList.appendChild(chip);
    });
  }

  function queryFavorite(host, port) {
    switchTab('single');
    if (singleHost) {
      singleHost.value = host;
    }
    if (singlePort) {
      singlePort.value = port || '';
    }
    handleSingleSubmit();
  }

  /* ---------- 自动刷新 ---------- */

  let lastQuery = null;   // { run: Function } 最近一次成功的查询（单查或批量）
  let refreshTimer = null;
  let lastBatchServers = []; // 最近一次批量查询的输入（用于分享链接）

  function recordLastQuery(run) {
    lastQuery = { run: run };
    applyAutoRefresh();
  }

  function applyAutoRefresh() {
    if (refreshTimer) {
      window.clearInterval(refreshTimer);
      refreshTimer = null;
    }
    if (!autoRefresh) {
      return;
    }
    const secs = parseInt(autoRefresh.value, 10) || 0;
    if (secs > 0 && lastQuery) {
      refreshTimer = window.setInterval(function () {
        if (document.hidden) {
          return; // 页面不可见时跳过，节省请求
        }
        lastQuery.run();
      }, secs * 1000);
    }
  }

  /* ---------- 分享链接 + CSV 导出 ---------- */

  /** 构造可分享的首页链接（首页支持 ?host= 与 ?servers= 自动查询）。 */
  function buildShareUrl(mode, host, port) {
    const base = location.origin + BASE + '/';
    if (mode === 'single') {
      const params = new URLSearchParams();
      if (host) {
        params.set('host', host);
      }
      if (port) {
        params.set('port', String(port));
      }
      return base + '?' + params.toString();
    }
    const params = new URLSearchParams();
    if (lastBatchServers.length > 0) {
      params.set('servers', lastBatchServers.map(function (s) {
        return s.host + (s.port ? ':' + s.port : '');
      }).join('\n'));
    }
    return base + '?' + params.toString();
  }

  /** 批量结果导出 CSV 并触发下载。 */
  function exportCsv(results) {
    const header = ['host', 'port', 'online', 'players', 'max', 'latency_ms', 'version', 'brand', 'motd', 'error_code', 'error_message'];
    const escapeCell = function (v) {
      const s = v === null || v === undefined ? '' : String(v);
      return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };
    const lines = [header.map(escapeCell).join(',')];
    (Array.isArray(results) ? results : []).forEach(function (item) {
      const online = item && item.online === true;
      const players = item && item.players ? item.players : {};
      const version = item && item.version ? item.version : {};
      const motd = item && item.motd ? (item.motd.plain_text || '') : '';
      const error = item && item.error ? item.error : {};
      lines.push([
        item ? item.host : '',
        item && item.port !== undefined ? item.port : '',
        online ? '1' : '0',
        players.online !== null && players.online !== undefined ? players.online : '',
        players.max !== null && players.max !== undefined ? players.max : '',
        item && item.latency_ms !== null && item.latency_ms !== undefined ? item.latency_ms : '',
        version.name || '',
        version.brand || '',
        motd,
        error.code || '',
        error.message || '',
      ].map(escapeCell).join(','));
    });
    const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'mc-servers-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  /* ---------- 在线人数趋势图（纯 SVG，基于 /api/monitor 历史数据） ---------- */

  function renderTrendChart(host) {
    if (!resultArea) {
      return;
    }
    // 移除旧的趋势容器（若存在）
    const oldTrend = document.getElementById('trend-container');
    if (oldTrend) {
      oldTrend.remove();
    }

    const container = el('div', 'trend-container');
    container.id = 'trend-container';
    container.appendChild(el('div', 'trend-title', '在线人数趋势'));
    container.appendChild(el('div', 'trend-loading', '加载中…'));
    resultArea.appendChild(container);

    const url = BASE + '/api/monitor?host=' + encodeURIComponent(String(host || '')) + '&limit=50';
    fetch(url)
      .then(function (res) { return res.json(); })
      .then(function (body) {
        const records = body && body.success === true && Array.isArray(body.records) ? body.records : [];
        // 数据库按时间倒序返回，反转成时间正序
        const series = records.slice().reverse();
        if (series.length < 2) {
          container.innerHTML = '';
          container.appendChild(el('div', 'trend-title', '在线人数趋势'));
          container.appendChild(el('div', 'trend-empty', '历史数据不足，暂无法绘制趋势图（需要至少 2 次有效监控记录）。'));
          return;
        }

        const W = 720;
        const H = 200;
        const PL = 36;
        const PR = 12;
        const PT = 14;
        const PB = 26;
        const iw = W - PL - PR;
        const ih = H - PT - PB;

        let maxY = 1;
        series.forEach(function (r) {
          const v = Number(r.online_players);
          if (!Number.isNaN(v) && v > maxY) {
            maxY = v;
          }
        });
        maxY = Math.ceil(maxY / 5) * 5; // 刻度向上取整到 5 的倍数

        const xOf = function (i) { return PL + (iw * i) / (series.length - 1); };
        const yOf = function (v) { return PT + ih - (Number(v) / maxY) * ih; };

        const fmt = function (t) {
          const d = new Date(t);
          return d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0');
        };
        const firstT = Number(series[0].checked_at || 0) * 1000;
        const lastT = Number(series[series.length - 1].checked_at || 0) * 1000;

        /* 水平网格线 + y 轴刻度（5 格） */
        let grid = '';
        for (let g = 0; g <= 4; g++) {
          const gy = PT + (ih * g) / 4;
          const val = Math.round(maxY - (maxY * g) / 4);
          grid += '<line x1="' + PL + '" y1="' + gy.toFixed(1) + '" x2="' + (W - PR) + '" y2="' + gy.toFixed(1) + '" class="trend-grid"/>';
          grid += '<text x="' + (PL - 6) + '" y="' + (gy + 4).toFixed(1) + '" text-anchor="end" class="trend-axis">' + String(val) + '</text>';
        }

        /* 折线 + 数据点 */
        const pts = series.map(function (r, i) {
          return xOf(i).toFixed(1) + ',' + yOf(Number(r.online_players)).toFixed(1);
        }).join(' ');
        const poly = '<polyline points="' + pts + '" class="trend-line"/>';
        let dots = '';
        series.forEach(function (r, i) {
          const v = Number(r.online_players);
          dots += '<circle cx="' + xOf(i).toFixed(1) + '" cy="' + yOf(v).toFixed(1) + '" r="2.5" class="trend-dot' + (v === 0 ? ' off' : '') + '"/>';
        });

        /* x 轴时间标签：首 / 中 / 尾 */
        const midIdx = series.length > 2 ? Math.floor(series.length / 2) : -1;
        let xTexts = '<text x="' + PL + '" y="' + (H - 8) + '" class="trend-axis">' + fmt(firstT) + '</text>';
        if (midIdx > 0 && midIdx < series.length - 1) {
          xTexts += '<text x="' + xOf(midIdx).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" class="trend-axis">' + fmt(Number(series[midIdx].checked_at || 0) * 1000) + '</text>';
        }
        xTexts += '<text x="' + (W - PR) + '" y="' + (H - 8) + '" text-anchor="end" class="trend-axis">' + fmt(lastT) + '</text>';

        container.innerHTML = '';
        container.appendChild(el('div', 'trend-title', '在线人数趋势（最近 ' + String(series.length) + ' 次检查）'));
        const svg = '<svg class="trend-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="在线人数趋势折线图">'
          + grid + poly + dots + xTexts + '</svg>';
        container.insertAdjacentHTML('beforeend', svg);
        container.appendChild(el('div', 'trend-foot', '峰值 ' + String(maxY) + ' 人 · 时间范围 ' + fmt(firstT) + ' ~ ' + fmt(lastT)));
      })
      .catch(function () {
        container.innerHTML = '';
        container.appendChild(el('div', 'trend-title', '在线人数趋势'));
        container.appendChild(el('div', 'trend-empty', '趋势数据加载失败，请稍后重试。'));
      });
  }

  /* ---------- 健康徽章 ---------- */

  async function refreshHealthBadge() {
    const link = $('.health-link');
    const heroBadge = $('#api-health-badge');
    let ok = false;
    try {
      const res = await fetch(BASE + '/health');
      const body = await res.json();
      ok = res.ok && body && body.success === true;
    } catch (healthError) {
      ok = false;
    }
    if (link) {
      link.classList.toggle('ok', ok);
      link.classList.toggle('bad', !ok);
    }
    if (heroBadge) {
      heroBadge.classList.toggle('ok', ok);
      heroBadge.classList.toggle('bad', !ok);
      const dot = heroBadge.querySelector('.badge-dot');
      if (dot) {
        dot.setAttribute('aria-hidden', 'true');
      }
      const label = heroBadge.querySelector('.badge-text');
      if (label) {
        label.textContent = ok ? 'API 在线' : 'API 异常';
      }
    }
  }

  /* ---------- 复制按钮 ---------- */

  /** 复制文本到剪贴板（带降级方案）。 */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      try {
        document.execCommand('copy');
        resolve();
      } catch (copyError) {
        reject(copyError);
      } finally {
        document.body.removeChild(textarea);
      }
    });
  }

  /** 点击复制按钮：复制所在代码块内容。 */
  function handleCopyClick(button) {
    const block = button.closest('.code-block') || button.closest('.qs-card');
    if (!block) {
      return;
    }
    const code = block.querySelector('pre code') || block.querySelector('pre');
    if (!code) {
      return;
    }
    const text = code.innerText || code.textContent || '';
    copyText(text)
      .then(function () {
        const original = button.textContent;
        button.textContent = '已复制';
        button.classList.add('copied');
        window.setTimeout(function () {
          button.textContent = original;
          button.classList.remove('copied');
        }, 1600);
      })
      .catch(function () {
        button.textContent = '复制失败';
        window.setTimeout(function () {
          button.textContent = '复制';
        }, 1600);
      });
  }

  /* ---------- 文档页增强：scrollspy / 平滑跳转 / 阅读进度 / reveal ---------- */

  /**
   * 文档页（/docs）交互增强：
   *   1) scrollspy：滚动正文时高亮当前章节目录项，并用滑动指示器平滑跟随；
   *   2) 点击目录项平滑跳转（带导航栏高度偏移）+ 落点标题柔和闪烁；
   *   3) 阅读进度条随正文滚动从左向右填充；
   *   4) 正文块进入视口淡入上移（reveal）、目录项入场微动画。
   * 首页无 .docs-content 时直接安全返回，绝不抛错。
   */
  function initDocsEnhancements() {
    // 守卫：无正文节点（首页）直接返回
    const content = $('.docs-content');
    if (!content) {
      return;
    }
    const toc = $('.docs-toc');
    const links = $$('.docs-toc a[href^="#"]');
    if (!toc || !links.length) {
      return;
    }

    // 滑动指示器（初始透明，由 CSS 控制）
    const indicator = el('span', 'toc-indicator');
    toc.appendChild(indicator);

    // 导航高度（用于跳转偏移与阈值判定）
    const navH = parseInt(
      getComputedStyle(document.documentElement).getPropertyValue('--nav-height'),
      10
    ) || 60;

    // 是否减弱动画（无障碍）
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // 阈值线：标题顶部越过该线即视为"已进入当前章节"
    const THRESHOLD = navH + 24;

    // 将指示器移动到指定目录项；link 为空则隐藏指示器
    function moveIndicator(link) {
      if (!link) {
        indicator.style.opacity = '0';
        return;
      }
      const top = link.offsetTop;
      const height = link.offsetHeight;
      indicator.style.transform = 'translateY(' + top + 'px)';
      indicator.style.height = height + 'px';
      indicator.style.opacity = '1';
    }

    // 根据 id 设置 active 类并移动指示器
    function setActive(id) {
      let activeLink = null;
      links.forEach(function (link) {
        const href = link.getAttribute('href') || '';
        const linkId = href.slice(1);
        if (linkId === id) {
          link.classList.add('active');
          activeLink = link;
        } else {
          link.classList.remove('active');
        }
      });
      moveIndicator(activeLink);
    }

    // 标题节点（按文档顺序）
    const headings = $$('.docs-content h2, .docs-content h3');

    /**
     * 由滚动位置计算当前活动章节：
     *   - 取文档顺序中，顶部已越过阈值线的最后一个标题；
     *   - 若没有任何标题越过阈值，取第一个仍在视口内的标题。
     */
    function computeActiveFromScroll() {
      const scrollY = window.pageYOffset;
      const vh = window.innerHeight;
      let activeId = null;

      // 1) 顶部已越过阈值线的最后一个标题
      for (let i = 0; i < headings.length; i++) {
        const h = headings[i];
        if (!h.id) {
          continue;
        }
        const top = h.getBoundingClientRect().top + scrollY;
        if (top - THRESHOLD <= scrollY) {
          activeId = h.id;
        } else {
          break;
        }
      }

      // 2) 兜底：取第一个仍在视口内的标题
      if (activeId === null) {
        for (let i = 0; i < headings.length; i++) {
          const h = headings[i];
          if (!h.id) {
            continue;
          }
          const top = h.getBoundingClientRect().top + scrollY;
          if (top <= scrollY + vh) {
            activeId = h.id;
            break;
          }
        }
      }

      if (activeId) {
        setActive(activeId);
      } else {
        links.forEach(function (link) {
          link.classList.remove('active');
        });
        moveIndicator(null);
      }
    }

    // scrollspy：IntersectionObserver 兜底（边缘情况 / 布局变化），主计算仍依赖滚动位置
    if ('IntersectionObserver' in window) {
      const spy = new IntersectionObserver(
        function () {
          computeActiveFromScroll();
        },
        {
          rootMargin: '-' + (THRESHOLD + 1) + 'px 0px -70% 0px',
          threshold: 0
        }
      );
      headings.forEach(function (h) {
        if (h.id) {
          spy.observe(h);
        }
      });
    }

    // 阅读进度条（若存在）
    const bar = $('.docs-progress-bar');

    function updateProgress() {
      if (!bar) {
        return;
      }
      const start = content.offsetTop;
      const total = Math.max(1, content.offsetHeight - window.innerHeight);
      const ratio = Math.min(1, Math.max(0, (window.pageYOffset - start) / total));
      bar.style.transform = 'scaleX(' + ratio + ')';
    }

    // 滚动处理（requestAnimationFrame 节流）
    let ticking = false;
    function onScroll() {
      if (ticking) {
        return;
      }
      ticking = true;
      window.requestAnimationFrame(function () {
        computeActiveFromScroll();
        updateProgress();
        ticking = false;
      });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);

    // 平滑跳转 + 落点标题闪烁
    links.forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        const href = link.getAttribute('href') || '';
        const id = href.slice(1);
        const target = document.getElementById(id);
        if (!target) {
          return;
        }
        const top = target.getBoundingClientRect().top + window.pageYOffset - (navH + 16);
        window.scrollTo({ top: Math.max(top, 0), behavior: reduce ? 'auto' : 'smooth' });
        if (history.replaceState) {
          history.replaceState(null, '', '#' + id);
        }
        target.classList.add('heading-flash');
        window.setTimeout(function () {
          target.classList.remove('heading-flash');
        }, 650);
        // 立即高亮，避免等待滚动事件
        setActive(id);
        moveIndicator(link);
      });
    });

    // 正文块进入视口淡入上移（减弱动画时直接显示，不加 reveal）
    if (!reduce) {
      const blocks = $$('.docs-content > *');
      blocks.forEach(function (block) {
        block.classList.add('reveal');
      });
      if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(
          function (entries, obs) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                entry.target.classList.add('reveal-in');
                obs.unobserve(entry.target);
              }
            });
          },
          { threshold: 0.08, rootMargin: '0px 0px -8% 0px' }
        );
        blocks.forEach(function (block) {
          revealObserver.observe(block);
        });
      } else {
        blocks.forEach(function (block) {
          block.classList.add('reveal-in');
        });
      }
    }

    // 目录项入场微动画（错峰延迟）；减弱动画时跳过
    if (!reduce) {
      const tocItems = $$('.docs-toc li');
      tocItems.forEach(function (li, i) {
        li.style.animationDelay = (i * 35) + 'ms';
      });
    }

    // 初次计算（避免首屏闪烁）
    computeActiveFromScroll();
    updateProgress();
  }

  /* ---------- 初始化与事件绑定 ---------- */

  function init() {
    // Tab 切换
    tabButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        switchTab(btn.dataset.mode || 'single');
      });
    });

    // 表单提交
    if (singleForm) {
      singleForm.addEventListener('submit', handleSingleSubmit);
    }
    if (batchSubmit) {
      batchSubmit.addEventListener('click', handleBatchSubmit);
    }
    if (batchClear && batchInput) {
      batchClear.addEventListener('click', function () {
        batchInput.value = '';
        clearResults();
        batchInput.focus();
      });
    }

    // 批量输入：Ctrl/Cmd + Enter 快捷提交
    if (batchInput) {
      batchInput.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
          event.preventDefault();
          handleBatchSubmit();
        }
      });
    }

    // 自动刷新间隔选择
    if (autoRefresh) {
      autoRefresh.addEventListener('change', applyAutoRefresh);
    }

    // 收藏夹清空
    if (favClear) {
      favClear.addEventListener('click', function () {
        saveFavorites([]);
        renderFavorites();
      });
    }
    renderFavorites();

    // 移动端导航折叠
    if (navToggle && siteNav) {
      navToggle.addEventListener('click', function () {
        siteNav.classList.toggle('open');
      });
      siteNav.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
          siteNav.classList.remove('open');
        }
      });
    }

    // 复制按钮（事件委托，兼容主页与文档页动态内容）
    document.addEventListener('click', function (event) {
      const button = event.target.closest('.copy-btn');
      if (button) {
        handleCopyClick(button);
      }
    });

    // 健康徽章：立即刷新 + 每 30 秒轮询
    refreshHealthBadge();
    window.setInterval(refreshHealthBadge, 30000);

    // 分享链接直达：?host= / ?servers= 自动发起查询
    const urlParams = new URLSearchParams(window.location.search);
    const urlHost = urlParams.get('host');
    const urlServers = urlParams.get('servers');
    if (urlHost) {
      switchTab('single');
      if (singleHost) {
        singleHost.value = urlHost;
      }
      if (singlePort && urlParams.get('port')) {
        singlePort.value = urlParams.get('port');
      }
      handleSingleSubmit();
    } else if (urlServers) {
      switchTab('batch');
      if (batchInput) {
        batchInput.value = urlServers;
      }
      handleBatchSubmit();
    }

    // 文档页增强（首页无 .docs-content 时内部安全返回）
    initDocsEnhancements();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
