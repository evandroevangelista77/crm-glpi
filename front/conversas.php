<?php

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Etapa;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$me = (int) Session::getLoginUserID();
$t = Conversa::getTable();

// abas da lista: abertas, minhas, sem atendente, fechadas
$tabs = [
    'abertas'  => ['Abertas', ['status' => Conversa::OPEN]],
    'minhas'   => ['Minhas', ['status' => Conversa::OPEN, 'users_id_attendant' => $me]],
    'sem'      => ['Sem atendente', ['status' => Conversa::OPEN, 'users_id_attendant' => 0]],
    'fechadas' => ['Fechadas', ['status' => [Conversa::WON, Conversa::LOST]]],
];
$f = isset($tabs[$_GET['f'] ?? '']) ? $_GET['f'] : 'abertas';
$q = trim((string) ($_GET['q'] ?? ''));
$scope = Conversa::scope($t);

$counts = [];
foreach ($tabs as $key => [$label, $cond]) {
    $counts[$key] = (int) ($DB->request(['COUNT' => 'cnt', 'FROM' => $t, 'WHERE' => $cond + $scope])->current()['cnt'] ?? 0);
}

$where = $tabs[$f][1] + $scope;
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = ['OR' => ["$t.name" => ['LIKE', $like], "$t.numero_wa" => ['LIKE', $like]]];
}
$rows = iterator_to_array($DB->request(['FROM' => $t, 'WHERE' => $where, 'ORDER' => ['last_message_at DESC', 'id DESC'], 'LIMIT' => 150]), false);

// conversa aberta no painel: a pedida ou a primeira da lista
$id = (int) ($_GET['id'] ?? 0) ?: (int) ($rows[0]['id'] ?? 0);
$conversa = null;
if ($id) {
    $conversa = new Conversa();
    if (!$conversa->getFromDB($id) || !$conversa->canViewItem()) {
        $conversa = null;
    }
}

Menu::header('Conversas', 'conversas');
echo "<div class='crm'>" . Menu::title('Conversas', 'conversas', Menu::button('Funil de vendas', Menu::page('/front/funil.php'), 'ti-layout-kanban', 'btn-outline-primary')) . "</div>";

echo "<div class='crm-inbox'>";

// --- lista
echo "<aside class='crm-inbox-list'><form method='get' class='crm-inbox-search'><input type='hidden' name='f' value='" . Menu::e($f) . "'>"
    . "<i class='ti ti-search'></i><input type='search' class='form-control' name='q' value='" . Menu::e($q) . "' placeholder='Buscar contato ou número'></form>";
echo "<div class='crm-inbox-tabs'>";
foreach ($tabs as $key => [$label]) {
    echo "<a class='" . ($f === $key ? 'active' : '') . "' href='?f=$key" . ($q !== '' ? '&q=' . urlencode($q) : '') . "'>" . Menu::e($label) . " <span>{$counts[$key]}</span></a>";
}
echo "</div><div class='crm-inbox-items'>";
foreach ($rows as $r) {
    $name = Conversa::title($r);
    $last = Conversa::lastMessageRow((int) $r['id']);
    $waiting = $last && $last['direction'] === 'in' && (int) $r['status'] === Conversa::OPEN;
    $href = '?f=' . $f . '&id=' . (int) $r['id'] . ($q !== '' ? '&q=' . urlencode($q) : '');
    echo "<a class='crm-inbox-item" . ((int) $r['id'] === $id ? ' active' : '') . "' href='" . Menu::e($href) . "'>" . Conversa::avatar($name, 'md')
        . "<div class='crm-inbox-item-body'><div class='d-flex justify-content-between gap-2'><strong class='text-truncate'>" . Menu::e($name) . "</strong>"
        . "<span class='small text-secondary'>" . Menu::e(Conversa::ago($r['last_message_at'])) . "</span></div>"
        . "<div class='d-flex justify-content-between gap-2 align-items-center'><span class='small text-secondary text-truncate'>" . Menu::e($last ? (($last['direction'] === 'out' ? 'Você: ' : '') . $last['body']) : '—') . "</span>"
        . ($waiting ? "<span class='crm-dot' title='Aguardando resposta'></span>" : '') . "</div>"
        . "<div class='mt-1'>" . Conversa::etapaBadge((int) $r['plugin_crm_etapas_id'])
        . ((int) $r['status'] !== Conversa::OPEN ? ' ' . Conversa::statusBadge((int) $r['status']) : '') . "</div></div></a>";
}
if (!$rows) {
    echo "<div class='text-center text-secondary p-4'><i class='ti ti-inbox fs-1'></i><div>Nenhuma conversa</div></div>";
}
echo "</div></aside>";

if (!$conversa) {
    echo "<section class='crm-inbox-chat crm-inbox-empty'><div class='text-center text-secondary'><i class='ti ti-message-2 fs-1'></i><div>Selecione uma conversa</div></div></section></div>";
    Html::footer();
    return;
}

$c = $conversa->fields;
$name = Conversa::title($c);
$edit = Conversa::canUpdate();
$open = (int) $c['status'] === Conversa::OPEN;

