# Changelog

## 0.7.0

- Nova tela **Gestão de Leads** no menu CRM: leads captados em eventos (plugin Gestão de Eventos), por evento, com situação (novo, contatado, qualificado, convertido, descartado), temperatura, interesse e conversão em oportunidade (canal "Presencial / evento"). Tabela `glpi_plugin_crm_leads`; `Lead::receive()` recebe os leads sem duplicar.
- Na Gestão de Eventos 1.3.0, a aba Marketing ganhou "Leads captados" e o botão **Enviar leads para o CRM**.
- Teste `tests/leads.php`.

## 0.6.1

- Nova coluna **Renegociação e ajustes** no funil (entre Negociação e Fechamento): para lá vai a oportunidade quando o Ciclo Financeiro é devolvido ao Aviso de Fechamento. O ciclo fica interrompido até ela seguir de novo para o Fechamento.

## 0.6.0

- **Venda concretizada abre o ciclo financeiro sozinha** (opção "Abrir o ciclo financeiro sozinho" em Configurações > Alertas, ligada por padrão), sem perguntar.
- **Documentos da venda vão para o ciclo:** todos os anexos da oportunidade viram Documentos do GLPI ligados ao ciclo (categoria conforme o tipo: edital, proposta, contrato, pedido de compra, ata), com evento na linha do tempo.
- Ciclo já nasce preenchido: comercial responsável, valor, cliente, nº do edital, contato, probabilidade 100%, dor/assunto, data da proposta e checklist de conformidade (comercial, técnico, aceite, contrato) conforme os documentos existentes.
- **Animação de fechamento** no CRM (confete, check e barra) e **aviso no centro da tela do Ciclo** informando que o cliente fechou e que o novo ciclo foi aberto.
- **Ficha da oportunidade:** botão "Avançar para …", seletor "Mover para…", "Marcar como ganha" e "Perdida"; o passo "Venda" da esteira também é clicável.
- **Aviso de fechamento:** mover para Fechamento pergunta "Tem certeza que pode seguir para a esteira do ciclo financeiro?" (Sim/Não), abre o ciclo e o Ciclo mostra um pop-up central com cliente, valor e data.
- **Voltar para Negociação interrompe o ciclo** (status Interrompido, fases paradas) e ele só é liberado quando a oportunidade seguir de novo para o Fechamento. Exige o plugin Ciclo 1.0.5 ou superior com o status Interrompido.
- Teste `tests/ciclo_auto.php` (precisa do plugin Ciclo no GLPI 12).

## 0.5.0

- Compatível com **GLPI 11 e 12** (antes só o 12): bases `Base` e `BaseLogged` escolhidas pela versão e webhook registrado como recurso sem sessão. Testado no GLPI 11.0.11 e no 12.0.0.
- Gerador dos dados de demonstração sem banco fixo; teste funcional (`tests/smoke.php`) e script de empacotamento.

## 0.4.0

- Dashboard com gráficos interativos, gaveta lateral e pop-up de oportunidades quentes (com radar ao fundo).
- Relatório executivo do funil e relatório da negociação em PDF; funil em CSV.
- Atas de reunião e histórico de etapas (tempo em cada fase).
- Aba de integração com o WhatsApp em linguagem simples.

## 0.3.0

- Canais de entrada, cliente privado ou público, documentos (edital obrigatório para público) e ficha da oportunidade.
- Esteira de venda até o fechamento e aviso de fechamento que oferece abrir o ciclo financeiro.

## 0.2.0

- Funil (Kanban), caixa de conversas, dashboard, configurações e alertas sonoros e pop-up.

## 0.1.0

- MVP: webhook do WhatsApp, conversas e etapas do funil.
