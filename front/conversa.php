<?php

/**
 * Endereço antigo da conversa: leva para a caixa de conversas.
 */

use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
Html::redirect(Menu::url('/front/conversas.php?id=' . (int) ($_GET['id'] ?? 0)));