// --- chat
echo "<section class='crm-inbox-chat'><header class='crm-inbox-head'>" . Conversa::avatar($name, 'lg')
    . "<div class='flex-grow-1'><h3 class='mb-0'>" . Menu::e($name) . "</h3><div class='small text-secondary'>"
    . Menu::e(Conversa::displayName($c)) . ($c['numero_wa'] !== '' ? " · <i class='ti ti-brand-whatsapp'></i> " . Menu::e($c['numero_wa']) : '')
    . "</div></div>" . Conversa::sourceBadge($c['source'], false) . " "
    . Conversa::clientBadge($c['client_type']) . ' ' . Conversa::statusBadge((int) $c['status'])
    . Menu::button('Ficha', Menu::page('/front/oportunidade.php?id=' . $id), 'ti-id', 'btn-outline-primary btn-sm') . "</header>";
echo "<div class='crm-chat' id='crm-msgs'></div>";
if ($edit && $open) {
    echo "<footer class='crm-composer'><textarea class='form-control' rows='1' id='crm-text' placeholder='Escreva uma mensagem (Enter envia, Shift+Enter quebra a linha)'></textarea>"
        . "<button class='btn btn-primary' id='crm-send'><i class='ti ti-send me-1'></i>Enviar</button></footer>";
} else {
    echo "<footer class='crm-composer text-secondary small'>" . ($open ? 'Você só pode visualizar esta conversa.' : 'Conversa encerrada. Reabra para responder.') . "</footer>";
}
echo "</section>";

// --- detalhes
$opts = '';
foreach (Etapa::forClient($c['client_type']) as $eid => $e) {
    $opts .= "<option value='$eid'" . ((int) $c['plugin_crm_etapas_id'] === $eid ? ' selected' : '') . '>' . Menu::e($e['name']) . '</option>';
}
$users = "<option value='0'>Sem atendente</option>";
foreach (Conversa::attendantOptions() as $uid => $uname) {
    $users .= "<option value='$uid'" . ((int) $c['users_id_attendant'] === $uid ? ' selected' : '') . '>' . Menu::e($uname) . '</option>';
}
$dis = $edit ? '' : ' disabled';
echo "<aside class='crm-inbox-side'><div class='crm-side-block'><div class='crm-side-title'>Cliente</div><dl class='crm-dl'>"
    . "<dt>Empresa</dt><dd>" . Menu::e($c['company'] ?: '—') . "</dd><dt>Contato</dt><dd>" . Menu::e(Conversa::displayName($c)) . "</dd>"
    . "<dt>E-mail</dt><dd>" . Menu::e($c['email'] ?: '—') . "</dd><dt>Telefone</dt><dd>" . Menu::e($c['numero_wa'] ?: '—') . "</dd>"
    . "<dt>Código</dt><dd>" . Menu::e(Conversa::code($id)) . "</dd></dl></div>"
    . "<div class='crm-side-block'><div class='crm-side-title'>Oportunidade</div>"
    . "<label class='form-label'>Etapa do funil</label><select class='form-select mb-2' data-act='etapa'" . ($dis ?: ($open ? '' : ' disabled')) . ">$opts</select>"
    . "<label class='form-label'>Atendente</label><select class='form-select mb-2' data-act='assign'$dis>$users</select>"
    . "<label class='form-label'>Valor (R$)</label><div class='input-group'><input class='form-control' data-act-input='value' inputmode='decimal' value='" . Menu::e(number_format((float) $c['value'], 2, ',', '.')) . "'$dis>"
    . "<button class='btn btn-outline-primary' data-act='value'$dis><i class='ti ti-check'></i></button></div></div>";
echo "<div class='crm-side-block'><div class='crm-side-title'>Detalhes</div>"
    . "<dl class='crm-dl'><dt>Criada em</dt><dd>" . Menu::e(Html::convDateTime($c['date_creation'])) . "</dd>"
    . "<dt>Última mensagem</dt><dd>" . Menu::e($c['last_message_at'] ? Html::convDateTime($c['last_message_at']) : '—') . "</dd>"
    . "<dt>Origem</dt><dd>" . Menu::e(Conversa::SOURCES[$c['source']] ?? 'Outros') . "</dd>"
    . ($c['date_closed'] ? "<dt>Encerrada em</dt><dd>" . Menu::e(Html::convDateTime($c['date_closed'])) . "</dd>" : '')
    . ((int) $c['status'] === Conversa::LOST && $c['lost_reason'] ? "<dt>Motivo da perda</dt><dd>" . Menu::e($c['lost_reason']) . "</dd>" : '')
    . "</dl></div>";
if ($edit) {
    echo "<div class='crm-side-block'><div class='crm-side-title'>Resultado</div>";
    if ($open) {
        echo "<button class='btn btn-success w-100 mb-2' data-act='won'><i class='ti ti-trophy me-1'></i>Marcar como venda</button>"
            . "<button class='btn btn-outline-danger w-100' data-act='lost'><i class='ti ti-circle-x me-1'></i>Marcar como perdida</button>";
    } else {
        echo "<button class='btn btn-outline-secondary w-100' data-act='reopen'><i class='ti ti-refresh me-1'></i>Reabrir conversa</button>";
    }
    echo "</div>";
}
echo "</aside></div>";

$boot = ['ajax' => Menu::url('/front/ajax.php'), 'id' => $id, 'messages' => Conversa::messages($id)];
echo "<script type='application/json' id='crm-boot'>" . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
echo Menu::script('inbox.js');
Html::footer();
