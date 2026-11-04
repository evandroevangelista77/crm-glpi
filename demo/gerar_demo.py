# Gera o SQL e os PDFs de demonstração do CRM (rode: python3 gerar_demo.py)
# Carregar no GLPI: mysql <banco-do-glpi> < crm-demo.sql  e copiar documentos/*.pdf para files/_plugins/crm/
# SQL e documentos de demonstração do CRM 0.3 (tudo com "[Demo]" no nome, para remover depois).
import os, random, hashlib
S = os.path.dirname(os.path.abspath(__file__))
DOCS = S + "/documentos"
os.makedirs(DOCS, exist_ok=True)
for f in os.listdir(DOCS):
    os.remove(os.path.join(DOCS, f))

def pdf(lines):
    """PDF mínimo de uma página com as linhas de texto (suficiente para abrir e visualizar)."""
    esc = lambda t: t.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
    content = "BT /F1 16 Tf 56 780 Td 20 TL\n" + "\n".join(f"({esc(l)}) Tj T*" for l in lines) + "\nET"
    objs = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        f"<< /Length {len(content.encode('latin-1', 'replace'))} >>\nstream\n{content}\nendstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    out = b"%PDF-1.4\n"
    offs = []
    for i, o in enumerate(objs, 1):
        offs.append(len(out))
        out += f"{i} 0 obj\n".encode() + o.encode("latin-1", "replace") + b"\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objs) + 1}\n0000000000 65535 f \n".encode()
    for o in offs:
        out += f"{o:010d} 00000 n \n".encode()
    out += f"trailer\n<< /Size {len(objs) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    return out

STAGES = ["Entrada", "Qualificação", "Análise do edital", "Elaboração do orçamento", "Orçamento enviado", "Negociação", "Fechamento"]
D = lambda **k: k
E = []  # entradas
def add(**k):
    k.setdefault("msgs", []); k.setdefault("docs", []); k.setdefault("att", 0); k.setdefault("closed", None); k.setdefault("reason", None)
    k.setdefault("edital", None); k.setdefault("session", None); k.setdefault("deadline", None); k.setdefault("notes", ""); k.setdefault("neg", "direta")
    E.append(k)

# ---------- abertas: privadas
add(contact="Marina Duarte", company="Clínica Vida Saudável", cnpj="12.345.678/0001-90", email="marina@vidasaudavel.exemplo.com.br", phone="5511911110001", ctype="privado", ch="whatsapp", subject="Sistema de agendamento para 6 médicos", value=18500, stage=0, status=1, att=1, created=1, last=35, deadline=5,
    msgs=[("in","Oi, vocês atendem clínicas com agendamento online?"),("out","Olá! Atendemos sim. Quantos profissionais vocês têm?"),("in","Somos 6 médicos e 2 recepções.")], docs=[("pedido_orcamento","Pedido de orçamento - Clinica Vida Saudavel.pdf")])
add(contact="Paulo Ribeiro", company="Mercado Bom Preço", cnpj="23.456.789/0001-01", email="paulo@bompreco.exemplo.com.br", phone="5511911110002", ctype="privado", ch="whatsapp", subject="Implantação em 3 lojas", value=9200, stage=0, status=1, att=0, created=0, last=12, deadline=7,
    msgs=[("in","Quanto custa a implantação para 3 lojas?")], docs=[("pedido_orcamento","RFQ Mercado Bom Preco.pdf")])
add(contact="Carla Mendes", company="Auto Peças Central", cnpj="34.567.890/0001-12", email="carla@autopecascentral.exemplo.com.br", phone="5511911110003", ctype="privado", ch="indicacao", subject="Proposta para 3 unidades", value=32000, stage=1, status=1, att=1, created=3, last=1440, deadline=10,
    msgs=[("in","Preciso de uma proposta para 3 unidades."),("out","Perfeito, envio a proposta ainda hoje."),("out","Enviei por e-mail, conseguiu ver?")], docs=[("pedido_orcamento","Pedido de orcamento - Auto Pecas Central.pdf")])
