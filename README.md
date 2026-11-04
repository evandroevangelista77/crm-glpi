# CRM GLPI

CRM comercial dentro do GLPI: canais de entrada (WhatsApp, e-mail, telefone, portal de licitação...), cliente privado ou público com documentos, esteira de venda até o fechamento, funil (Kanban), caixa de conversas, dashboard, relatórios em PDF, atas de reunião e alertas sonoros.

| | |
|---|---|
| **GLPI** | 11.0 e 12.0 (testado no 11.0.11 e no 12.0.0) |
| **PHP** | o mínimo do seu GLPI (testado com 8.5) |
| **Banco** | o do seu GLPI (MySQL ou MariaDB) |
| **Licença** | GPLv3+ |

## O que tem

- **Entradas:** lista de todas as oportunidades por canal, com filtro por tipo de cliente. A *Nova entrada* registra canal, contato, empresa, CNPJ, e-mail, telefone, assunto, valor, prazo e documentos. Cliente **público** exige o **edital** anexado.
- **Documentos:** pedido de orçamento, edital, orçamento, proposta técnica, pedido de compra, contrato e ata. Ficam em `files/_plugins/crm`, fora do endereço público, com nome aleatório.
- **Esteira de venda:** Entrada → Qualificação → Análise do edital (só público) → Elaboração do orçamento → Orçamento enviado → Negociação → Fechamento. Etapas, tipos e probabilidades são editáveis.
- **Funil (Kanban):** valor, previsão ponderada, filtros, arrastar e soltar (inclusive para Ganho e Perdido, com motivo), gaveta lateral com o resumo da oportunidade.
- **Conversas:** caixa de entrada do WhatsApp com chat, envio de respostas e painel da oportunidade.
- **Dashboard:** pipeline, previsão, vendas, conversão e ticket médio com comparação ao período anterior; funil, entrada por dia e tipo de cliente, canais, resultado e perdas, ranking de atendentes; cartões e gráficos clicáveis (gaveta lateral) e um pop-up com as oportunidades quentes.
- **Relatórios (PDF):** *executivo do funil* (7, 30 ou 90 dias) e *da negociação* (tempo com o cliente etapa por etapa, canais, reuniões, documentos e linha do tempo). O funil também sai em CSV.
- **Atas de reunião:** data, tipo, participantes, resumo e decisões, com o arquivo da ata anexado.
- **Alertas:** som e pop-up por tipo de evento (novo lead, negociação, venda, perda, SLA...), com preferências por usuário.
- **Aviso de fechamento:** ao chegar em Fechamento, pergunta se deve iniciar um **ciclo financeiro** (integração opcional, veja abaixo).

## Instalação

Com acesso ao servidor (a pasta do plugin **precisa se chamar `crm`**):

```bash
cd /var/www/glpi/plugins
git clone https://github.com/evandroevangelista77/crm-glpi.git crm
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install crm
sudo -u www-data php bin/console plugin:activate crm
```

Ou baixe o `.zip` (veja `tools/empacotar.sh`), descompacte em `plugins/` e use **Configurar → Plugins → Instalar → Ativar**.

**Atualizar:** `git pull` dentro de `plugins/crm` e rode `plugin:install crm` de novo (as migrações do banco são idempotentes).

**Direitos:** a instalação cria o direito *CRM* nos perfis. Quem administra o GLPI vê todas as oportunidades e configura; os demais atendem as próprias conversas e as que estão sem atendente.

## Primeiros passos

1. Abra **CRM → Configurações → Integração WhatsApp**. A tela explica, em linguagem simples, o que fazer e tem um botão que copia uma mensagem pronta para a TI.
2. Ajuste as etapas do funil e as regras de alerta em **Configurações**.
3. Registre uma entrada em **CRM → Entradas → Nova entrada** (ou carregue os [dados de demonstração](demo/LEIA-ME.md)).

### WhatsApp

O CRM recebe as mensagens de um **gateway de WhatsApp** (API oficial com intermediário, Evolution API, Chatwoot, o que a empresa usar) por um *webhook* assinado com HMAC-SHA256:

```
POST /plugins/crm/front/webhook.php
X-Signature: <hmac-sha256 do corpo com o segredo>

{"numero": "5511999999999", "nome": "Maria", "mensagem": "Olá!", "wa_message_id": "wamid.XYZ"}
```

Campos opcionais: `empresa`, `email`, `tipo_cliente` (`privado`/`publico`), `origem` e `valor`. Respostas do CRM: `200 ok`, `200 duplicada`, `401` (assinatura inválida), `422` (campo obrigatório faltando). O endereço, o segredo e um comando de teste aparecem na própria tela de integração.

### Ciclo financeiro (opcional)

Se o plugin **Ciclo Financeiro** estiver ativo, o aviso de fechamento abre um ciclo nele com os dados da oportunidade (cliente, valor, tipo de venda, privado ou público, contato, CNPJ, nº do edital e data do pregão). Sem esse plugin, o CRM funciona normalmente e só não oferece o ciclo.

## Para quem desenvolve

- **GLPI 11 e 12 no mesmo código.** O GLPI 12 exige propriedades tipadas (`public static string $rightname`) e o 11 as declara sem tipo; o PHP não aceita uma subclasse que mude o tipo. Por isso `src/Base.php` e `src/BaseLogged.php` declaram a base de um jeito em cada versão, e as demais classes só herdam dela.
- **Webhook sem login.** `setup.php` registra o `front/webhook.php` no firewall do GLPI (`STRATEGY_NO_CHECK`) e como recurso sem sessão (sem token CSRF); a autenticação é a assinatura HMAC.
- **Teste funcional** (precisa do plugin instalado e dos dados de demonstração):

  ```bash
  sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/smoke.php
  ```

- **Pacote de instalação:** `tools/empacotar.sh` gera `crm-glpi-<versão>.zip` a partir do que está commitado.

## Limites conhecidos

- Os alertas são só no navegador (som e pop-up), mais o e-mail de conversa atribuída pelas notificações do GLPI.
- O segredo do webhook fica em texto na configuração do plugin.
- A conversa guarda o número e o nome informados; não cria contato nem entidade do GLPI automaticamente.

## Licença

GPLv3+. Veja [LICENSE](LICENSE).
