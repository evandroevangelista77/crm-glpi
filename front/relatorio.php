<?php

/**
 * Relatórios do CRM para download:
 *   ?tipo=executivo&dias=7|30|90   relatório executivo do funil (PDF)
 *   ?tipo=negociacao&id=N          relatório de toda a negociação de uma oportunidade (PDF)
 *   ?tipo=csv                      o funil em planilha (CSV)
 */

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Relatorio;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

switch ($_GET['tipo'] ?? 'executivo') {
    case 'negociacao':
        Relatorio::negociacao(Conversa::load((int) ($_GET['id'] ?? 0)));
        break;
    case 'csv':
        Relatorio::csv();
        break;
    default:
        Relatorio::executivo((int) ($_GET['dias'] ?? 30));
}
