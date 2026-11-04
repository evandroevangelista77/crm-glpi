<?php

/**
 * Atas de reunião da oportunidade: registrar (com o arquivo da ata, se houver) e remover.
 */

use GlpiPlugin\Crm\Ata;
use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Session::checkCSRF($_POST);
$id = (int) ($_POST['id'] ?? 0);
try {
    Conversa::load($id, true);
    if (($_POST['action'] ?? '') === 'delete') {
        Ata::remove((int) ($_POST['ata'] ?? 0), $id);
        Session::addMessageAfterRedirect('Ata removida.', false, INFO);
    } else {
        Ata::create($id, $_POST, (array) ($_FILES['ata_file'] ?? []));
        Session::addMessageAfterRedirect('Ata registrada.', false, INFO);
    }
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
}
Html::redirect(Menu::url('/front/oportunidade.php?id=' . $id . '#atas'));
