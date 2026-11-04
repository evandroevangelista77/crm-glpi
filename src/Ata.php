<?php

namespace GlpiPlugin\Crm;

use RuntimeException;
use Session;

/**
 * Ata de reunião com o cliente: data, tipo, participantes, resumo e decisões, com o arquivo da
 * ata (PDF, Word...) anexado se o comercial tiver. Entra no relatório da negociação.
 */
class Ata extends Base
{
    public const KINDS = [
        'presencial' => ['Presencial', 'ti-users'],
        'video'      => ['Videoconferência', 'ti-video'],
        'telefone'   => ['Telefone', 'ti-phone'],
        'outro'      => ['Outro', 'ti-dots'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Atas de reunião' : 'Ata de reunião';
    }

    public static function forConversa(int $conversaId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $conversaId], 'ORDER' => ['meeting_date DESC', 'id DESC']]), false);
    }

    /** Registra a ata. $files segue o formato de vários arquivos do PHP; o primeiro vira o anexo da ata. */
    public static function create(int $conversaId, array $in, array $files = []): int
    {
        global $DB;
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 255);
        $summary = trim((string) ($in['summary'] ?? ''));
        $when = str_replace('T', ' ', trim((string) ($in['meeting_date'] ?? '')));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $when)) {
            $when .= ' 09:00';
        }
        if ($title === '') {
            throw new RuntimeException('Informe o título da reunião.', 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $when)) {
            throw new RuntimeException('Informe a data da reunião.', 422);
        }
        $hasFile = !empty($files['name'][0]) && (int) ($files['error'][0] ?? 4) !== UPLOAD_ERR_NO_FILE;
        if ($summary === '' && !$hasFile) {
            throw new RuntimeException('Escreva o resumo da reunião ou anexe o arquivo da ata.', 422);
        }
        $docId = 0;
        if ($hasFile) {
            Documento::upload($conversaId, $files, ['ata']);
            $docId = (int) (Documento::$lastIds[0] ?? 0);
        }
        $kind = isset(self::KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'presencial';
        $DB->insert(self::getTable(), [
            'plugin_crm_conversas_id' => $conversaId,
            'meeting_date'            => substr($when, 0, 16) . ':00',
            'kind'                    => $kind,
            'title'                   => $title,
            'participants'            => mb_substr(trim((string) ($in['participants'] ?? '')), 0, 500),
            'summary'                 => mb_substr($summary, 0, 8000),
            'decisions'               => mb_substr(trim((string) ($in['decisions'] ?? '')), 0, 4000),
            'duration_min'            => max(0, (int) ($in['duration_min'] ?? 0)),
            'plugin_crm_documentos_id' => $docId,
            'users_id'                => (int) Session::getLoginUserID(),
            'date_creation'           => date('Y-m-d H:i:s'),
        ]);
        return (int) $DB->insertId();
    }

    public static function remove(int $id, int $conversaId): void
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id, 'plugin_crm_conversas_id' => $conversaId]])->current();
        if ($row) {
            if ((int) $row['plugin_crm_documentos_id']) {
                Documento::remove((int) $row['plugin_crm_documentos_id']);
            }
            $DB->delete(self::getTable(), ['id' => $id]);
        }
    }
}
