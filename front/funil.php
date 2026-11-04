<?php

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Menu::header('Funil de vendas', 'funil');
$boot = ['ajax' => Menu::url('/front/ajax.php'), 'entry' => Menu::url('/front/entrada.php')];
$report = fn (string $q): string => Menu::e(Menu::page('/front/relatorio.php?' . $q));
$actions = "<div class='dropdown'><button type='button' class='btn btn-outline-primary dropdown-toggle' data-bs-toggle='dropdown'><i class='ti ti-file-type-pdf me-1'></i>Relatório executivo</button>"
    . "<div class='dropdown-menu dropdown-menu-end'><h6 class='dropdown-header'>Relatório executivo (PDF)</h6>"
    . "<a class='dropdown-item' href='" . $report('tipo=executivo&dias=7') . "'><i class='ti ti-calendar-week me-2'></i>Últimos 7 dias</a>"
    . "<a class='dropdown-item' href='" . $report('tipo=executivo&dias=30') . "'><i class='ti ti-calendar-month me-2'></i>Últimos 30 dias</a>"
    . "<a class='dropdown-item' href='" . $report('tipo=executivo&dias=90') . "'><i class='ti ti-calendar-stats me-2'></i>Últimos 90 dias</a>"
    . "<div class='dropdown-divider'></div><a class='dropdown-item' href='" . $report('tipo=csv') . "'><i class='ti ti-table-export me-2'></i>Exportar o funil (CSV)</a></div></div>"
    . Menu::button('Entradas', Menu::page('/front/entradas.php'), 'ti-list-details', 'btn-outline-secondary');
echo "<div class='crm'>" . Menu::title('Funil de vendas', 'funil', $actions)
    . "<div id='crm-funil' class='crm-loading'><span class='spinner-border spinner-border-sm me-2'></span>Carregando o funil…</div></div>";
echo "<script type='application/json' id='crm-boot'>" . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
echo Menu::script('drawer.js') . Menu::script('detail.js') . Menu::script('funil.js');
Html::footer();
