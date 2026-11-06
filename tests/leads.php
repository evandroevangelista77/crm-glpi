<?php
/**
 * Testa o envio de leads da Gestão de Eventos para o CRM (precisa dos dois plugins e dos leads de demonstração).
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/leads.php
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi'; chdir($root); require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production'); $kernel->boot(); global $DB, $CFG_GLPI;
$u = new User(); $u->getFromDBbyName('glpi'); $a = new Auth(); $a->auth_succeded = true; $a->user = $u; Session::init($a);
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
use GlpiPlugin\Gestaoeventos\{Evento, Leads, Registro};
$ok = fn ($l, $v) => print(($v ? 'OK   ' : 'FALHA') . " $l\n");
$ev = new Evento(); $ev->getFromDB(7);
$ok('CRM disponível', Leads::crmAvailable());
$before = countElementsInTable('glpi_plugin_crm_leads', ['event_id' => 7]);
echo Leads::sendToCrm($ev), "\n";
$ok('3 leads chegaram ao CRM', countElementsInTable('glpi_plugin_crm_leads', ['event_id' => 7]) - $before === 3);
$ok('registros marcados como enviados', countElementsInTable('glpi_plugin_gestaoeventos_registros', ['plugin_gestaoeventos_eventos_id' => 7, 'kind' => 'lead_contato', 'status' => 'enviado_crm']) === 3);
echo Leads::sendToCrm($ev), "\n";
// conversão
$lead = $DB->request(['FROM' => 'glpi_plugin_crm_leads', 'WHERE' => ['event_id' => 7], 'LIMIT' => 1])->current();
$opp = GlpiPlugin\Crm\Lead::convert((int) $lead['id']);
$ok("lead convertido na oportunidade $opp", $opp > 0 && $DB->request(['FROM'=>'glpi_plugin_crm_leads','WHERE'=>['id'=>$lead['id'],'status'=>'convertido']])->count() === 1);
$ok('oportunidade com canal Presencial / evento', $DB->request(['FROM'=>'glpi_plugin_crm_conversas','WHERE'=>['id'=>$opp,'source'=>'presencial']])->count() === 1);
// limpeza: volta o evento 7 ao estado "aguardando envio"
(new GlpiPlugin\Crm\Conversa())->delete(['id' => $opp], true);
$DB->delete('glpi_plugin_crm_leads', ['event_id' => 7]);
$DB->update('glpi_plugin_gestaoeventos_registros', ['status' => 'novo', 'extra' => null], ['plugin_gestaoeventos_eventos_id' => 7, 'kind' => 'lead_contato']);
$DB->delete('glpi_plugin_crm_alertas', ['type' => 'nova_conversa', 'plugin_crm_conversas_id' => 0]);