add(contact="Renato Alves", company="Rede Farma Mais", cnpj="45.678.901/0001-23", email="renato.alves@farmamais.exemplo.com.br", phone="5511911110005", ctype="privado", ch="email", subject="Contrato de suporte 24x7 para 12 filiais", value=54000, stage=3, status=1, att=1, created=12, last=95,
    docs=[("pedido_orcamento","Solicitacao de proposta - Farma Mais.pdf"),("proposta_tecnica","Proposta tecnica v1 - Farma Mais.pdf")])
add(contact="Juliana Prado", company="Construtora Alvorada", cnpj="56.789.012/0001-34", email="juliana@alvoradaconstrutora.exemplo.com.br", phone="5511911110004", ctype="privado", ch="telefone", subject="Gestão de obras: 3 canteiros", value=86000, stage=5, status=1, att=2, created=9, last=180,
    msgs=[("in","Podemos marcar a reunião para amanhã?"),("out","Claro, qual horário funciona melhor?"),("in","Pode ser às 14h, com o diretor financeiro.")], docs=[("pedido_orcamento","Escopo Alvorada.pdf"),("orcamento","Orcamento 0412 - Construtora Alvorada.pdf")])
add(contact="Eduardo Lima", company="Escola Aprender", cnpj="67.890.123/0001-45", email="eduardo@escolaaprender.exemplo.com.br", phone="5511911110006", ctype="privado", ch="site", subject="Plataforma para 140 alunos", value=14800, stage=1, status=1, att=2, created=5, last=55,
    msgs=[("in","Vocês têm plano para escolas pequenas?"),("out","Temos sim, a partir de 100 alunos."),("in","Temos 140. Pode me mandar valores?")], docs=[("pedido_orcamento","Formulario site - Escola Aprender.pdf")])
add(contact="Larissa Gomes", company="Loja Moda Sul", cnpj="78.901.234/0001-56", email="larissa@modasul.exemplo.com.br", phone="5511911110007", ctype="privado", ch="marketing", subject="Integração com e-commerce", value=6400, stage=0, status=1, att=0, created=0, last=40,
    msgs=[("in","Vocês integram com e-commerce?")])
add(contact="Fábio Teixeira", company="Transportes Rápido", cnpj="89.012.345/0001-67", email="fabio@transportesrapido.exemplo.com.br", phone="5511911110008", ctype="privado", ch="whatsapp", subject="Rastreamento de frota (60 veículos)", value=22000, stage=0, status=1, att=0, created=0, last=8,
    msgs=[("in","Bom dia, qual o prazo de implantação?"),("in","Preciso para o mês que vem.")])
add(contact="Sérgio Nogueira", company="Hotel Vista Mar", cnpj="90.123.456/0001-78", email="sergio@hotelvistamar.exemplo.com.br", phone="5511911110009", ctype="privado", ch="indicacao", subject="Sistema de reservas e canais", value=125000, stage=5, status=1, att=3, created=15, last=300,
    msgs=[("out","Ajustei a proposta com o desconto combinado."),("in","Ótimo, só preciso da aprovação da diretoria.")], docs=[("pedido_orcamento","Briefing Hotel Vista Mar.pdf"),("orcamento","Orcamento 0398 - Vista Mar.pdf"),("proposta_tecnica","Proposta tecnica - Vista Mar.pdf")])
add(contact="Aline Barros", company="Academia Corpo & Mente", cnpj="01.234.567/0001-89", email="aline@corpoemente.exemplo.com.br", phone="5511911110010", ctype="privado", ch="site", subject="App de treinos e cobrança", value=11500, stage=1, status=1, att=3, created=6, last=2000,
    msgs=[("in","Gostaria de ver uma demonstração."),("out","Posso na quinta às 10h. Funciona?")], docs=[("pedido_orcamento","Formulario site - Corpo e Mente.pdf")])
