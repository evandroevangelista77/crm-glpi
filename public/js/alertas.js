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
                popup({label: 'Ciclo financeiro iniciado', title: out.cycle.code || 'Ciclo aberto', body: `${info.customer} segue para a validação da documentação.`, icon: 'ti-coin', color: '#22c55e', url: out.cycle.url}, 12);
                done(out.cycle);
            } catch (err) {
                back.querySelector('[data-err]').textContent = err.message;
                btn.disabled = false;
            }
        };
        document.body.appendChild(back);
    }

    window.CRMAlerts = {
        play,
        popup,
        closing,
        tones: Object.keys(TONES),
        test: (tone, volume) => { if (!play(tone, volume)) popup({label: 'Som bloqueado', title: 'Clique na página e tente de novo', icon: 'ti-volume-off', color: '#f59e0b'}, 4); },
    };
})();
