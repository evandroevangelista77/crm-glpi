> Documento do **MVP inicial**. O que o plugin faz hoje está no [README](../README.md) e no [CHANGELOG](../CHANGELOG.md).

# Spec MVP: Conversas WhatsApp para GLPI (CRM comercial)

Status: rascunho para validação.
Versões-alvo: GLPI 11 e 12 (hoje os plugins do repositório declaram somente GLPI 12; suporte a 11 é requisito novo).

## 1. Objetivo

Permitir que a equipe comercial atenda leads e clientes pelo WhatsApp e acompanhe cada oportunidade dentro do GLPI, com histórico ligado ao contato e à entidade, funil por etapas e notificações.

Fora de escopo nesta versão: IA de qualificação, campanhas em massa, Click-to-WhatsApp, automação visual em canvas, gravação de reunião, extensão de Chrome, integração MCP.

## 2. Arquitetura

```
WhatsApp (cliente)
   │
   ▼
Meta Cloud API ──webhook──▶ Gateway WhatsApp (serviço externo)
                                 │  envia mensagens / recebe status
                                 │  webhook HMAC assinado
                                 ▼
                         Plugin GLPI "crmwhatsapp"
                         ├─ grava conversa e mensagens
                         ├─ vincula a Contato / Entidade
                         ├─ move etapa do funil
                         └─ dispara notificações e regras
                                 ▲
                                 │ atendente usa a tela do GLPI
```

- O gateway é responsável por: conexão com a API Oficial, templates, opt-in, retentativas e estado de entrega.
- O plugin é responsável por: dados comerciais, permissões, histórico, funil e notificações.
- O GLPI nunca mantém sessão de WhatsApp.
- O padrão de webhook segue o que já existe em `integracao_zabbix_glpi/glpi_webhook.js`.

## 3. Modelo de dados (proposta)

| Tabela | Campos principais | Observação |
|---|---|---|
| `glpi_plugin_crmwhatsapp_conversas` | id, contato_id, entidade_id, numero_wa, etapa_id, atendente_id, status (aberta/fechada/perdida), motivo_perda, date_creation, date_mod | Uma conversa por número e entidade ativa |
| `glpi_plugin_crmwhatsapp_mensagens` | id, conversa_id, direcao (in/out), tipo (texto/mídia/template), corpo, wa_message_id, status_entrega, date | `wa_message_id` único para idempotência |
| `glpi_plugin_crmwhatsapp_etapas` | id, nome, ordem, ativo | Padrão: prospecção, agendamento, negociação, pós-venda |
| `glpi_plugin_crmwhatsapp_eventos` | id, conversa_id, tipo, payload, date | Log de transições de etapa e atribuição |

Chaves de vínculo com o GLPI: `glpi_contacts` e `glpi_entities` (a validar nas versões-alvo).

## 4. Funcionalidades do MVP

**F1. Recebimento de mensagens**
- Endpoint de webhook que aceita apenas requisições com assinatura HMAC válida.
- Idempotência por `wa_message_id`: a mesma mensagem não pode ser gravada duas vezes.
- Se o número não corresponder a nenhum contato, cria um contato sem entidade e marca para triagem.

**F2. Tela de conversa**
- Lista de conversas com filtro por etapa, atendente e status.
- Painel da conversa com histórico, dados do contato e entidade.
- Envio de texto livre dentro da janela de 24 horas. Fora dela, só templates aprovados.

**F3. Funil por etapas**
- Movimentação manual de etapa pela tela.
- Motivo obrigatório ao marcar como perdida.
- Cada movimentação gera registro em `eventos`.

**F4. Atribuição e notificações**
- Atribuição manual a um atendente.
- Notificação do GLPI ao atendente quando chega mensagem em conversa atribuída a ele ou sem atendente.
- Notificação ao gestor quando conversa sem atendimento passa de um tempo configurável.

**F5. Follow-up**
- Tarefa agendada (`CronTask`) que lembra o atendente de conversas paradas há N dias.
- Não envia mensagem automaticamente nesta versão.

**F6. Permissões**
- Novo direito por perfil: visualizar, atender, mover etapa, configurar etapas.
- Só o atendente atribuído e o gestor veem o conteúdo completo da conversa.

## 5. Fora do MVP (próximas fases)

- Fase 2: relatórios de conversão por etapa e por atendente.
- Fase 3: regras de automação via motor de regras do GLPI (ex.: criar tarefa ao mudar etapa).
- Fase 4: envio em massa de templates, com controle de limites e opt-in.
- Fase 5: IA de triagem e resumo de conversa, como serviço externo.
- Fase 6: servidor MCP externo sobre a API REST do GLPI.

## 6. Requisitos não funcionais

- **Segurança:** segredo do webhook fora do código, em armazenamento criptografado (o plugin `sistemasinternos` já usa `GLPIKey` para isso). Logs sem corpo de mensagem em nível de info.
- **LGPD:** registro de opt-in por contato, campo para retenção e rotina de exclusão. Definir prazo de retenção com o jurídico.
- **Desempenho:** recebimento de webhook responde em até 2 s; a gravação pode ser assíncrona.
- **Compatibilidade:** testar em GLPI 11 e 12 com o mesmo código ou com ramos separados, a decidir após a análise de API.

## 7. Critérios de aceite do MVP

1. Uma mensagem enviada ao número cadastrado aparece na tela de conversa em até 5 s.
2. Reenvio do mesmo webhook não duplica mensagem.
3. Atendente só vê conversas atribuídas a ele ou sem atendente.
4. Mudança de etapa gera evento e notificação conforme regra.
5. Conversa sem atendimento além do prazo gera aviso ao gestor.
6. Plugin instala, ativa e desinstala sem erro em GLPI 11 e 12 (a confirmar).

## 8. Pendências a validar antes de começar

- [ ] Confirmar nomes e APIs de `Contact`, `Entity`, `CronTask`, `Notification` e do motor de regras nas versões 11 e 12.
- [ ] Escolher o gateway: serviço pronto (ex.: Chatwoot, Evolution API ou similar), ou gateway próprio.
- [ ] Confirmar Business Manager, número verificado e templates aprovados.
- [ ] Definir prazo de retenção de mensagens e base legal de tratamento.
- [ ] Definir quem é o gestor que recebe alertas de conversa parada.
