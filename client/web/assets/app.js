'use strict';

// ===== 主题系统：预设 + 深浅模式 =====
// 切换通过给 <body> 加 class 实现，纯 CSS 变量驱动，切换零副作用。
function applyTheme(cfg) {
  var body = document.body;
  // 移除旧主题 class
  body.classList.remove('theme--dirt', 'theme--end', 'theme--nether', 'theme--cobalt', 'theme--ocean', 'theme--sand', 'theme--obsidian', 'theme--deepslate');
  body.classList.remove('is-auto-dark');

  var preset = (cfg && cfg.ui_theme) || 'dirt';
  // 只允许白名单内的预设，避免 config.json 里写了奇怪值导致 CSS 变量失效
  if (['dirt', 'end', 'nether', 'cobalt', 'ocean', 'sand', 'obsidian', 'deepslate'].indexOf(preset) === -1) preset = 'dirt';

  body.classList.add('theme--' + preset);

  // 跟随系统：仅 dirt 亮色预设会被翻成深色泥土风；深色预设本身就是深色
  if (cfg && cfg.ui_auto_dark && preset === 'dirt') {
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    if (mq && mq.matches) body.classList.add('is-auto-dark');

    // 监听系统主题变化 —— 切到深色/浅色即时生效
    if (mq && mq.addEventListener) {
      if (!applyTheme._bound) {
        mq.addEventListener('change', function (e) {
          document.body.classList.toggle('is-auto-dark', e.matches);
        });
        applyTheme._bound = true;
      }
    }
  }
}

// 启动时立即拉配置并应用主题，消除 FOUC（首屏先按 :root 默认渲染，再跳到目标主题）。
fetch('/api/config').then(function (r) { return r.json(); }).then(function (d) {
  if (d && d.config) applyTheme(d.config);
}).catch(function () { /* 本地服务还没起来，等配置页加载再应用 */ });

// HTML 转义助手：供所有 IIFE 复用（搜索/监控/配置）
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

// 侧栏视图切换
const navItems = document.querySelectorAll('.nav-item');
navItems.forEach(function (btn) {
  btn.addEventListener('click', function () {
    navItems.forEach(function (b) { b.classList.toggle('active', b === btn); });
    const views = document.querySelectorAll('.view');
    views.forEach(function (v) { v.classList.toggle('hidden', v.id !== 'view-' + btn.dataset.view); });
  });
});

// 拉取运行状态，更新左下角状态胶囊
fetch('/api/state').then(function (r) { return r.json(); }).then(function (s) {
  const pill = document.getElementById('state-pill');
  if (pill && s.ok) {
    const n = (s.subscriptions || []).length;
    pill.textContent = '运行中 · 订阅 ' + n + ' 服';
  }
}).catch(function () {
  const pill = document.getElementById('state-pill');
  if (pill) pill.textContent = '数据源离线';
});

// 全局离线横幅（本地服务不可达）
fetch('/api/state').catch(function () {
  const b = document.getElementById('offline-banner');
  if (b) b.classList.remove('hidden');
});

