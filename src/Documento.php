<?php

namespace GlpiPlugin\Crm;

use RuntimeException;
use Session;

/**
 * Documento anexado a uma oportunidade (pedido de orçamento, edital,
 * orçamento, contrato...). Os arquivos ficam na pasta de arquivos do GLPI
 * (fora do endereço público), com nome aleatório, e só saem por
 * front/documento.php depois de conferir a permissão.
 */
class Documento extends Base
{
    public const TYPES = [
        'pedido_orcamento' => 'Pedido de orçamento',
        'edital'           => 'Edital',
        'orcamento'        => 'Orçamento (nossa proposta)',
        'proposta_tecnica' => 'Proposta técnica',
        'pedido_compra'    => 'Pedido de compra / PO',
        'contrato'         => 'Contrato',
        'ata'              => 'Ata de reunião',
        'outros'           => 'Outros',
    ];

    public const ICONS = [
        'pedido_orcamento' => 'ti-file-description',
        'edital'           => 'ti-gavel',
        'orcamento'        => 'ti-file-invoice',
        'proposta_tecnica' => 'ti-file-certificate',
        'pedido_compra'    => 'ti-shopping-cart',
        'contrato'         => 'ti-signature',
        'ata'              => 'ti-notes',
        'outros'           => 'ti-paperclip',
    ];

    private const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'xml', 'zip', 'png', 'jpg', 'jpeg', 'eml', 'msg'];
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** Ids dos documentos gravados pela última chamada de upload (na ordem dos arquivos). */
    public static array $lastIds = [];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Documentos' : 'Documento';
    }

    public static function dir(): string
    {
        $dir = rtrim((string) GLPI_PLUGIN_DOC_DIR, '/') . '/crm';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }

    public static function path(array $row): string
    {
        return self::dir() . '/' . basename((string) $row['stored']);
    }

    /** Documentos de uma oportunidade, mais novos primeiro. */
    public static function forConversa(int $conversaId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $conversaId], 'ORDER' => ['date DESC', 'id DESC']]), false);
    }

    /** Quantos documentos cada oportunidade tem e se já tem edital: [id => ['n' => int, 'edital' => bool]]. */
    public static function summary(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['plugin_crm_conversas_id', 'doc_type'], 'FROM' => self::getTable()]) as $r) {
            $id = (int) $r['plugin_crm_conversas_id'];
            $out[$id] ??= ['n' => 0, 'edital' => false];
            $out[$id]['n']++;
            $out[$id]['edital'] = $out[$id]['edital'] || $r['doc_type'] === 'edital';
        }
        return $out;
    }

    public static function hasEdital(int $conversaId): bool
    {
        return (bool) countElementsInTable(self::getTable(), ['plugin_crm_conversas_id' => $conversaId, 'doc_type' => 'edital']);
    }

    /**
     * Guarda os arquivos enviados ($_FILES['docs'] no formato de vários arquivos), cada um
     * com o tipo de mesma posição em $types. Devolve quantos foram anexados.
     */
    public static function upload(int $conversaId, array $files, array $types): int
    {
        global $DB;
        $count = 0;
        self::$lastIds = [];
        $names = (array) ($files['name'] ?? []);
        foreach ($names as $i => $original) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE || $original === '') {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException("Falha ao receber o arquivo \"$original\" (código $error).", 422);
            }
            $ext = strtolower(pathinfo((string) $original, PATHINFO_EXTENSION));
            if (!in_array($ext, self::EXTENSIONS, true)) {
                throw new RuntimeException("Tipo de arquivo não permitido: \"$original\".", 422);
            }
            $size = (int) $files['size'][$i];
            if ($size > self::MAX_BYTES) {
                throw new RuntimeException("O arquivo \"$original\" passa de 20 MB.", 422);
            }
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            $tmp = $files['tmp_name'][$i];
            // pela web só vale arquivo realmente enviado; a linha de comando (testes e importações) move arquivos locais
            $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, self::dir() . '/' . $stored) : (PHP_SAPI === 'cli' && rename($tmp, self::dir() . '/' . $stored));
            if (!$moved) {
                throw new RuntimeException("Não foi possível guardar \"$original\".", 500);
            }
            $type = (string) ($types[$i] ?? 'outros');
            $DB->insert(self::getTable(), [
                'plugin_crm_conversas_id' => $conversaId,
                'doc_type'                => isset(self::TYPES[$type]) ? $type : 'outros',
                'name'                    => mb_substr(basename((string) $original), 0, 255),
                'stored'                  => $stored,
                'size'                    => $size,
                'users_id'                => (int) Session::getLoginUserID(),
                'date'                    => date('Y-m-d H:i:s'),
            ]);
            self::$lastIds[] = (int) $DB->insertId();
            $count++;
        }
        return $count;
    }

    public static function remove(int $id): void
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        if ($row) {
            @unlink(self::path($row));
            $DB->delete(self::getTable(), ['id' => $id]);
        }
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
