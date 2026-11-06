/**
 * CRM — Gestão de Leads: muda a situação, converte em oportunidade e exclui.
 */
(() => {
    const boot = JSON.parse(document.getElementById('crm-boot').textContent);
    async function post(payload) {
        const res = await fetch(boot.ajax, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(payload)});
        const out = await res.json().catch(() => ({ok: false, error: `Resposta inválida (HTTP ${res.status}).`}));
        if (!res.ok || out.ok === false) throw new Error(out.error || `HTTP ${res.status}`);
        return out;
    }
    const fail = (e) => window.CRMAlerts?.popup({label: 'Não foi possível concluir', title: e.message, icon: 'ti-alert-triangle', color: '#ef4444'}, 6) || alert(e.message);
    const table = document.getElementById('crm-leads');
    if (!table) return;
    table.addEventListener('change', async (e) => {
        const sel = e.target.closest('[data-lead-status]');
        if (!sel) return;
        try { await post({action: 'lead_status', id: +sel.closest('tr').dataset.id, status: sel.value}); } catch (err) { fail(err); }
    });
    table.addEventListener('click', async (e) => {
        const tr = e.target.closest('tr');
        if (e.target.closest('[data-lead-convert]')) {
            if (!confirm('Converter este lead em oportunidade do funil?')) return;
            try { const out = await post({action: 'lead_convert', id: +tr.dataset.id}); location.href = out.url; } catch (err) { fail(err); }
        } else if (e.target.closest('[data-lead-delete]')) {
            if (!confirm('Excluir este lead?')) return;
            try { await post({action: 'lead_delete', id: +tr.dataset.id}); tr.remove(); } catch (err) { fail(err); }
        }
    });
})();
