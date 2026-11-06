<?php

namespace GlpiPlugin\Crm;

/**
 * Etapa da esteira de venda, da entrada até o fechamento. As etapas padrão são
 * criadas na instalação e podem ser editadas em CRM → Configurações.
 */
class Etapa extends Base
{
    /** Etapa para onde a oportunidade vai quando o Ciclo Financeiro a devolve ao Aviso de Fechamento. */
    public const RENEGOTIATION = 'Renegociação e ajustes';

    /** [nome, tipo, probabilidade de fechar em %, para quem vale] */
    public const DEFAULTS = [
        ['Entrada', 'prevenda', 10, 'all'],
        ['Qualificação', 'prevenda', 20, 'all'],
        ['Análise do edital', 'prevenda', 25, 'publico'],
        ['Elaboração do orçamento', 'negociacao', 40, 'all'],
        ['Orçamento enviado', 'negociacao', 55, 'all'],
        ['Negociação', 'negociacao', 70, 'all'],
        [self::RENEGOTIATION, 'negociacao', 60, 'all'],
        ['Fechamento', 'fechamento', 90, 'all'],
    ];

    /** O tipo define o alerta disparado quando a oportunidade entra na etapa. "Fechamento" é o aviso de fechamento. */
    public const KINDS = [
        'prevenda'   => 'Pré-venda',
        'negociacao' => 'Negociação',
        'posvenda'   => 'Pós-venda',
        'fechamento' => 'Fechamento',
    ];

    public const SCOPES = ['all' => 'Todos os clientes', 'publico' => 'Só clientes públicos', 'privado' => 'Só clientes privados'];

    /** Cor de cada coluna pela posição no funil. */
    public const COLORS = ['#64748b', '#0ea5e9', '#a855f7', '#6366f1', '#f59e0b', '#f97316', '#22c55e', '#14b8a6'];

    private static ?array $cache = null;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Etapas da esteira' : 'Etapa da esteira';
    }

    /** Etapas ativas, em ordem: [id => ['name', 'color', 'kind', 'probability', 'scope']]. */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (self::rows() as $row) {
                if ((int) $row['is_active']) {
                    self::$cache[(int) $row['id']] = [
                        'name'        => $row['name'],
                        'kind'        => $row['kind'],
                        'probability' => (int) $row['probability'],
                        'scope'       => $row['scope'],
                        'color'       => self::COLORS[count(self::$cache) % count(self::COLORS)],
                    ];
                }
            }
        }
        return self::$cache;
    }

    /** Etapas que valem para o tipo de cliente informado (privado ou publico). */
    public static function forClient(string $clientType): array
    {
        return array_filter(self::all(), fn ($e) => $e['scope'] === 'all' || $e['scope'] === $clientType);
    }

    /** Todas as linhas (inclusive inativas), para a tela de configuração. */
    public static function rows(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'ORDER' => ['rank', 'id']]), false);
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Primeira etapa do funil: onde entra toda oportunidade nova. */
    public static function first(): int
    {
        return (int) array_key_first(self::all());
    }
}
