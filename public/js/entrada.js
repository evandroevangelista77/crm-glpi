/**
 * CRM — formulário de nova entrada: campos do cliente público, linhas de
 * documentos e a regra do edital obrigatório.
 */
(() => {
    const form = document.getElementById('crm-entry');
    if (!form) return;
    const rows = document.getElementById('crm-docrows');
    const tpl = document.getElementById('crm-docrow');
    const negotiation = document.getElementById('f-negotiation');
    const company = document.getElementById('lbl-company');

    const addRow = (type = '') => {
        const row = tpl.content.firstElementChild.cloneNode(true);
        if (type) row.querySelector('select').value = type;
        row.querySelector('[data-rm]').onclick = () => row.remove();
        rows.appendChild(row);
        return row;
    };
    document.getElementById('crm-adddoc').onclick = () => addRow();

    const isPublic = () => form.querySelector('[name=client_type]:checked').value === 'publico';
    function sync() {
        const pub = isPublic();
        form.querySelectorAll('.crm-only-publico').forEach((el) => { el.style.display = pub ? '' : 'none'; });
        company.textContent = pub ? 'Órgão / entidade' : 'Empresa';
        if (pub && ['direta'].includes(negotiation.value)) negotiation.value = 'pregao';
        if (!pub && ['pregao', 'licitacao', 'ata'].includes(negotiation.value)) negotiation.value = 'direta';
        // cliente público: garante uma linha de edital
        if (pub && ![...rows.querySelectorAll('select')].some((s) => s.value === 'edital')) addRow('edital');
    }
    form.querySelectorAll('[name=client_type]').forEach((r) => r.addEventListener('change', sync));
    addRow('pedido_orcamento');
    sync();

    form.addEventListener('submit', (e) => {
        if (!isPublic()) return;
        const ok = [...rows.querySelectorAll('.crm-docrow')].some((r) => r.querySelector('select').value === 'edital' && r.querySelector('input[type=file]').files.length);
        if (!ok) {
            e.preventDefault();
            alert('Cliente público: anexe o edital (documento do tipo "Edital").');
        }
    });
})();
