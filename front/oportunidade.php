<?php

use GlpiPlugin\Crm\Alerta;
use GlpiPlugin\Crm\Ata;
use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Documento;
use GlpiPlugin\Crm\Etapa;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$id = (int) ($_REQUEST['id'] ?? 0);
$conversa = new Conversa();
if (!$id || !$conversa->getFromDB($id) || !$conversa->canViewItem()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$self = Menu::url('/front/oportunidade.php?id=' . $id);
$edit = Conversa::canUpdate();

if (isset($_POST['salvar'])) {
    Session::checkCSRF($_POST);
    try {
        if (!$edit) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $conversa->updateEntry($_POST);
        Session::addMessageAfterRedirect('Dados salvos.', false, INFO);
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
    Html::redirect($self);
}

$c = $conversa->fields;
$open = (int) $c['status'] === Conversa::OPEN;
$title = Conversa::title($c);
$docs = Documento::forConversa($id);
$editalMissing = $c['client_type'] === 'publico' && !Documento::hasEdital($id);
$etapas = Etapa::forClient($c['client_type']);
$curKind = Etapa::all()[(int) $c['plugin_crm_etapas_id']]['kind'] ?? '';
$ciclo = $conversa->cicloInfo();
$closing = $conversa->closingInfo(true);

Menu::header($title, 'entradas');
echo "<div class='crm'>" . Menu::title($title, 'oportunidade',
    Menu::button('Relatório da negociação', Menu::page('/front/relatorio.php?tipo=negociacao&id=' . $id), 'ti-file-type-pdf', $open ? 'btn-outline-secondary' : 'btn-success')
    . Menu::button('Conversa', Menu::page('/front/conversas.php?id=' . $id), 'ti-message-2', 'btn-outline-primary') . Menu::button('Entradas', Menu::page('/front/entradas.php'), 'ti-arrow-left', 'btn-outline-secondary'),
    'Oportunidade · ' . Conversa::code($id)) . "</div>";

// resumo
echo "<div class='d-flex flex-wrap gap-2 align-items-center mb-3'>" . Conversa::clientBadge($c['client_type']) . ' ' . Conversa::statusBadge((int) $c['status'])
    . " " . Conversa::sourceBadge($c['source'])
    . " <span class='crm-chip'><i class='ti ti-user'></i> " . Menu::e(Conversa::attendantName((int) $c['users_id_attendant'])) . "</span>"
    . ((float) $c['value'] ? " <span class='crm-chip'><i class='ti ti-currency-real'></i> " . Menu::e(Conversa::money((float) $c['value'])) . "</span>" : '')
    . ($editalMissing ? " <span class='crm-chip crm-chip-red'><i class='ti ti-alert-triangle'></i> Edital pendente</span>" : '') . "</div>";

// esteira de venda
$keys = array_keys($etapas);
$cur = array_search((int) $c['plugin_crm_etapas_id'], $keys, true);
$cur = $cur === false ? -1 : $cur;
$won = (int) $c['status'] === Conversa::WON;
$lost = (int) $c['status'] === Conversa::LOST;
echo "<div class='card mb-3'><div class='card-body py-3'><div class='crm-esteira'>";
foreach ($keys as $i => $eid) {
    $e = $etapas[$eid];
    $state = $won || $i < $cur ? 'done' : ($i === $cur ? ($lost ? 'lost' : 'current') : 'todo');
    $icon = $state === 'done' ? 'ti-check' : ($state === 'lost' ? 'ti-x' : '');
    $click = $edit && $open && $i !== $cur ? " data-etapa='$eid' role='button'" : '';
    echo "<div class='crm-estep $state'$click><div class='crm-edot'>" . ($icon ? "<i class='ti $icon'></i>" : ($i + 1)) . "</div>"
        . "<div class='crm-elabel'>" . Menu::e($e['name']) . "</div><div class='crm-edetail'>{$e['probability']}%" . ($e['scope'] === 'publico' ? ' · público' : '') . "</div></div>";
}
echo "<div class='crm-estep " . ($won ? 'done' : 'todo') . "'><div class='crm-edot'><i class='ti ti-trophy'></i></div><div class='crm-elabel'>Venda</div><div class='crm-edetail'>" . ($won ? 'fechada' : 'resultado') . "</div></div>";
echo "</div></div></div>";

// aviso de fechamento / ciclo financeiro
if (($open && $curKind === 'fechamento') || $won) {
    echo "<div class='card mb-3 crm-closing'><div class='card-body d-flex flex-wrap align-items-center gap-3'><div class='crm-kpi-icon' style='--c:#22c55e'><i class='ti ti-bell-ringing'></i></div>"
        . "<div class='flex-grow-1'><div class='fw-bold'>Aviso de fechamento</div><div class='text-secondary small'>";
    if ($ciclo) {
        echo "Ciclo financeiro <b>" . Menu::e($ciclo['code']) . "</b> em andamento (" . Menu::e($ciclo['status']) . ").</div></div>" . Menu::button('Abrir ciclo financeiro', $ciclo['url'], 'ti-external-link', 'btn-success');
    } elseif ($closing) {
        echo "Esta oportunidade chegou ao fechamento e ainda não tem ciclo financeiro.</div></div><button class='btn btn-success' id='crm-start-cycle'><i class='ti ti-coin me-1'></i>Iniciar ciclo financeiro</button>";
    } else {
        echo "O ciclo financeiro não está disponível (plugin inativo ou sem direito de abrir ciclos).</div></div>";
    }
    echo "</div></div>";
}

// formulário de dados
$v = fn (string $k): string => Menu::e((string) ($c[$k] ?? ''));
$dis = $edit ? '' : ' disabled';
$opts = function (array $list, string $current): string {
    $out = '';
    foreach ($list as $k => $label) {
        $out .= "<option value='$k'" . ($current === (string) $k ? ' selected' : '') . '>' . Menu::e(is_array($label) ? $label[0] : $label) . '</option>';
    }
    return $out;
};
echo "<div class='row row-cards'><div class='col-lg-8'><form method='post' action='" . Menu::e(Menu::page('/front/oportunidade.php')) . "' class='card mb-3'>"
    . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) . Html::hidden('id', ['value' => $id]) . Html::hidden('salvar', ['value' => 1])
    . "<div class='card-header'><h3 class='card-title'><i class='ti ti-id me-2'></i>Dados da oportunidade</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-4'><label class='form-label'>Canal de entrada</label><select class='form-select' name='source'$dis>" . $opts(Conversa::SOURCES, (string) $c['source']) . "</select></div>"
    . "<div class='col-md-4'><label class='form-label'>Tipo de cliente</label><select class='form-select' name='client_type'$dis>" . $opts(Conversa::CLIENT_TYPES, (string) $c['client_type']) . "</select></div>"
    . "<div class='col-md-4'><label class='form-label'>Como será contratado</label><select class='form-select' name='negotiation_type'$dis>" . $opts(Conversa::NEGOTIATION, (string) $c['negotiation_type']) . "</select></div>"
    . "<div class='col-md-6'><label class='form-label required'>Nome do contato</label><input class='form-control' name='name' value='" . Menu::e(Conversa::displayName($c)) . "' required$dis></div>"
    . "<div class='col-md-6'><label class='form-label'>" . ($c['client_type'] === 'publico' ? 'Órgão / entidade' : 'Empresa') . "</label><input class='form-control' name='company' value='" . $v('company') . "'$dis></div>"
    . "<div class='col-md-4'><label class='form-label'>CNPJ</label><input class='form-control' name='cnpj' value='" . $v('cnpj') . "'$dis></div>"
    . "<div class='col-md-4'><label class='form-label'>E-mail</label><input class='form-control' type='email' name='email' value='" . $v('email') . "'$dis></div>"
    . "<div class='col-md-4'><label class='form-label'>Telefone / WhatsApp</label><input class='form-control' name='numero_wa' value='" . $v('numero_wa') . "'$dis></div>"
    . "<div class='col-12'><label class='form-label'>Assunto / necessidade</label><input class='form-control' name='subject' value='" . $v('subject') . "'$dis></div>"
    . "<div class='col-md-4'><label class='form-label'>Valor (R$)</label><input class='form-control' name='value' inputmode='decimal' value='" . Menu::e(number_format((float) $c['value'], 2, ',', '.')) . "'$dis></div>"
    . "<div class='col-md-4'><label class='form-label'>Prazo de resposta</label><input class='form-control' type='date' name='deadline' value='" . $v('deadline') . "'$dis></div>"
    . ($c['client_type'] === 'publico'
        ? "<div class='col-md-4'><label class='form-label'>Data da sessão / pregão</label><input class='form-control' type='date' name='session_date' value='" . $v('session_date') . "'$dis></div>"
        . "<div class='col-md-12'><label class='form-label'>Nº do edital</label><input class='form-control' name='edital_number' value='" . $v('edital_number') . "'$dis></div>"
        : Html::hidden('session_date', ['value' => $c['session_date']]) . Html::hidden('edital_number', ['value' => $c['edital_number']]))
    . "<div class='col-12'><label class='form-label'>Observações</label><textarea class='form-control' rows='3' name='notes'$dis>" . $v('notes') . "</textarea></div>"
    . "</div></div>" . ($edit ? "<div class='card-footer text-end'><button class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>Salvar alterações</button></div>" : '') . "</form></div>";

// lateral: documentos e linha do tempo
echo "<div class='col-lg-4'><div class='card mb-3' id='documentos'><div class='card-header'><h3 class='card-title'><i class='ti ti-paperclip me-2'></i>Documentos</h3><div class='ms-auto text-secondary small'>" . count($docs) . "</div></div>";
if ($editalMissing) {
    echo "<div class='alert alert-danger m-3 mb-0 py-2'><i class='ti ti-gavel me-1'></i>Cliente público sem <b>edital</b>. Anexe o edital abaixo.</div>";
}
echo "<div class='list-group list-group-flush'>";
foreach ($docs as $d) {
    $download = Menu::url('/front/documento.php?id=' . (int) $d['id']);
    $ext = strtolower(pathinfo((string) $d['stored'], PATHINFO_EXTENSION));
    echo "<div class='list-group-item d-flex gap-2 align-items-center'><span class='crm-kpi-icon' style='--c:" . ($d['doc_type'] === 'edital' ? '#a855f7' : '#3b82f6') . "'><i class='ti " . (Documento::ICONS[$d['doc_type']] ?? 'ti-paperclip') . "'></i></span>"
        . "<div class='flex-grow-1 text-truncate'><a href='" . Menu::e($download) . "' class='fw-bold text-truncate d-block'>" . Menu::e($d['name']) . "</a>"
        . "<div class='small text-secondary'>" . Menu::e(Documento::TYPES[$d['doc_type']] ?? 'Outros') . " · " . Menu::e(Documento::humanSize((int) $d['size'])) . " · " . Menu::e(Html::convDate($d['date'])) . "</div></div>"
        . (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'], true) ? "<a class='btn btn-sm btn-outline-secondary' target='_blank' href='" . Menu::e($download . '&ver=1') . "' title='Ver'><i class='ti ti-eye'></i></a>" : '')
        . ($edit ? "<form method='post' action='" . Menu::e(Menu::page('/front/documento.php')) . "' onsubmit=\"return confirm('Remover este documento?')\">"
            . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) . Html::hidden('id', ['value' => $id]) . Html::hidden('doc', ['value' => $d['id']]) . Html::hidden('action', ['value' => 'delete'])
            . "<button class='btn btn-sm btn-outline-danger' title='Remover'><i class='ti ti-trash'></i></button></form>" : '') . "</div>";
}
if (!$docs) {
    echo "<div class='list-group-item text-secondary text-center py-3'>Nenhum documento anexado.</div>";
}
echo "</div>";
if ($edit) {
    $docOpts = '';
    foreach (Documento::TYPES as $k => $label) {
        $docOpts .= "<option value='$k'" . ($k === 'edital' && $editalMissing ? ' selected' : '') . '>' . Menu::e($label) . '</option>';
    }
    echo "<form method='post' enctype='multipart/form-data' action='" . Menu::e(Menu::page('/front/documento.php')) . "' class='card-body border-top'>"
        . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) . Html::hidden('id', ['value' => $id])
        . "<div class='crm-side-title'>Anexar documento</div><select class='form-select mb-2' name='doc_type[]'>$docOpts</select>"
        . "<input class='form-control mb-2' type='file' name='docs[]' required><button class='btn btn-outline-primary w-100'><i class='ti ti-upload me-1'></i>Anexar</button></form>";
}
echo "</div>";

