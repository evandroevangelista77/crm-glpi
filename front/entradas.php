<?php

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Documento;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$t = Conversa::getTable();
$where = Conversa::scope($t);
$type = isset(Conversa::CLIENT_TYPES[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
$source = isset(Conversa::SOURCES[$_GET['canal'] ?? '']) ? $_GET['canal'] : '';
$state = in_array($_GET['situacao'] ?? 'abertas', ['abertas', 'fechadas', 'todas'], true) ? ($_GET['situacao'] ?? 'abertas') : 'abertas';
$q = trim((string) ($_GET['q'] ?? ''));

// contagem por tipo de cliente (com os demais filtros, menos o próprio tipo)
$base = $where;
if ($source) {
    $base["$t.source"] = $source;
}
if ($state === 'abertas') {
    $base["$t.status"] = Conversa::OPEN;
} elseif ($state === 'fechadas') {
    $base["$t.status"] = [Conversa::WON, Conversa::LOST];
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $base[] = ['OR' => ["$t.name" => ['LIKE', $like], "$t.company" => ['LIKE', $like], "$t.email" => ['LIKE', $like], "$t.numero_wa" => ['LIKE', $like], "$t.subject" => ['LIKE', $like]]];
}
$counts = ['' => 0, 'privado' => 0, 'publico' => 0];
foreach ($DB->request(['SELECT' => ['client_type'], 'FROM' => $t, 'WHERE' => $base]) as $r) {
    $counts[$r['client_type']] = ($counts[$r['client_type']] ?? 0) + 1;
    $counts['']++;
}
$rows = iterator_to_array($DB->request(['FROM' => $t, 'WHERE' => $base + ($type ? ["$t.client_type" => $type] : []), 'ORDER' => ['date_creation DESC', 'id DESC'], 'LIMIT' => 300]), false);
$docs = Documento::summary();

Menu::header('Entradas', 'entradas');
echo "<div class='crm'>" . Menu::title('Canais de entrada', 'entradas', Conversa::canCreate() ? Menu::button('Nova entrada', Menu::page('/front/entrada.php')) : '') . "</div>";

// filtros
$link = fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['tipo' => $type, 'canal' => $source, 'situacao' => $state, 'q' => $q], $over), fn ($v) => $v !== ''));
echo "<div class='card mb-3'><div class='card-body'><div class='d-flex flex-wrap gap-3 align-items-end'>"
    . "<div class='btn-group'>";
foreach ([['', 'Todos', 'ti-users'], ['privado', 'Privados', 'ti-building'], ['publico', 'Públicos', 'ti-building-bank']] as [$k, $label, $icon]) {
    echo "<a class='btn " . ($type === $k ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . Menu::e($link(['tipo' => $k])) . "'><i class='ti $icon me-1'></i>" . Menu::e($label) . " <span class='badge bg-secondary-lt ms-1'>{$counts[$k]}</span></a>";
}
echo "</div><form method='get' class='d-flex flex-wrap gap-2 align-items-end flex-grow-1'><input type='hidden' name='tipo' value='" . Menu::e($type) . "'>"
    . "<div><label class='form-label mb-1'>Canal</label><select class='form-select' name='canal' onchange='this.form.submit()'><option value=''>Todos os canais</option>";
foreach (Conversa::SOURCES as $k => $label) {
    echo "<option value='$k'" . ($source === $k ? ' selected' : '') . '>' . Menu::e($label) . '</option>';
}
echo "</select></div><div><label class='form-label mb-1'>Situação</label><select class='form-select' name='situacao' onchange='this.form.submit()'>";
foreach (['abertas' => 'Abertas', 'fechadas' => 'Fechadas', 'todas' => 'Todas'] as $k => $label) {
    echo "<option value='$k'" . ($state === $k ? ' selected' : '') . '>' . Menu::e($label) . '</option>';
}
echo "</select></div><div class='flex-grow-1' style='min-width:220px'><label class='form-label mb-1'>Buscar</label><input class='form-control' type='search' name='q' value='" . Menu::e($q) . "' placeholder='Contato, empresa, e-mail, telefone ou assunto'></div>"
    . "<button class='btn btn-primary'><i class='ti ti-search me-1'></i>Buscar</button></form></div></div></div>";

// lista
echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . count($rows) . " entrada(s)</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-hover'>"
    . "<thead><tr><th>Cliente / contato</th><th>Tipo</th><th>Canal</th><th>Assunto</th><th>Etapa</th><th class='text-end'>Valor</th><th>Docs</th><th>Atendente</th><th class='text-end'>Entrada</th></tr></thead><tbody>";
foreach ($rows as $r) {
    $url = Menu::page('/front/oportunidade.php?id=' . (int) $r['id']);
    $title = Conversa::title($r);
    $d = $docs[(int) $r['id']] ?? ['n' => 0, 'edital' => false];
    $missing = $r['client_type'] === 'publico' && !$d['edital'];
    echo "<tr style='cursor:pointer' onclick=\"location.href='" . Menu::e($url) . "'\"><td><div class='d-flex align-items-center gap-2'>" . Conversa::avatar($title)
        . "<div><div class='fw-bold'>" . Menu::e($title) . "</div><div class='small text-secondary'>" . Menu::e(Conversa::displayName($r)) . ($r['email'] ? ' · ' . Menu::e($r['email']) : '') . "</div></div></div></td>"
        . "<td>" . Conversa::clientBadge($r['client_type']) . "</td>"
        . "<td>" . Conversa::sourceBadge($r['source']) . "</td>"
        . "<td class='text-secondary' style='max-width:260px'><div class='text-truncate'>" . Menu::e($r['subject'] ?: '—') . "</div></td>"
        . "<td>" . ((int) $r['status'] === Conversa::OPEN ? Conversa::etapaBadge((int) $r['plugin_crm_etapas_id']) : Conversa::statusBadge((int) $r['status'])) . "</td>"
        . "<td class='text-end'>" . ((float) $r['value'] ? Menu::e(Conversa::money((float) $r['value'])) : '—') . "</td>"
        . "<td>" . ($d['n'] ? "<span class='crm-chip'><i class='ti ti-paperclip'></i> {$d['n']}</span>" : '<span class="text-secondary">—</span>')
        . ($missing ? " <span class='crm-chip crm-chip-red' title='Cliente público sem edital'><i class='ti ti-alert-triangle'></i> Edital</span>" : '') . "</td>"
        . "<td>" . Menu::e(Conversa::attendantName((int) $r['users_id_attendant'])) . "</td>"
        . "<td class='text-end text-secondary small' title='" . Menu::e(Html::convDateTime($r['date_creation'])) . "'>" . Menu::e(Conversa::ago($r['date_creation'])) . "</td></tr>";
}
if (!$rows) {
    echo "<tr><td colspan='9' class='text-center text-secondary py-4'>Nenhuma entrada com esses filtros.</td></tr>";
}
echo "</tbody></table></div></div>";
Html::footer();
