<?php
/**
 * Testa o ciclo financeiro aberto sozinho quando a venda é concretizada (GLPI 12 com o plugin Ciclo ativo).
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/ciclo_auto.php
 * Cria uma oportunidade de teste, ganha, confere o ciclo e os documentos e remove tudo.
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;
$user = new User(); $user->getFromDBbyName('glpi');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth);
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
use GlpiPlugin\Crm\{Conversa, Documento, Etapa};
$throws = function (callable $f) { try { $f(); return null; } catch (Throwable $e) { return $e->getMessage(); } };
$ok = function ($label, $v) { echo ($v ? 'OK   ' : 'FALHA') . " $label\n"; return $v; };
if (!Conversa::cicloAvailable()) { echo "Plugin Ciclo indisponível aqui.\n"; exit(2); }

$maxTicket = (int) $DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => 'glpi_tickets'])->current()['m'];
$files = []; $types = [];
foreach ([['edital-teste.pdf', 'edital'], ['proposta-teste.pdf', 'orcamento'], ['contrato-teste.pdf', 'contrato']] as [$n, $t]) {
    $tmp = tempnam(sys_get_temp_dir(), 'crm'); file_put_contents($tmp, "%PDF-1.4 $n");
    $files['name'][] = $n; $files['tmp_name'][] = $tmp; $files['error'][] = 0; $files['size'][] = filesize($tmp); $types[] = $t;
}
$id = Conversa::createEntry(['client_type' => 'publico', 'source' => 'site', 'name' => 'Teste Auto', 'company' => 'Hospital Teste Ciclo', 'email' => 'teste@example.com', 'subject' => 'Licitação de teste', 'value' => '12345.67', 'edital_number' => 'PE 99/2026'], $files, $types);
$c = Conversa::load($id, true);
$ok('oportunidade criada com 3 documentos', count(Documento::forConversa($id)) === 3);
$c->moveTo(4);
$av = $c->autoCycle;
$ok('mover para Fechamento abre o ciclo (modo aviso): ' . ($av['code'] ?? '-'), !empty($av['id']) && $av['mode'] === 'aviso' && $av['docs'] === 3);
$cid = (int) $av['id'];
$st = fn () => (int) $DB->request(['FROM' => 'glpi_plugin_ciclo_ciclos', 'WHERE' => ['id' => $cid]])->current()['status'];
$phase = fn () => $DB->request(['FROM' => 'glpi_plugin_ciclo_fases', 'WHERE' => ['plugin_ciclo_ciclos_id' => $cid, 'status' => 'active']])->count();
$ok('ciclo em andamento com 1 fase ativa', $st() === 1 && $phase() === 1);
$c->moveTo(3);
$ok('voltar para Negociação interrompe o ciclo (status 4, nenhuma fase ativa)', $st() === 4 && $phase() === 0 && !empty($c->cyclePaused));
$ok('ciclo interrompido recusa ação da fase', (bool) $throws(function () use ($cid) { $cy = new GlpiPlugin\Ciclo\Ciclo(); $cy->getFromDB($cid); GlpiPlugin\Ciclo\Fase::workflow($cy, 'conclude', []); }));
$c->moveTo(4);
$ok('seguir para Fechamento libera o ciclo (modo retomado, 1 fase ativa)', $st() === 1 && $phase() === 1 && ($c->autoCycle['mode'] ?? '') === 'retomado');
// devolver ao Aviso de Fechamento pelo Ciclo manda a oportunidade de volta para a Negociação
$cyc = new GlpiPlugin\Ciclo\Ciclo(); $cyc->getFromDB($cid);
$msg = GlpiPlugin\Ciclo\Fase::workflow($cyc, 'return', ['comment' => 'Documentação incompleta', 'target' => 'aviso']);
$c->getFromDB($id);
$etName = Etapa::all()[(int) $c->fields['plugin_crm_etapas_id']]['name'] ?? '';
$ok("devolver ao Aviso de Fechamento: oportunidade na coluna '$etName' e ciclo interrompido", $etName === 'Renegociação e ajustes' && $st() === 4 && $phase() === 0);
$c->moveTo(4);
$ok('seguir de novo: aviso concluído sozinho e ciclo liberado na fase seguinte', $st() === 1 && $phase() === 1 && ($DB->request(['FROM' => 'glpi_plugin_ciclo_fases', 'WHERE' => ['plugin_ciclo_ciclos_id' => $cid, 'code' => 'aviso', 'status' => 'done']])->count() === 1));
$c->close(Conversa::WON);
$cy = $c->autoCycle;
$ok('ganhar depois reaproveita o ciclo (modo ganho)', ($cy['id'] ?? 0) === ($av['id'] ?? -1) && $cy['mode'] === 'ganho');
$ok('ciclo aberto automaticamente: ' . ($cy['code'] ?? '-'), !empty($cy['id']));
$c->getFromDB($id);
$ok('oportunidade guarda o ciclo', (int) $c->fields['ciclo_id'] === (int) ($cy['id'] ?? 0));
$ok('3 documentos levados ao ciclo (docs=' . ($cy['docs'] ?? '?') . ')', ($cy['docs'] ?? 0) === 3);
$n = countElementsInTable('glpi_documents_items', ['itemtype' => 'GlpiPlugin\\Ciclo\\Ciclo', 'items_id' => $cy['id']]);
$ok("Document_Item ligados ao ciclo ($n)", $n === 3);
$row = $DB->request(['FROM' => 'glpi_plugin_ciclo_ciclos', 'WHERE' => ['id' => $cy['id']]])->current();
$ok("valor e cliente preenchidos ({$row['amount']} / {$row['customer']})", (float) $row['amount'] === 12345.67 && $row['customer'] !== '');
$ok('comercial = atendente', (int) $row['users_id_commercial'] > 0);
echo "     crm: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
$d = json_decode((string) ($row['crm_data'] ?? ''), true) ?: [];
$ok('campos do CRM preenchidos (' . count($d) . ')', ($d['probability'] ?? '') === '100' && ($d['docs_attached'] ?? '') === 'sim' && ($d['chk_contract'] ?? '') === 'sim' && ($d['chk_commercial'] ?? '') === 'sim');
// segunda vez não duplica
$c2 = Conversa::load($id, true); $c2->close(Conversa::WON);
$ok('segunda chamada reaproveita o mesmo ciclo', ($c2->autoCycle['id'] ?? 0) === $cy['id']);

// limpeza
foreach ($DB->request(['FROM' => 'glpi_documents_items', 'WHERE' => ['itemtype' => 'GlpiPlugin\\Ciclo\\Ciclo', 'items_id' => $cy['id']]]) as $di) { (new Document())->delete(['id' => $di['documents_id']], true); }
$DB->delete('glpi_plugin_ciclo_documentos', ['plugin_ciclo_ciclos_id' => $cy['id']]);
(new GlpiPlugin\Ciclo\Ciclo())->delete(['id' => $cy['id']], true);
$DB->delete('glpi_tickets', ['id' => ['>', $maxTicket]]);
$DB->update(Conversa::getTable(), ['ciclo_id' => 0], ['id' => $id]);
(new Conversa())->delete(['id' => $id], true);
echo "limpeza feita\n";