// atas de reunião
$atas = Ata::forConversa($id);
echo "<div class='card mb-3' id='atas'><div class='card-header'><h3 class='card-title'><i class='ti ti-notes me-2'></i>Atas de reunião</h3><div class='ms-auto text-secondary small'>" . count($atas) . "</div></div><div class='list-group list-group-flush'>";
foreach ($atas as $a) {
    [$kindLabel, $kindIcon] = Ata::KINDS[$a['kind']] ?? Ata::KINDS['outro'];
    $doc = (int) $a['plugin_crm_documentos_id'] ? $DB->request(['FROM' => Documento::getTable(), 'WHERE' => ['id' => (int) $a['plugin_crm_documentos_id']]])->current() : null;
    echo "<div class='list-group-item'><div class='d-flex gap-2 align-items-start'><span class='crm-kpi-icon' style='--c:#f59e0b'><i class='ti $kindIcon'></i></span>"
        . "<div class='flex-grow-1' style='min-width:0'><div class='fw-bold'>" . Menu::e($a['title']) . "</div>"
        . "<div class='small text-secondary'>" . Menu::e(Html::convDateTime($a['meeting_date'])) . " · " . Menu::e($kindLabel) . ((int) $a['duration_min'] ? ' · ' . (int) $a['duration_min'] . ' min' : '') . " · " . Menu::e(getUserName((int) $a['users_id'])) . "</div>"
        . ($a['participants'] ? "<div class='small'><i class='ti ti-users'></i> " . Menu::e($a['participants']) . "</div>" : '')
        . ($a['summary'] ? "<details class='mt-1'><summary class='small text-primary' style='cursor:pointer'>Resumo e decisões</summary><div class='small mt-1' style='white-space:pre-wrap'>" . Menu::e($a['summary'])
            . ($a['decisions'] ? "

<b>Decisões e próximos passos</b>
" . Menu::e($a['decisions']) : '') . "</div></details>" : '')
        . ($doc ? "<a class='btn btn-sm btn-outline-secondary mt-2' href='" . Menu::e(Menu::url('/front/documento.php?id=' . (int) $doc['id'])) . "'><i class='ti ti-download me-1'></i>" . Menu::e($doc['name']) . "</a>" : '')
        . "</div>"
        . ($edit ? "<form method='post' action='" . Menu::e(Menu::page('/front/ata.php')) . "' onsubmit=\"return confirm('Remover esta ata?')\">"
            . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) . Html::hidden('id', ['value' => $id]) . Html::hidden('ata', ['value' => $a['id']]) . Html::hidden('action', ['value' => 'delete'])
            . "<button class='btn btn-sm btn-outline-danger' title='Remover'><i class='ti ti-trash'></i></button></form>" : '')
        . "</div></div>";
}
if (!$atas) {
    echo "<div class='list-group-item text-secondary text-center py-3'>Nenhuma ata registrada.</div>";
}
echo "</div>";
if ($edit) {
    $kindOpts = '';
    foreach (Ata::KINDS as $k => [$label]) {
        $kindOpts .= "<option value='$k'>" . Menu::e($label) . '</option>';
    }
    echo "<details class='card-body border-top'" . ($atas ? '' : ' open') . "><summary class='fw-bold' style='cursor:pointer'><i class='ti ti-plus me-1'></i>Registrar ata de reunião</summary>"
        . "<form method='post' enctype='multipart/form-data' action='" . Menu::e(Menu::page('/front/ata.php')) . "' class='mt-3'>"
        . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) . Html::hidden('id', ['value' => $id])
        . "<div class='row g-2'><div class='col-12'><label class='form-label required'>Título</label><input class='form-control' name='title' placeholder='Ex.: Reunião de alinhamento técnico' required></div>"
        . "<div class='col-md-7'><label class='form-label required'>Data e hora</label><input class='form-control' type='datetime-local' name='meeting_date' value='" . date('Y-m-d\TH:i') . "' required></div>"
        . "<div class='col-md-5'><label class='form-label'>Duração (min)</label><input class='form-control' type='number' min='0' name='duration_min' placeholder='60'></div>"
        . "<div class='col-12'><label class='form-label'>Tipo</label><select class='form-select' name='kind'>$kindOpts</select></div>"
        . "<div class='col-12'><label class='form-label'>Participantes</label><input class='form-control' name='participants' placeholder='Nomes e cargos'></div>"
        . "<div class='col-12'><label class='form-label'>Resumo da reunião</label><textarea class='form-control' rows='4' name='summary'></textarea></div>"
        . "<div class='col-12'><label class='form-label'>Decisões e próximos passos</label><textarea class='form-control' rows='3' name='decisions'></textarea></div>"
        . "<div class='col-12'><label class='form-label'>Arquivo da ata (opcional)</label><input class='form-control' type='file' name='ata_file[]'></div>"
        . "<div class='col-12'><button class='btn btn-primary w-100'><i class='ti ti-device-floppy me-1'></i>Salvar ata</button></div></div></form></details>";
}
echo "</div>";

