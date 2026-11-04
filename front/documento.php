<?php

/**
 * Documentos da oportunidade: baixar (GET), anexar e remover (POST).
 */

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Documento;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    $conversaId = (int) ($_POST['id'] ?? 0);
    $back = Menu::url('/front/oportunidade.php?id=' . $conversaId . '#documentos');
    try {
        $conversa = Conversa::load($conversaId, true);
        if (($_POST['action'] ?? '') === 'delete') {
            $doc = $DB->request(['FROM' => Documento::getTable(), 'WHERE' => ['id' => (int) ($_POST['doc'] ?? 0), 'plugin_crm_conversas_id' => $conversaId]])->current();
            if ($doc) {
                Documento::remove((int) $doc['id']);
                Session::addMessageAfterRedirect('Documento removido.', false, INFO);
            }
        } else {
            $n = Documento::upload($conversaId, (array) ($_FILES['docs'] ?? []), (array) ($_POST['doc_type'] ?? []));
            Session::addMessageAfterRedirect($n ? "$n documento(s) anexado(s)." : 'Escolha pelo menos um arquivo.', false, $n ? INFO : WARNING);
        }
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
    Html::redirect($back);
}

// download: confere que o usuário pode ver a oportunidade dona do arquivo
$doc = $DB->request(['FROM' => Documento::getTable(), 'WHERE' => ['id' => (int) ($_GET['id'] ?? 0)]])->current();
if (!$doc) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Conversa::load((int) $doc['plugin_crm_conversas_id']);
$file = Documento::path($doc);
if (!is_file($file)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$ext = strtolower(pathinfo((string) $doc['stored'], PATHINFO_EXTENSION));
$inline = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . ($inline[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . (isset($inline[$ext]) && isset($_GET['ver']) ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode((string) $doc['name']));
readfile($file);
exit;
