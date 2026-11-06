<?php

namespace GlpiPlugin\Crm;

use RuntimeException;
use Session;

/**
 * Lead captado em um evento (plugin Gestão de Eventos). O evento envia os leads para cá; a equipe
 * comercial acompanha (novo, contatado, qualificado) e converte em oportunidade do funil.
 */
class Lead extends Base
{
    public const STATUSES = [
        'novo'        => ['Novo', '#3b82f6', 'ti-sparkles'],
        'contatado'   => ['Contatado', '#f59e0b', 'ti-phone-call'],
        'qualificado' => ['Qualificado', '#a855f7', 'ti-star'],
        'convertido'  => ['Convertido', '#22c55e', 'ti-circle-check'],
        'descartado'  => ['Descartado', '#94a3b8', 'ti-ban'],
    ];

    public const TEMPS = [
        'quente' => ['Quente', '#ef4444', 'ti-flame'],
        'morno'  => ['Morno', '#f59e0b', 'ti-sun'],
        'frio'   => ['Frio', '#0ea5e9', 'ti-snowflake'],
    ];

    public const INTERESTS = [
        'seguranca' => 'Segurança', 'cloud' => 'Cloud', 'infra' => 'Infraestrutura / Data Center', 'software' => 'Software e licenças',
        'servicos' => 'Serviços gerenciados', 'redes' => 'Redes e Wi-Fi', 'outro' => 'Outro',
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Leads' : 'Lead';
    }

    /** Há Gestão de Eventos instalada? Os leads vêm dela, mas a tela funciona sem. */
    public static function eventsAvailable(): bool
    {
        global $DB;
        return $DB->tableExists('glpi_plugin_gestaoeventos_eventos');
    }

    /**
     * Recebe um lead de um evento (chamado pela Gestão de Eventos). Não duplica: o mesmo e-mail (ou
     * nome + empresa) no mesmo evento só entra uma vez. Devolve [id, novo?].
     */
    public static function receive(array $in): array
    {
        global $DB;
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 255);
        if ($name === '') {
            throw new RuntimeException('Informe o nome do lead.', 422);
        }
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $eventId = (int) ($in['event_id'] ?? 0);
        $dup = $email !== '' ? ['email' => $email] : ['name' => $name, 'company' => (string) ($in['company'] ?? '')];
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['event_id' => $eventId] + $dup, 'LIMIT' => 1])->current();
        if ($row) {
            return [(int) $row['id'], false];
        }
        $interest = isset(self::INTERESTS[$in['interest'] ?? '']) ? $in['interest'] : 'outro';
        $temp = isset(self::TEMPS[$in['temperature'] ?? '']) ? $in['temperature'] : 'morno';
        $now = date('Y-m-d H:i:s');
        $DB->insert(self::getTable(), [
            'event_id' => $eventId, 'event_code' => mb_substr((string) ($in['event_code'] ?? ''), 0, 30), 'event_name' => mb_substr((string) ($in['event_name'] ?? ''), 0, 255),
            'event_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['event_date'] ?? '')) ? $in['event_date'] : null,
            'name' => $name, 'company' => mb_substr((string) ($in['company'] ?? ''), 0, 255), 'email' => $email, 'phone' => mb_substr(preg_replace('/[^\d+() -]/', '', (string) ($in['phone'] ?? '')), 0, 50),
            'job_title' => mb_substr((string) ($in['job_title'] ?? ''), 0, 150), 'interest' => $interest, 'temperature' => $temp, 'status' => 'novo',
            'notes' => mb_substr((string) ($in['notes'] ?? ''), 0, 2000), 'date_creation' => $in['date_creation'] ?? $now, 'date_mod' => $now,
        ]);
        $id = (int) $DB->insertId();
        if (!empty($in['_alert'])) {
            Alerta::record('nova_conversa', 0, $name . ($in['company'] ? ' · ' . $in['company'] : ''), 'Lead do evento ' . ($in['event_name'] ?? ''), 0);
        }
        return [$id, true];
    }

    public static function setStatus(int $id, string $status): void
    {
        global $DB;
        if (!isset(self::STATUSES[$status])) {
            throw new RuntimeException('Situação inválida.', 422);
        }
        $DB->update(self::getTable(), ['status' => $status, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** Vira oportunidade na esteira (canal "Presencial / evento"). Devolve o id da oportunidade. */
    public static function convert(int $id): int
    {
        global $DB;
        $l = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$l) {
            throw new RuntimeException('Lead não encontrado.', 404);
        }
        if ((int) $l['conversas_id'] && countElementsInTable(Conversa::getTable(), ['id' => (int) $l['conversas_id']])) {
            return (int) $l['conversas_id'];
        }
        $oppId = Conversa::createEntry([
            'client_type' => 'privado', 'source' => 'presencial', 'name' => $l['name'], 'company' => $l['company'], 'email' => $l['email'], 'numero_wa' => $l['phone'],
            'subject' => 'Lead do evento ' . $l['event_name'] . ' · ' . (self::INTERESTS[$l['interest']] ?? 'Outro'),
            'notes' => trim('Captado no evento "' . $l['event_name'] . '"' . ($l['job_title'] ? ' (' . $l['job_title'] . ')' : '') . ".\n" . $l['notes']),
        ], [], []);
        $DB->update(self::getTable(), ['status' => 'convertido', 'conversas_id' => $oppId, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
        return $oppId;
    }

    public static function statusBadge(string $s): string
    {
        [$label, $color, $icon] = self::STATUSES[$s] ?? ['—', '#64748b', 'ti-help'];
        return Menu::badge($label, $color, $icon);
    }

    public static function tempBadge(string $t): string
    {
        [$label, $color, $icon] = self::TEMPS[$t] ?? ['—', '#64748b', 'ti-help'];
        return Menu::badge($label, $color, $icon);
    }
}