// linha do tempo
echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-timeline me-2'></i>Linha do tempo</h3></div><div class='card-body crm-timeline'>";
$events = iterator_to_array($DB->request(['FROM' => Alerta::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $id], 'ORDER' => ['id DESC'], 'LIMIT' => 12]), false);
foreach ($events as $ev) {
    $meta = Alerta::EVENTS[$ev['type']] ?? ['label' => $ev['type'], 'icon' => 'ti-point', 'color' => '#64748b'];
    echo "<div class='crm-tl'><span class='crm-kpi-icon' style='--c:{$meta['color']}'><i class='ti {$meta['icon']}'></i></span><div><div class='fw-bold small'>" . Menu::e($meta['label']) . "</div>"
        . "<div class='small text-secondary'>" . Menu::e($ev['body'] ?: $ev['title']) . "</div><div class='small text-secondary'>" . Menu::e(Html::convDateTime($ev['date'])) . "</div></div></div>";
}
echo "<div class='crm-tl'><span class='crm-kpi-icon' style='--c:#64748b'><i class='ti ti-flag'></i></span><div><div class='fw-bold small'>Entrada registrada</div><div class='small text-secondary'>"
    . Menu::e(Conversa::sourceLabel($c['source'])) . " · " . Menu::e(Html::convDateTime($c['date_creation'])) . "</div></div></div>";
echo "</div></div></div></div>";

$boot = ['ajax' => Menu::url('/front/ajax.php'), 'id' => $id, 'closing' => $closing];
echo "<script type='application/json' id='crm-boot'>" . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
echo Menu::script('ficha.js');
Html::footer();
