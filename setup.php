<?php

/**
 * Plugin "CRM" para GLPI 11 e 12.
 *
 * Conversas de WhatsApp recebidas por webhook, funil de vendas por etapas,
 * atribuição a atendentes, avisos por e-mail e dashboard comercial. Segue o
 * mesmo layout do plugin RH (seção no menu lateral logo abaixo de RH).
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Dashboard;
use GlpiPlugin\Crm\Menu;

define('PLUGIN_CRM_VERSION', '0.6.0');
define('PLUGIN_CRM_MIN_GLPI', '11.0.0');
define('PLUGIN_CRM_MAX_GLPI', '12.0.99');

function plugin_init_crm(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Conversa::class, ['notificationtemplates_types' => true]);
    Plugin::registerClass(Dashboard::class);

    // o gateway de WhatsApp chama o webhook sem sessão: a assinatura HMAC é a autenticação
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('crm', '#^/front/webhook\.php$#', \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
    // sem sessão, logo sem o token CSRF que o GLPI exige de formulários (o GLPI 11 recusa o POST sem isto)
    \Glpi\Http\SessionManager::registerPluginStatelessPath('crm', '#^/front/webhook\.php$#');

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Conversa::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Conversa::$rightname]);
        $_SESSION['glpiactiveprofile'][Conversa::$rightname] = (int) ($rights[Conversa::$rightname] ?? 0);
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['crm']     = [Menu::SECTOR => [Conversa::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['crm'] = 'plugin_crm_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['crm']        = ['css/crm.css'];
    // alertas sonoros e pop-up em qualquer tela do GLPI
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['crm'] = ['js/alertas.js'];
}

/** Seção "CRM" no menu lateral, logo abaixo de RH (ou de Financeiro, se RH ainda não estiver ativo). */
function plugin_crm_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'CRM',
        'icon'    => 'ti ti-message-circle',
        'types'   => [Conversa::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['rh', 'financeiro', 'pmo', 'dashboard'] as $after) {
        if (isset($menu[$after])) {
            $out = [];
            foreach ($menu as $key => $value) {
                $out[$key] = $value;
                if ($key === $after) {
                    $out += $section;
                }
            }
            return $out;
        }
    }
    return $section + $menu;
}

function plugin_version_crm(): array
{
    return [
        'name'         => 'CRM',
        'version'      => PLUGIN_CRM_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/crm-glpi',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_CRM_MIN_GLPI, 'max' => PLUGIN_CRM_MAX_GLPI],
        ],
    ];
}

function plugin_crm_check_prerequisites(): bool
{
    return true;
}

function plugin_crm_check_config($verbose = false): bool
{
    return true;
}
