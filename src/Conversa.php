<?php

namespace GlpiPlugin\Crm;

use NotificationEvent;
use Plugin;
use RuntimeException;
use Session;
use Toolbox;

/**
 * Oportunidade comercial: nasce por um canal de entrada (WhatsApp, e-mail,
 * telefone, portal de licitação...), tem cliente privado ou público, documentos,
 * etapa na esteira de venda, valor, atendente e resultado. Quando chega ao
 * fechamento, o CRM pergunta se deve iniciar o ciclo financeiro.
 */
class Conversa extends BaseLogged
{
    public const READALL = 1024;

    public const OPEN = 1;
    public const WON  = 2;
    public const LOST = 3;

    public const STATUSES = [
        self::OPEN => ['Aberta', '#3b82f6', 'ti-message-2'],
        self::WON  => ['Venda', '#22c55e', 'ti-circle-check'],
        self::LOST => ['Perdida', '#ef4444', 'ti-circle-x'],
    ];

    /** Canais de entrada. */
    public const SOURCES = [
        'whatsapp'   => 'WhatsApp',
        'email'      => 'E-mail',
        'telefone'   => 'Telefone',
        'site'       => 'Site / formulário',
        'portal'     => 'Portal de compras / licitação',
        'indicacao'  => 'Indicação',
        'marketing'  => 'Marketing / anúncio',
        'presencial' => 'Presencial / evento',
        'outros'     => 'Outros',
    ];

    public const SOURCE_ICONS = [
        'whatsapp' => 'ti-brand-whatsapp', 'email' => 'ti-mail', 'telefone' => 'ti-phone', 'site' => 'ti-world', 'portal' => 'ti-gavel',
        'indicacao' => 'ti-users', 'marketing' => 'ti-speakerphone', 'presencial' => 'ti-building-community', 'outros' => 'ti-dots',
    ];

    /** Cor de cada canal (a da marca, quando existe). */
    public const SOURCE_COLORS = [
        'whatsapp' => '#25d366', 'email' => '#ea4335', 'telefone' => '#0ea5e9', 'site' => '#6366f1', 'portal' => '#a855f7',
        'indicacao' => '#f59e0b', 'marketing' => '#ec4899', 'presencial' => '#14b8a6', 'outros' => '#64748b',
    ];

    /** Tipo de cliente: privado ou público (cliente público exige o edital). */
    public const CLIENT_TYPES = [
        'privado' => ['Privado', '#3b82f6', 'ti-building'],
        'publico' => ['Público', '#a855f7', 'ti-building-bank'],
    ];

    /** Como a venda é contratada (mesmas chaves do Aviso de Fechamento do Ciclo Financeiro). */
    public const NEGOTIATION = ['direta' => 'Negociação direta', 'licitacao' => 'Licitação', 'pregao' => 'Pregão', 'ata' => 'Ata de registro de preço'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Oportunidades' : 'Oportunidade';
    }

    public static function getIcon()
    {
        return 'ti ti-message-2';
    }

