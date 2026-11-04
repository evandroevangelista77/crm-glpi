<?php

use GlpiPlugin\Crm\Dashboard;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Dashboard::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Menu::header(Dashboard::getTypeName(), 'dashboard');
Dashboard::show();
echo Menu::script('drawer.js') . Menu::script('detail.js') . Menu::script('dashboard.js');
Html::footer();
