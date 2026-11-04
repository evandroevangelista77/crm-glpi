<?php
/**
 * Teste funcional do plugin CRM (histórico, atas, PDFs, CSV, dashboard e páginas), pela linha de comando.
 * Precisa do plugin instalado e dos dados de demonstração carregados (veja demo/LEIA-ME.md).
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/smoke.php
 *
 * Funciona no GLPI 11 e no 12. Cria e remove registros de teste; não altera os dados de demonstração.
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB, $CFG_GLPI;
$user = new User(); $user->getFromDBbyName('glpi');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth);
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
use GlpiPlugin\Crm\{Ata, Conversa, Dashboard, Documento, Etapa, Historico, Menu, Relatorio};
$ok = function ($label, $v) { echo ($v ? 'OK   ' : 'FALHA') . " $label\n"; return $v; };
$throws = function (callable $f) { try { $f(); return null; } catch (Throwable $e) { return $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); } };
$find = fn (string $company) => (int) $DB->request(['SELECT' => ['id'], 'FROM' => Conversa::getTable(), 'WHERE' => ['company' => $company]])->current()['id'];

// ---- histórico e tempo por etapa
$won = $find('Faculdade Horizonte');
$c = Conversa::load($won);
$seg = Historico::segments($c->fields);
$ok('histórico da venda: ' . count($seg) . ' etapas, ' . Relatorio::dur(array_sum(array_column($seg, 'seconds'))) . ' no total', count($seg) >= 5);
$nd = Relatorio::negotiationData($c);
$ok("dados da negociação: {$nd['in']}+{$nd['out']} msgs, " . count($nd['atas']) . ' atas, ' . count($nd['docs']) . " docs, {$nd['contactDays']} dias com contato", count($nd['atas']) === 2 && count($nd['docs']) >= 2);

// ---- atas: criar com arquivo, listar, remover
$open = $find('Prefeitura Municipal de Itu');
$tmp = tempnam(sys_get_temp_dir(), 'ata'); file_put_contents($tmp, "ata teste");
$files = ['name' => ['ata-reuniao.pdf'], 'tmp_name' => [$tmp], 'error' => [0], 'size' => [filesize($tmp)]];
$msg = $throws(fn () => Ata::create($open, ['title' => '', 'meeting_date' => '2026-11-03T10:00'], []));
$ok("ata sem título é recusada ($msg)", (bool) $msg);
$msg = $throws(fn () => Ata::create($open, ['title' => 'X', 'meeting_date' => '2026-11-03T10:00'], []));
$ok("ata sem resumo nem arquivo é recusada ($msg)", (bool) $msg);
$ataId = Ata::create($open, ['title' => 'Reunião de teste', 'meeting_date' => '2026-11-03T10:00', 'kind' => 'video', 'participants' => 'A; B', 'summary' => 'Resumo', 'decisions' => 'Próximo passo', 'duration_min' => 30], $files);
$row = $DB->request(['FROM' => Ata::getTable(), 'WHERE' => ['id' => $ataId]])->current();
$ok('ata criada com arquivo anexado (documento ' . $row['plugin_crm_documentos_id'] . ')', $ataId > 0 && (int) $row['plugin_crm_documentos_id'] > 0);
$ok('anexo da ata é do tipo "ata"', $DB->request(['FROM' => Documento::getTable(), 'WHERE' => ['id' => $row['plugin_crm_documentos_id'], 'doc_type' => 'ata']])->count() === 1);
Ata::remove($ataId, $open);
$ok('ata removida junto com o arquivo', !countElementsInTable(Ata::getTable(), ['id' => $ataId]) && !countElementsInTable(Documento::getTable(), ['id' => $row['plugin_crm_documentos_id']]));

// ---- histórico nas mudanças de etapa
$conv = Conversa::load($find('Clínica Vida Saudável'), true);
$before = count(Historico::forConversa($conv->getID()));
$conv->moveTo(array_key_first(array_filter(Etapa::all(), fn ($e) => $e['name'] === 'Qualificação')));
$ok('mudar de etapa grava no histórico', count(Historico::forConversa($conv->getID())) === $before + 1);
$conv->getFromDB($conv->getID());
$conv->moveTo(array_key_first(array_filter(Etapa::all(), fn ($e) => $e['name'] === 'Entrada')));
$DB->delete(Historico::getTable(), ['id' => $DB->request(['SELECT' => [new Glpi\DBAL\QueryExpression('MAX(`id`) AS `m`')], 'FROM' => Historico::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $conv->getID()]])->current()['m']]);
$DB->delete(Historico::getTable(), ['id' => $DB->request(['SELECT' => [new Glpi\DBAL\QueryExpression('MAX(`id`) AS `m`')], 'FROM' => Historico::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $conv->getID()]])->current()['m']]);
$DB->delete(GlpiPlugin\Crm\Alerta::getTable(), ['plugin_crm_conversas_id' => $conv->getID(), 'type' => ['prevenda']]);

// ---- PDFs
Relatorio::$capture = true;
$save = function (string $name) { $d = sys_get_temp_dir() . '/crm-pdfs'; @mkdir($d); file_put_contents("$d/$name", Relatorio::$captured); return strlen((string) Relatorio::$captured); };
foreach (['venda' => $won, 'aberta-publica' => $open, 'perdida' => $find('Prefeitura de Jundiaí'), 'fechamento' => $find('Imobiliária Lar Doce Lar')] as $name => $id) {
    Relatorio::$captured = null;
    $err = $throws(fn () => Relatorio::negociacao(Conversa::load($id)));
    $n = $save("negociacao-$name.pdf");
    $ok("PDF da negociação ($name): $n bytes" . ($err ? " — $err" : ''), !$err && $n > 4000 && str_starts_with((string) Relatorio::$captured, '%PDF'));
}
foreach ([7, 30, 90] as $dias) {
    Relatorio::$captured = null;
    $err = $throws(fn () => Relatorio::executivo($dias));
    $n = $save("executivo-$dias.pdf");
    $ok("PDF executivo ($dias dias): $n bytes" . ($err ? " — $err" : ''), !$err && $n > 6000 && str_starts_with((string) Relatorio::$captured, '%PDF'));
}
ob_start(); Relatorio::csv(); $csv = ob_get_clean();
$ok('CSV do funil: ' . substr_count($csv, "\n") . ' linhas', substr_count($csv, "\n") >= 20 && str_contains($csv, 'Código;Empresa'));

// ---- dashboard (dados da gaveta e pop-up)
ob_start(); Dashboard::show(); $html = ob_get_clean();
preg_match('/<script type=.application\/json. id=.crm-dash.>(.*?)<\/script>/s', $html, $m);
$boot = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
$ok('dashboard: JSON da gaveta válido (' . count($boot['items'] ?? []) . ' itens, ' . count($boot['lists'] ?? []) . ' listas)', is_array($boot) && count($boot['items']) > 10 && isset($boot['lists']['pipeline']));
$ok('oportunidades quentes: ' . count($boot['hot'] ?? []) . ' (' . implode(', ', array_map(fn ($id) => $boot['items'][$id]['title'] . ' ' . $boot['items'][$id]['prob'] . '%', $boot['hot'] ?? [])) . ')', count($boot['hot'] ?? []) === 5);
foreach (['crm-fn-row', 'crm-daycol', 'oak-kpi-click', 'crm-slice', 'crm-chrow', 'crm-hotbtn', 'crm-spark', 'Relatório executivo'] as $s) { $ok("dashboard contém '$s'", str_contains($html, $s)); }

// ---- páginas
$pages = ['dashboard' => ['dashboard.php', []], 'entradas' => ['entradas.php', []], 'oportunidade' => ['oportunidade.php', ['id' => $open]], 'conversas' => ['conversas.php', ['id' => 1]], 'config' => ['config.php', ['tab' => 'etapas']]];
foreach ($pages as $name => [$file, $get]) {
    $_GET = $get; $_REQUEST = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try { (function () use ($file, $root) { $code = str_replace('Session::checkLoginUser();', '', file_get_contents($root . '/plugins/crm/front/' . $file)); eval('?>' . $code); })(); } catch (Throwable $e) { echo 'EXCEÇÃO: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); }
    $out = ob_get_clean();
    $err = preg_match('/EXCEÇÃO.*/', $out, $mm) ? $mm[0] : '';
    $extra = $name === 'oportunidade' ? (str_contains($out, 'Atas de reunião') && str_contains($out, 'Relatório da negociação') ? '' : ' (sem atas/relatório)') : '';
    $ok("página $name renderiza (" . strlen($out) . " bytes)" . $extra . ($err ? " — $err" : ''), strlen($out) > 3000 && $err === '' && $extra === '');
}
echo "fim (PDFs de teste em " . sys_get_temp_dir() . "/crm-pdfs)\n";