add(contact="Tatiane Couto", company="Imobiliária Lar Doce Lar", cnpj="11.222.333/0001-44", email="tatiane@lardocelar.exemplo.com.br", phone="5511911110011", ctype="privado", ch="indicacao", subject="CRM imobiliário, 18 corretores", value=47000, stage=6, status=1, att=1, created=20, last=3000,
    msgs=[("in","Tudo certo com a proposta, podemos fechar!"),("out","Ótima notícia! Preparo o contrato.")], docs=[("pedido_orcamento","Pedido Lar Doce Lar.pdf"),("orcamento","Orcamento 0377 - Lar Doce Lar.pdf"),("pedido_compra","PO 5581 - Lar Doce Lar.pdf")])
add(contact="Bruno Sampaio", company="Restaurante Sabor da Terra", cnpj="22.333.444/0001-55", email="bruno@sabordaterra.exemplo.com.br", phone="5511911110012", ctype="privado", ch="presencial", subject="Cardápio digital e PDV", value=5200, stage=0, status=1, att=2, created=1, last=25,
    msgs=[("in","Boa tarde! Conheci vocês na feira.")])
add(contact="Viviane Costa", company="Contabilidade Prime", cnpj="33.444.555/0001-66", email="viviane@contabprime.exemplo.com.br", phone="5511911110013", ctype="privado", ch="email", subject="Suporte e backup gerenciado", value=16900, stage=3, status=1, att=0, created=2, last=70,
    docs=[("pedido_orcamento","Solicitacao Contabilidade Prime.pdf")])
add(contact="Otávio Menezes", company="Indústria Metalsul", cnpj="44.555.666/0001-77", email="otavio@metalsul.exemplo.com.br", phone="5511911110014", ctype="privado", ch="indicacao", subject="Monitoramento industrial (planta 2)", value=210000, stage=5, status=1, att=2, created=18, last=600,
    msgs=[("in","Mandamos a especificação técnica por e-mail."),("out","Recebi! Retorno com a proposta amanhã.")], docs=[("pedido_orcamento","Especificacao tecnica Metalsul.pdf"),("orcamento","Orcamento 0405 - Metalsul.pdf")])
# ---------- abertas: públicas
add(contact="Dra. Helena Duarte", company="Prefeitura Municipal de Itu", cnpj="45.123.456/0001-00", email="compras@itu.exemplo.sp.gov.br", phone="5511922220001", ctype="publico", ch="portal", neg="pregao", subject="Pregão: licenças e suporte de TI", value=380000, stage=2, status=1, att=1, created=6, last=500, edital="Pregão Eletrônico 045/2026", session=12, deadline=9,
    docs=[("edital","Edital PE 045-2026 - Prefeitura de Itu.pdf"),("pedido_orcamento","Termo de referencia - Itu.pdf")])
add(contact="Cel. Roberto Faria", company="Secretaria de Segurança Pública", cnpj="46.234.567/0001-11", email="licitacoes@ssp.exemplo.gov.br", phone="5511922220002", ctype="publico", ch="portal", neg="licitacao", subject="Concorrência: câmeras corporais (500 unid.)", value=1850000, stage=3, status=1, att=2, created=14, last=240, edital="Concorrência 012/2026", session=20, deadline=14,
    docs=[("edital","Edital Concorrencia 012-2026 - SSP.pdf"),("proposta_tecnica","Proposta tecnica - SSP.pdf")])
add(contact="Prof. Marcos Teles", company="Universidade Estadual do Vale", cnpj="47.345.678/0001-22", email="compras@uev.exemplo.edu.br", phone="5511922220003", ctype="publico", ch="email", neg="pregao", subject="Pregão: rede e Wi-Fi do campus", value=920000, stage=4, status=1, att=1, created=16, last=720, edital="Pregão Eletrônico 118/2026", session=6, deadline=3,
    docs=[("edital","Edital PE 118-2026 - UEV.pdf"),("orcamento","Planilha de precos - UEV.pdf")])
