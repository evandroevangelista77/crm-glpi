/**
 * CRM — caixa de conversas: histórico em formato de chat, envio de mensagens,
 * atualização automática e ações da oportunidade (etapa, atendente, valor,
 * venda, perda e reabertura).
 */
(() => {
    const boot = JSON.parse(document.getElementById('crm-boot').textContent);
    const box = document.getElementById('crm-msgs');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    let lastId = 0;
    let lastDay = '';

    async function api(action, payload = {}, post = true) {
        const opts = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        let url = boot.ajax;
        if (post) {
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({action, ...payload});
        } else {
            url += '?' + new URLSearchParams({action, ...payload});
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

    const ticks = {
        enviado: '<i class="ti ti-checks" title="Enviada"></i>',
        pendente: '<i class="ti ti-clock" title="Registrada; o gateway de envio não está configurado"></i>',
        falhou: '<i class="ti ti-alert-circle text-danger" title="Falha no envio pelo gateway"></i>',
    };

    function add(list) {
        const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
        list.forEach((m) => {
            if (m.id <= lastId) return;
            lastId = m.id;
            if (m.day !== lastDay) {
                box.insertAdjacentHTML('beforeend', `<div class="crm-chat-day"><span>${esc(m.day)}</span></div>`);
                lastDay = m.day;
            }
            const out = m.dir === 'out';
            box.insertAdjacentHTML('beforeend', `<div class="crm-chat-row ${out ? 'out' : 'in'}"><div class="crm-bubble">
                <div style="white-space:pre-wrap">${esc(m.body)}</div>
                <div class="crm-bubble-time">${esc(m.time)}${out ? ' ' + (ticks[m.delivery] || '') : ''}</div></div></div>`);
        });
        if (list.length && nearBottom) box.scrollTop = box.scrollHeight;
    }

    add(boot.messages);
    if (!boot.messages.length) box.innerHTML = '<div class="text-secondary text-center py-4">Nenhuma mensagem registrada.</div>';
    box.scrollTop = box.scrollHeight;

    // mensagens novas chegam sozinhas
    setInterval(async () => {
        if (document.hidden) return;
        try {
            const out = await api('messages', {id: boot.id, after: lastId}, false);
            if (out.messages.length) add(out.messages);
        } catch (e) { /* tenta no próximo ciclo */ }
    }, 8000);

    // envio
    const text = document.getElementById('crm-text');
    const send = document.getElementById('crm-send');
    async function sendMessage() {
        const body = text.value.trim();
        if (!body) return;
        send.disabled = true;
        try {
            const out = await api('send', {id: boot.id, text: body});
            text.value = '';
            const now = new Date();
            add([{id: out.id, dir: 'out', body, day: now.toLocaleDateString('pt-BR'), time: now.toTimeString().slice(0, 5), delivery: out.delivery}]);
            box.scrollTop = box.scrollHeight;
            if (out.delivery === 'pendente') toast('Mensagem registrada. Configure o gateway de envio em CRM → Configurações para entregar ao cliente.');
            if (out.delivery === 'falhou') toast('O gateway não aceitou a mensagem.', 'err');
        } catch (e) { toast(e.message, 'err'); } finally { send.disabled = false; text.focus(); }
    }
    send?.addEventListener('click', sendMessage);
    text?.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
    text?.addEventListener('input', () => { text.style.height = 'auto'; text.style.height = Math.min(text.scrollHeight, 140) + 'px'; });

    // ações da oportunidade
    const act = async (action, payload, ok) => {
        try {
            const out = await api(action, {id: boot.id, ...payload});
            toast(ok);
            const reload = () => setTimeout(() => location.reload(), 300);
            // chegou ao fechamento: pergunta se deve iniciar o ciclo financeiro antes de recarregar
            if ((out.cycle || out.closing || out.paused) && window.CRMAlerts) window.CRMAlerts.won(out, '', reload); else reload();
        } catch (e) { toast(e.message, 'err'); }
    };
    const etapaSel = document.querySelector('[data-act="etapa"]');
    let etapaAtual = etapaSel ? etapaSel.value : '';
    etapaSel?.addEventListener('change', async (e) => {
        if (e.target.selectedOptions[0].dataset.kind === 'fechamento' && !boot.hasCycle && window.CRMAlerts && !(await window.CRMAlerts.confirmCycle(boot.title))) { e.target.value = etapaAtual; return; }
        etapaAtual = e.target.value;
        act('move', {etapa: +e.target.value}, 'Etapa atualizada.');
    });
    document.querySelector('[data-act="assign"]')?.addEventListener('change', (e) => act('assign', {user: +e.target.value}, 'Atendente atualizado.'));
    document.querySelector('button[data-act="value"]')?.addEventListener('click', () => {
        const v = document.querySelector('[data-act-input="value"]').value.replace(/\./g, '').replace(',', '.');
        act('value', {value: v}, 'Valor atualizado.');
    });
    document.querySelector('[data-act="won"]')?.addEventListener('click', () => { if (confirm('Marcar esta conversa como venda?')) act('won', {}, 'Venda registrada.'); });
    document.querySelector('[data-act="reopen"]')?.addEventListener('click', () => act('reopen', {}, 'Conversa reaberta.'));
    document.querySelector('[data-act="lost"]')?.addEventListener('click', () => {
        const reason = (prompt('Motivo da perda (obrigatório):') || '').trim();
        if (reason) act('lost', {reason}, 'Marcada como perdida.');
    });
})();