// ===== 可视化搜索 =====
(function () {
  const singleForm = document.getElementById('single-form');
  const batchForm = document.getElementById('batch-form');
  const tabs = document.querySelectorAll('.search-tab');
  const singleResult = document.getElementById('single-result');
  const batchResult = document.getElementById('batch-result');
  const bHosts = document.getElementById('b-hosts');
  const hostInput = document.getElementById('s-host');

  /* ---- 数据源地址（玩家头像用，favicon 相对路径补全） ---- */
  // 提前解析 server_url 的 origin；查询前先 await 它，确保图标 URL 能正确补全为绝对地址。
  let API_BASE = '';
  const apiOriginP = fetch('/api/config').then(function (r) { return r.json(); }).then(function (d) {
    if (d && d.config && d.config.server_url) {
      API_BASE = String(d.config.server_url).replace(/\/+$/, '');
    }
    return API_BASE;
  }).catch(function () { return API_BASE; });
  /** 补全 URL：favicon.url 是含 base_path 的站点根相对路径（如 /mcstatus/favicon/x.png），
   *  需基于「协议+主机」解析为绝对地址，不能用 API_BASE 直接拼接（会重复 base_path）。 */
  function fullUrl(pathOrUrl) {
    if (!pathOrUrl) return '';
    if (/^https?:\/\//i.test(pathOrUrl)) return pathOrUrl;
    if (!API_BASE) return pathOrUrl; // 配置未加载完先原样返回
    try {
      const origin = new URL(API_BASE).origin; // 仅协议+主机
      return new URL(pathOrUrl, origin + '/').href;
    } catch (e) {
      return API_BASE + pathOrUrl;
    }
  }
  // fullUrl 的上游可用性守卫：已被 apiOriginP 作为 Promise 丢弃结果即可用它作为完成信号
  function withOrigin(cb) { return apiOriginP.then(function () { return cb(); }); }

  function latencyClass(l) {
    if (l === null || l === undefined) return 'unknown';
    if (l < 50) return 'good';
    if (l < 150) return 'mid';
    return 'bad';
  }
  function latencyText(l) {
    if (l === null || l === undefined) return '延迟未知';
    return l + ' ms';
  }
  function makeGrass() { const g = document.createElement('div'); g.className = 'grass-placeholder'; return g; }
  function makeStat(label, value, extraClass) {
    const s = document.createElement('div'); s.className = 'stat';
    const l = document.createElement('span'); l.className = 'stat-label'; l.textContent = label; s.appendChild(l);
    const v = document.createElement('span'); v.className = 'stat-value' + (extraClass ? ' ' + extraClass : ''); v.textContent = value; s.appendChild(v);
    return s;
  }
  function makeBadge(text, tone) {
    const b = document.createElement('span'); b.className = 'mini-badge' + (tone ? ' ' + tone : ''); b.textContent = text; return b;
  }

  /** 图标框：优先 favicon，失败/缺失回退到草方块占位。
   *  图标来源顺序：
   *    1. favicon.base64 —— 服务端把图标原样 Base64 返回，做成 data URL 最稳（不依赖服务端落盘/URL 可解析）
   *    2. favicon.url —— 相对路径经 fullUrl() 补全为服务端绝对地址（服务端需能落盘 favicon 文件）
   *  两者都取不到才用草方块占位。 */
  function buildFavicon(parent, res) {
    const f = res.online && res.favicon ? res.favicon : null;
    const box = document.createElement('div'); box.className = 'favicon-box mc-icon';
    if (f && f.base64) {
      const img = document.createElement('img');
      img.src = 'data:image/png;base64,' + f.base64;
      img.alt = '';
      img.addEventListener('error', function () { box.innerHTML = ''; box.appendChild(makeGrass()); });
      box.appendChild(img);
    } else {
      const url = f && f.url ? fullUrl(f.url) : null;
      if (url) {
        const img = document.createElement('img'); img.src = url; img.alt = '';
        img.addEventListener('error', function () { box.innerHTML = ''; box.appendChild(makeGrass()); });
        box.appendChild(img);
      } else {
        box.appendChild(makeGrass());
      }
    }
    parent.appendChild(box);
  }

  function showErr(container, text) {
    container.textContent = '';
    const p = document.createElement('p'); p.className = 'err'; p.textContent = text; container.appendChild(p);
  }

  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      tabs.forEach(function (x) { x.classList.toggle('active', x === t); });
      const isSingle = t.dataset.mode === 'single';
      singleForm.classList.toggle('hidden', !isSingle);
      batchForm.classList.toggle('hidden', isSingle);
    });
  });

  // 单查：官网同款结果卡片（图标 + 彩色 MOTD + 统计 + 徽章 + 玩家列表）
  function buildSingleCard(res) {
    const online = !!res.online;
    const hostText = String(res.host || '') + (res.port ? ':' + String(res.port) : '');
    const verName = res.version && res.version.name ? String(res.version.name) : '';
    const brand = res.version && res.version.brand ? String(res.version.brand) : '';

    const card = document.createElement('div');
    card.className = 'mc-card ' + (online ? 'up' : 'down');

    const rowEl = document.createElement('div'); rowEl.className = 'mc-server-row';
    buildFavicon(rowEl, res);

    const info = document.createElement('div'); info.className = 'mc-info';
    const top = document.createElement('div'); top.className = 'mc-top';
    const title = document.createElement('div'); title.className = 'mc-title';
    title.textContent = verName || brand || hostText;
    top.appendChild(title);

    const side = document.createElement('div'); side.className = 'mc-side';
    if (online) {
      const on = (res.players && res.players.online !== null && res.players.online !== undefined) ? String(res.players.online) : '?';
      const mx = (res.players && res.players.max !== null && res.players.max !== undefined) ? String(res.players.max) : '?';
      const pn = document.createElement('span'); pn.className = 'mc-players'; pn.textContent = on + '/' + mx; side.appendChild(pn);
      const lat = document.createElement('span'); lat.className = 'mc-latency ' + latencyClass(res.latency_ms); lat.textContent = latencyText(res.latency_ms); side.appendChild(lat);
    } else {
      const st = document.createElement('span'); st.className = 'mc-status offline'; st.textContent = '离线'; side.appendChild(st);
    }
    top.appendChild(side);
    info.appendChild(top);

    const motd = document.createElement('div'); motd.className = 'mc-motd';
    const motdHtml = res.motd ? res.motd.html : '';
    const motdPlain = res.motd ? res.motd.plain_text : '';
    if (motdHtml) {
      motd.innerHTML = motdHtml; // 服务端已 XSS 转义，唯一允许 innerHTML 的字段
    } else if (motdPlain) {
      motd.textContent = motdPlain;
    } else {
      const sp = document.createElement('span'); sp.className = 'motd-empty'; sp.textContent = '（该服务器未提供 MOTD）'; motd.appendChild(sp);
    }
    info.appendChild(motd);
    rowEl.appendChild(info);
    card.appendChild(rowEl);

    if (online) {
      const stats = document.createElement('div'); stats.className = 'stats-row';
      stats.appendChild(makeStat('在线人数', (res.players && res.players.online != null) ? String(res.players.online) : '未知', 'accent'));
      stats.appendChild(makeStat('最大人数', (res.players && res.players.max != null) ? String(res.players.max) : '未知', 'accent'));
      stats.appendChild(makeStat('版本', verName || '未知'));
      stats.appendChild(makeStat('协议号', res.version && res.version.protocol != null ? String(res.version.protocol) : '未知'));
      stats.appendChild(makeStat('核心', brand || '未知'));
      card.appendChild(stats);

      const badgeRow = document.createElement('div'); badgeRow.className = 'badge-row';
      if (res.protocol_used) badgeRow.appendChild(makeBadge(String(res.protocol_used), 'accent'));
      if (res.srv_used) {
        const srv = res.srv_record;
        badgeRow.appendChild(makeBadge((srv && srv.target) ? 'SRV: ' + srv.target + ':' + srv.port : 'SRV 自动解析', 'accent'));
      } else if (res.srv_used === false && !res.srv_record) {
        badgeRow.appendChild(makeBadge('未使用 SRV', ''));
      }
      const sc = res.secure_chat;
      if (sc) {
        if (sc.enforces === true) badgeRow.appendChild(makeBadge('Secure Chat 强制', 'good'));
        else if (sc.enforces === false) badgeRow.appendChild(makeBadge('Secure Chat 未强制', ''));
        if (sc.previews === true) badgeRow.appendChild(makeBadge('聊天预览', ''));
      }
      card.appendChild(badgeRow);

      if (res.cached) {
        const note = document.createElement('div'); note.className = 'cache-note'; note.textContent = '本次结果来自缓存'; card.appendChild(note);
      }

      const sample = (res.players && Array.isArray(res.players.sample)) ? res.players.sample : [];
      if (sample.length) {
        const listBox = document.createElement('div'); listBox.className = 'player-list';
        const lt = document.createElement('div'); lt.className = 'player-list-title'; lt.textContent = '玩家列表（' + sample.length + '）';
        listBox.appendChild(lt);
        const rows = document.createElement('div'); rows.className = 'player-rows';
        sample.forEach(function (pl) {
          const name = pl && pl.name ? String(pl.name) : '未知玩家';
          const uuid = pl && pl.uuid ? String(pl.uuid) : '';
          const row = document.createElement('div'); row.className = 'player-row';
          const avatar = document.createElement('img'); avatar.className = 'player-avatar'; avatar.alt = '';
          if (uuid) {
            avatar.src = API_BASE + '/avatar/' + encodeURIComponent(uuid) + '.png';
            avatar.addEventListener('error', function () { avatar.style.display = 'none'; });
          } else {
            avatar.style.display = 'none';
          }
          row.appendChild(avatar);
          const nm = document.createElement('span'); nm.className = 'player-name'; nm.textContent = name; row.appendChild(nm);
          if (uuid) { const u = document.createElement('span'); u.className = 'player-uuid'; u.textContent = uuid; row.appendChild(u); }
          rows.appendChild(row);
        });
        listBox.appendChild(rows);
        card.appendChild(listBox);
      }
    } else if (res.error) {
      const e = document.createElement('div'); e.className = 'batch-error';
      const code = document.createElement('span'); code.className = 'batch-code'; code.textContent = '错误码 ' + res.error.code + '：';
      e.appendChild(code); e.appendChild(document.createTextNode(res.error.message || '未知错误')); card.appendChild(e);
    }
    return card;
  }

  singleForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const host = hostInput.value.trim();
    if (!host) return;
    const port = document.getElementById('s-port').value.trim();
    withOrigin(function () {
      singleResult.textContent = '查询中…';
      fetch('/api/ping?host=' + encodeURIComponent(host) + (port ? '&port=' + encodeURIComponent(port) : ''))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { showErr(singleResult, '查询失败: ' + (d.error || '')); return; }
          singleResult.textContent = '';
          singleResult.appendChild(buildSingleCard(d.result));
        })
        .catch(function (err) { showErr(singleResult, '查询失败: ' + ((err && err.message) || err)); });
    });
  });

  // 批查：官网同款紧凑卡片（图标框 + 标题 ... 人数/延迟 右对齐 + MOTD 第二行）
  function parseServerLine(line) {
    const s = line.trim(); if (!s) return null;
    const m = s.match(/^(.*):(\d{1,5})$/);
    if (m) { const p = parseInt(m[2], 10); if (p >= 1 && p <= 65535 && m[1]) return { host: m[1], port: p }; }
    return { host: s, port: 0 };
  }
  function makeChip(label, n, kind) {
    const c = document.createElement('span'); c.className = 'summary-chip' + (kind ? ' ' + kind : '');
    c.appendChild(document.createTextNode(label + ' '));
    const num = document.createElement('span'); num.className = 'chip-num'; num.textContent = String(n); c.appendChild(num);
    return c;
  }
  function buildBatchRow(res) {
    const online = !!res.online;
    const hostText = String(res.host || '') + (res.port ? ':' + String(res.port) : '');
    const verName = online && res.version && res.version.name ? String(res.version.name) : '';

    const card = document.createElement('div');
    card.className = 'batch-item ' + (online ? 'batch-ok' : 'batch-fail');
    const rowEl = document.createElement('div'); rowEl.className = 'mc-server-row';
    buildFavicon(rowEl, res);

    const info = document.createElement('div'); info.className = 'mc-info';
    const top = document.createElement('div'); top.className = 'mc-top';
    const title = document.createElement('div'); title.className = 'mc-title'; title.textContent = verName || hostText; top.appendChild(title);
    const side = document.createElement('div'); side.className = 'mc-side';
    if (online) {
      const on = (res.players && res.players.online !== null && res.players.online !== undefined) ? String(res.players.online) : '?';
      const mx = (res.players && res.players.max !== null && res.players.max !== undefined) ? String(res.players.max) : '?';
      const pn = document.createElement('span'); pn.className = 'mc-players'; pn.textContent = on + '/' + mx; side.appendChild(pn);
      if (res.latency_ms !== null && res.latency_ms !== undefined) {
        const lat = document.createElement('span'); lat.className = 'mc-latency ' + latencyClass(res.latency_ms); lat.textContent = latencyText(res.latency_ms); side.appendChild(lat);
      }
      if (res.cached) { const ck = document.createElement('span'); ck.className = 'mc-cache'; ck.textContent = '缓存'; side.appendChild(ck); }
    } else if (res.error) {
      const st = document.createElement('span'); st.className = 'mc-status offline'; st.textContent = '错误 ' + String(res.error.code); side.appendChild(st);
    } else {
      const st = document.createElement('span'); st.className = 'mc-status offline'; st.textContent = '离线'; side.appendChild(st);
    }
    top.appendChild(side);
    info.appendChild(top);
    if (online && res.motd && res.motd.html) {
      const motd = document.createElement('div'); motd.className = 'mc-motd'; motd.innerHTML = res.motd.html; info.appendChild(motd);
    }
    rowEl.appendChild(info);
    card.appendChild(rowEl);

    if (!online && res.error) {
      const eb = document.createElement('div'); eb.className = 'batch-error';
      const code = document.createElement('span'); code.className = 'batch-code'; code.textContent = '错误码 ' + String(res.error.code) + '：';
      eb.appendChild(code); eb.appendChild(document.createTextNode(res.error.message || '未知错误')); card.appendChild(eb);
    }
    return card;
  }
  function renderBatch(d) {
    batchResult.textContent = '';
    const results = d.results || [];
    const ok = results.filter(function (r) { return !!r.online; }).length;
    const bar = document.createElement('div'); bar.className = 'batch-summary';
    bar.appendChild(makeChip('总计', results.length, ''));
    bar.appendChild(makeChip('成功', ok, 'ok'));
    bar.appendChild(makeChip('失败', results.length - ok, 'fail'));
    batchResult.appendChild(bar);
    results.forEach(function (res) { batchResult.appendChild(buildBatchRow(res)); });
  }

  document.getElementById('b-go').addEventListener('click', function () {
    const lines = bHosts.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    const servers = lines.map(parseServerLine).filter(Boolean);
    if (!servers.length) { showErr(batchResult, '请输入至少一个服务器'); return; }
    withOrigin(function () {
      batchResult.textContent = '并发查询 ' + servers.length + ' 台…';
      fetch('/api/ping/batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ servers: servers })
      }).then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { showErr(batchResult, '批量查询失败: ' + (d.error || '')); return; }
          renderBatch(d);
        })
        .catch(function (err) { showErr(batchResult, '批量查询失败: ' + ((err && err.message) || err)); });
    });
  });
})();