    public static function canView(): bool
    {
        return Session::haveRightsOr(self::$rightname, [READ, self::READALL]);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE);
    }

    /** Configurações globais (etapas, alertas, integração): só quem vê todas e pode alterar. */
    public static function canConfigure(): bool
    {
        return Session::haveRight(self::$rightname, self::READALL) && self::canUpdate();
    }

    /** Atendente vê as próprias oportunidades e as que estão sem atendente. */
    public function canViewItem(): bool
    {
        $me = (int) Session::getLoginUserID();
        return $this->isNewItem() || Session::haveRight(self::$rightname, self::READALL)
            || in_array((int) $this->fields['users_id_attendant'], [$me, 0], true);
    }

    public static function getFormURL($full = false)
    {
        return Menu::page('/front/oportunidade.php');
    }

    public static function getSearchURL($full = false)
    {
        return Menu::page('/front/entradas.php');
    }

    public static function code(int $id): string
    {
        return 'OP-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public static function statusBadge(int $status): string
    {
        [$label, $color, $icon] = self::STATUSES[$status] ?? ['?', '#64748b', 'ti-help'];
        return Menu::badge($label, $color, $icon);
    }

    public static function clientBadge(string $type): string
    {
        [$label, $color, $icon] = self::CLIENT_TYPES[$type] ?? self::CLIENT_TYPES['privado'];
        return Menu::badge($label, $color, $icon);
    }

    public static function sourceLabel(string $source): string
    {
        return self::SOURCES[$source] ?? 'Outros';
    }

    /** Selo do canal de entrada: ícone na cor do canal e, se pedido, o nome. */
    public static function sourceBadge(string $source, bool $label = true): string
    {
        $key = isset(self::SOURCES[$source]) ? $source : 'outros';
        $name = self::SOURCES[$key];
        return "<span class='crm-channel" . ($label ? '' : ' icon-only') . "' style='--c:" . self::SOURCE_COLORS[$key] . "' title='" . Menu::e($name) . "'>"
            . "<span class='crm-channel-ico'><i class='ti " . self::SOURCE_ICONS[$key] . "'></i></span>" . ($label ? "<span class='crm-channel-name'>" . Menu::e($name) . "</span>" : '') . "</span>";
    }

    public static function etapaBadge(int $etapaId): string
    {
        $etapa = Etapa::all()[$etapaId] ?? ['name' => '—', 'color' => '#64748b'];
        return Menu::badge($etapa['name'], $etapa['color']);
    }

    public static function attendantName(int $userId): string
    {
        return $userId ? (string) getUserName($userId) : 'Sem atendente';
    }

    /** Nome para exibir: dados de demonstração levam "[Demo]" no banco (para remover depois), sem mostrar na tela. */
    public static function displayName(array $fields): string
    {
        return trim((string) preg_replace('/^\[Demo\]\s*/u', '', (string) ($fields['name'] ?: $fields['numero_wa'])));
    }

    /** Nome da empresa (ou do contato, se não houver empresa) para títulos e alertas. */
    public static function title(array $fields): string
    {
        $company = trim((string) preg_replace('/^\[Demo\]\s*/u', '', (string) ($fields['company'] ?? '')));
        return $company !== '' ? $company : self::displayName($fields);
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: ['?'];
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }

    /** Matiz estável do avatar a partir do nome (o mesmo contato tem sempre a mesma cor). */
    public static function hue(string $name): int
    {
        $h = 7;
        foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $h = ($h * 31 + mb_ord($ch)) % 360;
        }
        return $h;
    }

    public static function avatar(string $name, string $size = 'md'): string
    {
        return "<span class='crm-avatar crm-avatar-$size' style='--h:" . self::hue($name) . "'>" . Menu::e(self::initials($name)) . "</span>";
    }

    /** "agora", "5 min", "2 h" ou "3 d" desde a data informada. */
    public static function ago(?string $date): string
    {
        if (!$date) {
            return '—';
        }
        $s = max(0, time() - strtotime($date));
        return match (true) {
            $s < 60    => 'agora',
            $s < 3600  => intdiv($s, 60) . ' min',
            $s < 86400 => intdiv($s, 3600) . ' h',
            default    => intdiv($s, 86400) . ' d',
        };
    }

    public static function money(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    /** "1.234,56" ou "1234.56" → 1234.56 */
    public static function parseMoney(string $v): float
    {
        $v = trim(str_replace(['R$', ' '], '', $v));
        if (str_contains($v, ',')) {
            $v = str_replace(',', '.', str_replace('.', '', $v));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);
        }
        return max(0.0, round((float) $v, 2));
    }

    /** Última mensagem da conversa: ['body', 'direction', 'date'] ou null. */
    public static function lastMessageRow(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['body', 'direction', 'date'], 'FROM' => Mensagem::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $id],
            'ORDER' => ['date DESC', 'id DESC'], 'LIMIT' => 1])->current();
        return $row ?: null;
    }

    /** Texto da última mensagem, com "Você:" quando foi o atendente (vazio se não houver). */
    public static function lastMessage(int $id): string
    {
        $row = self::lastMessageRow($id);
        return $row ? (($row['direction'] === 'out' ? 'Você: ' : '') . $row['body']) : '';
    }

    /** Filtro de visibilidade: sem "ver todos", só as oportunidades do atendente ou sem atendente. */
    public static function scope(string $alias): array
    {
        return Session::haveRight(self::$rightname, self::READALL) ? [] : ["$alias.users_id_attendant" => [(int) Session::getLoginUserID(), 0]];
    }

    /** Usuários que podem receber oportunidades: [id => nome]. */
    public static function attendantOptions(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => ['firstname', 'realname', 'name'], 'LIMIT' => 300]) as $u) {
            $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
            $out[(int) $u['id']] = $full !== '' ? $full : $u['name'];
        }
        return $out;
    }

    /** Carrega a oportunidade e confere a permissão de ver (e, se pedido, de alterar). */
    public static function load(int $id, bool $forUpdate = false): self
    {
        $c = new self();
        if (!$c->getFromDB($id) || !$c->canViewItem() || ($forUpdate && !self::canUpdate())) {
            throw new RuntimeException('Oportunidade não encontrada ou sem permissão.', 403);
        }
        return $c;
    }

    // ---------------------------------------------------------------- entrada

    /** Campos da entrada, limpos e validados. */
    public static function cleanEntry(array $in): array
    {
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
        $clientType = isset(self::CLIENT_TYPES[$in['client_type'] ?? '']) ? $in['client_type'] : 'privado';
        $f = [
            'name'             => mb_substr(trim((string) ($in['name'] ?? '')), 0, 255),
            'company'          => mb_substr(trim((string) ($in['company'] ?? '')), 0, 255),
            'cnpj'             => mb_substr(preg_replace('/[^0-9.\/-]/', '', (string) ($in['cnpj'] ?? '')), 0, 20),
            'email'            => mb_strtolower(trim((string) ($in['email'] ?? ''))),
            'numero_wa'        => preg_replace('/\D+/', '', (string) ($in['numero_wa'] ?? '')),
            'client_type'      => $clientType,
            'source'           => isset(self::SOURCES[$in['source'] ?? '']) ? $in['source'] : 'outros',
            'negotiation_type' => isset(self::NEGOTIATION[$in['negotiation_type'] ?? '']) ? $in['negotiation_type'] : ($clientType === 'publico' ? 'pregao' : 'direta'),
            'subject'          => mb_substr(trim((string) ($in['subject'] ?? '')), 0, 255),
            'notes'            => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 4000),
            'edital_number'    => mb_substr(trim((string) ($in['edital_number'] ?? '')), 0, 60),
            'deadline'         => $date($in['deadline'] ?? ''),
            'session_date'     => $date($in['session_date'] ?? ''),
            'value'            => self::parseMoney((string) ($in['value'] ?? '0')),
        ];
        if ($f['name'] === '') {
            throw new RuntimeException('Informe o nome do contato.', 422);
        }
        if ($f['email'] === '' && $f['numero_wa'] === '') {
            throw new RuntimeException('Informe ao menos o e-mail ou o telefone do contato.', 422);
        }
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido.', 422);
        }
        return $f;
    }

    /**
     * Registra uma nova entrada pelos canais (formulário "Nova entrada"). Cliente público
     * exige o edital entre os documentos enviados.
     */
    public static function createEntry(array $in, array $files, array $types): int
    {
        $f = self::cleanEntry($in);
        if ($f['client_type'] === 'publico') {
            $hasEdital = false;
            foreach ((array) ($files['name'] ?? []) as $i => $name) {
                if ($name !== '' && (int) ($files['error'][$i] ?? 4) === UPLOAD_ERR_OK && ($types[$i] ?? '') === 'edital') {
                    $hasEdital = true;
                }
            }
            if (!$hasEdital) {
                throw new RuntimeException('Cliente público: anexe o edital (tipo de documento "Edital").', 422);
            }
        }
        $etapa = array_key_first(Etapa::forClient($f['client_type'])) ?: Etapa::first();
        $c = new self();
        $id = (int) $c->add($f + [
            'plugin_crm_etapas_id' => $etapa,
            'users_id_attendant'   => (int) Session::getLoginUserID(),
            'status'               => self::OPEN,
            'last_message_at'      => date('Y-m-d H:i:s'),
        ]);
        if (!$id) {
            throw new RuntimeException('Não foi possível registrar a entrada.', 500);
        }
        Historico::record($id, 0, (int) $etapa);
        Documento::upload($id, $files, $types);
        $c->getFromDB($id);
        Alerta::record('nova_conversa', $id, self::title($c->fields),
            self::CLIENT_TYPES[$f['client_type']][0] . ' · ' . self::sourceLabel($f['source']) . ($f['subject'] !== '' ? ' · ' . $f['subject'] : ''), (int) $etapa);
        return $id;
    }

    /** Atualiza os dados da ficha. */
    public function updateEntry(array $in): void
    {
        $f = self::cleanEntry($in);
        $this->update(['id' => $this->getID()] + $f);
        // se o tipo de cliente mudou e a etapa atual deixou de valer, volta para a primeira etapa que vale
        $etapas = Etapa::forClient($f['client_type']);
        $this->getFromDB($this->getID());
        if (!isset($etapas[(int) $this->fields['plugin_crm_etapas_id']]) && $etapas) {
            $this->update(['id' => $this->getID(), 'plugin_crm_etapas_id' => array_key_first($etapas)]);
        }
    }

    // ----------------------------------------------------------- esteira

    /** Atribui um atendente e avisa quem recebeu a oportunidade. */
    public function assign(int $userId): void
    {
        $this->update(['id' => $this->getID(), 'users_id_attendant' => $userId]);
        if ($userId) {
            $this->getFromDB($this->getID());
            Alerta::record('atribuida', $this->getID(), self::title($this->fields), 'Atribuída a ' . self::attendantName($userId), (int) $this->fields['plugin_crm_etapas_id']);
            NotificationEvent::raiseEvent('crm_conversa_atribuida', $this, ['attendant' => $userId]);
        }
    }

    /**
     * Muda a etapa da esteira e avisa conforme o tipo da etapa de destino. Devolve os dados
     * do aviso de fechamento quando a oportunidade chega ao fechamento (para perguntar sobre
     * o ciclo financeiro), ou null.
     */
    public function moveTo(int $etapaId): ?array
    {
        $etapa = Etapa::all()[$etapaId] ?? null;
        if (!$etapa) {
            throw new RuntimeException('Etapa inválida.', 422);
        }
        if ($etapa['scope'] !== 'all' && $etapa['scope'] !== $this->fields['client_type']) {
            throw new RuntimeException('Esta etapa é exclusiva de clientes ' . ($etapa['scope'] === 'publico' ? 'públicos' : 'privados') . '.', 422);
        }
        if ((int) $this->fields['plugin_crm_etapas_id'] === $etapaId) {
            return null;
        }
        $from = (int) $this->fields['plugin_crm_etapas_id'];
        $this->update(['id' => $this->getID(), 'plugin_crm_etapas_id' => $etapaId]);
        $this->getFromDB($this->getID());
        Historico::record($this->getID(), $from, $etapaId);
        Alerta::record($etapa['kind'], $this->getID(), self::title($this->fields), 'Movida para ' . $etapa['name'], $etapaId);
        $this->autoCycle = null;
        $this->cyclePaused = null;
        if ($etapa['kind'] !== 'fechamento') {
            // voltou para antes do fechamento: o ciclo já aberto para até a oportunidade seguir de novo
            $this->cyclePaused = $this->pauseCycle('A oportunidade voltou para ' . $etapa['name'] . ' no CRM.');
            return null;
        }
        if (($resumed = $this->resumeCycle('A oportunidade seguiu para o fechamento no CRM.'))) {
            $this->autoCycle = $resumed + ['mode' => 'retomado'];
            return null;
        }
        // aviso de fechamento: com a abertura automática ligada, o ciclo nasce agora e o Ciclo mostra o aviso no centro da tela
        if ((int) $this->fields['status'] === self::OPEN && Settings::autoCycle() && self::cicloAvailable() && !$this->cicloInfo()) {
            try {
                $this->startCycle('hw_servico', (float) $this->fields['value'], $this->defaultCycleName());
                $this->autoCycle = ($this->cicloInfo() ?? []) + ['mode' => 'aviso'];
                return null;
            } catch (\Throwable $e) {
                Toolbox::logInFile('crm', 'Ciclo automático (fechamento) falhou: ' . $e->getMessage() . "\n");
            }
        }
        if ($this->cicloInfo()) {
            $this->autoCycle = $this->cicloInfo() + ['mode' => 'aviso'];
            return null;
        }
        return $this->closingInfo();
    }

    /** Fecha como venda ou perda (ou reabre). O motivo só é gravado na perda. Na venda, devolve o aviso de fechamento. */
    /** Ciclo aberto automaticamente na venda ganha (preenchido por close). */
    public ?array $autoCycle = null;
    /** Ciclo interrompido ao voltar para a negociação (preenchido por moveTo). */
    public ?array $cyclePaused = null;

    private function cicloObject(): ?\GlpiPlugin\Ciclo\Ciclo
    {
        $id = (int) ($this->fields['ciclo_id'] ?? 0);
        if (!$id || !class_exists(\GlpiPlugin\Ciclo\Ciclo::class) || !class_exists(\GlpiPlugin\Ciclo\Fase::class)) {
            return null;
        }
        $c = new \GlpiPlugin\Ciclo\Ciclo();
        return $c->getFromDB($id) ? $c : null;
    }

    /** Interrompe o ciclo desta oportunidade (se estiver em andamento). Devolve os dados do ciclo ou null. */
    private function pauseCycle(string $why): ?array
    {
        $c = $this->cicloObject();
        if (!$c || (int) $c->fields['status'] !== \GlpiPlugin\Ciclo\Ciclo::OPEN || !defined('GlpiPlugin\\Ciclo\\Ciclo::PAUSED')) {
            return null;
        }
        \GlpiPlugin\Ciclo\Fase::pause($c, $why);
        return $this->cicloInfo();
    }

    /** Libera o ciclo interrompido (se houver). Devolve os dados do ciclo ou null. */
    private function resumeCycle(string $why): ?array
    {
        $c = $this->cicloObject();
        if (!$c || !defined('GlpiPlugin\\Ciclo\\Ciclo::PAUSED') || (int) $c->fields['status'] !== \GlpiPlugin\Ciclo\Ciclo::PAUSED) {
            return null;
        }
        \GlpiPlugin\Ciclo\Fase::resume($c, $why);
        return $this->cicloInfo();
    }

    public function close(int $status, string $reason = ''): ?array
    {
        $this->autoCycle = null;
        $this->update([
            'id'          => $this->getID(),
            'status'      => $status,
            'lost_reason' => $status === self::LOST ? $reason : null,
            'date_closed' => $status === self::OPEN ? null : date('Y-m-d H:i:s'),
        ]);
        $this->getFromDB($this->getID());
        $title = self::title($this->fields);
        if ($status === self::WON) {
            Alerta::record('venda', $this->getID(), $title, 'Venda fechada · ' . self::money((float) $this->fields['value']), (int) $this->fields['plugin_crm_etapas_id']);
            if (Settings::autoCycle() && self::cicloAvailable()) {
                try {
                    $mode = 'ganho';
                    $this->resumeCycle('Venda concretizada no CRM.');
                    if (!$this->cicloInfo()) {
                        $this->startCycle('hw_servico', (float) $this->fields['value'], $this->defaultCycleName());
                        $mode = 'novo';
                    }
                    $this->autoCycle = ($this->cicloInfo() ?? []) + ['mode' => $mode];
                    return null;
                } catch (\Throwable $e) {
                    // se o ciclo falhar a venda continua ganha; o usuário pode abrir pelo aviso de fechamento
                    Toolbox::logInFile('crm', 'Ciclo automático falhou: ' . $e->getMessage() . "\n");
                }
            }
            return $this->closingInfo();
        }
        if ($status === self::LOST) {
            Alerta::record('perda', $this->getID(), $title, 'Perdida' . ($reason !== '' ? ': ' . $reason : ''), (int) $this->fields['plugin_crm_etapas_id']);
        }
        return null;
    }

    public function setValue(float $value): void
    {
        $this->update(['id' => $this->getID(), 'value' => max(0, round($value, 2))]);
    }

    // ------------------------------------------------------ ciclo financeiro

    /** O plugin Ciclo Financeiro está ativo e o usuário pode abrir ciclos? */
    public static function cicloAvailable(): bool
    {
        return Plugin::isPluginActive('ciclo') && class_exists(\GlpiPlugin\Ciclo\Ciclo::class) && \GlpiPlugin\Ciclo\Ciclo::canCreate();
    }

    /** Ciclo financeiro ligado a esta oportunidade: ['id', 'code', 'url', 'status'] ou null. */
    public function cicloInfo(): ?array
    {
        global $DB;
        $id = (int) ($this->fields['ciclo_id'] ?? 0);
        if (!$id || !class_exists(\GlpiPlugin\Ciclo\Ciclo::class)) {
            return null;
        }
        $row = $DB->request(['FROM' => \GlpiPlugin\Ciclo\Ciclo::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$row) {
            return null;
        }
        return [
            'id'     => $id,
            'code'   => \GlpiPlugin\Ciclo\Ciclo::code($id),
            'url'    => \GlpiPlugin\Ciclo\Ciclo::getFormURLWithID($id),
            'status' => \GlpiPlugin\Ciclo\Ciclo::STATUSES[(int) $row['status']][0] ?? '—',
            'customer' => (string) ($row['customer'] ?? ''),
            'amount'   => (float) ($row['amount'] ?? 0),
            'date'     => substr((string) ($row['date_closing'] ?? ''), 0, 10),
            'docs'     => countElementsInTable('glpi_plugin_ciclo_documentos', ['plugin_ciclo_ciclos_id' => $id]),
        ];
    }

    /** Dados do aviso de fechamento (pop-up "Deseja iniciar um ciclo financeiro?") ou null se não se aplica. */
    public function closingInfo(bool $force = false): ?array
    {
        if ((!$force && !Settings::askCycle()) || !self::cicloAvailable() || $this->cicloInfo()) {
            return null;
        }
        $f = $this->fields;
        $saleTypes = array_map(fn ($t) => $t[0], \GlpiPlugin\Ciclo\Ciclo::SALE_TYPES);
        return [
            'id'        => $this->getID(),
            'code'      => self::code($this->getID()),
            'customer'  => self::title($f),
            'name'      => self::title($f) . ($f['subject'] ? ' — ' . $f['subject'] : ''),
            'amount'    => (float) $f['value'],
            'saleTypes' => $saleTypes,
            'saleType'  => 'hw_servico',
            'report'    => Menu::url('/front/relatorio.php?tipo=negociacao&id=' . $this->getID()),
        ];
    }

    /** Abre o ciclo financeiro (plugin Ciclo Financeiro) com os dados do aviso de fechamento. */
    public function startCycle(string $saleType, float $amount, string $name): array
    {
        if (!self::cicloAvailable()) {
            throw new RuntimeException('O plugin Ciclo Financeiro não está ativo ou você não tem direito de abrir ciclos.', 403);
        }
        if ($this->cicloInfo()) {
            throw new RuntimeException('Esta oportunidade já tem um ciclo financeiro.', 409);
        }
        $f = $this->fields;
        $docs = [];
        foreach (Documento::forConversa($this->getID()) as $d) {
            $docs[$d['doc_type']] ??= substr((string) $d['date'], 0, 10);
        }
        $originMap = ['indicacao' => 'indicacao', 'marketing' => 'marketing', 'site' => 'marketing'];
        $crm = array_filter([
            'funnel_phase'     => 'conformidade',
            'crm_status'       => 'ganhou',
            'origin'           => $originMap[$f['source']] ?? 'outro',
            'prospect_type'    => 'receptiva',
            'business_type'    => $f['client_type'],
            'negotiation_type' => $f['negotiation_type'],
            'ro_number'        => $f['edital_number'],
            'date_auction'     => $f['session_date'],
            'project_name'     => $name,
            'proposal_sent'    => 'sim',
            'contact_name'     => $f['name'],
            'contact_email'    => $f['email'],
            'contact_phone'    => $f['numero_wa'],
            'company_doc'      => $f['cnpj'],
            'probability'      => '100',
            'pain_description' => (string) $f['subject'],
            'economic_buyer'   => $f['name'],
            'am_estimate'      => (string) $amount,
            'date_proposal_sent' => $docs['orcamento'] ?? null,
            'docs_attached'    => $docs ? 'sim' : null,
            'chk_commercial'   => isset($docs['orcamento']) ? 'sim' : null,
            'chk_technical'    => isset($docs['proposta_tecnica']) ? 'sim' : null,
            'chk_acceptance'   => isset($docs['pedido_compra']) ? 'sim' : null,
            'chk_contract'     => isset($docs['contrato']) ? 'sim' : null,
        ], fn ($v) => $v !== null && $v !== '');
        $ciclo = new \GlpiPlugin\Ciclo\Ciclo();
        $cicloId = (int) $ciclo->add([
            'name'        => $name,
            'customer'    => self::title($f),
            'opportunity' => self::code($this->getID()),
            'sale_type'   => $saleType,
            'amount'      => $amount,
            'comment'     => (string) $f['subject'],
            'users_id_commercial' => (int) $f['users_id_attendant'],
            'crm'         => $crm,
        ]);
        if (!$cicloId) {
            throw new RuntimeException('O Ciclo Financeiro não foi aberto. Confira os dados.', 500);
        }
        $this->update(['id' => $this->getID(), 'ciclo_id' => $cicloId, 'value' => $amount]);
        $this->getFromDB($this->getID());
        $moved = $this->carryDocuments($cicloId);
        return ($this->cicloInfo() ?? ['id' => $cicloId]) + ['docs' => $moved['ok'], 'docs_skipped' => $moved['skipped']];
    }

    public function defaultCycleName(): string
    {
        $subject = trim((string) $this->fields['subject']);
        return trim(self::title($this->fields) . ($subject !== '' ? ' · ' . $subject : ''));
    }

    /** Copia todos os documentos da oportunidade para o ciclo (Documentos do GLPI + fase Aviso de Fechamento). */
    private function carryDocuments(int $cicloId): array
    {
        global $DB;
        $category = ['edital' => 1, 'orcamento' => 21, 'proposta_tecnica' => 21, 'contrato' => 3, 'pedido_compra' => 6, 'ata' => 20];
        $ciclo = new \GlpiPlugin\Ciclo\Ciclo();
        $ciclo->getFromDB($cicloId);
        $ok = 0;
        $skipped = [];
        foreach (Documento::forConversa($this->getID()) as $d) {
            $src = Documento::path($d);
            $name = (string) $d['name'];
            if (!is_file($src) || !\Document::isValidDoc($name)) {
                $skipped[] = $name;
                continue;
            }
            $prefix = 'cf' . bin2hex(random_bytes(6)) . '_';
            $tmp = GLPI_TMP_DIR . '/' . $prefix . $name;
            if (!copy($src, $tmp)) {
                $skipped[] = $name;
                continue;
            }
            $label = Documento::TYPES[$d['doc_type']] ?? 'Documento';
            $docId = (int) (new \Document())->add([
                'name'                  => $name,
                'comment'               => 'Documento do CRM (' . $label . ') · ' . self::code($this->getID()),
                'documentcategories_id' => $category[$d['doc_type']] ?? 2,
                '_filename'             => [$prefix . $name],
                '_prefix_filename'      => [$prefix],
                'itemtype'              => \GlpiPlugin\Ciclo\Ciclo::class,
                'items_id'              => $cicloId,
                'entities_id'           => (int) $ciclo->fields['entities_id'],
                'is_recursive'          => 1,
            ]);
            @unlink($tmp);
            if (!$docId) {
                $skipped[] = $name;
                continue;
            }
            $DB->insert('glpi_plugin_ciclo_documentos', ['documents_id' => $docId, 'plugin_ciclo_ciclos_id' => $cicloId, 'code' => 'aviso']);
            \GlpiPlugin\Ciclo\Evento::record($cicloId, 'aviso', 'comment', 'Documento trazido do CRM: ' . $name . ' (' . $label . ')');
            $ok++;
        }
        return ['ok' => $ok, 'skipped' => $skipped];
    }

    // --------------------------------------------------------------- chat

    /** Registra a resposta do atendente e tenta enviar pelo gateway, se configurado. */
    public function send(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Escreva a mensagem.', 422);
        }
        $me = (int) Session::getLoginUserID();
        $delivery = 'pendente';
        $gateway = Settings::get('gateway_url');
        if ($gateway !== '' && $this->fields['numero_wa'] !== '') {
            $delivery = self::postToGateway($gateway, ['numero' => $this->fields['numero_wa'], 'mensagem' => $text]) ? 'enviado' : 'falhou';
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) (new Mensagem())->add(['plugin_crm_conversas_id' => $this->getID(), 'direction' => 'out', 'users_id' => $me, 'body' => $text, 'delivery' => $delivery, 'date' => $now]);
        $this->update(['id' => $this->getID(), 'last_message_at' => $now] + ((int) $this->fields['users_id_attendant'] ? [] : ['users_id_attendant' => $me]));
        return ['id' => $id, 'delivery' => $delivery];
    }

    private static function postToGateway(string $url, array $payload): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Signature: ' . hash_hmac('sha256', $body, Settings::get('webhook_secret'))],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    /** Mensagens da conversa (a partir de $after, para atualizar a tela sem recarregar). */
    public static function messages(int $id, int $after = 0): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => Mensagem::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $id, 'id' => ['>', $after]], 'ORDER' => ['date ASC', 'id ASC'], 'LIMIT' => 500]) as $m) {
            $out[] = [
                'id'       => (int) $m['id'],
                'dir'      => $m['direction'],
                'body'     => $m['body'],
                'day'      => \Html::convDate($m['date']),
                'time'     => substr((string) $m['date'], 11, 5),
                'delivery' => $m['delivery'],
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------- funil

    /**
     * Tudo que uma tela precisa para mostrar uma oportunidade (cartão do funil, lista e gaveta do
     * dashboard): cliente, contato, etapa e esteira, valor, probabilidade, atividade e atalhos.
     * $last é a última mensagem (['body', 'direction']) ou null; $docs vem de Documento::summary().
     */
    public static function dealData(array $r, array $docs = [], ?array $last = null): array
    {
        $name = self::displayName($r);
        $title = self::title($r);
        $all = Etapa::all();
        $stageId = (int) $r['plugin_crm_etapas_id'];
        $stage = $all[$stageId] ?? ['name' => '—', 'color' => '#64748b', 'probability' => 0, 'kind' => ''];
        $status = (int) $r['status'];
        $keys = array_keys(Etapa::forClient($r['client_type']));
        $cur = array_search($stageId, $keys, true);
        $steps = [];
        foreach ($keys as $i => $eid) {
            $steps[] = ['name' => $all[$eid]['name'], 'state' => $status === self::WON || ($cur !== false && $i < $cur) ? 'done' : ($i === $cur ? 'current' : 'todo')];
        }
        $d = $docs[(int) $r['id']] ?? ['n' => 0, 'edital' => false];
        $value = (float) $r['value'];
        $prob = $status === self::WON ? 100 : ($status === self::LOST ? 0 : (int) $stage['probability']);
        return [
            'id'            => (int) $r['id'],
            'code'          => self::code((int) $r['id']),
            'etapa'         => $stageId,
            'stage'         => $stage['name'],
            'stageKind'     => $stage['kind'],
            'stageColor'    => $stage['color'],
            'prob'          => $prob,
            'status'        => $status,
            'name'          => $name,
            'title'         => $title,
            'initials'      => self::initials($title),
            'hue'           => self::hue($title),
            'numero'        => $r['numero_wa'],
            'email'         => (string) $r['email'],
            'cnpj'          => (string) $r['cnpj'],
            'clientType'    => $r['client_type'],
            'subject'       => (string) $r['subject'],
            'attendant'     => (int) $r['users_id_attendant'] ? (string) getUserName((int) $r['users_id_attendant']) : '',
            'attendantId'   => (int) $r['users_id_attendant'],
            'last'          => $last ? mb_strimwidth($last['body'], 0, 90, '…') : '',
            'waiting'       => $last && $last['direction'] === 'in',
            'ago'           => self::ago($r['last_message_at']),
            'minutes'       => $r['last_message_at'] ? (int) floor((time() - strtotime($r['last_message_at'])) / 60) : 0,
            'value'         => $value,
            'expected'      => round($value * $prob / 100, 2),
            'source'        => self::sourceLabel($r['source']),
            'sourceIcon'    => self::SOURCE_ICONS[$r['source']] ?? 'ti-dots',
            'sourceColor'   => self::SOURCE_COLORS[$r['source']] ?? '#64748b',
            'docs'          => $d['n'],
            'editalMissing' => $r['client_type'] === 'publico' && !$d['edital'],
            'deadline'      => $r['deadline'] ? date('d/m/Y', strtotime($r['deadline'])) : '',
            'session'       => $r['session_date'] ? date('d/m/Y', strtotime($r['session_date'])) : '',
            'edital'        => (string) $r['edital_number'],
            'created'       => $r['date_creation'] ? date('d/m/Y', strtotime($r['date_creation'])) : '',
            'url'           => Menu::url('/front/oportunidade.php?id=' . (int) $r['id']),
            'chat'          => Menu::url('/front/conversas.php?id=' . (int) $r['id']),
            'report'        => Menu::url('/front/relatorio.php?tipo=negociacao&id=' . (int) $r['id']),
            'steps'         => $steps,
        ];
    }

    /** Dados do funil (Kanban): etapas e oportunidades abertas, na ordem da última atividade. */
    public static function board(): array
    {
        global $DB;
        $docs = Documento::summary();
        $cards = [];
        $rows = $DB->request(['FROM' => self::getTable() . ' AS c', 'WHERE' => ['c.status' => self::OPEN] + self::scope('c'),
            'ORDER' => ['c.last_message_at DESC', 'c.id DESC'], 'LIMIT' => 400]);
        foreach ($rows as $r) {
            $cards[] = self::dealData($r, $docs, self::lastMessageRow((int) $r['id']));
        }
        $stages = [];
        foreach (Etapa::all() as $id => $e) {
            $stages[] = ['id' => $id] + $e;
        }
        $month = date('Y-m-d H:i:s', strtotime('-30 days'));
        $closed = fn (int $status): int => (int) ($DB->request(['COUNT' => 'cnt', 'FROM' => self::getTable() . ' AS c',
            'WHERE' => ['c.status' => $status, 'c.date_closed' => ['>=', $month]] + self::scope('c')])->current()['cnt'] ?? 0);
        return [
            'ok'         => true,
            'edit'       => self::canUpdate(),
            'stages'     => $stages,
            'cards'      => $cards,
            'attendants' => self::attendantOptions(),
            'won'        => $closed(self::WON),
            'lost'       => $closed(self::LOST),
        ];
    }
}
