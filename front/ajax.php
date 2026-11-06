<?php

/**
 * API do CRM para o funil, a caixa de conversas e a ficha da oportunidade (JSON).
 */

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Lead;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();

$reply = function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
};

try {
    if (!Conversa::canView()) {
        throw new RuntimeException('Sem permissão.', 403);
    }
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $in = $post ? (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []) : $_GET;
    $id = (int) ($in['id'] ?? 0);

    switch ($in['action'] ?? '') {
        case 'lead_status':
            Lead::setStatus($id, (string) ($in['status'] ?? ''));
            $reply(['ok' => true]);
            return;
        case 'lead_convert':
            if (!Conversa::canCreate()) {
                throw new RuntimeException('Sem permissão para criar oportunidades.', 403);
            }
            $opp = Lead::convert($id);
            $reply(['ok' => true, 'url' => Menu::page('/front/oportunidade.php?id=' . $opp)]);
            return;
        case 'lead_delete':
            global $DB;
            $DB->delete(Lead::getTable(), ['id' => $id]);
            $reply(['ok' => true]);
            return;
        case 'board':
            $reply(Conversa::board());
            return;
        case 'messages':
            Conversa::load($id);
            $reply(['ok' => true, 'messages' => Conversa::messages($id, (int) ($in['after'] ?? 0))]);
            return;
    }
    if (!$post) {
        throw new RuntimeException('Use POST.', 405);
    }
    $cycle = null;
    $paused = null;
    $closing = null; // aviso de fechamento: o navegador pergunta se deve iniciar o ciclo financeiro
    switch ($in['action'] ?? '') {
        case 'move':
            $conv = Conversa::load($id, true);
            $closing = $conv->moveTo((int) ($in['etapa'] ?? 0));
            $cycle = $conv->autoCycle;
            $paused = $conv->cyclePaused;
            break;
        case 'assign':
            Conversa::load($id, true)->assign((int) ($in['user'] ?? 0));
            break;
        case 'value':
            Conversa::load($id, true)->setValue(Conversa::parseMoney((string) ($in['value'] ?? 0)));
            break;
        case 'won':
            $conv = Conversa::load($id, true);
            $closing = $conv->close(Conversa::WON);
            $cycle = $conv->autoCycle;
            break;
        case 'lost':
            $reason = trim((string) ($in['reason'] ?? ''));
            if ($reason === '') {
                throw new RuntimeException('Informe o motivo da perda.', 422);
            }
            Conversa::load($id, true)->close(Conversa::LOST, $reason);
            break;
        case 'reopen':
            Conversa::load($id, true)->close(Conversa::OPEN);
            break;
        case 'send':
            $result = Conversa::load($id, true)->send((string) ($in['text'] ?? ''));
            $reply(['ok' => true] + $result);
            return;
        case 'cycle':
            $cycle = Conversa::load($id, true)->startCycle(
                (string) ($in['sale_type'] ?? 'hw_servico'),
                Conversa::parseMoney((string) ($in['amount'] ?? 0)),
                trim((string) ($in['name'] ?? ''))
            );
            $reply(['ok' => true, 'cycle' => $cycle]);
            return;
        default:
            throw new RuntimeException('Ação desconhecida.', 400);
    }
    $reply(['ok' => true, 'closing' => $closing, 'cycle' => $cycle, 'paused' => $paused]);
} catch (Throwable $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500;
    $reply(['ok' => false, 'error' => $e->getMessage()], $code);
}
