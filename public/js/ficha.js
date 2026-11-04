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

    document.querySelectorAll('.crm-estep[data-etapa]').forEach((el) => el.addEventListener('click', async () => {
        try {
            const out = await post({action: 'move', id: boot.id, etapa: +el.dataset.etapa});
            if (out.closing && window.CRMAlerts) window.CRMAlerts.closing(out.closing, reload); else reload();
        } catch (e) {
            window.CRMAlerts?.popup({label: 'Não foi possível mover', title: e.message, icon: 'ti-alert-triangle', color: '#ef4444'}, 6);
        }
    }));

    document.getElementById('crm-start-cycle')?.addEventListener('click', () => {
        if (boot.closing && window.CRMAlerts) window.CRMAlerts.closing(boot.closing, reload);
    });
})();
