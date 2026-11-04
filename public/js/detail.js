/**
 * CRM — componentes compartilhados do dashboard e do funil: dica ao passar o mouse
 * (qualquer elemento com data-tip="Título|linha|linha") e a gaveta lateral com a lista
 * de oportunidades e a ficha resumida de cada uma (usa OakDrawer, o mesmo painel dos
 * outros plugins).
 */
(() => {
    if (window.CRMDetail) return;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL', maximumFractionDigits: 0});
    const TYPE = {privado: ['Privado', 'ti-building'], publico: ['Público', 'ti-building-bank']};

    // ---- dica ao passar o mouse
    let tip;
    const tipEl = () => (tip ??= document.body.appendChild(Object.assign(document.createElement('div'), {className: 'crm-tip', hidden: true})));
    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest?.('[data-tip]');
        if (!el) return;
        const [head, ...rest] = el.dataset.tip.split('|');
        const t = tipEl();
        t.innerHTML = `<b>${esc(head)}</b>${rest.map((l) => `<span>${esc(l)}</span>`).join('')}`;
        t.hidden = false;
    });
    document.addEventListener('mousemove', (e) => {
        if (!tip || tip.hidden) return;
        const pad = 14;
        const x = Math.min(e.clientX + pad, window.innerWidth - tip.offsetWidth - 8);
        const y = e.clientY + pad + tip.offsetHeight > window.innerHeight ? e.clientY - tip.offsetHeight - pad : e.clientY + pad;
        tip.style.transform = `translate(${Math.max(8, x)}px, ${Math.max(8, y)}px)`;
    });
    document.addEventListener('mouseout', (e) => {
        if (tip && e.target.closest?.('[data-tip]') && !e.relatedTarget?.closest?.('[data-tip]')) tip.hidden = true;
    });

    // ---- peças da gaveta
    const ring = (p, color) => `<svg class="crm-ring" viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.9" fill="none" class="bg" stroke-width="3.4"/>
        <circle cx="18" cy="18" r="15.9" fill="none" stroke="${color}" stroke-width="3.4" stroke-linecap="round" stroke-dasharray="${p} ${100 - p}" stroke-dashoffset="25"/><text x="18" y="21.5" text-anchor="middle">${p}%</text></svg>`;
    const avatar = (it, size = 'md') => `<span class="crm-avatar crm-avatar-${size}" style="--h:${it.hue}">${esc(it.initials)}</span>`;
    const typeBadge = (it) => { const [l, i] = TYPE[it.clientType] || TYPE.privado; return `<span class="crm-type ${esc(it.clientType)}"><i class="ti ${i}"></i> ${l}</span>`; };
    const channel = (it) => `<span class="crm-channel" style="--c:${esc(it.sourceColor)}"><span class="crm-channel-ico"><i class="ti ${esc(it.sourceIcon)}"></i></span><span class="crm-channel-name">${esc(it.source)}</span></span>`;
    const steps = (it) => `<div class="crm-mini-steps">${it.steps.map((s, i) => `<div class="crm-mini ${s.state}"><span>${s.state === 'done' ? '<i class="ti ti-check"></i>' : i + 1}</span><em>${esc(s.name)}</em></div>`).join('')}</div>`;
    const stageBadge = (it) => `<span class="badge crm-badge" style="--c:${esc(it.stageColor)}">${esc(it.stage)}</span>`;

    function row(it) {
        const flags = [
            it.waiting ? '<span class="crm-chip crm-chip-red" title="O cliente falou por último"><i class="ti ti-message-circle-exclamation"></i> Aguardando</span>' : '',
            !it.attendant && it.status === 1 ? '<span class="crm-chip crm-chip-red"><i class="ti ti-user-question"></i> Sem atendente</span>' : '',
            it.editalMissing ? '<span class="crm-chip crm-chip-red"><i class="ti ti-alert-triangle"></i> Edital</span>' : '',
        ].join('');
        return `<div class="crm-dr-item" data-item="${it.id}" role="button" tabindex="0" style="--oc:${esc(it.stageColor)}">
            ${avatar(it)}
            <div class="crm-dr-main"><div class="d-flex justify-content-between gap-2"><strong class="text-truncate">${esc(it.title)}</strong><span class="crm-dr-val">${it.value ? brl(it.value) : ''}</span></div>
                <div class="small text-secondary text-truncate">${esc(it.subject || it.name)}</div>
                <div class="d-flex flex-wrap gap-1 align-items-center mt-1">${stageBadge(it)} ${typeBadge(it)} ${flags}<span class="ms-auto small text-secondary"><i class="ti ti-clock"></i> ${esc(it.ago)}</span></div></div>
            <div class="crm-dr-prob" title="Chance de fechar">${ring(it.prob, it.stageColor)}</div></div>`;
    }

    function detail(it, hasBack) {
        const phone = String(it.numero || '').replace(/\D/g, '');
        const info = [
            ['Contato', it.name], ['E-mail', it.email ? `<a href="mailto:${esc(it.email)}">${esc(it.email)}</a>` : ''],
            ['Telefone', phone ? `<a href="https://wa.me/${phone}" target="_blank" rel="noopener">${esc(it.numero)}</a>` : ''], ['CNPJ', esc(it.cnpj)],
            ['Atendente', it.attendant ? esc(it.attendant) : '<span class="text-danger">Sem atendente</span>'], ['Entrada', `${esc(it.created)} · ${esc(it.source)}`],
            ['Última atividade', `há ${esc(it.ago)}`], ['Prazo de resposta', esc(it.deadline)],
            ...(it.clientType === 'publico' ? [['Nº do edital', esc(it.edital)], ['Sessão / pregão', esc(it.session)]] : []),
            ['Documentos', it.docs ? `${it.docs} anexo(s)` : 'nenhum'],
        ].filter(([, v]) => v);
        const alerts = [
            it.waiting ? '<div class="alert alert-warning py-2 mb-2"><i class="ti ti-message-circle-exclamation me-1"></i>O cliente falou por último. Responda para não esfriar a negociação.</div>' : '',
            it.editalMissing ? '<div class="alert alert-danger py-2 mb-2"><i class="ti ti-gavel me-1"></i>Cliente público sem edital anexado.</div>' : '',
        ].join('');
        const closing = it.status === 2 || it.stageKind === 'fechamento';
        return `${hasBack ? '<button type="button" class="btn btn-sm btn-outline-secondary mb-3" data-back><i class="ti ti-arrow-left me-1"></i>Voltar à lista</button>' : ''}
            <div class="d-flex align-items-center gap-3 mb-3">${avatar(it, 'lg')}<div class="flex-grow-1" style="min-width:0"><h4 class="mb-1 text-truncate">${esc(it.title)}</h4>
                <div class="d-flex flex-wrap gap-2 align-items-center">${typeBadge(it)} ${stageBadge(it)} ${channel(it)}</div></div></div>
            ${it.subject ? `<p class="text-secondary">${esc(it.subject)}</p>` : ''}
            ${alerts}
            <div class="crm-dr-tiles"><div><span>Valor</span><strong>${it.value ? brl(it.value) : '—'}</strong></div>
                <div><span>Chance de fechar</span><div class="crm-dr-ring">${ring(it.prob, it.stageColor)}</div></div>
                <div><span>Valor esperado</span><strong class="text-success">${it.expected ? brl(it.expected) : '—'}</strong></div></div>
            <div class="crm-side-title">Esteira de venda</div>${steps(it)}
            <div class="crm-side-title mt-3">Dados</div><dl class="crm-dl crm-dl-wide">${info.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>
            <div class="d-grid gap-2 mt-3">
                <a class="btn btn-primary" href="${esc(it.url)}"><i class="ti ti-id me-1"></i>Abrir ficha completa</a>
                <div class="d-flex gap-2"><a class="btn btn-outline-primary flex-fill" href="${esc(it.chat)}"><i class="ti ti-message-2 me-1"></i>Conversa</a>
                    ${phone ? `<a class="btn btn-outline-success flex-fill" href="https://wa.me/${phone}" target="_blank" rel="noopener"><i class="ti ti-brand-whatsapp me-1"></i>WhatsApp</a>` : ''}</div>
                ${closing ? `<a class="btn btn-outline-secondary" href="${esc(it.report)}"><i class="ti ti-file-type-pdf me-1"></i>Relatório da negociação (PDF)</a>` : ''}
            </div>`;
    }

    function open(it, back = null) {
        window.OakDrawer.open({title: it.code, icon: 'ti-id', color: it.stageColor, html: detail(it, !!back)});
        const body = document.querySelector('.oak-drawer-body');
        body?.querySelector('[data-back]')?.addEventListener('click', back);
    }

    // lista de oportunidades por trás de um número: {title, sub, icon, color}, itens já resolvidos
    function list(meta, items) {
        const total = items.reduce((a, i) => a + i.value, 0);
        const expected = items.reduce((a, i) => a + i.expected, 0);
        window.OakDrawer.open({title: meta.title, icon: meta.icon, color: meta.color, html: `<p class="oak-dr-lead">${esc(meta.sub || '')}</p>
            <div class="crm-dr-summary"><div><span>Oportunidades</span><strong>${items.length}</strong></div><div><span>Valor</span><strong>${brl(total)}</strong></div><div><span>Esperado</span><strong class="text-success">${brl(expected)}</strong></div></div>
            <div class="crm-dr-list">${items.map(row).join('') || '<div class="text-secondary text-center py-4">Nada por aqui.</div>'}</div>`});
        const body = document.querySelector('.oak-drawer-body');
        const go = (el) => { const it = items.find((x) => x.id === +el.dataset.item); if (it) open(it, () => list(meta, items)); };
        body?.addEventListener('click', (e) => { const el = e.target.closest('[data-item]'); if (el) go(el); });
        body?.addEventListener('keydown', (e) => { const el = e.target.closest('[data-item]'); if (el && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); go(el); } });
    }

    window.CRMDetail = {esc, brl, ring, avatar, typeBadge, open, list, row};
})();
