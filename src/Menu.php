<?php

namespace GlpiPlugin\Crm;

use Session;

/**
 * Seção "CRM" do menu lateral e endereços dos recursos do plugin.
 */
class Menu
{
    public const SECTOR = 'crm';

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/crm' . $path;
    }

    /** Endereço relativo, como o menu do GLPI espera. */
    public static function page(string $path): string
    {
        return '/plugins/crm' . $path;
    }

    public static function fileVersion(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_CRM_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
    }

    public static function css(): string
    {
        return "<link rel='stylesheet' href='" . htmlspecialchars(self::url('/css/crm.css') . '?m=' . self::fileVersion('css/crm.css'), ENT_QUOTES) . "'>";
    }

    public static function script(string $file): string
    {
        return \Html::script(self::url('/js/' . $file), ['version' => self::fileVersion('js/' . $file)], false);
    }

    /** Itens da seção CRM conforme os direitos. */
    public static function content(): array
    {
        $items = [];
        if (Session::haveRightsOr(Conversa::$rightname, [READ, Conversa::READALL])) {
            $items['funil'] = ['title' => 'Funil de vendas', 'page' => self::page('/front/funil.php'), 'icon' => 'ti ti-layout-kanban'];
            $items['entradas'] = ['title' => 'Entradas', 'page' => self::page('/front/entradas.php'), 'icon' => 'ti ti-inbox',
                'links' => Conversa::canCreate() ? ['add' => self::page('/front/entrada.php'), 'search' => self::page('/front/entradas.php')] : []];
            $items['leads'] = ['title' => 'Gestão de Leads', 'page' => self::page('/front/leads.php'), 'icon' => 'ti ti-user-star'];
            $items['conversas'] = ['title' => 'Conversas', 'page' => self::page('/front/conversas.php'), 'icon' => 'ti ti-message-2'];
            $items['dashboard'] = ['title' => 'Dashboard CRM', 'page' => self::page('/front/dashboard.php'), 'icon' => 'ti ti-chart-bar'];
            $items['config'] = ['title' => 'Configurações', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
        }
        return $items;
    }

    public static function header(string $title, string $item): void
    {
        \Html::header($title, '', self::SECTOR, $item);
        echo self::css();
    }

    /** Subtítulos das telas do CRM (o mesmo texto em todo lugar). */
    public const SUBTITLES = [
        'entradas'   => 'Todas as entradas comerciais por canal (WhatsApp, e-mail, telefone, portal de licitação, indicação...), com tipo de cliente, documentos e etapa da esteira.',
        'entrada'    => 'Registre um pedido de orçamento, uma licitação ou qualquer contato comercial: canal, tipo de cliente, contato, empresa e documentos. Cliente público exige o edital.',
        'oportunidade' => 'Ficha completa: esteira de venda até o fechamento, dados do cliente e do pedido, documentos e linha do tempo. No fechamento, o CRM oferece abrir o ciclo financeiro.',
        'funil'      => 'Oportunidades abertas por etapa. Arraste o cartão para outra coluna para mudar a etapa; clique para ver o resumo na gaveta lateral. A tela se atualiza sozinha.',
        'leads'      => 'Leads captados nos eventos (Gestão de Eventos): acompanhe por evento, registre o contato, qualifique e converta em oportunidade do funil.',
        'conversas'  => 'Caixa de entrada do WhatsApp: converse com o cliente, atribua um atendente, ajuste o valor e mova a oportunidade pelo funil.',
        'dashboard'  => 'Pipeline, previsão, vendas e conversão no período, com comparação ao período anterior, funil, ranking de atendentes e motivos de perda.',
        'config'     => 'Etapas do funil, alertas sonoros e pop-up por tipo de evento, integração com o WhatsApp e as suas preferências pessoais.',
    ];

    /**
     * Cabeçalho padrão das telas: seção, título, subtítulo e ações à direita.
     * @param string $actions HTML dos botões/filtros do canto direito
     */
    public static function title(string $title, string $key, string $actions = '', string $crumb = 'CRM'): string
    {
        return "<div class='crm-title'><div><div class='crm-crumb'>" . self::e($crumb) . "</div><h2>" . self::e($title) . "</h2>"
            . (isset(self::SUBTITLES[$key]) ? "<div class='text-secondary small crm-subtitle'>" . self::e(self::SUBTITLES[$key]) . "</div>" : '')
            . "</div>" . ($actions ? "<div class='d-flex flex-wrap gap-2 align-items-center'>$actions</div>" : '') . "</div>";
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1'></i>" . self::e($label) . "</a>";
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    public static function badge(string $label, string $color, string $icon = ''): string
    {
        return "<span class='badge crm-badge' style='--c:$color'>" . ($icon ? "<i class='ti $icon me-1'></i>" : '') . self::e($label) . "</span>";
    }
}
