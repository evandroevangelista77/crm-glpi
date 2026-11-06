<?php

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Lead;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$t = Lead::getTable();
$event = (int) ($_GET['evento'] ?? 0);
$status = isset(Lead::STATUSES[$_GET['situacao'] ?? '']) ? $_GET['situacao'] : '';
$temp = isset(Lead::TEMPS[$_GET['temperatura'] ?? '']) ? $_GET['temperatura'] : '';
$q = trim((string) ($_GET['q'] ?? ''));

$all = iterator_to_array($DB->request(['FROM' => $t, 'ORDER' => ['date_creation DESC', 'id DESC']]), false);

// eventos que têm leads (mais o que a Gestão de Eventos conhece)
$events = [];
foreach ($all as $l) {
    $events[(int) $l['event_id']] ??= ['name' => $l['event_name'], 'date' => $l['event_date'], 'n' => 0, 'new' => 0, 'conv' => 0];
    $events[(int) $l['event_id']]['n']++;
    $events[(int) $l['event_id']]['new'] += $l['status'] === 'novo' ? 1 : 0;
    $events[(int) $l['event_id']]['conv'] += $l['status'] === 'convertido' ? 1 : 0;
}
uasort($events, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

$rows = array_values(array_filter($all, function ($l) use ($event, $status, $temp, $q) {
    if ($event && (int) $l['event_id'] !== $event) { return false; }
    if ($status && $l['status'] !== $status) { return false; }
    if ($temp && $l['temperature'] !== $temp) { return false; }
    if ($q !== '' && stripos($l['name'] . ' ' . $l['company'] . ' ' . $l['email'] . ' ' . $l['job_title'] . ' ' . $l['event_name'], $q) === false) { return false; }
    return true;
}));

$total = count($all);
$by = fn (string $k, string $v) => count(array_filter($all, fn ($l) => $l[$k] === $v));
$conv = $by('status', 'convertido');

Menu::header('Gestão de Leads', 'leads');
echo "<div class='crm'>" . Menu::title('Gestão de Leads', 'leads') . "</div>";

$kpi = fn (string $label, string $value, string $sub, string $color) => "<div class='col-6 col-lg-3'><div class='crm-kpi' style='--c:$color'><div class='crm-kpi-label'>" . Menu::e($label) . "</div><div class='crm-kpi-value'>" . Menu::e($value) . "</div><div class='crm-kpi-sub'>" . Menu::e($sub) . "</div></div></div>";
echo "<div class='crm'><div class='row g-3 mb-3'>"
    . $kpi('Leads de eventos', (string) $total, count($events) . ' evento(s)', '#3b82f6')
    . $kpi('Novos, sem contato', (string) $by('status', 'novo'), 'aguardando o comercial', '#f59e0b')
    . $kpi('Leads quentes', (string) $by('temperature', 'quente'), 'prioridade de contato', '#ef4444')
    . $kpi('Convertidos', (string) $conv, ($total ? round(100 * $conv / $total) : 0) . '% viraram oportunidade', '#22c55e')
    . "</div>";

// eventos
if ($events) {
    echo "<div class='d-flex flex-wrap gap-2 mb-3'>";
    $link = fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['evento' => $event, 'situacao' => $status, 'temperatura' => $temp, 'q' => $q], $over), fn ($v) => $v !== '' && $v !== 0));
    echo "<a class='btn btn-sm " . ($event ? 'btn-outline-secondary' : 'btn-primary') . "' href='" . Menu::e($link(['evento' => 0])) . "'>Todos os eventos <span class='badge bg-secondary-lt ms-1'>$total</span></a>";
    foreach ($events as $eid => $ev) {
        echo "<a class='btn btn-sm " . ($event === $eid ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . Menu::e($link(['evento' => $eid])) . "'><i class='ti ti-calendar-event me-1'></i>" . Menu::e($ev['name'])
            . " <span class='badge bg-secondary-lt ms-1'>{$ev['n']}</span>" . ($ev['new'] ? " <span class='badge bg-warning-lt ms-1' title='Novos'>{$ev['new']} novos</span>" : '') . "</a>";
    }
    echo "</div>";
}

// filtros
echo "<div class='card mb-3'><div class='card-body'><form method='get' class='d-flex flex-wrap gap-2 align-items-end'><input type='hidden' name='evento' value='$event'>"
    . "<div><label class='form-label mb-1'>Situação</label><select class='form-select' name='situacao' onchange='this.form.submit()'><option value=''>Todas</option>";
