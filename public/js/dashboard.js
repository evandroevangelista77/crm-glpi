/**
 * CRM — dashboard interativo: cartões, funil, canais e fatias abrem a gaveta lateral com as
 * oportunidades por trás do número, e ao entrar um pop-up destaca as oportunidades com mais
 * chance de venda para o comercial acompanhar a negociação.
 */
(() => {
    const boot = JSON.parse(document.getElementById('crm-dash').textContent);
    const {esc, brl, ring, avatar, typeBadge, open, list} = window.CRMDetail;
    const items = Object.fromEntries(Object.entries(boot.items).map(([k, v]) => [k, v]));
    const resolve = (ids) => ids.map((id) => items[id]).filter(Boolean);

    // ---- gaveta: cartões, linhas do funil, canais e fatias das roscas
    function openList(key) {
        const meta = boot.lists[key];
        if (meta && window.OakDrawer) list(meta, resolve(meta.ids));
    }
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-list]');
        if (el && !e.target.closest('a')) openList(el.dataset.list);
        const tr = e.target.closest('tr[data-item]');
        if (tr && items[tr.dataset.item]) open(items[tr.dataset.item]);
    });
    document.addEventListener('keydown', (e) => {
        const el = e.target.closest?.('[data-list]');
        if (el && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openList(el.dataset.list); }
    });

    // ---- pop-up de oportunidades quentes
    const HEAT = {fechamento: ['Pronta para fechar', 3], negociacao: ['Em negociação', 2], prevenda: ['Esquentando', 1], posvenda: ['Em pós-venda', 1]};
    const TAGS = [
        'Quem responde primeiro, vende primeiro.',
        'Negociação parada esfria. Fale com o cliente hoje.',
        'O próximo fechamento está nesta lista.',
        'Um contato agora vale mais que três amanhã.',
    ];
    const today = new Date().toISOString().slice(0, 10);
    const store = {
        get: () => { try { return localStorage.getItem('crm.hot.hide'); } catch (e) { return null; } },
        set: (v) => { try { v ? localStorage.setItem('crm.hot.hide', v) : localStorage.removeItem('crm.hot.hide'); } catch (e) { /* sem armazenamento */ } },
    };

    function hotRow(it, i) {
        const [label, flames] = HEAT[it.stageKind] || ['Em andamento', 1];
        const phone = String(it.numero || '').replace(/\D/g, '');
        const tags = [
            `<span class="crm-heat h${flames}"><i class="ti ti-flame"></i>${esc(label)}</span>`,
            it.waiting ? '<span class="crm-hot-tag late"><i class="ti ti-message-circle-exclamation"></i>Cliente aguardando você</span>' : '',
            !it.attendant ? '<span class="crm-hot-tag late"><i class="ti ti-user-question"></i>Sem atendente</span>' : '',
            it.editalMissing ? '<span class="crm-hot-tag late"><i class="ti ti-gavel"></i>Edital pendente</span>' : '',
        ].join('');
        return `<div class="crm-hot-row r${i + 1}" data-hot="${it.id}" style="--c:${esc(it.stageColor)}">
            <div class="crm-hot-rank">${i + 1}</div>${avatar(it, 'md')}
            <div class="crm-hot-main"><div class="d-flex flex-wrap align-items-center gap-2"><strong class="crm-hot-name">${esc(it.title)}</strong>${typeBadge(it)}</div>
                <div class="small text-secondary text-truncate">${esc(it.subject || it.name)} · ${esc(it.stage)}${it.attendant ? ' · ' + esc(it.attendant) : ''}</div>
                <div class="d-flex flex-wrap gap-1 mt-1">${tags}</div></div>
            <div class="crm-hot-ring">${ring(it.prob, '#f59e0b')}</div>
            <div class="crm-hot-money"><strong>${brl(it.value)}</strong><span>esperado ${brl(it.expected)}</span></div>
            <div class="crm-hot-actions"><button type="button" class="btn btn-warning btn-sm" data-act="open"><i class="ti ti-eye me-1"></i>Abrir</button>
                ${phone ? `<a class="btn btn-outline-success btn-sm" href="https://wa.me/${phone}" target="_blank" rel="noopener"><i class="ti ti-brand-whatsapp"></i></a>` : ''}</div></div>`;
    }

    // radar ao fundo: anéis, varredura e um alvo por oportunidade (quanto maior a chance, mais perto do centro)
    const HEAT_COLOR = {fechamento: '#ef4444', negociacao: '#f97316'};
    function blip(it, isHot) {
        const angle = (it.id * 137.508) % 360;
        const r = 11 + (1 - it.prob / 100) * 35;
        const rad = angle * Math.PI / 180;
        const color = isHot ? (HEAT_COLOR[it.stageKind] || '#f59e0b') : '#4ade80';
        return `<i class="crm-blip ${isHot ? 'hot' : ''}" data-b="${it.id}" style="left:${(50 + r * Math.sin(rad)).toFixed(1)}%;top:${(50 - r * Math.cos(rad)).toFixed(1)}%;--d:${(angle / 360 * 5).toFixed(2)}s;--c:${color}"></i>`;
    }
    const radar = (hot, watching) => `<div class="crm-radar" aria-hidden="true"><div class="crm-radar-disc"><i class="crm-sweep"></i>${watching.map((i) => blip(i, false)).join('')}${hot.map((i) => blip(i, true)).join('')}</div>
        <span class="crm-radar-tag"><b></b>RADAR ATIVO · ${hot.length} ALVOS QUENTES · ${watching.length} EM OBSERVAÇÃO</span></div>`;

    function showHot(force = false) {
        const hot = resolve(boot.hot);
        if (!hot.length || (!force && store.get() === today)) return;
        const stake = hot.reduce((a, i) => a + i.value, 0);
        const expected = hot.reduce((a, i) => a + i.expected, 0);
        const watching = Object.values(items).filter((i) => i.status === 1 && !boot.hot.includes(i.id));
        const back = document.createElement('div');
        back.className = 'crm-hot-back';
        back.innerHTML = `<div class="crm-hot" role="dialog" aria-modal="true" aria-label="Oportunidades quentes">
            ${radar(hot, watching)}
            <div class="crm-hot-head"><div class="crm-hot-flame"><i class="ti ti-flame"></i></div>
                <div class="flex-grow-1"><div class="crm-hot-kicker">Oportunidades quentes</div><h2>${brl(stake)} em jogo agora</h2>
                    <p>${hot.length} oportunidades com a maior chance de virar venda · previsão de <b>${brl(expected)}</b>. <em>${TAGS[Math.floor(Math.random() * TAGS.length)]}</em></p></div>
                <button type="button" class="btn-close" data-x aria-label="Fechar"></button></div>
            <div class="crm-hot-list">${hot.map(hotRow).join('')}</div>
            <div class="crm-hot-foot"><label class="form-check mb-0 small"><input type="checkbox" class="form-check-input" data-hide><span class="form-check-label">Não mostrar de novo hoje</span></label>
                <a class="btn btn-outline-secondary ms-auto" href="${esc(boot.funil)}"><i class="ti ti-layout-kanban me-1"></i>Ir para o funil</a>
                <button type="button" class="btn btn-warning" data-x><i class="ti ti-flame me-1"></i>Vamos fechar</button></div></div>`;
        const close = () => {
            if (back.querySelector('[data-hide]').checked) store.set(today);
            back.classList.add('out');
            setTimeout(() => back.remove(), 220);
            document.removeEventListener('keydown', onKey);
        };
        const onKey = (e) => e.key === 'Escape' && close();
        document.addEventListener('keydown', onKey);
        back.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        back.addEventListener('mousedown', (e) => e.target === back && close());
        // passar o mouse numa linha acende o alvo dela no radar
        back.querySelectorAll('.crm-hot-row').forEach((row) => {
            const target = () => back.querySelector(`[data-b="${row.dataset.hot}"]`);
            row.addEventListener('mouseenter', () => target()?.classList.add('on'));
            row.addEventListener('mouseleave', () => target()?.classList.remove('on'));
        });
        back.querySelectorAll('[data-hot] [data-act="open"]').forEach((b) => b.addEventListener('click', () => {
            const it = items[b.closest('[data-hot]').dataset.hot];
            close();
            open(it);
        }));
        document.body.appendChild(back);
    }

    document.getElementById('crm-hot-open')?.addEventListener('click', () => showHot(true));
    setTimeout(() => showHot(false), 450);
})();
