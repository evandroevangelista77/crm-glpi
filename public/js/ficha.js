/**
 * CRM — ficha da oportunidade: clicar numa etapa da esteira move a oportunidade,
 * e o aviso de fechamento pergunta se deve iniciar o ciclo financeiro.
 */
(() => {
    const boot = JSON.parse(document.getElementById('crm-boot').textContent);
    const reload = () => location.reload();

    async function post(payload) {
        const res = await fetch(boot.ajax, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(payload)});
        const out = await res.json().catch(() => ({ok: false, error: `Resposta inválida (HTTP ${res.status}).`}));
        if (!res.ok || out.ok === false) throw new Error(out.error || `HTTP ${res.status}`);
        return out;
    }

    const fail = (e) => window.CRMAlerts?.popup({label: 'Não foi possível concluir', title: e.message, icon: 'ti-alert-triangle', color: '#ef4444'}, 6);
    const after = (out) => ((out.cycle || out.closing || out.paused) && window.CRMAlerts ? window.CRMAlerts.won(out, boot.title || '', reload) : reload());
    const move = async (etapa, kind) => {
        if (kind === 'fechamento' && !boot.hasCycle && window.CRMAlerts && !(await window.CRMAlerts.confirmCycle(boot.title))) {
            const g = document.getElementById('crm-goto'); if (g) g.value = '';
            return;
        }
        try { after(await post({action: 'move', id: boot.id, etapa})); } catch (e) { fail(e); }
    };
    // passos da esteira e botão "Avançar"
    document.querySelectorAll('[data-etapa]').forEach((el) => el.addEventListener('click', () => move(+el.dataset.etapa, el.dataset.kind)));
    document.getElementById('crm-goto')?.addEventListener('change', (e) => e.target.value && move(+e.target.value, e.target.selectedOptions[0].dataset.kind));
    // venda concretizada (passo "Venda" e botões)
    document.querySelectorAll('[data-won]').forEach((el) => el.addEventListener('click', async () => {
        if (!confirm('Marcar esta oportunidade como venda concretizada?')) return;
        try { after(await post({action: 'won', id: boot.id})); } catch (e) { fail(e); }
    }));
    document.getElementById('crm-lose')?.addEventListener('click', async () => {
        const reason = prompt('Qual o motivo da perda?');
        if (!reason || !reason.trim()) return;
        try { await post({action: 'lost', id: boot.id, reason: reason.trim()}); reload(); } catch (e) { fail(e); }
    });

    document.getElementById('crm-start-cycle')?.addEventListener('click', () => {
        if (boot.closing && window.CRMAlerts) window.CRMAlerts.closing(boot.closing, reload);
    });
})();
