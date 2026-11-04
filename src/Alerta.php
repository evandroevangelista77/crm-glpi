<?php

namespace GlpiPlugin\Crm;

use Session;

/**
 * Eventos do CRM que viram alerta (som e pop-up) para a equipe. Cada ação
 * relevante grava um evento; o navegador consulta os novos a cada poucos
 * segundos (front/alertas.php) e as regras de Configurações decidem o que
 * toca, aparece ou fica em silêncio.
 */
class Alerta extends Base
{
    /** Eventos: rótulo, grupo, ícone, cor e o toque padrão. */
    public const EVENTS = [
        'nova_conversa' => ['label' => 'Novo lead', 'group' => 'Pré-vendas', 'icon' => 'ti-user-plus', 'color' => '#0ea5e9', 'tone' => 'ping'],
        'prevenda'      => ['label' => 'Lead avançou na pré-venda', 'group' => 'Pré-vendas', 'icon' => 'ti-filter', 'color' => '#64748b', 'tone' => 'soft'],
        'negociacao'    => ['label' => 'Entrou em negociação', 'group' => 'Negociações', 'icon' => 'ti-handshake', 'color' => '#f59e0b', 'tone' => 'double'],
        'fechamento'    => ['label' => 'Aviso de fechamento', 'group' => 'Vendas', 'icon' => 'ti-bell-ringing', 'color' => '#22c55e', 'tone' => 'cash'],
        'posvenda'      => ['label' => 'Cliente em pós-venda', 'group' => 'Vendas', 'icon' => 'ti-heart-handshake', 'color' => '#14b8a6', 'tone' => 'ping'],
        'venda'         => ['label' => 'Venda fechada', 'group' => 'Vendas', 'icon' => 'ti-trophy', 'color' => '#22c55e', 'tone' => 'cash'],
        'perda'         => ['label' => 'Oportunidade perdida', 'group' => 'Vendas', 'icon' => 'ti-circle-x', 'color' => '#ef4444', 'tone' => 'low'],
        'mensagem'      => ['label' => 'Nova mensagem do cliente', 'group' => 'Atendimento', 'icon' => 'ti-message-2', 'color' => '#3b82f6', 'tone' => 'soft'],
        'atribuida'     => ['label' => 'Conversa atribuída a você', 'group' => 'Atendimento', 'icon' => 'ti-user-check', 'color' => '#6366f1', 'tone' => 'ping'],
        'parada'        => ['label' => 'Conversa sem resposta (SLA)', 'group' => 'Atendimento', 'icon' => 'ti-alarm', 'color' => '#ef4444', 'tone' => 'alarm'],
    ];

    /** Toques disponíveis (sintetizados no navegador, sem arquivos de áudio). */
    public const TONES = [
        'ping'   => 'Ping curto',
        'soft'   => 'Suave',
        'double' => 'Duplo',
        'cash'   => 'Caixa registradora (venda)',
        'low'    => 'Grave (perda)',
        'alarm'  => 'Alarme (SLA)',
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Alertas' : 'Alerta';
    }

    /** Grava um evento. O autor é o usuário logado (vazio no webhook). */
    public static function record(string $type, int $conversaId, string $title, string $body = '', int $etapaId = 0): void
    {
        global $DB;
        $DB->insert(self::getTable(), [
            'type'                    => $type,
            'plugin_crm_conversas_id' => $conversaId,
            'plugin_crm_etapas_id'    => $etapaId,
            'users_id'                => (int) Session::getLoginUserID(),
            'title'                   => mb_substr($title, 0, 255),
            'body'                    => mb_substr($body, 0, 255),
            'date'                    => date('Y-m-d H:i:s'),
        ]);
    }

    private static function lastId(): int
    {
        global $DB;
        $row = $DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('MAX(`id`) AS `m`')], 'FROM' => self::getTable()])->current();
        return (int) ($row['m'] ?? 0);
    }

    /**
     * Eventos novos para o usuário logado (depois de $since), já filtrados pelas
     * regras e pelas preferências. Com $since = 0 só devolve o ponto de partida.
     */
    public static function feed(int $since): array
    {
        global $DB;
        $me = (int) Session::getLoginUserID();
        $prefs = Preferencia::forUser($me);
        $out = ['ok' => true, 'last' => self::lastId(), 'events' => [], 'popup_seconds' => Settings::popupSeconds(), 'volume' => $prefs['volume']];
        if ($since <= 0) {
            return $out;
        }
        self::checkIdle();

        $rules = Settings::alertRules();
        $c = Conversa::getTable();
        $rows = $DB->request([
            'SELECT'     => ['a.*', 'c.users_id_attendant'],
            'FROM'       => self::getTable() . ' AS a',
            'INNER JOIN' => [$c . ' AS c' => ['ON' => ['c' => 'id', 'a' => 'plugin_crm_conversas_id']]],
            'WHERE'      => ['a.id' => ['>', $since]] + Conversa::scope('c'),
            'ORDER'      => 'a.id',
            'LIMIT'      => 25,
        ]);
        foreach ($rows as $r) {
            $out['last'] = max($since, (int) $r['id']);
            $type = $r['type'];
            $rule = $rules[$type] ?? null;
            $event = self::EVENTS[$type] ?? null;
            if (!$rule || !$event || !$rule['active']) {
                continue;
            }
            if ((int) $r['users_id'] === $me && $type !== 'parada') {
                continue; // quem fez a ação não precisa ser avisado
            }
            if ($type === 'atribuida' && (int) $r['users_id_attendant'] !== $me) {
                continue;
            }
            $out['events'][] = [
                'id'    => (int) $r['id'],
                'type'  => $type,
                'label' => $event['label'],
                'icon'  => $event['icon'],
                'color' => $event['color'],
                'title' => $r['title'],
                'body'  => $r['body'],
                'url'   => Menu::url('/front/conversas.php?id=' . (int) $r['plugin_crm_conversas_id']),
                'tone'  => ($rule['sound'] && $prefs['sound']) ? $rule['tone'] : '',
                'popup' => (bool) ($rule['popup'] && $prefs['popup']),
            ];
        }
        return $out;
    }

    /**
     * Gera o alerta de SLA: conversa aberta cuja última mensagem é do cliente e
     * está sem resposta há mais minutos que o limite. Roda no máximo 1 vez por minuto.
     */
    private static function checkIdle(): void
    {
        global $DB;
        $sla = Settings::slaMinutes();
        if ($sla <= 0 || time() - (int) Settings::get('idle_checked_at', '0') < 60) {
            return;
        }
        Settings::set(['idle_checked_at' => (string) time()]);
        $cutoff = date('Y-m-d H:i:s', time() - $sla * 60);
        $candidates = $DB->request(['FROM' => Conversa::getTable(), 'WHERE' => ['status' => Conversa::OPEN, 'last_message_at' => ['<', $cutoff]], 'LIMIT' => 50]);
        foreach ($candidates as $conv) {
            $last = Conversa::lastMessageRow((int) $conv['id']);
            if (!$last || $last['direction'] !== 'in') {
                continue;
            }
            $already = countElementsInTable(self::getTable(), ['type' => 'parada', 'plugin_crm_conversas_id' => $conv['id'], 'date' => ['>=', $conv['last_message_at']]]);
            if (!$already) {
                $minutes = (int) round((time() - strtotime($conv['last_message_at'])) / 60);
                self::record('parada', (int) $conv['id'], Conversa::displayName($conv), "Sem resposta há $minutes min", (int) $conv['plugin_crm_etapas_id']);
            }
        }
    }
}
