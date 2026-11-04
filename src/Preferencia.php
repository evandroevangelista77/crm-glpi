<?php

namespace GlpiPlugin\Crm;

/**
 * Preferências de alerta de cada usuário: som, pop-up e volume.
 */
class Preferencia extends Base
{
    public const DEFAULTS = ['sound' => 1, 'popup' => 1, 'volume' => 70];

    public static function getTypeName($nb = 0)
    {
        return 'Preferências de alerta';
    }

    public static function forUser(int $userId): array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['users_id' => $userId], 'LIMIT' => 1])->current();
        return $row ? ['sound' => (int) $row['sound'], 'popup' => (int) $row['popup'], 'volume' => (int) $row['volume']] : self::DEFAULTS;
    }

    public static function save(int $userId, array $prefs): void
    {
        global $DB;
        $data = [
            'sound'  => empty($prefs['sound']) ? 0 : 1,
            'popup'  => empty($prefs['popup']) ? 0 : 1,
            'volume' => max(0, min(100, (int) ($prefs['volume'] ?? 70))),
        ];
        $exists = $DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['users_id' => $userId]])->current();
        if ($exists) {
            $DB->update(self::getTable(), $data, ['users_id' => $userId]);
        } else {
            $DB->insert(self::getTable(), $data + ['users_id' => $userId]);
        }
    }
}
