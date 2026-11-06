/**
 * CRM — funil de vendas (Kanban): oportunidades abertas por etapa, com valor,
 * previsão ponderada, filtros, arrastar e soltar (inclusive para Ganho e
 * Perdido) e atualização automática a cada 30 segundos.
 */
(() => {
    const boot = JSON.parse(document.getElementById('crm-boot').textContent);
    const app = document.getElementById('crm-funil');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL', maximumFractionDigits: 0});
    let data = null;
    let dragging = false;
    const filter = {q: '', user: '', type: ''};

    async function api(action, payload = null) {
        const opts = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        let url = boot.ajax;
        if (payload) {
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({action, ...payload});
        } else {
            url += '?' + new URLSearchParams({action});
        }
        const res = await fetch(url, opts);
        let out;
        try { out = await res.json(); } catch (e) { throw new Error(`Resposta inválida do servidor (HTTP ${res.status}).`); }
        if (!res.ok || out.ok === false) throw new Error(out.error || `HTTP ${res.status}`);
        return out;
    }

    let toastBox;
    const toast = (msg, kind = '') => {
        toastBox ??= document.body.appendChild(Object.assign(document.createElement('div'), {className: 'crm-toast'}));
        const el = toastBox.appendChild(Object.assign(document.createElement('div'), {className: kind, textContent: msg}));
        setTimeout(() => el.remove(), kind === 'err' ? 7000 : 3000);
    };

    function modal(title, body, buttons) {
        const back = document.createElement('div');
        back.className = 'crm-modal-back';
        back.innerHTML = `<div class="crm-modal"><div class="crm-modal-h"><h3>${title}</h3><button type="button" class="btn-close" data-x></button></div><div class="crm-modal-b">${body}</div><div class="crm-modal-f"></div></div>`;
        const close = () => { back.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => e.key === 'Escape' && close();
        document.addEventListener('keydown', onKey);
        back.querySelector('[data-x]').onclick = close;
        back.addEventListener('mousedown', (e) => e.target === back && close());
        buttons.forEach((b) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn ' + (b.cls || 'btn-secondary');
            btn.innerHTML = b.label;
            btn.onclick = async () => {
                if (!b.run) return close();
                btn.disabled = true;
                try { if (await b.run(back) !== false) close(); } catch (e) { toast(e.message, 'err'); } finally { btn.disabled = false; }
            };
            back.querySelector('.crm-modal-f').appendChild(btn);
        });
        document.body.appendChild(back);
        back.querySelector('input,textarea,select')?.focus();
        return back;
    }

    async function load() {
        try {
            data = await api('board');
            render();
        } catch (e) {
            app.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    const visible = () => data.cards.filter((c) => {
        if (filter.type && c.clientType !== filter.type) return false;
        if (filter.user === 'none' && c.attendantId) return false;
        if (filter.user && filter.user !== 'none' && String(c.attendantId) !== filter.user) return false;
        if (filter.q) {
            const q = filter.q.toLowerCase();
            return c.name.toLowerCase().includes(q) || c.title.toLowerCase().includes(q) || c.numero.includes(q) || (c.email || '').toLowerCase().includes(q);
        }
        return true;
    });

    function ageClass(c) {
        if (c.waiting && c.minutes > 60) return 'late';
        if (c.waiting && c.minutes > 15) return 'warn';
        return '';
    }

    const TYPE = {privado: ['Privado', 'ti-building'], publico: ['Público', 'ti-building-bank']};

    function card(c) {
        const att = c.attendant
            ? `<span class="crm-avatar crm-avatar-sm" style="--h:${(c.attendant.split('').reduce((a, ch) => (a * 31 + ch.charCodeAt(0)) % 360, 7))}" title="${esc(c.attendant)}">${esc(c.attendant.split(/\s+/).map((p, i, a) => (i === 0 || i === a.length - 1) ? p[0] : '').join('').toUpperCase())}</span>`
            : '<span class="crm-chip crm-chip-red"><i class="ti ti-user-question"></i> Sem atendente</span>';
        const [typeLabel, typeIcon] = TYPE[c.clientType] || TYPE.privado;
        const sub = c.title !== c.name ? c.name : c.numero;
        return `<div class="crm-card crm-deal" draggable="${data.edit}" data-id="${c.id}" data-url="${esc(c.url)}">
            <div class="d-flex align-items-start gap-2">
                <span class="crm-avatar crm-avatar-md" style="--h:${c.hue}">${esc(c.initials)}</span>
                <div class="flex-grow-1 text-truncate"><div class="fw-bold text-truncate">${esc(c.title)}</div>
                    <div class="small text-secondary text-truncate">${esc(sub)}</div></div>
                <span class="crm-age ${ageClass(c)}" title="Última atividade"><i class="ti ti-clock"></i> ${esc(c.ago)}</span>
            </div>
            <div class="crm-deal-value">${c.value ? brl(c.value) : '<span class="text-secondary">Sem valor</span>'}</div>
            <div class="crm-deal-msg text-truncate-2">${c.waiting ? '<i class="ti ti-message-circle-exclamation text-warning"></i> ' : ''}${esc(c.subject || c.last) || '<span class="text-secondary">Sem mensagens</span>'}</div>
            <div class="crm-card-meta">${att}
                <span class="crm-type ${esc(c.clientType)}"><i class="ti ${typeIcon}"></i> ${typeLabel}</span>
                <span class="crm-channel icon-only" style="--c:${esc(c.sourceColor)}" title="${esc(c.source)}"><span class="crm-channel-ico"><i class="ti ${esc(c.sourceIcon)}"></i></span></span>
                ${c.docs ? `<span class="crm-chip" title="Documentos anexados"><i class="ti ti-paperclip"></i> ${c.docs}</span>` : ''}
                ${c.editalMissing ? '<span class="crm-chip crm-chip-red" title="Cliente público sem edital anexado"><i class="ti ti-alert-triangle"></i> Edital</span>' : ''}
                ${c.deadline ? `<span class="crm-chip" title="Prazo"><i class="ti ti-calendar-due"></i> ${esc(c.deadline)}</span>` : ''}
            </div>
        </div>`;
    }

    function render() {
        const cards = visible();
        const prob = Object.fromEntries(data.stages.map((s) => [s.id, s.probability]));
        const total = cards.reduce((a, c) => a + c.value, 0);
        const weighted = cards.reduce((a, c) => a + c.value * (prob[c.etapa] || 0) / 100, 0);

        const users = Object.entries(data.attendants).map(([id, n]) => `<option value="${id}" ${filter.user === id ? 'selected' : ''}>${esc(n)}</option>`).join('');
        let html = `<div class="crm-toolbar">
            <div class="crm-search"><i class="ti ti-search"></i><input type="search" class="form-control" placeholder="Buscar contato, empresa ou número" value="${esc(filter.q)}" data-q></div>
            <select class="form-select" style="max-width:170px" data-type><option value="">Todos os clientes</option><option value="privado" ${filter.type === 'privado' ? 'selected' : ''}>Privados</option><option value="publico" ${filter.type === 'publico' ? 'selected' : ''}>Públicos</option></select>
            <select class="form-select" style="max-width:220px" data-user><option value="">Todos os atendentes</option><option value="none" ${filter.user === 'none' ? 'selected' : ''}>Sem atendente</option>${users}</select>
            <div class="crm-summary">
                <div><span>Oportunidades</span><strong>${cards.length}</strong></div>
                <div><span>Em aberto</span><strong>${brl(total)}</strong></div>
                <div><span>Previsão ponderada</span><strong>${brl(weighted)}</strong></div>
                <div><span>Ganhas · 30 dias</span><strong class="text-success">${data.won}</strong></div>
                <div><span>Perdidas · 30 dias</span><strong class="text-danger">${data.lost}</strong></div>
            </div>
            ${data.edit ? `<a class="btn btn-primary ms-auto" href="${esc(boot.entry)}"><i class="ti ti-plus me-1"></i>Nova entrada</a>` : ''}
        </div><div class="crm-board crm-board-pro">`;
        data.stages.forEach((s) => {
            const list = cards.filter((c) => c.etapa === s.id);
            const sum = list.reduce((a, c) => a + c.value, 0);
            html += `<div class="crm-col"><div class="crm-col-head" style="--c:${s.color}">
                    <div class="d-flex align-items-center gap-2 w-100"><span class="crm-col-name">${esc(s.name)}</span>${s.scope === 'publico' ? '<span class="crm-type publico" title="Etapa só para clientes públicos">Público</span>' : ''}<span class="cnt">${list.length}</span></div>
                    <div class="crm-col-sum"><strong>${brl(sum)}</strong><span title="Probabilidade de fechar">${s.probability}%</span></div></div>
                <div class="crm-col-body" data-etapa="${s.id}">${list.map(card).join('') || '<div class="crm-col-empty"><i class="ti ti-inbox"></i><div>Nenhuma oportunidade</div></div>'}</div></div>`;
        });
        html += `</div><div class="crm-dropstrip" data-strip>
            <div class="crm-drop won" data-drop="won"><i class="ti ti-trophy"></i> Solte aqui para marcar como <b>Ganho</b></div>
            <div class="crm-drop lost" data-drop="lost"><i class="ti ti-circle-x"></i> Solte aqui para marcar como <b>Perdido</b></div></div>`;
        app.className = '';
        app.innerHTML = html;
        bind();
    }

    async function closeDeal(id, kind) {
        const c = data.cards.find((x) => x.id === id);
        if (!c) return;
        if (kind === 'won') {
            const out = await api('won', {id});
            toast(`${c.title}: venda registrada.`);
            return window.CRMAlerts ? window.CRMAlerts.won(out, c.title, load) : load();
        }
        modal('Marcar como perdido', `<p class="mb-2">Qual o motivo da perda de <b>${esc(c.title)}</b>?</p><input class="form-control" placeholder="Ex.: preço, escolheu concorrente, sem retorno" data-reason>`, [
            {label: 'Cancelar'},
            {label: 'Marcar como perdido', cls: 'btn-danger', run: async (back) => {
                const reason = back.querySelector('[data-reason]').value.trim();
                if (!reason) throw new Error('Informe o motivo da perda.');
                await api('lost', {id, reason});
                toast(`${c.title}: marcada como perdida.`);
                load();
            }},
        ]);
    }

    function bind() {
        const strip = app.querySelector('[data-strip]');
        app.querySelector('[data-q]').addEventListener('input', (e) => { filter.q = e.target.value; const pos = e.target.selectionStart; render(); const el = app.querySelector('[data-q]'); el.focus(); el.setSelectionRange(pos, pos); });
        app.querySelector('[data-user]').addEventListener('change', (e) => { filter.user = e.target.value; render(); });
        app.querySelector('[data-type]').addEventListener('change', (e) => { filter.type = e.target.value; render(); });

        app.querySelectorAll('.crm-deal').forEach((el) => {
            // clique abre a gaveta com o resumo da oportunidade (a ficha completa é um botão dentro dela)
            el.addEventListener('click', () => {
                if (dragging) return;
                const c = data.cards.find((x) => x.id === +el.dataset.id);
                if (c && window.CRMDetail && window.OakDrawer) window.CRMDetail.open(c); else location.href = el.dataset.url;
            });
            el.addEventListener('dragstart', (e) => {
                dragging = true;
                el.classList.add('dragging');
                strip.classList.add('show');
                e.dataTransfer.setData('text/plain', el.dataset.id);
                e.dataTransfer.effectAllowed = 'move';
            });
            el.addEventListener('dragend', () => { el.classList.remove('dragging'); strip.classList.remove('show'); setTimeout(() => { dragging = false; }, 50); });
        });

        app.querySelectorAll('.crm-col-body').forEach((col) => {
            col.addEventListener('dragover', (e) => { if (data.edit) { e.preventDefault(); col.classList.add('over'); } });
            col.addEventListener('dragleave', () => col.classList.remove('over'));
            col.addEventListener('drop', async (e) => {
                e.preventDefault();
                col.classList.remove('over');
                const id = +e.dataTransfer.getData('text/plain');
                const etapa = +col.dataset.etapa;
                const c = data.cards.find((x) => x.id === id);
                if (!c || c.etapa === etapa) return;
                if (data.stages.find((s) => s.id === etapa)?.kind === 'fechamento' && window.CRMAlerts && !(await window.CRMAlerts.confirmCycle(c.title))) return;
                const before = c.etapa;
                c.etapa = etapa; // atualiza na hora; volta se o servidor recusar
                render();
                try {
                    const out = await api('move', {id, etapa});
                    toast(`${c.title} movida para ${data.stages.find((s) => s.id === etapa).name}.`);
                    if ((out.cycle || out.closing || out.paused) && window.CRMAlerts) window.CRMAlerts.won(out, c.title, load);
                } catch (err) {
                    c.etapa = before;
                    render();
                    toast(err.message, 'err');
                }
            });
        });

        strip.querySelectorAll('[data-drop]').forEach((zone) => {
            zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('over'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('over'));
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.classList.remove('over');
                strip.classList.remove('show');
                closeDeal(+e.dataTransfer.getData('text/plain'), zone.dataset.drop).catch((err) => toast(err.message, 'err'));
            });
        });
    }

    load();
    setInterval(() => { if (!dragging && !document.hidden && !document.querySelector('.crm-modal-back')) load(); }, 30000);
})();
