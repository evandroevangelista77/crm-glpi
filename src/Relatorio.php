<?php

namespace GlpiPlugin\Crm;

/**
 * Relatórios do CRM em PDF (TCPDF, com os gráficos desenhados no próprio arquivo):
 *  - executivo: o funil no período, para a diretoria e o comercial;
 *  - negociação: toda a negociação de uma oportunidade (tempo com o cliente, canais, reuniões,
 *    documentos e linha do tempo), pensado para o fechamento;
 *  - csv: o funil em planilha.
 */
class Relatorio
{
    private const BLUE = '#2563eb';
    private const GREEN = '#16a34a';
    private const RED = '#dc2626';
    private const PURPLE = '#9333ea';
    private const AMBER = '#d97706';

    private static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }

    private static function brl(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    private static function brl0(float $v): string
    {
        return 'R$ ' . number_format($v, 0, ',', '.');
    }

    /** Valor compacto para os cartões: "R$ 5,93 mi", "R$ 571,5 mil" ou "R$ 850". */
    private static function brlShort(float $v): string
    {
        return match (true) {
            $v >= 1_000_000 => 'R$ ' . number_format($v / 1_000_000, 2, ',', '.') . ' mi',
            $v >= 10_000    => 'R$ ' . number_format($v / 1_000, 1, ',', '.') . ' mil',
            default         => 'R$ ' . number_format($v, 0, ',', '.'),
        };
    }

    /** "3 d 4 h", "5 h 20 min" ou "12 min". */
    public static function dur(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        return match (true) {
            $d > 0  => "$d d" . ($h ? " $h h" : ''),
            $h > 0  => "$h h" . ($m ? " $m min" : ''),
            default => max(1, $m) . ' min',
        };
    }

    private static function newDoc(string $kicker, string $title, string $sub, string $meta, string $foot): RelatorioDoc
    {
        $pdf = new RelatorioDoc();
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->docKicker = $kicker;
        $pdf->docTitle = $title;
        $pdf->docSub = $sub;
        $pdf->docMeta = $meta;
        $pdf->docFoot = $foot;
        $pdf->cover = true;
        $pdf->AddPage();
        return $pdf;
    }

    private static function footer(): string
    {
        return 'Gerado em ' . date('d/m/Y H:i') . ' por ' . getUserName((int) \Session::getLoginUserID()) . ' · CRM · GLPI';
    }

    /** Na linha de comando (testes) com $capture ligado, o PDF fica em $captured em vez de ser enviado. */
    public static bool $capture = false;
    public static ?string $captured = null;

    /** Envia o PDF para download e encerra. */
    private static function send(RelatorioDoc $pdf, string $name): void
    {
        if (PHP_SAPI === 'cli' && self::$capture) {
            self::$captured = $pdf->Output($name, 'S');
            return;
        }
        $pdf->Output($name, 'D');
        exit;
    }

    // ============================================================ negociação

    /** Tudo sobre a negociação de uma oportunidade, calculado a partir das mensagens, etapas, atas e documentos. */
    public static function negotiationData(Conversa $c): array
    {
        global $DB;
        $f = $c->fields;
        $id = (int) $f['id'];
        $start = (int) strtotime((string) $f['date_creation']);
        $end = $f['date_closed'] ? (int) strtotime((string) $f['date_closed']) : time();
        $segments = Historico::segments($f);

        $in = $out = 0;
        $firstIn = $firstOut = $firstMsg = $lastMsg = null;
        $days = [];
        foreach ($DB->request(['FROM' => Mensagem::getTable(), 'WHERE' => ['plugin_crm_conversas_id' => $id], 'ORDER' => ['date ASC', 'id ASC']]) as $m) {
            $ts = (int) strtotime((string) $m['date']);
            $firstMsg ??= $ts;
            $lastMsg = $ts;
            $days[date('Y-m-d', $ts)] = true;
            if ($m['direction'] === 'in') {
                $in++;
                $firstIn ??= $ts;
            } else {
                $out++;
                if ($firstIn !== null) {
                    $firstOut ??= $ts;
                }
            }
        }
        $atas = Ata::forConversa($id);
        $docs = Documento::forConversa($id);
        $kinds = [];
        foreach ($atas as $a) {
            $kinds[$a['kind']] = ($kinds[$a['kind']] ?? 0) + 1;
            $days[substr((string) $a['meeting_date'], 0, 10)] = true;
        }
        $docTypes = [];
        foreach ($docs as $d) {
            $docTypes[$d['doc_type']] = ($docTypes[$d['doc_type']] ?? 0) + 1;
            $days[substr((string) $d['date'], 0, 10)] = true;
        }
        foreach (Historico::forConversa($id) as $h) {
            $days[substr((string) $h['date'], 0, 10)] = true;
        }
        return [
            'start' => $start, 'end' => $end, 'total' => max(0, $end - $start), 'segments' => $segments,
            'in' => $in, 'out' => $out, 'firstMsg' => $firstMsg, 'lastMsg' => $lastMsg,
            'firstResponse' => ($firstIn !== null && $firstOut !== null) ? $firstOut - $firstIn : null,
            'atas' => $atas, 'kinds' => $kinds, 'docs' => $docs, 'docTypes' => $docTypes,
            'contactDays' => count($days), 'ciclo' => $c->cicloInfo(),
        ];
    }

    /** ", com 12 mensagens, 2 reuniões e 3 documentos" (só o que existiu). */
    private static function touchpoints(array $d, int $meetings): string
    {
        $parts = array_filter([
            $d['in'] + $d['out'] ? ($d['in'] + $d['out']) . ' mensagem(ns) no WhatsApp' : '',
            $meetings ? "$meetings reunião(ões)" : '',
            $d['docs'] ? count($d['docs']) . ' documento(s)' : '',
        ]);
        return $parts ? ', com ' . implode(', ', $parts) : '';
    }

    public static function negociacao(Conversa $c): void
    {
        $f = $c->fields;
        $d = self::negotiationData($c);
        $title = Conversa::title($f);
        $status = (int) $f['status'];
        [$label, $color] = match ($status) {
            Conversa::WON  => ['Venda fechada', self::GREEN],
            Conversa::LOST => ['Oportunidade perdida', self::RED],
            default        => ['Em negociação', self::BLUE],
        };
        $etapas = Etapa::all();
        $meetings = count($d['atas']);

        $pdf = self::newDoc('RELATÓRIO DA NEGOCIAÇÃO', $title, Conversa::code((int) $f['id']) . ' · ' . $label,
            'Cliente ' . (Conversa::CLIENT_TYPES[$f['client_type']][0] ?? '') . ' · entrada por ' . Conversa::sourceLabel($f['source']) . ' em ' . date('d/m/Y', $d['start']) . ($f['date_closed'] ? ' · encerrada em ' . date('d/m/Y', $d['end']) : ''),
            self::footer());
        $y = RelatorioDoc::COVER_TOP;

        $phrase = match ($status) {
            Conversa::WON  => "$title fechou após " . self::dur($d['total']) . ' de relacionamento comercial'
                . self::touchpoints($d, $meetings) . '.',
            Conversa::LOST => "$title foi perdida após " . self::dur($d['total']) . " de negociação" . ($f['lost_reason'] ? ': ' . $f['lost_reason'] . '.' : '.'),
            default        => "$title está em negociação há " . self::dur($d['total']) . ", na etapa " . ($etapas[(int) $f['plugin_crm_etapas_id']]['name'] ?? '—') . '.',
        };
        $y = $pdf->banner($y, $label, $phrase, $color);

        // indicadores
        $cw = 43.5;
        $gap = 2.7;
        $cards = [
            ['Valor da negociação', self::brlShort((float) $f['value']), Conversa::NEGOTIATION[$f['negotiation_type']] ?? '', self::GREEN],
            ['Tempo com o cliente', self::dur($d['total']), date('d/m/Y', $d['start']) . ' a ' . date('d/m/Y', $d['end']), self::BLUE],
            ['Dias com contato', (string) $d['contactDays'], 'dias com alguma interação', self::PURPLE],
            ['1ª resposta ao cliente', $d['firstResponse'] !== null ? self::dur($d['firstResponse']) : '—', 'tempo até o primeiro retorno', self::AMBER],
            ['Mensagens', (string) ($d['in'] + $d['out']), "{$d['in']} do cliente · {$d['out']} do comercial", self::BLUE],
            ['Reuniões', (string) $meetings, $meetings ? 'com ata registrada' : 'nenhuma ata registrada', self::PURPLE],
            ['Documentos', (string) count($d['docs']), 'anexados à negociação', self::AMBER],
            ['Probabilidade final', ($status === Conversa::WON ? 100 : ($status === Conversa::LOST ? 0 : (int) ($etapas[(int) $f['plugin_crm_etapas_id']]['probability'] ?? 0))) . '%', 'chance de fechar', self::GREEN],
        ];
        foreach ($cards as $i => [$l, $v, $s, $col]) {
            $pdf->card(RelatorioDoc::X + ($i % 4) * ($cw + $gap), $y + intdiv($i, 4) * 27, $cw, 24, $l, $v, mb_strimwidth($s, 0, 30, '…'), $col);
        }
        $y += 58;

        // cliente e pedido
        $y = $pdf->section($y, 'Cliente e pedido');
        $rows = [
            ['Empresa / órgão', self::e($f['company'] ?: '—')], ['Tipo de cliente', self::e(Conversa::CLIENT_TYPES[$f['client_type']][0] ?? '')],
            ['Contato', self::e(Conversa::displayName($f))], ['E-mail', self::e($f['email'] ?: '—')], ['Telefone / WhatsApp', self::e($f['numero_wa'] ?: '—')], ['CNPJ', self::e($f['cnpj'] ?: '—')],
            ['Assunto', self::e($f['subject'] ?: '—')], ['Como foi contratado', self::e(Conversa::NEGOTIATION[$f['negotiation_type']] ?? '—')],
            ['Atendente responsável', self::e(Conversa::attendantName((int) $f['users_id_attendant']))],
        ];
        if ($f['client_type'] === 'publico') {
            $rows[] = ['Edital', self::e($f['edital_number'] ?: '—')];
            $rows[] = ['Sessão / pregão', self::e($f['session_date'] ? date('d/m/Y', strtotime($f['session_date'])) : '—')];
        }
        if ($d['ciclo']) {
            $rows[] = ['Ciclo financeiro', self::e($d['ciclo']['code'] . ' · ' . $d['ciclo']['status'])];
        }
        $y = $pdf->table($y, ['Campo', 'Informação'], $rows, [28, 72], ['L', 'L']);

        // tempo por etapa
        $y = $pdf->section($y + 2, 'Tempo com o cliente, etapa por etapa', 'Quanto tempo a oportunidade ficou em cada fase da esteira de venda');
        $max = max(1, ...array_column($d['segments'], 'seconds'));
        $bars = [];
        foreach ($d['segments'] as $i => $s) {
            $e = $etapas[$s['etapa']] ?? ['name' => 'Etapa removida', 'color' => '#64748b'];
            $bars[] = [($i + 1) . '. ' . $e['name'], $s['seconds'], self::dur($s['seconds']) . ' · ' . ($d['total'] ? round($s['seconds'] / $d['total'] * 100) : 0) . '%', $e['color']];
        }
        $y = $pdf->hbars(RelatorioDoc::X, $y, RelatorioDoc::W, $bars, $max, 58, 7.2, false, 38) + 2;
        $y = $pdf->paragraph($y, '<b>Entrada:</b> ' . date('d/m/Y H:i', $d['start']) . ' &nbsp;·&nbsp; <b>' . ($f['date_closed'] ? 'Encerramento' : 'Hoje') . ':</b> ' . date('d/m/Y H:i', $d['end'])
            . ' &nbsp;·&nbsp; <b>Total:</b> ' . self::dur($d['total']), 8.5) + 3;

        // canais
        $y = $pdf->section($y, 'Canais e pontos de contato', 'Por onde a negociação aconteceu');
        $rows = [[self::e(Conversa::sourceLabel($f['source'])), 'Canal de entrada', 'Primeiro contato em ' . date('d/m/Y', $d['start'])]];
        if ($d['in'] + $d['out']) {
            $rows[] = ['WhatsApp', ($d['in'] + $d['out']) . ' mensagens', "{$d['in']} do cliente e {$d['out']} do comercial, de " . date('d/m', (int) $d['firstMsg']) . ' a ' . date('d/m', (int) $d['lastMsg'])];
        }
        foreach ($d['kinds'] as $k => $n) {
            $rows[] = ['Reunião · ' . self::e(Ata::KINDS[$k][0] ?? $k), "$n reunião(ões)", 'Com ata registrada'];
        }
        foreach ($d['docTypes'] as $k => $n) {
            $rows[] = ['Documento · ' . self::e(Documento::TYPES[$k] ?? $k), "$n arquivo(s)", 'Anexado à ficha'];
        }
        $y = $pdf->table($y, ['Canal', 'Volume', 'Detalhe'], $rows, [34, 24, 42], ['L', 'L', 'L']);

        // reuniões e atas
        $y = $pdf->section($y + 2, 'Reuniões e atas', $meetings ? "$meetings reunião(ões) registrada(s) pelo comercial" : 'Nenhuma ata de reunião registrada');
        if ($d['atas']) {
            $rows = [];
            foreach (array_reverse($d['atas']) as $a) {
                $rows[] = [date('d/m/Y H:i', strtotime((string) $a['meeting_date'])), self::e(Ata::KINDS[$a['kind']][0] ?? ''), '<b>' . self::e($a['title']) . '</b><br>' . self::e($a['participants']),
                    self::e(mb_strimwidth(trim($a['summary'] . ($a['decisions'] ? ' | Decisões: ' . $a['decisions'] : '')), 0, 420, '…')), (int) $a['plugin_crm_documentos_id'] ? 'Sim' : '—'];
            }
            $y = $pdf->table($y, ['Data', 'Tipo', 'Reunião', 'Resumo e decisões', 'Arquivo'], $rows, [13, 12, 25, 44, 6], ['L', 'L', 'L', 'L', 'C']);
        }

        // documentos
        if ($d['docs']) {
            $y = $pdf->section($y + 2, 'Documentos da negociação');
            $rows = [];
            foreach (array_reverse($d['docs']) as $doc) {
                $rows[] = [self::e(Documento::TYPES[$doc['doc_type']] ?? 'Outros'), self::e($doc['name']), self::e(Documento::humanSize((int) $doc['size'])), date('d/m/Y', strtotime((string) $doc['date']))];
            }
            $y = $pdf->table($y, ['Tipo', 'Arquivo', 'Tamanho', 'Anexado em'], $rows, [24, 52, 12, 12], ['L', 'L', 'R', 'L']);
        }

        // linha do tempo
        $y = $pdf->section($y + 2, 'Linha do tempo');
        $events = [[$d['start'], 'Entrada registrada', Conversa::sourceLabel($f['source']) . ($f['subject'] ? ' · ' . $f['subject'] : '')]];
        foreach (Historico::forConversa((int) $f['id']) as $i => $h) {
            if ($i === 0 && !(int) $h['from_etapas_id']) {
                continue;
            }
            $events[] = [(int) strtotime((string) $h['date']), 'Etapa: ' . ($etapas[(int) $h['plugin_crm_etapas_id']]['name'] ?? '—'), (int) $h['users_id'] ? getUserName((int) $h['users_id']) : ''];
        }
        foreach ($d['atas'] as $a) {
            $events[] = [(int) strtotime((string) $a['meeting_date']), 'Reunião: ' . $a['title'], Ata::KINDS[$a['kind']][0] ?? ''];
        }
        foreach ($d['docs'] as $doc) {
            $events[] = [(int) strtotime((string) $doc['date']), 'Documento: ' . (Documento::TYPES[$doc['doc_type']] ?? 'Outros'), $doc['name']];
        }
        if ($f['date_closed']) {
            $events[] = [$d['end'], $status === Conversa::WON ? 'Venda fechada' : 'Oportunidade perdida', $status === Conversa::LOST ? (string) $f['lost_reason'] : self::brl((float) $f['value'])];
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
        $rows = array_map(fn ($ev) => [date('d/m/Y H:i', $ev[0]), '<b>' . self::e($ev[1]) . '</b>', self::e($ev[2])], $events);
        $pdf->table($y, ['Quando', 'Evento', 'Detalhe'], $rows, [16, 40, 44], ['L', 'L', 'L']);

        self::send($pdf, 'CRM-negociacao-' . Conversa::code((int) $f['id']) . '.pdf');
    }

    // ============================================================ executivo

    /** Indicadores do período para o relatório executivo (as mesmas regras do dashboard). */
    public static function metrics(int $dias): array
    {
        global $DB;
        $from = strtotime("-$dias days", strtotime('today'));
        $prevFrom = strtotime("-$dias days", $from);
        $t = Conversa::getTable();
        $rows = iterator_to_array($DB->request(['FROM' => $t, 'WHERE' => Conversa::scope($t), 'LIMIT' => 5000]), false);
        $etapas = Etapa::all();
        $docs = Documento::summary();
        $ts = fn (?string $s): int => $s ? (int) strtotime($s) : 0;

        $open = $won = $lost = $prevWon = $prevLost = $created = [];
        $leads = $prevLeads = 0;
        foreach ($rows as $r) {
            $c = $ts($r['date_creation']);
            $x = $ts($r['date_closed']);
            if ($c >= $from) {
                $leads++;
                $created[] = $r;
            } elseif ($c >= $prevFrom) {
                $prevLeads++;
            }
            switch ((int) $r['status']) {
                case Conversa::OPEN:
                    $open[] = $r;
                    break;
                case Conversa::WON:
                    $x >= $from ? $won[] = $r : ($x >= $prevFrom ? $prevWon[] = $r : null);
                    break;
                case Conversa::LOST:
                    $x >= $from ? $lost[] = $r : ($x >= $prevFrom ? $prevLost[] = $r : null);
                    break;
            }
        }
        $sum = fn (array $l): float => array_sum(array_map(fn ($r) => (float) $r['value'], $l));
        $closed = count($won) + count($lost);
        $prevClosed = count($prevWon) + count($prevLost);

        $stages = [];
        foreach ($etapas as $id => $e) {
            $stages[$id] = $e + ['n' => 0, 'value' => 0.0];
        }
        $pipeline = $weighted = 0.0;
        $noAtt = $waiting = $editais = 0;
        $hot = [];
        foreach ($open as $r) {
            $id = (int) $r['plugin_crm_etapas_id'];
            $v = (float) $r['value'];
            $pipeline += $v;
            $prob = (int) ($etapas[$id]['probability'] ?? 0);
            $weighted += $v * $prob / 100;
            if (isset($stages[$id])) {
                $stages[$id]['n']++;
                $stages[$id]['value'] += $v;
            }
            $last = Conversa::lastMessageRow((int) $r['id']);
            $isWaiting = $last && $last['direction'] === 'in';
            $noAtt += !(int) $r['users_id_attendant'] ? 1 : 0;
            $waiting += $isWaiting ? 1 : 0;
            if ($r['client_type'] === 'publico' && empty($docs[(int) $r['id']]['edital'])) {
                $editais++;
            }
            $hot[] = ['r' => $r, 'prob' => $prob, 'expected' => $v * $prob / 100, 'waiting' => $isWaiting];
        }
        usort($hot, fn ($a, $b) => $b['expected'] <=> $a['expected']);

        $byChannel = [];
        $byType = ['privado' => ['leads' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0], 'publico' => ['leads' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0]];
        foreach ($created as $r) {
            $ty = $r['client_type'] === 'publico' ? 'publico' : 'privado';
            $byChannel[$r['source']][$ty] = ($byChannel[$r['source']][$ty] ?? 0) + 1;
            $byType[$ty]['leads']++;
        }
        foreach ($won as $r) {
            $ty = $r['client_type'] === 'publico' ? 'publico' : 'privado';
            $byType[$ty]['won']++;
            $byType[$ty]['value'] += (float) $r['value'];
        }
        foreach ($lost as $r) {
            $byType[$r['client_type'] === 'publico' ? 'publico' : 'privado']['lost']++;
        }
        uasort($byChannel, fn ($a, $b) => array_sum($b) <=> array_sum($a));

        $reasons = [];
        foreach ($lost as $r) {
            $k = trim((string) $r['lost_reason']) ?: 'Sem motivo informado';
            $reasons[$k] = ($reasons[$k] ?? 0) + 1;
        }
        arsort($reasons);

        $rank = [];
        foreach ($rows as $r) {
            $rank[(int) $r['users_id_attendant']] ??= ['open' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0];
        }
        foreach ($open as $r) {
            $rank[(int) $r['users_id_attendant']]['open']++;
        }
        foreach ($won as $r) {
            $rank[(int) $r['users_id_attendant']]['won']++;
            $rank[(int) $r['users_id_attendant']]['value'] += (float) $r['value'];
        }
        foreach ($lost as $r) {
            $rank[(int) $r['users_id_attendant']]['lost']++;
        }
        uasort($rank, fn ($a, $b) => [$b['value'], $b['won']] <=> [$a['value'], $a['won']]);

        // semanas do período (leads e vendas)
        $weeks = [];
        $nWeeks = max(1, (int) ceil($dias / 7));
        for ($i = 0; $i < $nWeeks; $i++) {
            $ws = strtotime('+' . ($i * 7) . ' days', $from);
            $weeks[] = ['label' => date('d/m', $ws), 'start' => $ws, 'end' => strtotime('+7 days', $ws), 'leads' => 0, 'won' => 0];
        }
        foreach ($created as $r) {
            foreach ($weeks as $i => $w) {
                if ($ts($r['date_creation']) >= $w['start'] && $ts($r['date_creation']) < $w['end']) {
                    $weeks[$i]['leads']++;
                }
            }
        }
        foreach ($won as $r) {
            foreach ($weeks as $i => $w) {
                if ($ts($r['date_closed']) >= $w['start'] && $ts($r['date_closed']) < $w['end']) {
                    $weeks[$i]['won']++;
                }
            }
        }

        $cycle = array_map(fn ($r) => max(0, $ts($r['date_closed']) - $ts($r['date_creation'])), $won);
        return [
            'dias' => $dias, 'from' => $from, 'open' => $open, 'won' => $won, 'lost' => $lost, 'leads' => $leads, 'prevLeads' => $prevLeads,
            'wonValue' => $sum($won), 'prevWonValue' => $sum($prevWon), 'prevWonN' => count($prevWon),
            'rate' => $closed ? count($won) / $closed * 100 : 0.0, 'prevRate' => $prevClosed ? count($prevWon) / $prevClosed * 100 : 0.0,
            'ticket' => $won ? $sum($won) / count($won) : 0.0, 'cycleAvg' => $cycle ? (int) round(array_sum($cycle) / count($cycle)) : 0,
            'pipeline' => $pipeline, 'weighted' => $weighted, 'noAtt' => $noAtt, 'waiting' => $waiting, 'editais' => $editais,
            'stages' => $stages, 'byChannel' => $byChannel, 'byType' => $byType, 'reasons' => $reasons, 'rank' => $rank, 'weeks' => $weeks, 'hot' => $hot,
        ];
    }

    private static function deltaText(float $cur, float $prev): array
    {
        if ($prev == 0.0) {
            return $cur > 0 ? ['novo no período', self::GREEN] : ['', '#64748b'];
        }
        $pct = (int) round(($cur - $prev) / $prev * 100);
        return [($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs. período anterior', $pct >= 0 ? self::GREEN : self::RED];
    }

    public static function executivo(int $dias): void
    {
        $dias = in_array($dias, [7, 30, 90], true) ? $dias : 30;
        $m = self::metrics($dias);
        $pdf = self::newDoc('RELATÓRIO EXECUTIVO · CRM', 'Funil de vendas', 'Últimos ' . $dias . ' dias',
            date('d/m/Y', $m['from']) . ' a ' . date('d/m/Y') . ' · ' . count($m['open']) . ' oportunidades abertas · ' . count($m['won']) . ' vendas e ' . count($m['lost']) . ' perdas no período', self::footer());
        $y = RelatorioDoc::COVER_TOP;

        $y = $pdf->banner($y, 'Resultado do período',
            'Vendas de ' . self::brl0($m['wonValue']) . ' (' . count($m['won']) . ' fechadas), conversão de ' . round($m['rate']) . '% e pipeline de ' . self::brl0($m['pipeline'])
            . ', com previsão ponderada de ' . self::brl0($m['weighted']) . '.', self::BLUE);

        // indicadores
        $cw = 43.5;
        $gap = 2.7;
        [$dWon, $cWon] = self::deltaText($m['wonValue'], $m['prevWonValue']);
        [$dLeads, $cLeads] = self::deltaText($m['leads'], $m['prevLeads']);
        [$dRate, $cRate] = self::deltaText($m['rate'], $m['prevRate']);
        $cards = [
            ['Pipeline em aberto', self::brlShort($m['pipeline']), count($m['open']) . ' oportunidades', self::BLUE, '', '#64748b'],
            ['Previsão ponderada', self::brlShort($m['weighted']), 'valor × probabilidade', self::PURPLE, '', '#64748b'],
            ['Vendas no período', self::brlShort($m['wonValue']), count($m['won']) . ' fechadas', self::GREEN, $dWon, $cWon],
            ['Novos leads', (string) $m['leads'], 'entradas no período', '#0ea5e9', $dLeads, $cLeads],
            ['Taxa de conversão', round($m['rate']) . '%', count($m['won']) . ' vendas · ' . count($m['lost']) . ' perdas', '#0d9488', $dRate, $cRate],
            ['Ticket médio', self::brlShort($m['ticket']), 'por venda fechada', self::AMBER, '', '#64748b'],
            ['Ciclo comercial médio', self::dur($m['cycleAvg']), 'da entrada ao fechamento', '#6366f1', '', '#64748b'],
            ['Aguardando resposta', (string) $m['waiting'], $m['noAtt'] . ' sem atendente', self::RED, '', '#64748b'],
        ];
        foreach ($cards as $i => [$l, $v, $s, $col, $dt, $dc]) {
            $pdf->card(RelatorioDoc::X + ($i % 4) * ($cw + $gap), $y + intdiv($i, 4) * 29, $cw, 26, $l, $v, mb_strimwidth($s, 0, 32, '…'), $col, $dt, $dc);
        }
        $y += 62;

        // funil
        $y = $pdf->section($y, 'Funil de vendas', 'Oportunidades abertas por etapa da esteira');
        $maxN = max(1, ...array_values(array_column($m['stages'] ?: [['n' => 1]], 'n')));
        $bars = [];
        foreach ($m['stages'] as $s) {
            $bars[] = [$s['name'] . ' (' . $s['probability'] . '%)', $s['n'], $s['n'] . ' · ' . self::brl0($s['value']), $s['color']];
        }
        $y = $pdf->hbars(RelatorioDoc::X, $y, RelatorioDoc::W, $bars, $maxN, 58, 7.4, false, 40) + 3;

        // entrada de clientes
        $y = $pdf->section($y, 'Entrada de clientes', 'Leads por semana e por canal, com o tipo de cliente (privado ou público)');
        $y = $pdf->ensure($y, 48);
        $pdf->columns(RelatorioDoc::X, $y, 88, 42, array_map(fn ($w) => [$w['label'], $w['leads'], self::BLUE], $m['weeks']));
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->ink('#64748b');
        $pdf->SetXY(RelatorioDoc::X + 96, $y);
        $pdf->Cell(86, 4, 'POR TIPO DE CLIENTE', 0, 2, 'L');
        $ty = $y + 6;
        foreach ($m['byType'] as $k => $t) {
            $col = $k === 'publico' ? self::PURPLE : self::BLUE;
            $pdf->card(RelatorioDoc::X + 96, $ty, 86, 17, ($k === 'publico' ? 'Público' : 'Privado') . ' · ' . $t['leads'] . ' entradas', self::brlShort($t['value']), $t['won'] . ' vendas · ' . $t['lost'] . ' perdas', $col);
            $ty += 19.5;
        }
        $y += 46;
        $y = $pdf->legend(RelatorioDoc::X, $y, [['Privado', self::BLUE], ['Público', self::PURPLE]]);
        $maxCh = max(1, ...array_values(array_map('array_sum', $m['byChannel'] ?: [[1]])));
        foreach ($m['byChannel'] as $source => $by) {
            $y = $pdf->ensure($y, 7);
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->ink(RelatorioDoc::INK);
            $pdf->SetXY(RelatorioDoc::X, $y);
            $pdf->Cell(52, 5.5, Conversa::sourceLabel($source), 0, 0, 'L');
            $total = array_sum($by);
            $pdf->stacked(RelatorioDoc::X + 54, $y + 1.2, 110 * $total / $maxCh, 3.6, [[$by['privado'] ?? 0, self::BLUE], [$by['publico'] ?? 0, self::PURPLE]]);
            $pdf->SetFont('dejavusans', 'B', 7.5);
            $pdf->SetXY(RelatorioDoc::X + 166, $y);
            $pdf->Cell(16, 5.5, (string) $total, 0, 0, 'L');
            $y += 7;
        }
        $y += 3;

        // resultado e perdas
        $y = $pdf->section($y, 'Resultado e motivos de perda');
        $rows = [];
        foreach (array_slice($m['reasons'], 0, 6, true) as $reason => $n) {
            $rows[] = [$reason, $n, $n . ' (' . round($n / max(1, count($m['lost'])) * 100) . '%)', self::RED];
        }
        $y = $m['reasons'] ? $pdf->hbars(RelatorioDoc::X, $y, RelatorioDoc::W, $rows, max(1, ...array_values($m['reasons'])), 70, 7, false, 26) + 3 : $pdf->paragraph($y, 'Nenhuma perda no período.') + 3;

        // ranking
        $y = $pdf->section($y, 'Ranking de atendentes', 'Vendas no período');
        $rows = [];
        foreach ($m['rank'] as $uid => $r) {
            $rows[] = [self::e($uid ? Conversa::attendantName($uid) : 'Sem atendente'), (string) $r['open'], (string) $r['won'], (string) $r['lost'], self::brl0($r['value'])];
        }
        $y = $pdf->table($y, ['Atendente', 'Abertas', 'Vendas', 'Perdidas', 'Valor vendido'], $rows ?: [['—', '', '', '', '']], [36, 14, 14, 14, 22], ['L', 'R', 'R', 'R', 'R']) + 2;

        // oportunidades quentes
        $y = $pdf->section($y, 'Oportunidades com mais chance de venda', 'Maior valor esperado (valor × probabilidade da etapa)');
        $rows = [];
        foreach (array_slice($m['hot'], 0, 8) as $h) {
            $r = $h['r'];
            $flags = array_filter([$h['waiting'] ? 'cliente aguardando' : '', !(int) $r['users_id_attendant'] ? 'sem atendente' : '']);
            $rows[] = ['<b>' . self::e(Conversa::title($r)) . '</b><br>' . self::e($r['subject']), self::e(Etapa::all()[(int) $r['plugin_crm_etapas_id']]['name'] ?? '—'), $h['prob'] . '%', self::brl0((float) $r['value']),
                self::brl0($h['expected']), self::e(implode(' · ', $flags) ?: Conversa::attendantName((int) $r['users_id_attendant']))];
        }
        $y = $pdf->table($y, ['Oportunidade', 'Etapa', 'Prob.', 'Valor', 'Esperado', 'Atenção'], $rows ?: [['—', '', '', '', '', '']], [32, 16, 8, 14, 14, 16], ['L', 'L', 'R', 'R', 'R', 'L']) + 2;

        // vendas do período
        $y = $pdf->section($y, 'Vendas do período', 'Com o tempo de relacionamento até o fechamento');
        $rows = [];
        foreach ($m['won'] as $r) {
            $rows[] = ['<b>' . self::e(Conversa::title($r)) . '</b>', self::e(Conversa::CLIENT_TYPES[$r['client_type']][0] ?? ''), self::e(Conversa::sourceLabel($r['source'])),
                self::brl0((float) $r['value']), self::dur(max(0, (int) strtotime((string) $r['date_closed']) - (int) strtotime((string) $r['date_creation']))), date('d/m/Y', strtotime((string) $r['date_closed']))];
        }
        $y = $pdf->table($y, ['Cliente', 'Tipo', 'Canal', 'Valor', 'Ciclo comercial', 'Fechamento'], $rows ?: [['Nenhuma venda no período', '', '', '', '', '']], [30, 12, 20, 14, 14, 10], ['L', 'L', 'L', 'R', 'R', 'R']) + 2;

        // pontos de atenção
        $y = $pdf->section($y, 'Pontos de atenção');
        $lines = [
            "<b>{$m['waiting']}</b> oportunidade(s) com o cliente aguardando resposta.",
            "<b>{$m['noAtt']}</b> oportunidade(s) abertas sem atendente.",
            "<b>{$m['editais']}</b> cliente(s) público(s) com o edital pendente.",
        ];
        $pdf->bullets($y, $lines);

        self::send($pdf, 'CRM-relatorio-executivo-' . date('Y-m-d') . '.pdf');
    }

    // ============================================================ csv

    /** O funil (oportunidades abertas) em planilha, com ponto e vírgula e acentos para o Excel. */
    public static function csv(): void
    {
        global $DB;
        $t = Conversa::getTable();
        $docs = Documento::summary();
        $etapas = Etapa::all();
        $cli = PHP_SAPI === 'cli';
        if (!$cli) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="CRM-funil-' . date('Y-m-d') . '.csv"');
        }
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Código', 'Empresa', 'Contato', 'E-mail', 'Telefone', 'Tipo de cliente', 'Canal', 'Etapa', 'Probabilidade (%)', 'Valor (R$)', 'Valor esperado (R$)', 'Atendente', 'Assunto', 'Documentos', 'Entrada', 'Última atividade'], ';');
        foreach ($DB->request(['FROM' => $t, 'WHERE' => ['status' => Conversa::OPEN] + Conversa::scope($t), 'ORDER' => ['plugin_crm_etapas_id', 'id'], 'LIMIT' => 5000]) as $r) {
            $prob = (int) ($etapas[(int) $r['plugin_crm_etapas_id']]['probability'] ?? 0);
            fputcsv($out, [
                Conversa::code((int) $r['id']), Conversa::title($r), Conversa::displayName($r), $r['email'], $r['numero_wa'], Conversa::CLIENT_TYPES[$r['client_type']][0] ?? '', Conversa::sourceLabel($r['source']),
                $etapas[(int) $r['plugin_crm_etapas_id']]['name'] ?? '', $prob, number_format((float) $r['value'], 2, ',', ''), number_format((float) $r['value'] * $prob / 100, 2, ',', ''),
                Conversa::attendantName((int) $r['users_id_attendant']), $r['subject'], $docs[(int) $r['id']]['n'] ?? 0, $r['date_creation'], $r['last_message_at'],
            ], ';');
        }
        fclose($out);
        if (!($cli && self::$capture)) {
            exit;
        }
    }
}