add(contact="Sra. Denise Moura", company="Tribunal Regional do Trabalho", cnpj="48.456.789/0001-33", email="contratos@trt.exemplo.jus.br", phone="5511922220004", ctype="publico", ch="portal", neg="ata", subject="Ata de registro de preços: backup", value=640000, stage=2, status=1, att=0, created=3, last=100, edital="ARP 007/2026", session=15, deadline=10,
    docs=[("edital","Edital ARP 007-2026 - TRT.pdf")])
add(contact="Dr. Álvaro Pires", company="Hospital Municipal Santa Luzia", cnpj="49.567.890/0001-44", email="suprimentos@hmsl.exemplo.gov.br", phone="5511922220005", ctype="publico", ch="portal", neg="pregao", subject="Pregão: prontuário eletrônico", value=1250000, stage=6, status=1, att=2, created=28, last=1300, edital="Pregão Eletrônico 201/2026", session=-3, deadline=None,
    docs=[("edital","Edital PE 201-2026 - Santa Luzia.pdf"),("orcamento","Proposta comercial - Santa Luzia.pdf"),("contrato","Minuta de contrato - Santa Luzia.pdf")])
add(contact="Sr. Cláudio Neves", company="Prefeitura de Cotia", cnpj="50.678.901/0001-55", email="compras@cotia.exemplo.sp.gov.br", phone="5511922220006", ctype="publico", ch="email", neg="pregao", subject="Pregão: manutenção de equipamentos", value=210000, stage=2, status=1, att=0, created=1, last=60, edital="Pregão Eletrônico 066/2026", session=18, deadline=12)  # sem edital anexado: alerta de pendência
# ---------- vendas
for i, (c, co, cn, ct, ch, subj, val, att, cr, cl, ed) in enumerate([
    ("Zé Carlos", "Padaria do Zé", "55.111.222/0001-01", "privado", "whatsapp", "PDV e controle de estoque", 8900, 1, 28, 2, None),
    ("Marta Rocha", "Distribuidora Norte", "55.222.333/0001-02", "privado", "indicacao", "Gestão de entregas", 64000, 2, 33, 5, None),
    ("Dr. Caio Mota", "Clínica Sorriso", "55.333.444/0001-03", "privado", "site", "Prontuário odontológico", 23500, 3, 25, 8, None),
    ("Gustavo Reis", "Supermercado União", "55.444.555/0001-04", "privado", "marketing", "Plano anual de suporte", 98000, 1, 40, 11, None),
    ("Priscila Lopes", "Papelaria Central", "55.555.666/0001-05", "privado", "whatsapp", "Emissão fiscal", 4300, 2, 15, 14, None),
    ("Wagner Dias", "Oficina Mecânica 2000", "55.666.777/0001-06", "privado", "indicacao", "Sistema de ordens de serviço", 7800, 3, 22, 19, None),
    ("Dr. Henrique Salles", "Faculdade Horizonte", "55.777.888/0001-07", "privado", "email", "Portal acadêmico", 152000, 1, 50, 24, None),
    ("Simone Aguiar", "Prefeitura de Mairiporã", "55.888.999/0001-08", "publico", "portal", "Pregão: licenças de escritório", 175000, 2, 45, 26, "Pregão Eletrônico 031/2026"),
    ("Nelson Braga", "Câmara Municipal de Atibaia", "55.999.000/0001-09", "publico", "portal", "Dispensa: suporte técnico", 38000, 3, 35, 20, "Dispensa 012/2026"),
    ("Renata Fogaça", "Rede Café Aroma", "56.000.111/0001-10", "privado", "marketing", "Franquias: controle de vendas", 18700, 1, 55, 38, None),
]):
    add(contact=c, company=co, cnpj=cn, email=f"contato@{co.split()[0].lower().replace('í','i').replace('ã','a')}.exemplo.com.br", phone=f"551193333{i:04d}", ctype=ct, ch=ch, subject=subj, value=val, stage=6, status=2, att=att, created=cr, closed=cl, neg=("pregao" if ct == "publico" else "direta"), edital=ed,
        docs=([("edital", f"Edital - {co}.pdf")] if ct == "publico" else []) + [("orcamento", f"Orcamento - {co}.pdf"), ("pedido_compra", f"Pedido de compra - {co}.pdf")])