// ===== 订阅监控 =====
(function () {
  const list = document.getElementById('monitor-list');
  function renderMonitor() {
    fetch('/api/monitor-panel').then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok || !d.snapshots) return;
      const snaps = d.snapshots;
      if (!snaps.length) { list.innerHTML = '<p class="err">暂无本地快照，请先在「可视化搜索」或让轮询器跑一到两轮。</p>'; return; }
      list.innerHTML = snaps.map(function (s) {
        const online = !!s.online;
        const on = s.players_online != null ? s.players_online : '-';
        const max = s.players_max != null ? s.players_max : '-';
        const lat = s.latency_ms != null ? s.latency_ms + 'ms' : '-';
        return '<div class="monitor-row ' + (online ? 'up' : 'down') + '">' +
          '<span class="monitor-alias">' + esc(s.alias || s.host) + '</span>' +
          '<span class="monitor-host">' + esc(s.host + ':' + s.port) + '</span>' +
          '<span class="monitor-count">' + on + '/' + max + '</span>' +
          '<span class="monitor-lat">' + lat + '</span>' +
          '<span class="monitor-at">' + esc(s.at || '') + '</span>' +
          '</div>';
      }).join('');
    }).catch(function () { list.innerHTML = '<p class="err">无法连接本地服务</p>'; });
  }
  renderMonitor();
  setInterval(renderMonitor, 30000);
})();

