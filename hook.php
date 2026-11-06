<?php

use GlpiPlugin\Crm\Alerta;
use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Ata;
use GlpiPlugin\Crm\Documento;
use GlpiPlugin\Crm\Etapa;
use GlpiPlugin\Crm\Historico;
use GlpiPlugin\Crm\Mensagem;
use GlpiPlugin\Crm\Notifications;
use GlpiPlugin\Crm\Preferencia;
use GlpiPlugin\Crm\Settings;

function plugin_crm_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    $int       = "int $sign NOT NULL DEFAULT 0";
    $common    = "`entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL";

    $tables = [
        Etapa::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `rank` int NOT NULL DEFAULT 0, `is_active` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`), KEY `rank` (`rank`)",
        // status: 1 aberta, 2 fechada (venda), 3 perdida
        Conversa::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `numero_wa` varchar(32) NOT NULL,
            `contacts_id` $int, `plugin_crm_etapas_id` $int, `users_id_attendant` $int, `status` tinyint NOT NULL DEFAULT 1,
            `lost_reason` varchar(255) DEFAULT NULL, `last_message_at` timestamp NULL DEFAULT NULL, $common,
            PRIMARY KEY (`id`), KEY `numero_wa` (`numero_wa`), KEY `status` (`status`), KEY `plugin_crm_etapas_id` (`plugin_crm_etapas_id`),
            KEY `users_id_attendant` (`users_id_attendant`), KEY `entities_id` (`entities_id`)",
        // wa_message_id único: o mesmo webhook reenviado não duplica a mensagem
        Mensagem::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_crm_conversas_id` $int, `direction` varchar(3) NOT NULL DEFAULT 'in',
            `body` text, `wa_message_id` varchar(128) DEFAULT NULL, `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `wa_message_id` (`wa_message_id`), KEY `plugin_crm_conversas_id` (`plugin_crm_conversas_id`)",
        // eventos que viram alerta (som e pop-up) no navegador
        Alerta::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `type` varchar(24) NOT NULL, `plugin_crm_conversas_id` $int, `plugin_crm_etapas_id` $int,
            `users_id` $int, `title` varchar(255) DEFAULT NULL, `body` varchar(255) DEFAULT NULL, `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `plugin_crm_conversas_id` (`plugin_crm_conversas_id`), KEY `type` (`type`), KEY `date` (`date`)",
        Preferencia::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `users_id` $int, `sound` tinyint NOT NULL DEFAULT 1, `popup` tinyint NOT NULL DEFAULT 1,
            `volume` int NOT NULL DEFAULT 70, PRIMARY KEY (`id`), UNIQUE KEY `users_id` (`users_id`)",
        // anexos da oportunidade (pedido de orçamento, edital, orçamento...); o arquivo fica em files/_plugins/crm
        Documento::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_crm_conversas_id` $int, `doc_type` varchar(20) NOT NULL DEFAULT 'outros',
            `name` varchar(255) NOT NULL, `stored` varchar(80) NOT NULL, `size` bigint NOT NULL DEFAULT 0, `users_id` $int, `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `plugin_crm_conversas_id` (`plugin_crm_conversas_id`), KEY `doc_type` (`doc_type`)",
        // histórico de etapas (tempo em cada fase) e atas de reunião
        Historico::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_crm_conversas_id` $int, `from_etapas_id` $int, `plugin_crm_etapas_id` $int,
            `users_id` $int, `date` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `plugin_crm_conversas_id` (`plugin_crm_conversas_id`)",
        Ata::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_crm_conversas_id` $int, `meeting_date` datetime DEFAULT NULL, `kind` varchar(12) NOT NULL DEFAULT 'presencial',
            `title` varchar(255) NOT NULL, `participants` varchar(500) DEFAULT NULL, `summary` mediumtext, `decisions` text, `duration_min` int NOT NULL DEFAULT 0,
            `plugin_crm_documentos_id` $int, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `plugin_crm_conversas_id` (`plugin_crm_conversas_id`), KEY `meeting_date` (`meeting_date`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // 0.2.0: valor, origem e fechamento da oportunidade; probabilidade e tipo da etapa; autor e entrega da mensagem
    $add = function (string $table, string $field, string $def) use ($DB): void {
        if (!$DB->fieldExists($table, $field)) {
            $DB->doQuery("ALTER TABLE `$table` ADD `$field` $def");
        }
    };
    $add(Etapa::getTable(), 'probability', 'int NOT NULL DEFAULT 0 AFTER `rank`');
    $add(Etapa::getTable(), 'kind', "varchar(12) NOT NULL DEFAULT 'prevenda' AFTER `probability`");
    $add(Conversa::getTable(), 'value', 'decimal(12,2) NOT NULL DEFAULT 0 AFTER `status`');
    $add(Conversa::getTable(), 'source', "varchar(30) NOT NULL DEFAULT 'whatsapp' AFTER `value`");
    $add(Conversa::getTable(), 'date_closed', 'timestamp NULL DEFAULT NULL AFTER `last_message_at`');
    $add(Mensagem::getTable(), 'users_id', "int $sign NOT NULL DEFAULT 0 AFTER `direction`");
    $add(Mensagem::getTable(), 'delivery', "varchar(12) NOT NULL DEFAULT 'ok' AFTER `body`");

    // 0.3.0: canais de entrada, cliente privado ou público, ciclo financeiro e escopo das etapas
    $c = Conversa::getTable();
    $add($c, 'company', 'varchar(255) DEFAULT NULL AFTER `name`');
    $add($c, 'cnpj', 'varchar(20) DEFAULT NULL AFTER `company`');
    $add($c, 'email', 'varchar(255) DEFAULT NULL AFTER `cnpj`');
    $add($c, 'client_type', "varchar(10) NOT NULL DEFAULT 'privado' AFTER `email`");
    $add($c, 'negotiation_type', "varchar(10) NOT NULL DEFAULT 'direta' AFTER `source`");
    $add($c, 'subject', 'varchar(255) DEFAULT NULL AFTER `negotiation_type`');
    $add($c, 'notes', 'text AFTER `subject`');
    $add($c, 'edital_number', 'varchar(60) DEFAULT NULL AFTER `notes`');
    $add($c, 'deadline', 'date DEFAULT NULL AFTER `edital_number`');
    $add($c, 'session_date', 'date DEFAULT NULL AFTER `deadline`');
    $add($c, 'ciclo_id', "int $sign NOT NULL DEFAULT 0 AFTER `session_date`");
    $add(Etapa::getTable(), 'scope', "varchar(8) NOT NULL DEFAULT 'all' AFTER `kind`");

    // esteira de venda: da entrada ao fechamento. Em instalação nova cria as etapas; numa atualização
    // da 0.2.0 renomeia as quatro etapas antigas (mantendo as oportunidades nelas) e acrescenta as novas.
    $stages = Etapa::getTable();
    if (!countElementsInTable($stages)) {
        foreach (Etapa::DEFAULTS as $i => [$name, $kind, $probability, $scope]) {
            $DB->insert($stages, ['name' => $name, 'rank' => ($i + 1) * 10, 'is_active' => 1, 'kind' => $kind, 'probability' => $probability, 'scope' => $scope]);
        }
        Settings::set(['esteira_v3' => '1']);
    } elseif (Settings::get('esteira_v3') !== '1') {
        foreach (['Prospecção' => 'Entrada', 'Agendamento' => 'Qualificação', 'Pós-venda' => 'Fechamento'] as $old => $new) {
            $DB->update($stages, ['name' => $new], ['name' => $old]);
        }
        foreach (Etapa::DEFAULTS as $i => [$name, $kind, $probability, $scope]) {
            $data = ['rank' => ($i + 1) * 10, 'kind' => $kind, 'probability' => $probability, 'scope' => $scope];
            if (countElementsInTable($stages, ['name' => $name])) {
                $DB->update($stages, $data, ['name' => $name]);
            } else {
                $DB->insert($stages, $data + ['name' => $name, 'is_active' => 1]);
            }
        }
        Settings::set(['esteira_v3' => '1']);
    }

    // 0.4.0: oportunidades que já existiam ganham um primeiro registro no histórico de etapas
    $DB->doQuery('INSERT INTO `' . Historico::getTable() . '` (`plugin_crm_conversas_id`, `from_etapas_id`, `plugin_crm_etapas_id`, `users_id`, `date`) '
        . 'SELECT c.`id`, 0, c.`plugin_crm_etapas_id`, 0, COALESCE(c.`date_creation`, NOW()) FROM `' . Conversa::getTable() . '` c '
        . 'WHERE NOT EXISTS (SELECT 1 FROM `' . Historico::getTable() . '` h WHERE h.`plugin_crm_conversas_id` = c.`id`)');

    // configuração e segredo do webhook: gerados uma vez e mantidos nas atualizações
    $defaults = ['sla_minutes' => '60', 'popup_seconds' => '8', 'gateway_url' => '', 'alert_rules' => '{}', 'ask_cycle' => '1', 'auto_cycle' => '1'];
    $current = Config::getConfigurationValues(Settings::CONTEXT);
    $missing = array_diff_key($defaults, $current);
    if (empty($current['webhook_secret'])) {
        $missing['webhook_secret'] = bin2hex(random_bytes(24));
    }
    if ($missing) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // direitos: todos atendem as próprias conversas; quem administra vê todas
    $rights = [Conversa::$rightname => [READ | CREATE | UPDATE, READ | CREATE | UPDATE | PURGE | Conversa::READALL]];
    foreach ($rights as $right => [$user, $admin]) {
        if (countElementsInTable(ProfileRight::getTable(), ['name' => $right])) {
            continue; // atualização: mantém o que foi configurado
        }
        ProfileRight::addProfileRights([$right]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? $admin : $user], ['profiles_id' => $row['id'], 'name' => $right]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $current = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Conversa::$rightname]);
        $_SESSION['glpiactiveprofile'][Conversa::$rightname] = (int) ($current[Conversa::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    Notifications::install();
    return true;
}

function plugin_crm_uninstall(): bool
{
    global $DB;
    Notifications::uninstall();
    foreach ([Mensagem::getTable(), Conversa::getTable(), Etapa::getTable(), Alerta::getTable(), Preferencia::getTable(), Documento::getTable(), Historico::getTable(), Ata::getTable()] as $table) {
        $DB->dropTable($table, true);
    }
    $DB->delete('glpi_logs', ['itemtype' => Conversa::class]);
    Config::deleteConfigurationValues(Settings::CONTEXT, ['webhook_secret', 'sla_minutes', 'popup_seconds', 'gateway_url', 'alert_rules', 'idle_checked_at', 'ask_cycle', 'auto_cycle', 'esteira_v3']);
    ProfileRight::deleteProfileRights([Conversa::$rightname]);
    unset($_SESSION['glpimenu']);
    return true;
}

/** Na busca: sem "ver todos", o atendente vê só as conversas atribuídas a ele ou sem atendente. */
function plugin_crm_addDefaultWhere($itemtype)
{
    $me = (int) Session::getLoginUserID();
    if ($itemtype === Conversa::class && !Session::haveRight(Conversa::$rightname, Conversa::READALL)) {
        $t = Conversa::getTable();
        return ["$t.users_id_attendant" => [$me, 0]];
    }
    return [];
}
