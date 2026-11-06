/**
 * CRM — alertas sonoros e pop-up em qualquer tela do GLPI.
 * Consulta front/alertas.php a cada 10 segundos; as regras (Configurações) e as
 * preferências do usuário decidem o que toca e o que aparece. Os toques são
 * sintetizados no navegador (Web Audio), sem arquivos de áudio.
 */
(() => {
    if (window.top !== window.self || window.CRMAlerts) return;
    const script = document.currentScript;
    if (!script || !script.src) return;
    const base = new URL(script.src).pathname.replace(/\/js\/alertas\.js.*$/, '');
    const endpoint = `${base}/front/alertas.php`;
    if (/\/front\/(login|logout|initpassword|lostpassword)/.test(location.pathname)) return;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const store = {
        get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* sem armazenamento */ } },
    };

    // ---- toques: [frequência Hz, início s, duração s, forma de onda, ganho]
    const TONES = {
        ping:   [[880, 0, .14, 'sine', .9], [1320, .12, .22, 'sine', .8]],
        soft:   [[660, 0, .22, 'sine', .7]],
        double: [[740, 0, .14, 'triangle', .9], [740, .2, .14, 'triangle', .9]],
        cash:   [[1046, 0, .1, 'square', .45], [1318, .09, .1, 'square', .45], [1568, .18, .1, 'square', .45], [2093, .27, .35, 'sine', .8]],
        low:    [[220, 0, .3, 'sawtooth', .5], [165, .26, .45, 'sawtooth', .5]],
        alarm:  [[988, 0, .16, 'square', .5], [740, .2, .16, 'square', .5], [988, .4, .16, 'square', .5], [740, .6, .2, 'square', .5]],
    };
    let ctx = null;
    const audio = () => {
        try {
            ctx ??= new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') ctx.resume();
        } catch (e) { ctx = null; }
        return ctx;
    };
    // navegadores só liberam áudio depois de um clique ou tecla na página
    ['click', 'keydown'].forEach((ev) => document.addEventListener(ev, () => audio(), {once: true, passive: true}));

    function play(tone, volume = 70) {
        const c = audio();
        const notes = TONES[tone];
        if (!c || !notes || c.state !== 'running') return false;
        const master = c.createGain();
        master.gain.value = Math.max(0, Math.min(1, volume / 100)) * .35;
        master.connect(c.destination);
        const t0 = c.currentTime;
        notes.forEach(([freq, start, dur, type, gain]) => {
            const osc = c.createOscillator();
            const g = c.createGain();
            osc.type = type;
            osc.frequency.value = freq;
            g.gain.setValueAtTime(0, t0 + start);
            g.gain.linearRampToValueAtTime(gain, t0 + start + .015);
            g.gain.exponentialRampToValueAtTime(.001, t0 + start + dur);
            osc.connect(g).connect(master);
            osc.start(t0 + start);
            osc.stop(t0 + start + dur + .05);
        });
        return true;
    }

    // ---- pop-up
    let box;
    function popup(ev, seconds = 8) {
        box ??= document.body.appendChild(Object.assign(document.createElement('div'), {className: 'crm-alerts'}));
        const el = document.createElement('div');
        el.className = 'crm-alert';
        el.style.setProperty('--c', ev.color || '#3b82f6');
        el.innerHTML = `<div class="crm-alert-icon"><i class="ti ${esc(ev.icon)}"></i></div>
            <div class="crm-alert-body"><div class="crm-alert-label">${esc(ev.label)}</div><div class="crm-alert-title">${esc(ev.title)}</div>
            ${ev.body ? `<div class="crm-alert-text">${esc(ev.body)}</div>` : ''}
            ${ev.url ? `<a class="crm-alert-link" href="${esc(ev.url)}">Abrir conversa <i class="ti ti-arrow-right"></i></a>` : ''}</div>
            <button type="button" class="crm-alert-x" aria-label="Fechar">&times;</button>`;
        const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 250); };
        el.querySelector('.crm-alert-x').onclick = close;
        box.appendChild(el);
        while (box.children.length > 4) box.firstChild.remove();
        setTimeout(close, seconds * 1000);
    }

    // ---- consulta
    let last = Number(store.get('crm.alertas.last') || 0);
    let busy = false;
    async function poll() {
        if (busy) return;
        busy = true;
        try {
            const res = await fetch(`${endpoint}?since=${last}`, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok) return;
            const first = last === 0; // primeira consulta só marca o ponto de partida
            last = data.last;
            store.set('crm.alertas.last', String(last));
            if (first) return;
            let tone = '';
            data.events.forEach((ev) => {
                if (ev.popup) popup(ev, data.popup_seconds);
                tone = tone || ev.tone; // um toque por consulta, o do primeiro evento
            });
            if (tone) play(tone, data.volume);
        } catch (e) { /* rede indisponível: tenta de novo no próximo ciclo */ } finally { busy = false; }
    }
    setInterval(poll, 10000);
    poll();

    // ---- aviso de fechamento: pergunta se deve iniciar o ciclo financeiro
    const ajaxUrl = `${base}/front/ajax.php`;
    const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    function closing(info, done = () => {}) {
        if (!info) { done(); return; }
        play('cash', 70);
        const types = Object.entries(info.saleTypes).map(([k, v]) => `<option value="${esc(k)}" ${k === info.saleType ? 'selected' : ''}>${esc(v)}</option>`).join('');
        const back = document.createElement('div');
        back.className = 'crm-modal-back';
        back.innerHTML = `<div class="crm-modal" style="max-width:720px">
            <div class="crm-modal-h"><h3><i class="ti ti-bell-ringing me-2 text-success"></i>Aviso de fechamento</h3><button type="button" class="btn-close" data-x></button></div>
            <div class="crm-modal-b">
                <p class="mb-1"><b>${esc(info.customer)}</b> chegou ao fechamento (${esc(info.code)}).</p>
                <p class="text-secondary">Deseja iniciar um <b>ciclo financeiro</b>? O ciclo acompanha a venda do aviso de fechamento até o recebimento dos valores, fase a fase.</p>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label">Venda / projeto</label><input class="form-control" data-f="name" value="${esc(info.name)}"></div>
                    <div class="col-md-7"><label class="form-label">Tipo de venda</label><select class="form-select" data-f="sale_type">${types}</select></div>
                    <div class="col-md-5"><label class="form-label">Valor (R$)</label><input class="form-control" data-f="amount" inputmode="decimal" value="${brl(info.amount)}"></div>
                </div>
                <div class="text-danger small mt-2" data-err></div>
            </div>
            <div class="crm-modal-f"><button type="button" class="btn btn-secondary" data-no>Agora não</button>
                ${info.report ? `<a class="btn btn-outline-secondary" href="${esc(info.report)}"><i class="ti ti-file-type-pdf me-1"></i>Relatório da negociação</a>` : ''}
                <button type="button" class="btn btn-success ms-auto" data-yes><i class="ti ti-coin me-1"></i>Iniciar ciclo financeiro</button></div></div>`;
        const close = () => { back.remove(); document.removeEventListener('keydown', onKey); done(); };
        const onKey = (e) => e.key === 'Escape' && close();
        document.addEventListener('keydown', onKey);
        back.querySelector('[data-x]').onclick = close;
        back.querySelector('[data-no]').onclick = close;
        back.querySelector('[data-yes]').onclick = async (e) => {
            const btn = e.currentTarget;
            const f = Object.fromEntries([...back.querySelectorAll('[data-f]')].map((el) => [el.dataset.f, el.value.trim()]));
            btn.disabled = true;
            try {
                const res = await fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify({action: 'cycle', id: info.id, ...f})});
                const out = await res.json();
                if (!res.ok || out.ok === false) throw new Error(out.error || `HTTP ${res.status}`);
                back.remove();
                document.removeEventListener('keydown', onKey);
                celebrate(out.cycle, info.customer, done);
            } catch (err) {
                back.querySelector('[data-err]').textContent = err.message;
                btn.disabled = false;
            }
        };
        document.body.appendChild(back);
    }

    // ---- venda concretizada: animação de fechamento e abertura do ciclo
    function confetti(host) {
        const colors = ['#22c55e', '#f59e0b', '#3b82f6', '#ec4899', '#a855f7', '#14b8a6'];
        for (let i = 0; i < 70; i++) {
            const p = document.createElement('i');
            p.className = 'crm-confetti';
            p.style.cssText = `left:${Math.random() * 100}%;background:${colors[i % colors.length]};--dx:${(Math.random() - .5) * 240}px;--rot:${Math.random() * 720 - 360}deg;animation-delay:${Math.random() * .6}s;animation-duration:${1.8 + Math.random() * 1.4}s`;
            host.appendChild(p);
        }
    }
    const EVT = {
        aviso: {title: 'Aviso de fechamento', icon: 'ti-bell-ringing', sub: (c) => `Abrindo o ciclo financeiro <b>${esc(c.code || '')}</b>…`, confetti: false},
        novo:  {title: 'Venda fechada!', icon: 'ti-trophy', sub: (c) => `Abrindo o ciclo financeiro <b>${esc(c.code || '')}</b>${c.docs ? ` com ${c.docs} documento${c.docs > 1 ? 's' : ''}` : ''}…`, confetti: true},
        retomado: {title: 'Ciclo liberado', icon: 'ti-player-play', sub: (c) => `Retomando o ciclo financeiro <b>${esc(c.code || '')}</b>…`, confetti: false},
        ganho: {title: 'Venda fechada!', icon: 'ti-trophy', sub: (c) => `Indo para o ciclo financeiro <b>${esc(c.code || '')}</b>…`, confetti: true},
    };
    function celebrate(cycle, customer, done = () => {}) {
        const mode = EVT[cycle.mode] ? cycle.mode : 'novo';
        const ev = EVT[mode];
        play('cash', 80);
        const back = document.createElement('div');
        back.className = 'crm-win-back';
        back.innerHTML = `<div class="crm-win">
            <div class="crm-win-check"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none"/><path fill="none" d="M14 27l8 8 16-17"/></svg></div>
            <h2>${ev.title}</h2>
            <p class="crm-win-who">${esc(customer || cycle.customer || '')}</p>
            <p class="crm-win-sub"><i class="ti ${ev.icon} me-1"></i>${ev.sub(cycle)}</p>
            <div class="crm-win-bar"><span></span></div></div>`;
        document.body.appendChild(back);
        if (ev.confetti) confetti(back);
        let went = false;
        const go = () => {
            if (went) return;
            went = true;
            if (cycle.url) {
                const q = new URLSearchParams({crm_evt: mode, cliente: customer || cycle.customer || '', valor: cycle.amount || '', data: cycle.date || '', docs: cycle.docs || 0});
                location.href = `${cycle.url}${cycle.url.includes('?') ? '&' : '?'}${q}`;
            } else { back.remove(); done(cycle); }
        };
        setTimeout(go, ['aviso', 'retomado'].includes(mode) ? 1800 : 2600);
        back.addEventListener('click', go);
    }
    // pergunta antes de mandar a oportunidade para a esteira do ciclo financeiro
    function confirmCycle(customer) {
        return new Promise((resolve) => {
            const back = document.createElement('div');
            back.className = 'crm-modal-back';
            back.style.alignItems = 'center';
            back.innerHTML = `<div class="crm-modal" style="max-width:480px">
                <div class="crm-modal-h"><h3><i class="ti ti-help-circle me-2 text-warning"></i>Enviar para o ciclo financeiro?</h3></div>
                <div class="crm-modal-b"><p class="mb-1">Tem certeza que <b>${esc(customer || 'esta oportunidade')}</b> pode seguir para a <b>esteira do ciclo financeiro</b>?</p>
                    <p class="text-secondary small mb-0">Ao confirmar, o ciclo é aberto com os documentos e os dados da venda.</p></div>
                <div class="crm-modal-f"><button type="button" class="btn btn-secondary" data-no>Não</button>
                    <button type="button" class="btn btn-success ms-auto" data-yes><i class="ti ti-check me-1"></i>Sim, enviar</button></div></div>`;
            const end = (v) => { back.remove(); document.removeEventListener('keydown', onKey); resolve(v); };
            const onKey = (e) => e.key === 'Escape' && end(false);
            document.addEventListener('keydown', onKey);
            back.querySelector('[data-no]').onclick = () => end(false);
            back.querySelector('[data-yes]').onclick = () => end(true);
            document.body.appendChild(back);
            back.querySelector('[data-yes]').focus();
        });
    }
    // resposta de mover/ganhar: ciclo aberto sozinho -> animação; senão, o aviso de fechamento (pergunta)
    function won(out, customer, done = () => {}) {
        if (out && out.paused) {
            popup({label: 'Ciclo financeiro interrompido', title: out.paused.code || 'Ciclo', body: 'A oportunidade voltou para a negociação. O ciclo só continua quando ela seguir para o fechamento.', icon: 'ti-player-pause', color: '#f59e0b'}, 10);
            done();
            return;
        }
        if (out && out.cycle) { celebrate(out.cycle, customer, done); return; }
        closing(out && out.closing, done);
    }

    // ---- no Ciclo Financeiro: aviso no centro da tela
    (function cycleEvent() {
        const q = new URLSearchParams(location.search);
        const mode = q.get('crm_evt');
        if (!mode || !/\/plugins\/ciclo\//.test(location.pathname)) return;
        const docs = +q.get('docs') || 0;
        const valor = Number(q.get('valor'));
        const cliente = q.get('cliente') || '';
        const data = q.get('data') || new Date().toISOString().slice(0, 10);
        ['crm_evt', 'cliente', 'valor', 'data', 'docs'].forEach((k) => q.delete(k));
        try { history.replaceState(null, '', location.pathname + (q.toString() ? `?${q}` : '') + location.hash); } catch (e) { /* segue */ }
        const fmtDate = (d) => d.split('-').reverse().join('/');
        const show = () => {
            const code = (document.title.match(/CF-\d+/) || document.body.innerText.match(/CF-\d+/) || [''])[0];
            const aviso = mode === 'aviso';
            const retomado = mode === 'retomado';
            const head = retomado ? 'Ciclo liberado' : aviso ? 'Aviso de fechamento' : (mode === 'novo' ? 'Cliente fechado!' : 'Venda concretizada!');
            const line = retomado ? 'A oportunidade seguiu da negociação e o ciclo financeiro voltou a andar' : aviso ? 'A oportunidade chegou ao fechamento e o ciclo financeiro foi aberto' : (mode === 'novo' ? 'Um novo ciclo financeiro foi aberto' : 'Venda concretizada, o ciclo financeiro segue em andamento');
            const back = document.createElement('div');
            back.className = 'crm-win-back crm-win-center';
            back.innerHTML = `<div class="crm-win">
                <div class="crm-win-check"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none"/><path fill="none" d="M14 27l8 8 16-17"/></svg></div>
                <h2>${head}</h2>
                <p class="crm-win-who">${line}${code ? ` — <b>${esc(code)}</b>` : ''}</p>
                <div class="crm-win-facts">
                    ${cliente ? `<div><small>Cliente</small><b>${esc(cliente)}</b></div>` : ''}
                    ${valor ? `<div><small>Valor</small><b>R$ ${brl(valor)}</b></div>` : ''}
                    <div><small>Data</small><b>${esc(fmtDate(data))}</b></div>
                </div>
                <div class="crm-win-chips">
                    <span><i class="ti ti-paperclip"></i> ${docs} documento${docs === 1 ? '' : 's'} da venda</span>
                    <span><i class="ti ti-database-import"></i> Dados do CRM preenchidos</span>
                </div>
                <button type="button" class="btn btn-success mt-3" data-ok><i class="ti ti-arrow-right me-1"></i>Seguir</button></div>`;
            document.body.appendChild(back);
            if (mode === 'novo' || mode === 'ganho') confetti(back);
            play('cash', 70);
            const off = () => back.remove();
            back.querySelector('[data-ok]').onclick = off;
            back.addEventListener('click', (e) => e.target === back && off());
            document.addEventListener('keydown', (e) => e.key === 'Escape' && off(), {once: true});
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show); else show();
    })();

    window.CRMAlerts = {
        play,
        popup,
        closing,
        celebrate,
        confirmCycle,
        won,
        tones: Object.keys(TONES),
        test: (tone, volume) => { if (!play(tone, volume)) popup({label: 'Som bloqueado', title: 'Clique na página e tente de novo', icon: 'ti-volume-off', color: '#f59e0b'}, 4); },
    };
})();