# ---------- perdas
for i, (c, co, ct, ch, subj, val, att, cr, cl, why) in enumerate([
    ("Fernando Vaz", "Escola Futuro", "privado", "whatsapp", "Plataforma de ensino", 15000, 2, 20, 4, "Escolheu concorrente"),
    ("Mirela Santos", "Lanchonete Bom Gosto", "privado", "marketing", "PDV", 3900, 3, 14, 9, "Preço acima do orçamento"),
    ("Anderson Pinto", "Oficina Rápida", "privado", "site", "Gestão de oficina", 6100, 1, 18, 13, "Sem retorno do cliente"),
    ("Cíntia Barreto", "Loja Brinquedos Mágicos", "privado", "indicacao", "E-commerce e estoque", 28000, 2, 30, 17, "Escolheu concorrente"),
    ("Dr. Vítor Hugo", "Prefeitura de Jundiaí", "publico", "portal", "Pregão: infraestrutura de rede", 540000, 1, 42, 31, "Desclassificada no pregão"),
    ("Karina Lemos", "Autarquia de Água e Esgoto", "publico", "portal", "Pregão: sistema de cobrança", 310000, 3, 36, 22, "Outro licitante com menor preço"),
    ("Luciana Prado", "Studio Fitness Plus", "privado", "marketing", "App de alunos", 7200, 2, 44, 36, "Sem retorno do cliente"),
]):
    add(contact=c, company=co, cnpj=f"57.{i:03d}.222/0001-{i:02d}", email=f"contato@{co.split()[0].lower()}.exemplo.com.br", phone=f"551194444{i:04d}", ctype=ct, ch=ch, subject=subj, value=val, stage=(2 if ct == "publico" else 1), status=3, att=att, created=cr, closed=cl, reason=why,
        neg=("pregao" if ct == "publico" else "direta"), edital=("Pregão Eletrônico 0%d/2026" % (50 + i) if ct == "publico" else None),
        docs=([("edital", f"Edital - {co}.pdf")] if ct == "publico" else []) + [("pedido_orcamento", f"Pedido de orcamento - {co}.pdf")])

