<?php

namespace GlpiPlugin\Crm;

use Session;

/**
 * Histórico de etapas da oportunidade: cada mudança de etapa (e a entrada) fica registrada
 * com data e autor, para medir quanto tempo o comercial ficou em cada fase e com o cliente.
 */
class Historico extends Base
{
    public static function getTypeName($nb = 0)
    {
        return 'Histórico de etapas';
    }

    /** Registra a entrada numa etapa ($from = 0 na entrada da oportunidade). */
    public static function record(int $conversaId, int $from, int $to, ?string $date = null): void
    {
        global $DB;
        $DB->insert(self::getTable(), [
            'plugin_crm_conversas_id' => $conversaId,
            'from_etapas_id'          => $from,
            'plugin_crm_etapas_id'    => $to,
            'users_id'                => (int) Session::getLoginUserID(),
            'date'                    => $date ?? date('Y-m-d H:i:s'),
        ]);
    }

    public static function forConversa(int $conversaId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $conversaId], 'ORDER' => ['date ASC', 'id ASC']]), false);
    }

    /**
     * Permanência em cada etapa: [['etapa' => id, 'start' => ts, 'end' => ts, 'seconds' => n]].
     * A última vai até o fechamento (ou até agora, se a oportunidade ainda está aberta).
     */
    public static function segments(array $conversa): array
    {
        $rows = self::forConversa((int) $conversa['id']);
        $created = strtotime((string) $conversa['date_creation']) ?: time();
        if (!$rows) {
            $rows = [['plugin_crm_etapas_id' => (int) $conversa['plugin_crm_etapas_id'], 'date' => date('Y-m-d H:i:s', $created)]];
        }
        $end = $conversa['date_closed'] ? (int) strtotime((string) $conversa['date_closed']) : time();
        $out = [];
        foreach ($rows as $i => $r) {
            $start = (int) strtotime((string) $r['date']);
            $next = isset($rows[$i + 1]) ? (int) strtotime((string) $rows[$i + 1]['date']) : $end;
            $out[] = ['etapa' => (int) $r['plugin_crm_etapas_id'], 'start' => $start, 'end' => max($start, $next), 'seconds' => max(0, $next - $start)];
        }
        return $out;
    }
}
