<?php

use GlpiPlugin\Crm\Alerta;
use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Etapa;
use GlpiPlugin\Crm\Menu;
use GlpiPlugin\Crm\Preferencia;
use GlpiPlugin\Crm\Settings;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$admin = Conversa::canConfigure();
$me = (int) Session::getLoginUserID();
$tabs = ['perfil' => ['Minhas preferências', 'ti-user-cog']];
if ($admin) {
    $tabs += ['alertas' => ['Alertas', 'ti-bell-ringing'], 'etapas' => ['Etapas do funil', 'ti-layout-kanban'], 'integracao' => ['Integração WhatsApp', 'ti-plug-connected']];
}
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'perfil';

// ---- gravação
if (isset($_POST['section'])) {
    Session::checkCSRF($_POST);
    $section = (string) $_POST['section'];
    $back = Menu::url('/front/config.php?tab=' . $section);
    if ($section === 'perfil') {
        Preferencia::save($me, ['sound' => isset($_POST['sound']), 'popup' => isset($_POST['popup']), 'volume' => $_POST['volume'] ?? 70]);
        Session::addMessageAfterRedirect('Preferências salvas.', false, INFO);
    } elseif (!$admin) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    } elseif ($section === 'alertas') {
        $rules = [];
        foreach (Alerta::EVENTS as $key => $event) {
            $in = (array) ($_POST['rules'][$key] ?? []);
            $rules[$key] = [
                'active' => isset($in['active']) ? 1 : 0,
                'sound'  => isset($in['sound']) ? 1 : 0,
                'popup'  => isset($in['popup']) ? 1 : 0,
                'tone'   => isset(Alerta::TONES[$in['tone'] ?? '']) ? $in['tone'] : $event['tone'],
            ];
        }
        Settings::set([
            'alert_rules'   => json_encode($rules),
            'sla_minutes'   => (string) max(0, (int) ($_POST['sla_minutes'] ?? 60)),
            'popup_seconds' => (string) max(3, min(60, (int) ($_POST['popup_seconds'] ?? 8))),
            'ask_cycle'     => isset($_POST['ask_cycle']) ? '1' : '0',
            'auto_cycle'    => isset($_POST['auto_cycle']) ? '1' : '0',
        ]);
        Session::addMessageAfterRedirect('Regras de alerta salvas.', false, INFO);
    } elseif ($section === 'etapas') {
        $kinds = array_keys(Etapa::KINDS);
        $active = 0;
        foreach ((array) ($_POST['etapas'] ?? []) as $id => $e) {
            $name = trim((string) ($e['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $isActive = isset($e['active']) ? 1 : 0;
            $active += $isActive;
            $DB->update(Etapa::getTable(), [
                'name'        => mb_substr($name, 0, 255),
                'kind'        => in_array($e['kind'] ?? '', $kinds, true) ? $e['kind'] : 'prevenda',
                'probability' => max(0, min(100, (int) ($e['probability'] ?? 0))),
                'scope'       => isset(Etapa::SCOPES[$e['scope'] ?? '']) ? $e['scope'] : 'all',
                'rank'        => (int) ($e['rank'] ?? 0),
                'is_active'   => $isActive,
            ], ['id' => (int) $id]);
        }
        $new = (array) ($_POST['nova'] ?? []);
        if (trim((string) ($new['name'] ?? '')) !== '') {
            $DB->insert(Etapa::getTable(), [
                'name'        => mb_substr(trim($new['name']), 0, 255),
                'kind'        => in_array($new['kind'] ?? '', $kinds, true) ? $new['kind'] : 'prevenda',
                'probability' => max(0, min(100, (int) ($new['probability'] ?? 0))),
                'scope'       => isset(Etapa::SCOPES[$new['scope'] ?? '']) ? $new['scope'] : 'all',
                'rank'        => (int) ($new['rank'] ?? 99),
                'is_active'   => 1,
            ]);
            $active++;
        }
        if (!$active) {
            $DB->update(Etapa::getTable(), ['is_active' => 1], ['id' => (int) (Etapa::rows()[0]['id'] ?? 0)]);
            Session::addMessageAfterRedirect('É preciso manter ao menos uma etapa ativa.', false, WARNING);
        } else {
            Session::addMessageAfterRedirect('Etapas salvas.', false, INFO);
        }
        Etapa::forget();
    } elseif ($section === 'integracao') {
        $values = ['gateway_url' => trim((string) ($_POST['gateway_url'] ?? ''))];
        if (isset($_POST['regen_secret'])) {
            $values['webhook_secret'] = bin2hex(random_bytes(24));
        }
        Settings::set($values);
        Session::addMessageAfterRedirect(isset($_POST['regen_secret']) ? 'Novo segredo gerado. Atualize o gateway.' : 'Integração salva.', false, INFO);
    }
    Html::redirect($back);
}

$csrf = Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
$self = Menu::e(Menu::page('/front/config.php'));
$check = fn (bool $on): string => $on ? ' checked' : '';

Menu::header('Configurações', 'config');
echo "<div class='crm'>" . Menu::title('Configurações do CRM', 'config') . "</div>";
echo "<ul class='nav nav-tabs mb-3'>";
foreach ($tabs as $key => [$label, $icon]) {
    echo "<li class='nav-item'><a class='nav-link" . ($tab === $key ? ' active' : '') . "' href='?tab=$key'><i class='ti $icon me-1'></i>" . Menu::e($label) . "</a></li>";
}
echo "</ul>";

// ---- minhas preferências
if ($tab === 'perfil') {
    $p = Preferencia::forUser($me);
    $toneOpts = '';
    foreach (Alerta::TONES as $k => $label) {
        $toneOpts .= "<option value='$k'>" . Menu::e($label) . '</option>';
    }
    echo "<form method='post' action='$self' class='card'><div class='card-header'><h3 class='card-title'>Alertas deste navegador</h3></div><div class='card-body'>$csrf<input type='hidden' name='section' value='perfil'>"
        . "<p class='text-secondary'>Estas opções valem para o seu usuário. O que dispara cada alerta é definido por quem administra o CRM.</p>"
        . "<div class='row g-3'><div class='col-md-4'><label class='form-check form-switch'><input class='form-check-input' type='checkbox' name='sound'" . $check((bool) $p['sound']) . "><span class='form-check-label'>Tocar som nos alertas</span></label></div>"
        . "<div class='col-md-4'><label class='form-check form-switch'><input class='form-check-input' type='checkbox' name='popup'" . $check((bool) $p['popup']) . "><span class='form-check-label'>Mostrar pop-up nos alertas</span></label></div>"
        . "<div class='col-md-4'><label class='form-label'>Volume: <b id='vol-n'>{$p['volume']}</b>%</label><input type='range' class='form-range' min='0' max='100' name='volume' id='vol' value='{$p['volume']}'></div></div>"
        . "<hr><div class='row g-2 align-items-end'><div class='col-md-4'><label class='form-label'>Testar um toque</label><select class='form-select' id='tone-test'>$toneOpts</select></div>"
        . "<div class='col-auto'><button type='button' class='btn btn-outline-primary' id='btn-test-sound'><i class='ti ti-volume me-1'></i>Tocar</button></div>"
        . "<div class='col-auto'><button type='button' class='btn btn-outline-primary' id='btn-test-popup'><i class='ti ti-bell me-1'></i>Testar pop-up</button></div></div>"
        . "<div class='form-hint mt-2'>Os navegadores só liberam o som depois que você clica na página. Se não tocar, clique em qualquer lugar e tente de novo.</div>"
        . "</div><div class='card-footer text-end'><button class='btn btn-primary'>Salvar preferências</button></div></form>";
}

// ---- regras de alerta
if ($tab === 'alertas' && $admin) {
    $rules = Settings::alertRules();
    echo "<form method='post' action='$self' class='card'><div class='card-header'><h3 class='card-title'>Alertas por tipo de evento</h3></div>$csrf<input type='hidden' name='section' value='alertas'>"
        . "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Evento</th><th class='text-center'>Ativo</th><th class='text-center'>Som</th><th>Toque</th><th class='text-center'>Pop-up</th><th></th></tr></thead><tbody>";
    $group = '';
    foreach (Alerta::EVENTS as $key => $event) {
        $r = $rules[$key];
        if ($event['group'] !== $group) {
            $group = $event['group'];
            echo "<tr class='crm-group-row'><td colspan='6'>" . Menu::e($group) . "</td></tr>";
        }
        $tones = '';
        foreach (Alerta::TONES as $k => $label) {
            $tones .= "<option value='$k'" . ($r['tone'] === $k ? ' selected' : '') . '>' . Menu::e($label) . '</option>';
        }
        echo "<tr><td><div class='d-flex align-items-center gap-2'><span class='crm-kpi-icon' style='--c:{$event['color']}'><i class='ti {$event['icon']}'></i></span><div><div class='fw-bold'>" . Menu::e($event['label']) . "</div></div></div></td>"
            . "<td class='text-center'><label class='form-check form-switch d-inline-block m-0'><input class='form-check-input' type='checkbox' name='rules[$key][active]'" . $check((bool) $r['active']) . "></label></td>"
            . "<td class='text-center'><label class='form-check form-switch d-inline-block m-0'><input class='form-check-input' type='checkbox' name='rules[$key][sound]'" . $check((bool) $r['sound']) . "></label></td>"
            . "<td style='min-width:200px'><select class='form-select form-select-sm' name='rules[$key][tone]'>$tones</select></td>"
            . "<td class='text-center'><label class='form-check form-switch d-inline-block m-0'><input class='form-check-input' type='checkbox' name='rules[$key][popup]'" . $check((bool) $r['popup']) . "></label></td>"
            . "<td class='text-end'><button type='button' class='btn btn-sm btn-outline-secondary' data-test-row='$key'><i class='ti ti-player-play'></i></button></td></tr>";
    }
    echo "</tbody></table></div><div class='card-body border-top'><div class='row g-3'>"
        . "<div class='col-md-4'><label class='form-label'>SLA: alertar conversa sem resposta após (minutos)</label><input class='form-control' type='number' min='0' name='sla_minutes' value='" . Settings::slaMinutes() . "'><div class='form-hint'>0 desliga o alerta de SLA.</div></div>"
        . "<div class='col-md-4'><label class='form-label'>Duração do pop-up (segundos)</label><input class='form-control' type='number' min='3' max='60' name='popup_seconds' value='" . Settings::popupSeconds() . "'></div>"
        . "<div class='col-md-4'><label class='form-label'>Aviso de fechamento</label><label class='form-check form-switch'><input class='form-check-input' type='checkbox' name='ask_cycle'" . $check(Settings::askCycle()) . "><span class='form-check-label'>Perguntar se deseja iniciar o ciclo financeiro</span></label></div>"
        . "<div class='col-md-4'><label class='form-label'>Venda concretizada</label><label class='form-check form-switch'><input class='form-check-input' type='checkbox' name='auto_cycle'" . $check(Settings::autoCycle()) . "><span class='form-check-label'>Abrir o ciclo financeiro sozinho, levando os documentos</span></label></div></div>"
        . "<div class='form-hint mt-3'>Cada pessoa também pode silenciar som ou pop-up em <b>Minhas preferências</b>. Quem fez a ação não recebe o próprio alerta, e atendentes só são avisados das conversas que podem ver.</div>"
        . "</div><div class='card-footer text-end'><button class='btn btn-primary'>Salvar regras</button></div></form>";
}

// ---- etapas
if ($tab === 'etapas' && $admin) {
    $scopeOpts = fn (string $sel): string => implode('', array_map(fn ($k, $l) => "<option value='$k'" . ($sel === $k ? ' selected' : '') . '>' . Menu::e($l) . '</option>', array_keys(Etapa::SCOPES), Etapa::SCOPES));
    $kindOpts = fn (string $sel): string => implode('', array_map(fn ($k, $l) => "<option value='$k'" . ($sel === $k ? ' selected' : '') . '>' . Menu::e($l) . '</option>', array_keys(Etapa::KINDS), Etapa::KINDS));
    echo "<form method='post' action='$self' class='card'><div class='card-header'><h3 class='card-title'>Etapas do funil</h3></div>$csrf<input type='hidden' name='section' value='etapas'>"
        . "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th style='width:90px'>Ordem</th><th>Nome</th><th>Tipo (alerta)</th><th>Vale para</th><th style='width:140px'>Probabilidade (%)</th><th class='text-center'>Ativa</th></tr></thead><tbody>";
    foreach (Etapa::rows() as $e) {
        $id = (int) $e['id'];
        echo "<tr><td><input class='form-control' type='number' name='etapas[$id][rank]' value='" . (int) $e['rank'] . "'></td>"
            . "<td><input class='form-control' name='etapas[$id][name]' value='" . Menu::e($e['name']) . "' required></td>"
            . "<td><select class='form-select' name='etapas[$id][kind]'>" . $kindOpts((string) $e['kind']) . "</select></td>"
            . "<td><select class='form-select' name='etapas[$id][scope]'>" . $scopeOpts((string) $e['scope']) . "</select></td>"
            . "<td><input class='form-control' type='number' min='0' max='100' name='etapas[$id][probability]' value='" . (int) $e['probability'] . "'></td>"
            . "<td class='text-center'><label class='form-check form-switch d-inline-block m-0'><input class='form-check-input' type='checkbox' name='etapas[$id][active]'" . $check((bool) $e['is_active']) . "></label></td></tr>";
    }
    echo "<tr class='crm-group-row'><td colspan='6'>Nova etapa</td></tr><tr><td><input class='form-control' type='number' name='nova[rank]' value='" . (count(Etapa::rows()) + 1) . "'></td>"
        . "<td><input class='form-control' name='nova[name]' placeholder='Ex.: Proposta enviada'></td><td><select class='form-select' name='nova[kind]'>" . $kindOpts('negociacao') . "</select></td><td><select class='form-select' name='nova[scope]'>" . $scopeOpts('all') . "</select></td>"
        . "<td><input class='form-control' type='number' min='0' max='100' name='nova[probability]' value='50'></td><td></td></tr>";
    echo "</tbody></table></div><div class='card-body border-top form-hint'>O <b>tipo</b> define o alerta disparado quando a oportunidade entra na etapa; a etapa do tipo <b>Fechamento</b> dispara o aviso de fechamento e a pergunta sobre o ciclo financeiro. <b>Vale para</b> restringe a etapa a clientes públicos ou privados (ex.: análise do edital). A <b>probabilidade</b> pondera o valor da oportunidade na previsão do dashboard. Etapas desativadas somem do funil, mas as conversas nelas continuam salvas.</div>"
        . "<div class='card-footer text-end'><button class='btn btn-primary'>Salvar etapas</button></div></form>";
}

// ---- integração
if ($tab === 'integracao' && $admin) {
    $url = Settings::webhookUrl();
    $secret = Settings::get('webhook_secret');
    $gateway = Settings::get('gateway_url');
    $host = (string) parse_url($url, PHP_URL_HOST);
    $internal = !str_starts_with($url, 'https://') || preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)|\.(local|lan|internal)$/', $host);
    $in = $DB->request([
        'SELECT' => [new \Glpi\DBAL\QueryExpression('MAX(`date`) AS `d`'), new \Glpi\DBAL\QueryExpression('COUNT(*) AS `n`')],
        'FROM'   => \GlpiPlugin\Crm\Mensagem::getTable(),
        'WHERE'  => ['direction' => 'in', ['NOT' => ['wa_message_id' => ['LIKE', 'demo-%']]]],
    ])->current();
    $received = (int) ($in['n'] ?? 0);

    // mensagem pronta para o gestor mandar à TI
    $forIt = "Olá! Preciso ligar o WhatsApp da empresa ao CRM (GLPI).\n\n"
        . "1) Endereço para onde o WhatsApp deve enviar cada mensagem recebida:\n$url\n\n"
        . "2) Chave de segurança (assina as mensagens, HMAC-SHA256 no cabeçalho X-Signature):\n$secret\n\n"
        . "3) Formato: POST em JSON com numero, mensagem e wa_message_id (obrigatórios) e, se possível, nome, empresa, email, tipo_cliente, origem e valor.\n\n"
        . "4) Para eu responder os clientes pelo CRM, me passe também o endereço de envio de mensagens do gateway.\n\n"
        . "Os detalhes técnicos e um comando de teste estão em CRM > Configurações > Integração WhatsApp > \"Informações para a TI\".";

    // situação, em uma frase
    if ($received) {
        echo "<div class='crm-status ok'><i class='ti ti-circle-check'></i><div><div class='crm-status-t'>Conectado: o CRM está recebendo as conversas do WhatsApp</div>"
            . "<div>Última mensagem recebida há <b>" . Menu::e(Conversa::ago($in['d'])) . "</b>. Você não precisa fazer mais nada aqui.</div></div></div>";
    } else {
        echo "<div class='crm-status wait'><i class='ti ti-hourglass-empty'></i><div><div class='crm-status-t'>Aguardando a primeira mensagem do WhatsApp</div>"
            . "<div>Ainda não chegou nenhuma conversa. Siga os 3 passos abaixo; quando a TI terminar, é só mandar uma mensagem de teste para o número da empresa.</div></div></div>";
    }

    echo "<div class='row row-cards mb-3'><div class='col-lg-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-list-numbers me-2'></i>O que você precisa fazer</h3></div><div class='card-body'>"
        . "<p class='text-secondary'>Para as conversas do WhatsApp da empresa aparecerem aqui, a <b>TI</b> precisa fazer uma ligação entre o número de WhatsApp e o CRM. Você não precisa mexer em nada técnico.</p>"
        . "<ol class='crm-steps'>"
        . "<li><b>Peça à TI para fazer a ligação.</b> Clique em <em>\"Copiar mensagem para a TI\"</em> ao lado e envie por e-mail ou Teams. Ela já vem pronta, com tudo que a TI precisa.</li>"
        . "<li><b>Mande uma mensagem de teste</b> para o número de WhatsApp da empresa, quando a TI avisar que terminou.</li>"
        . "<li><b>Confira em <em>Conversas</em>.</b> Se a conversa de teste apareceu, está tudo certo e o aviso acima fica verde.</li>"
        . "</ol>"
        . "<div class='crm-tip-box'><i class='ti ti-bulb'></i><div><b>Quer responder os clientes de dentro do CRM?</b> Peça à TI também o <em>endereço de envio</em> e preencha no último campo abaixo. Sem ele, o que você digita no CRM fica só guardado e não chega ao cliente.</div></div>"
        . "</div></div></div>"
        . "<div class='col-lg-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-send me-2'></i>Envie para a TI</h3></div><div class='card-body d-flex flex-column gap-3'>"
        . "<p class='mb-0 text-secondary'>Uma mensagem pronta com o endereço do CRM, a chave de segurança e as instruções. Basta colar no e-mail ou no Teams.</p>"
        . "<textarea id='ti-message' hidden>" . Menu::e($forIt) . "</textarea>"
        . "<button type='button' class='btn btn-primary btn-lg' data-copy='ti-message'><i class='ti ti-copy me-2'></i>Copiar mensagem para a TI</button>"
        . "<div class='small text-secondary'><i class='ti ti-lock me-1'></i>A mensagem contém a chave de segurança: envie só para a TI, por um canal da empresa.</div>"
        . ($internal ? "<div class='crm-check warn'><i class='ti ti-alert-triangle'></i><div><b>Atenção</b><div class='small'>O endereço do CRM hoje só funciona <b>dentro da rede da empresa</b>. Se o WhatsApp passa por um serviço na internet (como a API oficial da Meta), peça também à TI para liberar o CRM para acesso de fora da empresa, com um endereço seguro.</div></div></div>" : '')
        . "</div></div></div></div>";

    // campos
    echo "<form method='post' action='$self' class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-key me-2'></i>Dados da ligação</h3></div><div class='card-body'>$csrf<input type='hidden' name='section' value='integracao'>"
        . "<div class='mb-4'><label class='form-label'>Endereço do CRM para receber as mensagens</label><div class='input-group'><input class='form-control' id='webhook-url' readonly value='" . Menu::e($url) . "' onclick='this.select()'>"
        . "<button type='button' class='btn btn-outline-primary' data-copy='webhook-url'><i class='ti ti-copy me-1'></i>Copiar</button></div>"
        . "<div class='form-hint'>É para onde o WhatsApp manda cada mensagem que o cliente envia. <b>Passe para a TI.</b></div></div>"
        . "<div class='mb-4'><label class='form-label'>Chave de segurança</label><div class='input-group'>"
        . "<input class='form-control' type='password' id='secret' readonly value='" . Menu::e($secret) . "'><button type='button' class='btn btn-outline-secondary' id='btn-secret' title='Mostrar ou ocultar'><i class='ti ti-eye'></i></button>"
        . "<button type='button' class='btn btn-outline-primary' data-copy='secret'><i class='ti ti-copy me-1'></i>Copiar</button>"
        . "<button class='btn btn-outline-danger' name='regen_secret' value='1' onclick=\"return confirm('Gerar uma nova chave? A TI precisará atualizar a ligação, senão as mensagens deixam de chegar.')\"><i class='ti ti-refresh me-1'></i>Gerar nova</button></div>"
        . "<div class='form-hint'>Funciona como uma senha entre o WhatsApp e o CRM, para garantir que só mensagens autênticas entrem. <b>Passe para a TI e não compartilhe com outras pessoas.</b> Se gerar uma nova, avise a TI.</div></div>"
        . "<div class='mb-2'><label class='form-label'>Endereço para enviar as respostas <span class='text-secondary fw-normal'>(opcional)</span></label><input class='form-control' name='gateway_url' value='" . Menu::e($gateway) . "' placeholder='A TI informa este endereço'>"
        . "<div class='form-hint'>Quando preenchido, as respostas que você digita no CRM chegam ao cliente no WhatsApp. " . ($gateway !== '' ? "<span class='text-success'><b>Ativado.</b></span>" : "Hoje está <b>vazio</b>: as respostas ficam só guardadas no CRM, com um relógio ao lado.") . "</div></div>"
        . "</div><div class='card-footer text-end'><button class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div></form>";

    // problemas comuns, em linguagem simples
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-help-circle me-2'></i>Se algo não funcionar</h3></div><div class='card-body'><ul class='mb-0'>"
        . "<li class='mb-2'><b>Mandei a mensagem de teste e ela não apareceu.</b> Pergunte à TI se a ligação já foi feita e se o teste foi para o número certo.</li>"
        . "<li class='mb-2'><b>A TI diz que aparece \"erro de assinatura\" (401).</b> A chave de segurança dela está diferente da daqui. Copie de novo e envie.</li>"
        . "<li class='mb-2'><b>Minha resposta fica com um relógio.</b> Ainda falta o <em>endereço para enviar as respostas</em>. Peça à TI.</li>"
        . "<li class='mb-0'><b>Minha resposta fica com um aviso vermelho.</b> O serviço de WhatsApp não aceitou o envio. Avise a TI.</li></ul></div></div>";

    // parte técnica, recolhida
    $curl = "SECRET='cole-a-chave-aqui'\nBODY='{\"numero\":\"5511999999999\",\"nome\":\"Maria\",\"mensagem\":\"Olá, quero um orçamento\",\"wa_message_id\":\"teste-001\"}'\n"
        . "SIG=\$(printf '%s' \"\$BODY\" | openssl dgst -sha256 -hmac \"\$SECRET\" | awk '{print \$NF}')\n"
        . "curl -i -X POST '" . $url . "' \\\n  -H 'Content-Type: application/json' -H \"X-Signature: \$SIG\" -d \"\$BODY\"";
    $fields = [
        ['numero', 'sim', 'Telefone do cliente com país e DDD, só números (ex.: 5511999999999).'],
        ['mensagem', 'sim', 'Texto recebido do cliente.'],
        ['wa_message_id', 'sim', 'Identificador único da mensagem no gateway; evita duplicar quando ele reenvia.'],
        ['nome', 'não', 'Nome do contato.'], ['empresa', 'não', 'Empresa ou órgão do cliente.'], ['email', 'não', 'E-mail do contato.'],
        ['tipo_cliente', 'não', '<code>privado</code> ou <code>publico</code> (padrão: privado).'],
        ['origem', 'não', 'whatsapp (padrão), email, telefone, site, portal, indicacao, marketing, presencial ou outros.'],
        ['valor', 'não', 'Valor estimado da oportunidade, em reais.'],
    ];
    echo "<details class='card'><summary class='card-header' style='cursor:pointer;list-style:none'><h3 class='card-title'><i class='ti ti-code me-2'></i>Informações para a TI <span class='text-secondary fw-normal small'>(detalhes técnicos; clique para abrir)</span></h3></summary><div class='card-body'>"
        . "<div class='row g-4'><div class='col-lg-7'><div class='crm-side-title'>Campos que o gateway envia (POST em JSON para o endereço do CRM)</div>"
        . "<table class='table table-sm'><thead><tr><th>Campo</th><th>Obrigatório</th><th>O que é</th></tr></thead><tbody>";
    foreach ($fields as [$name, $req, $desc]) {
        echo "<tr><td><code>$name</code></td><td>" . ($req === 'sim' ? "<span class='badge bg-red-lt'>sim</span>" : "<span class='text-secondary'>não</span>") . "</td><td class='small'>$desc</td></tr>";
    }
    echo "</tbody></table></div><div class='col-lg-5'><div class='crm-side-title'>Respostas do CRM</div><dl class='crm-dl crm-dl-wide'>"
        . "<dt><code>200 ok</code></dt><dd>Mensagem gravada (cria a oportunidade se o número for novo).</dd>"
        . "<dt><code>200 duplicada</code></dt><dd>Esse <code>wa_message_id</code> já foi recebido.</dd>"
        . "<dt><code>401</code></dt><dd>Assinatura inválida: chave diferente ou corpo alterado depois de assinar.</dd>"
        . "<dt><code>422</code></dt><dd>Falta <code>numero</code>, <code>mensagem</code> ou <code>wa_message_id</code>.</dd></dl>"
        . "<div class='small text-secondary'>Envio de respostas: o CRM faz POST em JSON <code>{\"numero\", \"mensagem\"}</code> no endereço de envio, com a mesma assinatura, e espera 2xx em até 5 segundos.</div></div></div>"
        . "<div class='crm-side-title mt-3'>Teste sem o gateway</div>"
        . "<div class='d-flex justify-content-between align-items-start gap-2'><pre class='crm-code flex-grow-1 mb-0' id='curl-example'>" . Menu::e($curl) . "</pre>"
        . "<button type='button' class='btn btn-outline-primary' data-copy='curl-example'><i class='ti ti-copy me-1'></i>Copiar</button></div>"
        . "<div class='small text-secondary mt-2'>Troque <code>cole-a-chave-aqui</code> pela chave de segurança. Resposta esperada: <code>200</code> com <code>\"status\":\"ok\"</code>.</div>"
        . "</div></details>"
        . "<script>document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {"
        . "const el = document.getElementById(b.dataset.copy); const text = el.value ?? el.textContent; const old = b.innerHTML;"
        . "try { await navigator.clipboard.writeText(text); b.innerHTML = '<i class=\"ti ti-check me-1\"></i>Copiado'; } catch (e) { b.innerHTML = 'Não foi possível copiar'; }"
        . "setTimeout(() => { b.innerHTML = old; }, 1800); }));</script>";
}

$events = json_encode(array_map(fn ($e) => ['label' => $e['label'], 'icon' => $e['icon'], 'color' => $e['color']], Alerta::EVENTS), JSON_UNESCAPED_UNICODE);
echo "<script>
(() => {
    const events = $events;
    const vol = document.getElementById('vol');
    if (vol) vol.addEventListener('input', () => { document.getElementById('vol-n').textContent = vol.value; });
    document.getElementById('btn-test-sound')?.addEventListener('click', () => window.CRMAlerts?.test(document.getElementById('tone-test').value, +vol.value));
    document.getElementById('btn-test-popup')?.addEventListener('click', () => window.CRMAlerts?.popup({label: 'Novo lead', title: 'Maria Souza', body: 'Olá, gostaria de um orçamento.', icon: 'ti-user-plus', color: '#0ea5e9', url: ''}, 6));
    document.querySelectorAll('[data-test-row]').forEach((btn) => btn.addEventListener('click', () => {
        const row = btn.closest('tr');
        const ev = events[btn.dataset.testRow];
        window.CRMAlerts?.test(row.querySelector('select').value, 70);
        window.CRMAlerts?.popup({...ev, title: 'Exemplo de alerta', body: 'Assim este evento aparece para a equipe.', url: ''}, 5);
    }));
    document.getElementById('btn-secret')?.addEventListener('click', () => { const s = document.getElementById('secret'); s.type = s.type === 'password' ? 'text' : 'password'; });
})();
</script>";
Html::footer();
