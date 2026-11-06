<?php

namespace GlpiPlugin\Crm;

use Config;

/**
 * Configuração global do CRM (guardada em Configurar → Geral, contexto plugin:crm).
 */
class Settings
{
    public const CONTEXT = 'plugin:crm';

    public static function get(string $key, string $default = ''): string
    {
        $value = Config::getConfigurationValues(self::CONTEXT)[$key] ?? null;
        return $value === null ? $default : (string) $value;
    }

    public static function set(array $values): void
    {
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    /** Minutos sem resposta ao cliente para disparar o alerta de SLA (0 desliga). */
    public static function slaMinutes(): int
    {
        return (int) self::get('sla_minutes', '60');
    }

    public static function popupSeconds(): int
    {
        return max(3, (int) self::get('popup_seconds', '8'));
    }

    /** Perguntar se deseja iniciar um ciclo financeiro quando a oportunidade chega ao fechamento. */
    /** Abre o ciclo financeiro sozinho quando a venda é concretizada. */
    public static function autoCycle(): bool
    {
        return self::get('auto_cycle', '1') === '1';
    }

    public static function askCycle(): bool
    {
        return self::get('ask_cycle', '1') === '1';
    }

    public static function webhookUrl(): string
    {
        global $CFG_GLPI;
        return rtrim((string) $CFG_GLPI['url_base'], '/') . Menu::page('/front/webhook.php');
    }

    /** Regras de alerta por evento, completadas com os padrões. */
    public static function alertRules(): array
    {
        $saved = json_decode(self::get('alert_rules', '{}'), true) ?: [];
        $rules = [];
        foreach (Alerta::EVENTS as $key => $event) {
            $rules[$key] = array_merge(['active' => 1, 'sound' => 1, 'popup' => 1, 'tone' => $event['tone']], (array) ($saved[$key] ?? []));
        }
        return $rules;
    }
}
