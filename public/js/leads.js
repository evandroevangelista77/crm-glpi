/**
 * CRM — Gestão de Leads: clicar num lead abre a gaveta lateral com o resumo completo;
 * dá para mudar a situação, converter em oportunidade e excluir (pela lista ou pela gaveta).
 */
(() => {
    const boot = JSON.parse(document.getElementById('crm-boot').textContent);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    async function post(payload) {
        const res = await fetch(boot.ajax, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(payload)});
        const out = await res.json().catch(() => ({ok: false, error: `Resposta inválida (HTTP ${res.status}).`}));
        if (!res.ok || out.ok === false) throw new Error(out.error || `HTTP ${res.status}`);
        return out;
    }
    const fail = (e) => window.CRMAlerts?.popup({label: 'Não foi possível concluir', title: e.message, icon: 'ti-alert-triangle', color: '#ef4444'}, 6) || alert(e.message);
    const table = document.getElementById('crm-leads');
    if (!table) return;

    const badge = (m, key) => { const x = m[key] || {label: '—', color: '#64748b', icon: 'ti-help'}; return `<span class="badge crm-badge" style="--c:${esc(x.color)}"><i class="ti ${esc(x.icon)} me-1"></i>${esc(x.label)}</span>`; };
    const row = (icon, label, value) => value ? `<div class="crm-lead-field"><i class="ti ${icon}"></i><div><span>${esc(label)}</span><div>${value}</div></div></div>` : '';
    const phoneDigits = (p) => String(p).replace(/\D+/g, '');

    // cartões de totais: a gaveta lista os leads do grupo; clicar num deles abre o resumo (com botão para voltar à lista)
    const GROUPS = {
        all: ['Todos os leads de eventos', 'ti-users', '#3b82f6', () => true],
        novo: ['Novos, sem contato', 'ti-sparkles', '#f59e0b', (l) => l.status === 'novo'],
        quente: ['Leads quentes', 'ti-flame', '#ef4444', (l) => l.temp === 'quente'],
        convertido: ['Convertidos em oportunidade', 'ti-circle-check', '#22c55e', (l) => l.status === 'convertido'],
    };
    function openGroup(key) {
        const [title, icon, color, test] = GROUPS[key];
        const items = boot.order.map((id) => boot.leads[id]).filter((l) => l && test(l));
        const events = new Set(items.map((l) => l.event)).size;
        const rows = items.map((l) => `<div class="crm-dr-item crm-lead-li" data-lead="${l.id}" role="button" tabindex="0" style="--oc:${esc((boot.temps[l.temp] || {}).color || '#64748b')}">
            <span class="crm-avatar crm-avatar-md" style="--h:${l.hue}">${esc(l.initials)}</span>
            <div class="crm-dr-main"><div class="d-flex justify-content-between gap-2"><strong class="text-truncate">${esc(l.name)}</strong><span class="small text-secondary">há ${esc(l.ago)}</span></div>
                <div class="small text-secondary text-truncate">${esc([l.job, l.company].filter(Boolean).join(' · '))}</div>
                <div class="d-flex flex-wrap gap-1 mt-1">${badge(boot.temps, l.temp)} ${badge(boot.statuses, l.status)} <span class="crm-chip"><i class="ti ti-calendar-event"></i> ${esc(l.event)}</span></div></div></div>`).join('');
        window.OakDrawer.open({title, icon, color, html: `<div class="crm-dr-summary"><div><span>Leads</span><strong>${items.length}</strong></div><div><span>Eventos</span><strong>${events}</strong></div></div>
            <div class="crm-dr-list">${rows || '<div class="text-secondary text-center py-4">Nenhum lead neste grupo.</div>'}</div>`});
        const body = document.querySelector('.oak-drawer-body');
        const go = (el) => open(+el.dataset.lead, key);
        body.querySelectorAll('[data-lead]').forEach((el) => {
            el.addEventListener('click', () => go(el));
            el.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(el); });
        });
    }
    document.querySelectorAll('[data-kpi]').forEach((el) => {
        const run = () => openGroup(el.dataset.kpi);
        el.addEventListener('click', run);
        el.addEventListener('keydown', (e) => { if (e.key === 'Enter') run(); });
    });

    function open(id, fromGroup = '') {
        const l = boot.leads[id];
        if (!l) return;
        const statusOptions = Object.entries(boot.statuses).filter(([k]) => k !== 'convertido').map(([k, s]) => `<option value="${k}" ${l.status === k ? 'selected' : ''}>${esc(s.label)}</option>`).join('');
        const wa = phoneDigits(l.phone);
        const html = `${fromGroup ? `<button type="button" class="btn btn-sm btn-outline-secondary mb-3" data-dr-back><i class="ti ti-arrow-left me-1"></i>Voltar à lista</button>` : ''}<div class="crm-lead-head">
                <span class="crm-avatar crm-avatar-lg" style="--h:${l.hue}">${esc(l.initials)}</span>
                <div class="flex-grow-1"><h4 class="mb-0">${esc(l.name)}</h4><div class="text-secondary">${esc([l.job, l.company].filter(Boolean).join(' · ') || '—')}</div>
                    <div class="d-flex flex-wrap gap-2 mt-2" data-dr-badges>${badge(boot.temps, l.temp)} ${badge(boot.statuses, l.status)}</div></div></div>
            <div class="crm-lead-section">Contato</div>
            ${row('ti-mail', 'E-mail', l.email ? `<a href="mailto:${esc(l.email)}">${esc(l.email)}</a>` : '')}
            ${row('ti-phone', 'Telefone', l.phone ? `${esc(l.phone)}${wa.length >= 10 ? ` &nbsp;<a href="https://wa.me/${wa}" target="_blank" rel="noopener" title="Abrir no WhatsApp"><i class="ti ti-brand-whatsapp"></i> WhatsApp</a>` : ''}` : '')}
            ${row('ti-building', 'Empresa', esc(l.company))}
            ${row('ti-id-badge-2', 'Cargo', esc(l.job))}
            <div class="crm-lead-section">Evento de origem</div>
            ${row('ti-calendar-event', 'Evento', esc(l.event))}
            ${row('ti-hash', 'Código do evento', esc(l.eventCode))}
            ${row('ti-calendar', 'Data do evento', esc(l.eventDate))}
            ${row('ti-clock', 'Captado', `${esc(l.captured)} <span class="text-secondary">(há ${esc(l.ago)})</span>`)}
            <div class="crm-lead-section">Interesse</div>
            ${row('ti-target-arrow', 'Interesse principal', esc(l.interest))}
            ${row('ti-notes', 'Observações do contato', l.notes ? esc(l.notes).replace(/\n/g, '<br>') : '<span class="text-secondary">Sem observações.</span>')}
            ${l.siblings > 1 ? row('ti-users', 'Outros eventos', `Este e-mail aparece em <b>${l.siblings}</b> eventos: lead recorrente.`) : ''}
            <div class="crm-lead-section">Acompanhamento</div>
            ${l.oppUrl ? row('ti-briefcase', 'Oportunidade', `<a href="${esc(l.oppUrl)}">${esc(l.oppCode)} — abrir a ficha</a>`) : ''}
            ${row('ti-refresh', 'Atualizado', esc(l.updated))}
            ${boot.canCreate && !l.oppUrl ? `<div class="crm-lead-actions"><label class="form-label mb-1">Situação</label>
                <select class="form-select mb-2" data-dr-status>${statusOptions}</select>
                <div class="d-flex gap-2"><button type="button" class="btn btn-primary flex-grow-1" data-dr-convert><i class="ti ti-arrow-right me-1"></i>Converter em oportunidade</button>
                <button type="button" class="btn btn-outline-danger" data-dr-delete title="Excluir lead"><i class="ti ti-trash"></i></button></div></div>` : ''}`;
        window.OakDrawer.open({title: 'Resumo do lead', icon: 'ti-user-star', color: (boot.temps[l.temp] || {}).color || '#3b82f6', html});
        const body = document.querySelector('.oak-drawer-body');
        body.querySelector('[data-dr-back]')?.addEventListener('click', () => openGroup(fromGroup));
        body.querySelector('[data-dr-status]')?.addEventListener('change', async (e) => {
            try {
                await post({action: 'lead_status', id, status: e.target.value});
                l.status = e.target.value;
                body.querySelector('[data-dr-badges]').innerHTML = `${badge(boot.temps, l.temp)} ${badge(boot.statuses, l.status)}`;
                const sel = table.querySelector(`tr[data-id="${id}"] [data-lead-status]`);
                if (sel) sel.value = l.status;
            } catch (err) { fail(err); }
        });
        body.querySelector('[data-dr-convert]')?.addEventListener('click', async () => {
            if (!confirm('Converter este lead em oportunidade do funil?')) return;
            try { const out = await post({action: 'lead_convert', id}); location.href = out.url; } catch (err) { fail(err); }
        });
        body.querySelector('[data-dr-delete]')?.addEventListener('click', async () => {
            if (!confirm('Excluir este lead?')) return;
            try { await post({action: 'lead_delete', id}); table.querySelector(`tr[data-id="${id}"]`)?.remove(); document.querySelector('.oak-drawer .btn-close')?.click(); } catch (err) { fail(err); }
        });
    }

    table.addEventListener('change', async (e) => {
        const sel = e.target.closest('[data-lead-status]');
        if (!sel) return;
        const id = +sel.closest('tr').dataset.id;
        try { await post({action: 'lead_status', id, status: sel.value}); if (boot.leads[id]) boot.leads[id].status = sel.value; } catch (err) { fail(err); }
    });
    table.addEventListener('click', async (e) => {
        const tr = e.target.closest('tr');
        if (!tr) return;
        if (e.target.closest('[data-lead-convert]')) {
            if (!confirm('Converter este lead em oportunidade do funil?')) return;
            try { const out = await post({action: 'lead_convert', id: +tr.dataset.id}); location.href = out.url; } catch (err) { fail(err); }
        } else if (e.target.closest('[data-lead-delete]')) {
            if (!confirm('Excluir este lead?')) return;
            try { await post({action: 'lead_delete', id: +tr.dataset.id}); tr.remove(); } catch (err) { fail(err); }
        } else if (!e.target.closest('select, a, button') && tr.dataset.id) {
            open(+tr.dataset.id);
        }
    });
    table.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('tr[data-id]')) open(+e.target.dataset.id); });
})();