// ===== 数据统计监测（历史快照聚合 + SVG 趋势图） =====
(function () {
  const list = document.getElementById('stats-list');

  // RFC3339 -> 本地短时间（月/日 时:分）；解析失败原样返回
  function localTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.getMonth() + 1 + '/' + d.getDate() + ' ' +
      String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }
  function fmtRate(v) { return Math.round(v * 100) + '%'; }

  // 单台服务器：汇总行 + SVG 在线人数趋势图
  function buildStatsCard(st) {
    const wrap = document.createElement('div'); wrap.className = 'stats-card';

    // 头部：别名 / host:port / 当前状态
    const head = document.createElement('div'); head.className = 'stats-card-head';
    const title = document.createElement('div'); title.className = 'stats-card-title';
    const name = document.createElement('span'); name.className = 'stats-alias'; name.textContent = st.alias || st.host || st.key;
    const host = document.createElement('span'); host.className = 'stats-host'; host.textContent = st.host + ':' + st.port;
    title.appendChild(name); title.appendChild(host);
    const status = document.createElement('span'); status.className = 'stats-status ' + (st.latest_online ? 'up' : 'down');
    status.textContent = st.latest_online ? '在线' : '离线';
    head.appendChild(title); head.appendChild(status);
    wrap.appendChild(head);

    // 汇总指标
    const statsRow = document.createElement('div'); statsRow.className = 'stats-metrics';
    const chips = [
      ['采样次数', String(st.count)],
      ['在线次数', st.online_count + ' / ' + st.count],
      ['在线率', fmtRate(st.online_rate || 0)],
      ['平均人数', st.avg_players ? st.avg_players.toFixed(1) : '-'],
      ['峰值人数', String(st.peak_players || 0)]
    ];
    chips.forEach(function (c) {
      const chip = document.createElement('div'); chip.className = 'stat';
      const l = document.createElement('span'); l.className = 'stat-label'; l.textContent = c[0]; chip.appendChild(l);
      const v = document.createElement('span'); v.className = 'stat-value'; v.textContent = c[1]; chip.appendChild(v);
      statsRow.appendChild(chip);
    });
    wrap.appendChild(statsRow);

    // 趋势图
    const chartBox = document.createElement('div'); chartBox.className = 'stats-chart';
    const series = st.series || [];
    if (series.length < 2) {
      const hint = document.createElement('p'); hint.className = 'err';
      hint.textContent = '历史采样不足，暂无法绘制趋势图（至少需要 2 条采样）。请让轮询器多跑几轮。';
      chartBox.appendChild(hint);
    } else {
      chartBox.innerHTML = buildTrendSvg(st);
    }
    wrap.appendChild(chartBox);

    return wrap;
  }

  // 纯 SVG 折线图：X=时间（抽 3 个刻度），Y=在线人数，离线点标红
  function buildTrendSvg(st) {
    const series = st.series || [];
    const W = 660, H = 170, padL = 40, padR = 10, padT = 10, padB = 24;
    const iw = W - padL - padR, ih = H - padT - padB;
    const n = series.length;

    let maxP = 4;
    series.forEach(function (p) { if (p.online && p.players != null && p.players > maxP) maxP = p.players; });
    const sx = function (i) { return padL + (n <= 1 ? 0 : (iw * i) / (n - 1)); };
    const sy = function (v) { return padT + ih - (maxP <= 0 ? 0 : (v / maxP) * ih); };

    // 在线人数折线
    const pts = [];
    series.forEach(function (p, i) { if (p.online && p.players != null) pts.push([sx(i), sy(p.players)]); });
    const lineStr = pts.map(function (pt) { return pt[0].toFixed(1) + ',' + pt[1].toFixed(1); }).join(' ');

    // 离线标记点
    const offStr = series.map(function (p, i) {
      return p.online ? null : '<circle cx="' + sx(i).toFixed(1) + '" cy="' + (padT + ih / 2).toFixed(1) + '" r="2.5" fill="#C0392B"/>';
    }).filter(Boolean).join('');

    // X 轴时间刻度
    const idxs = [];
    if (n > 0) { idxs.push(0); if (n > 1) idxs.push(Math.floor((n - 1) / 2)); idxs.push(n - 1); }
    const xLabels = idxs.map(function (i) {
      return '<text x="' + sx(i).toFixed(1) + '" y="' + (H - 6) + '" text-anchor="middle" class="svg-axis">' + esc(localTime(series[i].at)) + '</text>';
    }).join('');

    // Y 轴刻度
    let yGrid = '';
    for (let g = 0; g <= 4; g++) {
      const val = Math.round((maxP * g) / 4);
      const y = sy(val);
      yGrid += '<line x1="' + padL + '" y1="' + y.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + y.toFixed(1) + '" class="svg-grid"/>' +
        '<text x="' + (padL - 6) + '" y="' + (y + 3).toFixed(1) + '" text-anchor="end" class="svg-axis">' + val + '</text>';
    }

    return '<svg viewBox="0 0 ' + W + ' ' + H + '" class="trend-svg" preserveAspectRatio="xMidYMid meet" role="img" aria-label="在线人数趋势">' +
      yGrid + xLabels +
      '<polyline points="' + lineStr + '" fill="none" class="trend-line"/>' +
      offStr +
      '</svg>';
  }

  function renderStats() {
    fetch('/api/stats').then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok || !d.servers) return;
      const servers = d.servers;
      list.textContent = '';
      if (!servers.length) {
        list.innerHTML = '<p class="err">暂无历史快照数据。请先让「订阅监控」的轮询器至少跑两轮采样，或从命令行使用 --run 模式采集。</p>';
        return;
      }
      servers.forEach(function (st) { list.appendChild(buildStatsCard(st)); });
    }).catch(function () { list.innerHTML = '<p class="err">无法连接本地服务</p>'; });
  }
  renderStats();
  setInterval(renderStats, 30000);
})();