q = lambda s: "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"
sql = [
"SET " + ", ".join(f"@s{i+1}=(SELECT id FROM glpi_plugin_crm_etapas WHERE name={q(n)} LIMIT 1)" for i, n in enumerate(STAGES)) + ";",
"SET @u1=IFNULL((SELECT id FROM glpi_users WHERE name='tech' LIMIT 1),0), @u2=IFNULL((SELECT id FROM glpi_users WHERE name='normal' LIMIT 1),@u1), @u3=IFNULL((SELECT id FROM glpi_users WHERE name='glpi' LIMIT 1),@u1);",
"DELETE FROM glpi_plugin_crm_mensagems WHERE wa_message_id LIKE 'demo-%';",
"DELETE FROM glpi_plugin_crm_documentos WHERE plugin_crm_conversas_id IN (SELECT id FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%');",
"DELETE FROM glpi_plugin_crm_alertas WHERE plugin_crm_conversas_id IN (SELECT id FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%');",
"DELETE FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%';"]
for n, e in enumerate(E, 1):
    att = {0: "0", 1: "@u1", 2: "@u2", 3: "@u3"}[e["att"]]
    last = f"NOW() - INTERVAL {e['last']} MINUTE" if e.get("last") is not None else f"NOW() - INTERVAL {e['closed']} DAY"
    closed = f"NOW() - INTERVAL {e['closed']} DAY" if e["closed"] is not None else "NULL"
    sess = f"CURDATE() + INTERVAL {e['session']} DAY" if e["session"] is not None else "NULL"
    dead = f"CURDATE() + INTERVAL {e['deadline']} DAY" if e["deadline"] is not None else "NULL"
    created = f"NOW() - INTERVAL {e['created']} DAY - INTERVAL 3 HOUR"
    sql.append(
        "INSERT INTO glpi_plugin_crm_conversas (name, company, cnpj, email, client_type, numero_wa, contacts_id, plugin_crm_etapas_id, users_id_attendant, status, value, source, negotiation_type, subject, notes, edital_number, deadline, session_date, lost_reason, last_message_at, date_closed, entities_id, is_recursive, date_creation, date_mod) VALUES "
        f"({q('[Demo] ' + e['contact'])}, {q(e['company'])}, {q(e['cnpj'])}, {q(e['email'])}, '{e['ctype']}', '{e['phone']}', 0, @s{e['stage']+1}, {att}, {e['status']}, {e['value']}, '{e['ch']}', '{e['neg']}', {q(e['subject'])}, {q(e['notes'])}, {q(e['edital']) if e['edital'] else 'NULL'}, {dead}, {sess}, {q(e['reason']) if e['reason'] else 'NULL'}, {last}, {closed}, 0, 0, {created}, NOW());")
    ref = f"(SELECT id FROM glpi_plugin_crm_conversas WHERE numero_wa='{e['phone']}' AND name LIKE '[Demo]%' LIMIT 1)"
    m = len(e["msgs"])
    for k, (d, text) in enumerate(e["msgs"], 1):
        base = f"NOW() - INTERVAL {e['last']} MINUTE" if e.get("last") is not None else f"NOW() - INTERVAL {e['closed']} DAY"
        sql.append("INSERT INTO glpi_plugin_crm_mensagems (plugin_crm_conversas_id, direction, users_id, body, delivery, wa_message_id, date) VALUES "
                   f"({ref}, '{d}', {'@u1' if d == 'out' else '0'}, {q(text)}, '{'enviado' if d == 'out' else 'ok'}', 'demo-{n}-{k}', {base} - INTERVAL {(m - k) * 7} MINUTE);")
    for k, (dtype, fname) in enumerate(e["docs"], 1):
        stored = hashlib.md5(f"demo{n}{k}".encode()).hexdigest() + ".pdf"
        data = pdf([fname.replace(".pdf", ""), "", f"Cliente: {e['company']}", f"Contato: {e['contact']}", f"Assunto: {e['subject']}", "", "Documento de demonstracao do CRM (dados ficticios)."])
        open(f"{DOCS}/{stored}", "wb").write(data)
        sql.append("INSERT INTO glpi_plugin_crm_documentos (plugin_crm_conversas_id, doc_type, name, stored, size, users_id, date) VALUES "
                   f"({ref}, '{dtype}', {q(fname)}, '{stored}', {len(data)}, @u1, NOW() - INTERVAL {max(0, e['created'] - 0)} DAY);")
    # linha do tempo (alertas antigos; o aviso ao navegador ignora eventos anteriores à primeira consulta)
    sql.append("INSERT INTO glpi_plugin_crm_alertas (type, plugin_crm_conversas_id, plugin_crm_etapas_id, users_id, title, body, date) VALUES "
               f"('nova_conversa', {ref}, @s1, 0, {q(e['company'])}, {q(e['subject'])}, {created});")
    if e["status"] == 2:
        sql.append("INSERT INTO glpi_plugin_crm_alertas (type, plugin_crm_conversas_id, plugin_crm_etapas_id, users_id, title, body, date) VALUES "
                   f"('venda', {ref}, @s7, @u1, {q(e['company'])}, 'Venda fechada', NOW() - INTERVAL {e['closed']} DAY);")
    if e["status"] == 3:
        sql.append("INSERT INTO glpi_plugin_crm_alertas (type, plugin_crm_conversas_id, plugin_crm_etapas_id, users_id, title, body, date) VALUES "
                   f"('perda', {ref}, @s{e['stage']+1}, @u1, {q(e['company'])}, {q('Perdida: ' + e['reason'])}, NOW() - INTERVAL {e['closed']} DAY);")
    if e["status"] == 1 and e["stage"] == 6:
        sql.append("INSERT INTO glpi_plugin_crm_alertas (type, plugin_crm_conversas_id, plugin_crm_etapas_id, users_id, title, body, date) VALUES "
                   f"('fechamento', {ref}, @s7, @u1, {q(e['company'])}, 'Movida para Fechamento', NOW() - INTERVAL 1 DAY);")

