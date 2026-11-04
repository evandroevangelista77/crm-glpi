<?php

/**
 * Eventos novos para os alertas (som e pop-up) do usuário logado. O navegador
 * chama este endereço a cada poucos segundos (public/js/alertas.js).
 */

use GlpiPlugin\Crm\Alerta;
use GlpiPlugin\Crm\Conversa;

Session::checkLoginUser();
header('Content-Type: application/json; charset=UTF-8');
if (!Conversa::canView()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    return;
}
echo json_encode(Alerta::feed((int) ($_GET['since'] ?? 0)), JSON_UNESCAPED_UNICODE);