foreach (Lead::STATUSES as $k => $s) { echo "<option value='$k'" . ($status === $k ? ' selected' : '') . '>' . Menu::e($s[0]) . '</option>'; }
echo "</select></div><div><label class='form-label mb-1'>Temperatura</label><select class='form-select' name='temperatura' onchange='this.form.submit()'><option value=''>Todas</option>";
foreach (Lead::TEMPS as $k => $s) { echo "<option value='$k'" . ($temp === $k ? ' selected' : '') . '>' . Menu::e($s[0]) . '</option>'; }
echo "</select></div><div class='flex-grow-1' style='min-width:220px'><label class='form-label mb-1'>Buscar</label><input class='form-control' type='search' name='q' value='" . Menu::e($q) . "' placeholder='Nome, empresa, e-mail, cargo ou evento'></div>"
    . "<button class='btn btn-primary'><i class='ti ti-search me-1'></i>Buscar</button></form></div></div>";

// lista
$canCreate = Conversa::canCreate();
echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . count($rows) . " lead(s)</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-hover' id='crm-leads'>"
    . "<thead><tr><th>Lead</th><th>Evento</th><th>Interesse</th><th>Temperatura</th><th>Situação</th><th class='text-end'>Captado</th><th class='text-end'>Ações</th></tr></thead><tbody>";
foreach ($rows as $l) {
    $id = (int) $l['id'];
    $opp = (int) $l['conversas_id'];
    echo "<tr data-id='$id'><td><div class='d-flex align-items-center gap-2'>" . Conversa::avatar($l['name'])
        . "<div><div class='fw-bold'>" . Menu::e($l['name']) . "</div><div class='small text-secondary'>" . Menu::e(trim(($l['job_title'] ?: '') . ($l['job_title'] && $l['company'] ? ' · ' : '') . ($l['company'] ?: ''))) . "</div>"
        . "<div class='small text-secondary'>" . ($l['email'] ? "<i class='ti ti-mail'></i> " . Menu::e($l['email']) : '') . ($l['phone'] ? " &nbsp;<i class='ti ti-phone'></i> " . Menu::e($l['phone']) : '') . "</div></div></div></td>"
        . "<td><span class='crm-chip'><i class='ti ti-calendar-event'></i> " . Menu::e($l['event_name']) . "</span>" . ($l['event_date'] ? "<div class='small text-secondary mt-1'>" . Menu::e(Html::convDate($l['event_date'])) . "</div>" : '') . "</td>"
        . "<td class='text-secondary'>" . Menu::e(Lead::INTERESTS[$l['interest']] ?? 'Outro') . "</td>"
        . "<td>" . Lead::tempBadge($l['temperature']) . "</td>"
        . "<td>" . ($canCreate && $l['status'] !== 'convertido'
            ? "<select class='form-select form-select-sm' data-lead-status style='min-width:130px'>" . implode('', array_map(fn ($k) => "<option value='$k'" . ($l['status'] === $k ? ' selected' : '') . '>' . Menu::e(Lead::STATUSES[$k][0]) . '</option>', array_diff(array_keys(Lead::STATUSES), ['convertido']))) . "</select>"
            : Lead::statusBadge($l['status'])) . "</td>"
        . "<td class='text-end text-secondary small'>" . Menu::e(Conversa::ago($l['date_creation'])) . "</td>"
        . "<td class='text-end text-nowrap'>" . ($opp
            ? Menu::button('Ver oportunidade', Menu::page('/front/oportunidade.php?id=' . $opp), 'ti-external-link', 'btn-sm btn-outline-success')
            : ($canCreate ? "<button class='btn btn-sm btn-primary' data-lead-convert><i class='ti ti-arrow-right me-1'></i>Converter em oportunidade</button> <button class='btn btn-sm btn-outline-danger' data-lead-delete title='Excluir lead'><i class='ti ti-trash'></i></button>" : '')) . "</td></tr>";
}
if (!$rows) {
    echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Nenhum lead com esses filtros. Os leads chegam pelo botão \"Enviar leads para o CRM\" na Gestão de Eventos.</td></tr>";
}
echo "</tbody></table></div></div></div>";
echo "<script type='application/json' id='crm-boot'>" . json_encode(['ajax' => Menu::url('/front/ajax.php')], JSON_HEX_TAG | JSON_HEX_AMP) . "</script>";
echo Menu::script('leads.js');
Html::footer();