// ===== 告警与事件 =====
(function () {
  const list = document.getElementById('events-list');
  const onlyAlert = document.getElementById('ev-only-alert');
  const clearBtn = document.getElementById('ev-clear');

  // 类型 → 中文标签 + 徽章样式
  const typeMeta = { alert: ['告警', 'alert'], summary: ['总结', 'summary'] };
  const kindMeta = {
    offline: '离线', latency: '延迟', player_drop: '人数骤降', summary: '周期总结'
  };

  function localTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.getMonth() + 1 + '/' + d.getDate() + ' ' +
      String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' +
      String(d.getSeconds()).padStart(2, '0');
  }

  function buildEventRow(e) {
    const tm = typeMeta[e.type] || ['事件', ''];
    const kind = kindMeta[e.kind] || e.kind || '';
    const row = document.createElement('div');
    row.className = 'ev-row ev-' + (e.type === 'alert' ? 'alert' : 'summary');

    const time = document.createElement('span'); time.className = 'ev-time'; time.textContent = localTime(e.at);
    const badge = document.createElement('span'); badge.className = 'ev-badge ' + tm[1]; badge.textContent = tm[0];
    const who = esc(e.alias || e.server_key || '');

    row.appendChild(time);
    row.appendChild(badge);
    if (kind) {
      const k = document.createElement('span');
      k.className = 'ev-kind'; k.textContent = kind;
      // 规则类型 → kind 配色（离线/延迟/人数骤降/总结）
      const kindClass = { offline: 'offline', latency: 'latency', player_drop: 'player_drop', summary: 'summary' }[e.kind];
      if (kindClass) k.classList.add(kindClass);
      row.appendChild(k);
    }
    if (who) { const w = document.createElement('span'); w.className = 'ev-who'; w.textContent = who; row.appendChild(w); }
    const msg = document.createElement('span'); msg.className = 'ev-msg'; msg.textContent = e.message; row.appendChild(msg);
    return row;
  }

  function render() {
    fetch('/api/events').then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) return;
      const evs = d.events || [];
      const filtered = onlyAlert.checked ? evs.filter(function (e) { return e.type === 'alert'; }) : evs;
      list.textContent = '';
      if (!filtered.length) {
        list.innerHTML = '<p class="events-empty">' + (onlyAlert.checked
          ? '暂无告警记录。离线 / 延迟 / 人数骤降等规则命中时会在这里出现。'
          : '暂无事件记录。后台采样产生的告警与周期总结会出现在这里。') + '</p>';
        return;
      }
      filtered.forEach(function (e) { list.appendChild(buildEventRow(e)); });
    }).catch(function () { list.innerHTML = '<p class="err">无法连接本地服务</p>'; });
  }

  onlyAlert.addEventListener('change', render);

  clearBtn.addEventListener('click', function () {
    if (!window.confirm('确定清空所有事件记录吗？此操作不可恢复。')) return;
    fetch('/api/events', { method: 'DELETE' }).then(function (r) { return r.json(); }).then(function () {
      render();
    });
  });

  render();
  setInterval(render, 15000);
})();

