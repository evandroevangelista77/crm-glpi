<?php

namespace GlpiPlugin\Crm;

/**
 * Mesma base de Base, com o histórico de alterações do GLPI ligado ($dohistory).
 * Também é declarada de um jeito em cada versão (veja Base).
 */
if (version_compare(GLPI_VERSION, '12.0.0-dev', '>=')) {
    abstract class BaseLogged extends \CommonDBTM
    {
        public static string $rightname = 'plugin_crm_conversas';
        public bool $dohistory = true;
    }
} else {
    abstract class BaseLogged extends \CommonDBTM
    {
        public static $rightname = 'plugin_crm_conversas';
        public $dohistory = true;
    }
}