# ---------- histórico de etapas e atas (dados de demonstração coerentes com a etapa atual e as datas)
sql.insert(sql.index("DELETE FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%';"), "DELETE FROM glpi_plugin_crm_historicos WHERE plugin_crm_conversas_id IN (SELECT id FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%');")
sql.insert(sql.index("DELETE FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%';"), "DELETE FROM glpi_plugin_crm_atas WHERE plugin_crm_conversas_id IN (SELECT id FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%');")
ATAS = {
    "Prefeitura Municipal de Itu": [("Reunião de alinhamento do edital", "video", 45, "Dra. Helena Duarte (Compras); Eng. Paulo (TI)", "Esclarecidas as exigências técnicas do item 4 do edital e o prazo de entrega. A prefeitura confirmou a sessão pública e pediu a planilha de preços até a véspera.", "Enviar planilha de preços e atestados de capacidade técnica.")],
    "Secretaria de Segurança Pública": [("Visita técnica", "presencial", 120, "Cel. Roberto Faria; Maj. Telles; Eng. de campo", "Visita ao centro de comando para levantar a infraestrutura. Definidos os pontos de instalação e a necessidade de treinamento de 40 policiais.", "Elaborar proposta técnica com plano de treinamento.")],
    "Universidade Estadual do Vale": [("Esclarecimentos do pregão", "telefone", 30, "Prof. Marcos Teles; Compras", "Dúvidas sobre cobertura Wi-Fi nos prédios antigos e o modelo de garantia.", "Responder pedido de esclarecimento formal."), ("Reunião de proposta", "video", 60, "Prof. Marcos Teles; TI da universidade", "Apresentada a planilha de preços. Pequeno ajuste solicitado no item de cabeamento.", "Reenviar proposta ajustada.")],
    "Hospital Municipal Santa Luzia": [("Reunião de fechamento", "presencial", 90, "Dr. Álvaro Pires; Diretoria administrativa; Jurídico", "Aprovada a minuta de contrato. Definido cronograma de implantação em 3 fases e responsáveis de cada área.", "Assinatura do contrato e abertura do ciclo financeiro."), ("Demonstração do prontuário", "presencial", 150, "Equipe clínica; TI do hospital", "Demonstração com casos reais da enfermaria. Feedback positivo sobre a prescrição eletrônica.", "Ajustar perfis de acesso.")],
    "Construtora Alvorada": [("Reunião com o diretor financeiro", "video", 50, "Juliana Prado; Diretor financeiro", "Discussão do modelo de cobrança por canteiro. Cliente pediu desconto para contrato de 24 meses.", "Enviar contraproposta até sexta-feira.")],
    "Hotel Vista Mar": [("Apresentação da solução", "presencial", 100, "Sérgio Nogueira; Gerente de reservas", "Demonstração do motor de reservas e integração com canais. Cliente gostou do painel de ocupação.", "Ajustar proposta com desconto combinado.")],
    "Indústria Metalsul": [("Levantamento da planta 2", "presencial", 180, "Otávio Menezes; Engenharia de processos", "Mapeados 14 pontos de monitoramento e requisitos de segurança da informação industrial.", "Enviar proposta com cronograma por fase.")],
    "Imobiliária Lar Doce Lar": [("Negociação final", "video", 40, "Tatiane Couto; Sócio-diretor", "Condições comerciais acordadas. Cliente aguarda apenas o contrato revisado.", "Enviar contrato e iniciar ciclo financeiro.")],
    "Padaria do Zé": [("Fechamento", "telefone", 20, "Zé Carlos", "Condições aceitas por telefone. Início da implantação na semana seguinte.", "Enviar contrato.")],
    "Faculdade Horizonte": [("Reunião com a reitoria", "presencial", 110, "Dr. Henrique Salles; Pró-reitor administrativo", "Apresentação do portal acadêmico, cronograma e SLA. Aprovação condicionada à integração com o sistema financeiro.", "Validar integração com o financeiro."), ("Assinatura", "presencial", 45, "Dr. Henrique Salles; Jurídico", "Contrato assinado pelas partes.", "Abrir ciclo financeiro.")],
}
hist_sql = []
for n, e in enumerate(E, 1):
    ref = f"(SELECT id FROM glpi_plugin_crm_conversas WHERE numero_wa='{e['phone']}' AND name LIKE '[Demo]%' LIMIT 1)"
    # trajetória: etapas aplicáveis ao tipo de cliente até a etapa atual, espalhadas entre a entrada e o fim (fechamento ou agora)
    path = [i for i in range(7) if not (e['ctype'] == 'privado' and i == 2)]
    cur = e['stage'] if e['stage'] in path else path[-1]
    steps = path[:path.index(cur) + 1]
    total_min = (e['created'] - (e['closed'] if e['closed'] is not None else 0)) * 1440 if e['closed'] is not None else e['created'] * 1440
    total_min = max(total_min, 90 * len(steps))
    start = f"NOW() - INTERVAL {e['created']} DAY - INTERVAL 3 HOUR"
    prev = 0
    for k, st in enumerate(steps):
        offset = int(total_min * (k / len(steps)) ** 1.25) if k else 0
        hist_sql.append("INSERT INTO glpi_plugin_crm_historicos (plugin_crm_conversas_id, from_etapas_id, plugin_crm_etapas_id, users_id, date) VALUES "
                        f"({ref}, {('@s%d' % (prev + 1)) if k else 0}, @s{st + 1}, {'@u1' if k else 0}, {start} + INTERVAL {offset} MINUTE);")
        prev = st
    for (title, kind, dur, who, resumo, decisoes) in ATAS.get(e['company'], []):
        hist_sql.append("INSERT INTO glpi_plugin_crm_atas (plugin_crm_conversas_id, meeting_date, kind, title, participants, summary, decisions, duration_min, plugin_crm_documentos_id, users_id, date_creation) VALUES "
                        f"({ref}, NOW() - INTERVAL {max(1, e['created'] // 2) + (1 if title.startswith('Reunião de fechamento') else 0)} DAY, '{kind}', {q(title)}, {q(who)}, {q(resumo)}, {q(decisoes)}, {dur}, 0, @u1, NOW());")
sql.extend(hist_sql)

sql.append("SELECT client_type, status, COUNT(*) AS n, SUM(value) AS valor FROM glpi_plugin_crm_conversas WHERE name LIKE '[Demo]%' GROUP BY client_type, status;")
sql.append("SELECT doc_type, COUNT(*) FROM glpi_plugin_crm_documentos GROUP BY doc_type;")
open(S + "/crm-demo.sql", "w").write("\n".join(sql) + "\n")
print(len(E), "entradas,", len(os.listdir(DOCS)), "documentos")
