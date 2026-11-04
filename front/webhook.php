<?php

/**
 * Webhook do gateway de WhatsApp. Recebe mensagens do cliente e grava na
 * conversa (cria a conversa se o número for novo).
 *
 * POST JSON: {"numero": "5511...", "nome": "...", "mensagem": "...", "wa_message_id": "...", "origem": "whatsapp", "valor": 0}
 * Cabeçalho X-Signature: HMAC-SHA256 do corpo com o segredo do plugin (hex).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Crm\Alerta;
use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Etapa;
use GlpiPlugin\Crm\Mensagem;
use GlpiPlugin\Crm\Settings;

header('Content-Type: application/json');

function crm_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crm_reply(405, ['erro' => 'use POST']);
}

$raw = file_get_contents('php://input');
$secret = Settings::get('webhook_secret');
$signature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
if ($secret === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
    crm_reply(401, ['erro' => 'assinatura inválida']);
}

$data = json_decode($raw, true);
$numero = preg_replace('/\D+/', '', (string) ($data['numero'] ?? ''));
$texto = trim((string) ($data['mensagem'] ?? ''));
$waId = trim((string) ($data['wa_message_id'] ?? ''));
if ($numero === '' || $texto === '' || $waId === '') {
    crm_reply(422, ['erro' => 'numero, mensagem e wa_message_id são obrigatórios']);
}

// mesma mensagem reenviada pelo gateway: não grava duas vezes
if (countElementsInTable(Mensagem::getTable(), ['wa_message_id' => $waId])) {
    crm_reply(200, ['status' => 'duplicada']);
}

$now = date('Y-m-d H:i:s');
$conversa = new Conversa();
if (!$conversa->getFromDBByCrit(['numero_wa' => $numero, 'status' => Conversa::OPEN])) {
    $nome = trim((string) ($data['nome'] ?? '')) ?: $numero;
    $tipo = isset(Conversa::CLIENT_TYPES[$data['tipo_cliente'] ?? '']) ? $data['tipo_cliente'] : 'privado';
    $etapa = array_key_first(Etapa::forClient($tipo)) ?: Etapa::first();
    $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
    $id = (int) $conversa->add([
        'name'                 => $nome,
        'company'              => mb_substr(trim((string) ($data['empresa'] ?? '')), 0, 255),
        'email'                => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
        'client_type'          => $tipo,
        'numero_wa'            => $numero,
        'plugin_crm_etapas_id' => $etapa,
        'users_id_attendant'   => 0,
        'status'               => Conversa::OPEN,
        'value'                => max(0, (float) ($data['valor'] ?? 0)),
        'source'               => isset(Conversa::SOURCES[$data['origem'] ?? '']) ? $data['origem'] : 'whatsapp',
        'last_message_at'      => $now,
    ]);
    $conversa->getFromDB($id);
    GlpiPlugin\Crm\Historico::record($id, 0, (int) $etapa);
    Alerta::record('nova_conversa', $id, Conversa::title($conversa->fields), mb_strimwidth($texto, 0, 120, '…'), (int) $etapa);
} else {
    $id = (int) $conversa->fields['id'];
    $conversa->update(['id' => $id, 'last_message_at' => $now]);
    Alerta::record('mensagem', $id, Conversa::title($conversa->fields), mb_strimwidth($texto, 0, 120, '…'), (int) $conversa->fields['plugin_crm_etapas_id']);
}

(new Mensagem())->add([
    'plugin_crm_conversas_id' => $id,
    'direction'               => 'in',
    'body'                    => $texto,
    'wa_message_id'           => $waId,
    'date'                    => $now,
]);

crm_reply(200, ['status' => 'ok', 'conversa' => $id]);