// ===== 配置 =====
(function () {
  const form = document.getElementById('config-form');
  const subsEditor = document.getElementById('subs-editor');
  const msg = document.getElementById('cfg-msg');

  function load() {
    fetch('/api/config').then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) return;
      const c = d.config;
      document.getElementById('cfg-server-url').value = c.server_url || '';
      document.getElementById('cfg-poll').value = c.poll_interval_seconds != null ? c.poll_interval_seconds : '';
      document.getElementById('cfg-port').value = c.default_port || '';
      document.getElementById('cfg-offline').value = c.offline_consecutive != null ? c.offline_consecutive : '';
      document.getElementById('cfg-latency').value = c.latency_high_ms != null ? c.latency_high_ms : '';
      document.getElementById('cfg-drop').value = c.drop_ratio != null ? c.drop_ratio : '';
      document.getElementById('cfg-dropmin').value = c.drop_min_people != null ? c.drop_min_people : '';
      document.getElementById('cfg-hist').value = c.history_max_records != null ? c.history_max_records : '';
      document.getElementById('cfg-series').value = c.series_max_points != null ? c.series_max_points : '';
      document.getElementById('cfg-event-enabled').checked = c.event_enabled !== false;
      document.getElementById('cfg-event-summary').checked = !!c.event_include_summary;
      document.getElementById('cfg-alert-cd').value = c.alert_cooldown_seconds != null ? c.alert_cooldown_seconds : '';
      document.getElementById('cfg-event-max').value = c.event_max_records != null ? c.event_max_records : '';
      document.getElementById('cfg-theme').value = c.ui_theme || 'dirt';
      document.getElementById('cfg-auto-dark').checked = !!c.ui_auto_dark;
      // 订阅行
      const subs = (d.subscriptions && d.subscriptions.servers) || [];
      subsEditor.innerHTML = '';
      subs.forEach(function (s, i) {
        const row = document.createElement('div');
        row.className = 'sub-row';
        row.innerHTML =
          '<input data-i="' + i + '" data-f="host" value="' + esc(s.host || '') + '" placeholder="host">' +
          '<input data-i="' + i + '" data-f="port" type="number" value="' + (s.port || '') + '" placeholder="port">' +
          '<input data-i="' + i + '" data-f="alias" value="' + esc(s.alias || '') + '" placeholder="别名">' +
          '<input data-i="' + i + '" data-f="group" value="' + esc(s.group || '') + '" placeholder="分组">' +
          '<button type="button" class="sub-del" data-i="' + i + '">✕</button>';
        subsEditor.appendChild(row);
      });
      // 添加一行
      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.id = 'sub-add';
      addBtn.textContent = '+ 添加订阅';
      addBtn.addEventListener('click', function () {
        const row = document.createElement('div');
        row.className = 'sub-row';
        row.innerHTML = '<input data-f="host" placeholder="host"><input data-f="port" type="number" placeholder="port">' +
          '<input data-f="alias" placeholder="别名"><input data-f="group" placeholder="分组">' +
          '<button type="button" class="sub-del">✕</button>';
        subsEditor.appendChild(row);
      });
      subsEditor.parentNode.appendChild(addBtn);
    });
  }
  // 删除订阅行（事件委托）
  subsEditor.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('sub-del')) {
      e.target.closest('.sub-row').remove();
    }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    msg.textContent = '保存中…';
    const subs = Array.from(subsEditor.querySelectorAll('.sub-row')).map(function (row) {
      const o = {};
      row.querySelectorAll('input').forEach(function (inp) {
        const f = inp.dataset.f;
        if (f === 'port') o[f] = inp.value ? parseInt(inp.value, 10) : 0;
        else o[f] = inp.value.trim();
      });
      return o;
    });
    const editable = {
      server_url: document.getElementById('cfg-server-url').value.trim(),
      poll_interval_seconds: parseInt(document.getElementById('cfg-poll').value, 10),
      default_port: parseInt(document.getElementById('cfg-port').value, 10),
      offline_consecutive: parseInt(document.getElementById('cfg-offline').value, 10),
      latency_high_ms: parseInt(document.getElementById('cfg-latency').value, 10),
      drop_ratio: parseFloat(document.getElementById('cfg-drop').value),
      drop_min_people: parseInt(document.getElementById('cfg-dropmin').value, 10),
      history_max_records: parseInt(document.getElementById('cfg-hist').value, 10),
      series_max_points: parseInt(document.getElementById('cfg-series').value, 10),
      event_enabled: document.getElementById('cfg-event-enabled').checked,
      event_include_summary: document.getElementById('cfg-event-summary').checked,
      alert_cooldown_seconds: parseInt(document.getElementById('cfg-alert-cd').value, 10),
      event_max_records: parseInt(document.getElementById('cfg-event-max').value, 10),
      ui_theme: document.getElementById('cfg-theme').value,
      ui_auto_dark: document.getElementById('cfg-auto-dark').checked
    };
    // 用「已脱敏」的全量 config 做底，覆盖可编辑字段；服务端会用内存里的真实 Token 回填
    fetch('/api/config').then(function (r) { return r.json(); }).then(function (d) {
      const full = d.config || {};
      const config = Object.assign({}, full, editable);
      delete config.webhook_token; // 永不上送
      return fetch('/api/config', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ config: config, subscriptions: subs })
      });
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.ok) {
        msg.textContent = '已保存 ✓';
        applyTheme(config); // 主题切换即时生效，不用刷新页面
      } else {
        msg.textContent = '保存失败: ' + (d.error || '未知错误');
      }
    });
  });

  load();
})();