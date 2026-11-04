<?php

namespace GlpiPlugin\Crm;

use CommonGLPI;

/**
 * Dashboard do CRM: pipeline e previsão, vendas e conversão no período (com
 * comparação com o período anterior), funil, entrada de clientes por dia e tipo,
 * resultado, canais, ranking de atendentes e atividade recente. Cartões, barras e
 * fatias abrem a gaveta lateral com as oportunidades por trás do número, e ao entrar
 * um pop-up destaca as oportunidades com mais chance de venda (public/js/dashboard.js).
 */
class Dashboard extends CommonGLPI
{
    public const PERIODS = [7 => '7 dias', 30 => '30 dias', 90 => '90 dias'];

    public static function getTypeName($nb = 0)
    {
        return 'Dashboard CRM';
    }

    public static function canView(): bool
    {
        return Conversa::canView();
    }

    private static function brl(float $v): string
    {
        return 'R$ ' . number_format($v, 0, ',', '.');
    }

    /** Selo de variação em relação ao período anterior. */
    private static function delta(float $cur, float $prev, bool $upIsGood = true): string
    {
        if ($prev == 0.0) {
            return $cur > 0 ? "<span class='crm-delta up'><i class='ti ti-sparkles'></i> novo</span>" : '';
        }
        $pct = round(($cur - $prev) / $prev * 100);
        if ($pct === 0.0) {
            return "<span class='crm-delta'>= igual</span>";
        }
        $good = ($pct > 0) === $upIsGood;
        return "<span class='crm-delta " . ($good ? 'up' : 'down') . "'><i class='ti " . ($pct > 0 ? 'ti-arrow-up-right' : 'ti-arrow-down-right') . "'></i> " . abs($pct) . "%</span>";
    }

    /** Minigráfico de tendência dentro do cartão. */
    private static function spark(array $values, string $color): string
    {
        $n = count($values);
        if ($n < 2 || max($values) <= 0) {
            return '';
        }
        $w = 120;
        $h = 34;
        $max = max($values);
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($i / ($n - 1) * $w, 1) . ',' . round($h - 3 - ($h - 8) * $v / $max, 1);
        }
        $id = 'sp' . substr(md5($color . implode(',', $values)), 0, 6);
        return "<svg class='crm-spark' viewBox='0 0 $w $h' preserveAspectRatio='none' aria-hidden='true'><defs><linearGradient id='$id' x1='0' y1='0' x2='0' y2='1'>"
            . "<stop offset='0' stop-color='$color' stop-opacity='.35'/><stop offset='1' stop-color='$color' stop-opacity='0'/></linearGradient></defs>"
            . "<polygon points='0,$h " . implode(' ', $pts) . " $w,$h' fill='url(#$id)'/><polyline points='" . implode(' ', $pts) . "' fill='none' stroke='$color' stroke-width='2' stroke-linejoin='round' stroke-linecap='round'/></svg>";
    }

    private static function kpi(string $list, string $label, string $value, string $icon, string $color, string $foot, string $spark = ''): string
    {
        return "<div class='col-sm-6 col-xl-3'><div class='card crm-kpi oak-kpi-click' role='button' tabindex='0' data-list='$list' style='--c:$color'><div class='card-body'>"
            . "<div class='d-flex justify-content-between align-items-start'><div class='text-secondary small fw-bold text-uppercase'>" . Menu::e($label) . "</div>"
            . "<div class='crm-kpi-icon' style='--c:$color'><i class='ti $icon'></i></div></div>"
            . "<div class='d-flex align-items-end justify-content-between gap-2'><div class='crm-kpi-value'>" . Menu::e($value) . "</div>$spark</div>"
            . "<div class='crm-kpi-foot'>$foot</div></div></div></div>";
    }

    /** Barras por dia: leads (privado e público empilhados) ao lado das vendas, com faixa de destaque e dica por dia. */
    private static function barsChart(array $days, array $priv, array $pub, array $won, array $wonValue): string
    {
        $w = 960;
        $h = 300;
        $padL = 38;
        $padB = 28;
        $padT = 14;
        $totals = array_map(fn ($a, $b) => $a + $b, $priv, $pub);
        $max = max(1, max($totals ?: [0]), max($won ?: [0]));
        $step = max(1, (int) ceil($max / 4));
        $top = $step * 4;
        $n = count($days);
        $slot = ($w - $padL - 10) / max(1, $n);
        $bw = max(3, min(18, $slot * .34));
        $plotH = $h - $padT - $padB;
        $base = $padT + $plotH;
        $svg = "<svg viewBox='0 0 $w $h' class='crm-chart' role='img' aria-label='Entrada de clientes por tipo e vendas por dia'><defs>"
            . "<linearGradient id='g-priv' x1='0' y1='0' x2='0' y2='1'><stop offset='0' stop-color='#60a5fa'/><stop offset='1' stop-color='#2563eb'/></linearGradient>"
            . "<linearGradient id='g-pub' x1='0' y1='0' x2='0' y2='1'><stop offset='0' stop-color='#c084fc'/><stop offset='1' stop-color='#9333ea'/></linearGradient>"
            . "<linearGradient id='g-won' x1='0' y1='0' x2='0' y2='1'><stop offset='0' stop-color='#4ade80'/><stop offset='1' stop-color='#16a34a'/></linearGradient></defs>";
        for ($i = 0; $i <= 4; $i++) {
            $y = $padT + $plotH - ($plotH * $i / 4);
            $svg .= "<line x1='$padL' x2='" . ($w - 6) . "' y1='$y' y2='$y' class='crm-grid'/><text x='" . ($padL - 8) . "' y='" . ($y + 4) . "' text-anchor='end' class='crm-axis'>" . ($step * $i) . "</text>";
        }
        foreach ($days as $i => $d) {
            $x = $padL + $slot * $i + $slot / 2;
            $label = date('d/m', strtotime($d));
            $tip = Menu::e("$label|{$priv[$i]} lead(s) privado(s)|{$pub[$i]} lead(s) público(s)|{$won[$i]} venda(s)" . ($wonValue[$i] > 0 ? ' · ' . self::brl($wonValue[$i]) : ''));
            // faixa de destaque do dia (recebe o mouse e mostra a dica)
            $svg .= "<g class='crm-daycol' data-tip='$tip'><rect class='crm-dayband' x='" . round($padL + $slot * $i, 1) . "' y='$padT' width='" . round($slot, 1) . "' height='$plotH' rx='4'/>";
            $hp = $plotH * $priv[$i] / $top;
            $hu = $plotH * $pub[$i] / $top;
            $bh = $plotH * $won[$i] / $top;
            if ($priv[$i]) {
                $svg .= "<rect class='crm-bar' x='" . round($x - $bw - 1, 1) . "' y='" . round($base - $hp, 1) . "' width='" . round($bw, 1) . "' height='" . round($hp, 1) . "' fill='url(#g-priv)'/>";
            }
            if ($pub[$i]) {
                $svg .= "<rect class='crm-bar' x='" . round($x - $bw - 1, 1) . "' y='" . round($base - $hp - $hu, 1) . "' width='" . round($bw, 1) . "' height='" . round($hu, 1) . "' rx='3' fill='url(#g-pub)'/>";
            }
            if ($won[$i]) {
                $svg .= "<rect class='crm-bar' x='" . round($x + 1, 1) . "' y='" . round($base - $bh, 1) . "' width='" . round($bw, 1) . "' height='" . round($bh, 1) . "' rx='3' fill='url(#g-won)'/>";
            }
            $svg .= "</g>";
            if ($n <= 14 || $i % (int) ceil($n / 10) === 0) {
                $svg .= "<text x='" . round($x, 1) . "' y='" . ($h - 8) . "' text-anchor='middle' class='crm-axis'>$label</text>";
            }
        }
        return $svg . "</svg>";
    }

    /** Rosca com fatias que respondem ao mouse e total no centro. */
    private static function donut(array $parts): string
    {
        $total = array_sum(array_column($parts, 0));
        $svg = "<svg viewBox='0 0 42 42' class='crm-donut' role='img'><circle cx='21' cy='21' r='15.9155' fill='none' class='crm-donut-bg' stroke-width='5'/>";
        $offset = 25;
        foreach ($parts as $p) {
            [$value, $color, $label] = $p;
            $pct = $total ? $value / $total * 100 : 0;
            if ($pct > 0) {
                $len = max(0, $pct - .6);
                $svg .= "<circle class='crm-slice' data-tip='" . Menu::e("$label|$value (" . round($pct) . "%)") . "' cx='21' cy='21' r='15.9155' fill='none' stroke='$color' stroke-width='5' stroke-dasharray='" . round($len, 2) . " " . round(100 - $len, 2) . "' stroke-dashoffset='" . round($offset, 2) . "'"
                    . (isset($p[3]) ? " data-list='" . Menu::e($p[3]) . "'" : '') . "/>";
            }
            $offset -= $pct;
        }
        return $svg . "<text x='21' y='21.4' text-anchor='middle' class='crm-donut-n'>$total</text><text x='21' y='26' text-anchor='middle' class='crm-donut-s'>total</text></svg>";
    }

    public static function show(): void
    {
        global $DB;
        $dias = (int) ($_GET['dias'] ?? 30);
        if (!isset(self::PERIODS[$dias])) {
            $dias = 30;
        }
        $now = time();
        $from = strtotime("-$dias days", strtotime('today'));
        $prevFrom = strtotime("-$dias days", $from);
        $ts = fn (?string $d): int => $d ? (int) strtotime($d) : 0;

        // uma consulta só: tudo que o painel precisa, calculado em PHP
        $t = Conversa::getTable();
        $rows = iterator_to_array($DB->request(['FROM' => $t, 'WHERE' => Conversa::scope($t), 'LIMIT' => 5000]), false);
        $etapas = Etapa::all();
        $docSummary = Documento::summary();

        $open = $won = $lost = $prevWon = $prevLost = $createdNow = [];
        $leads = $prevLeads = 0;
        foreach ($rows as $r) {
            $created = $ts($r['date_creation']);
            $closed = $ts($r['date_closed']);
            if ($created >= $from) {
                $leads++;
                $createdNow[] = $r;
            } elseif ($created >= $prevFrom) {
                $prevLeads++;
            }
            switch ((int) $r['status']) {
                case Conversa::OPEN:
                    $open[] = $r;
                    break;
                case Conversa::WON:
                    if ($closed >= $from) {
                        $won[] = $r;
                    } elseif ($closed >= $prevFrom) {
                        $prevWon[] = $r;
                    }
                    break;
                case Conversa::LOST:
                    if ($closed >= $from) {
                        $lost[] = $r;
                    } elseif ($closed >= $prevFrom) {
                        $prevLost[] = $r;
                    }
                    break;
            }
        }
        $sum = fn (array $list): float => array_sum(array_map(fn ($r) => (float) $r['value'], $list));
        $wonValue = $sum($won);
        $prevWonValue = $sum($prevWon);
        $closedN = count($won) + count($lost);
        $rate = $closedN ? count($won) / $closedN * 100 : 0.0;
        $prevClosed = count($prevWon) + count($prevLost);
        $prevRate = $prevClosed ? count($prevWon) / $prevClosed * 100 : 0.0;
        $ticket = $won ? $wonValue / count($won) : 0.0;
        $prevTicket = $prevWon ? $prevWonValue / count($prevWon) : 0.0;

        // pipeline por etapa, previsão ponderada, sem atendente e aguardando resposta
        $byStage = [];
        foreach ($etapas as $id => $e) {
            $byStage[$id] = $e + ['n' => 0, 'value' => 0.0];
        }
        $pipeline = $weighted = 0.0;
        $noAttendant = $waiting = 0;
        $lastById = [];
        foreach ($open as $r) {
            $id = (int) $r['plugin_crm_etapas_id'];
            $v = (float) $r['value'];
            $pipeline += $v;
            if (isset($byStage[$id])) {
                $byStage[$id]['n']++;
                $byStage[$id]['value'] += $v;
                $weighted += $v * $byStage[$id]['probability'] / 100;
            }
            if (!(int) $r['users_id_attendant']) {
                $noAttendant++;
            }
            $lastById[(int) $r['id']] = Conversa::lastMessageRow((int) $r['id']);
            if ($lastById[(int) $r['id']] && $lastById[(int) $r['id']]['direction'] === 'in') {
                $waiting++;
            }
        }

        // séries diárias
        $days = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $days[] = date('Y-m-d', strtotime("-$i days", strtotime('today')));
        }
        $index = array_flip($days);
        $privSeries = $pubSeries = $wonSeries = array_fill(0, count($days), 0);
        $wonValueSeries = array_fill(0, count($days), 0.0);
        foreach ($rows as $r) {
            $d = $r['date_creation'] ? substr($r['date_creation'], 0, 10) : '';
            if (isset($index[$d])) {
                if ($r['client_type'] === 'publico') {
                    $pubSeries[$index[$d]]++;
                } else {
                    $privSeries[$index[$d]]++;
                }
            }
            $d = ((int) $r['status'] === Conversa::WON && $r['date_closed']) ? substr($r['date_closed'], 0, 10) : '';
            if (isset($index[$d])) {
                $wonSeries[$index[$d]]++;
                $wonValueSeries[$index[$d]] += (float) $r['value'];
            }
        }
        $leadSeries = array_map(fn ($a, $b) => $a + $b, $privSeries, $pubSeries);

        // motivos de perda
        $reasons = [];
        foreach ($lost as $r) {
            $k = trim((string) $r['lost_reason']) ?: 'Sem motivo informado';
            $reasons[$k] = ($reasons[$k] ?? 0) + 1;
        }
        arsort($reasons);

        // canais de entrada e tipo de cliente (leads do período) e resultado por tipo
        $byChannel = [];
        $byType = ['privado' => ['leads' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0], 'publico' => ['leads' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0]];
        foreach ($createdNow as $r) {
            $type = $r['client_type'] === 'publico' ? 'publico' : 'privado';
            $byChannel[$r['source']][$type] = ($byChannel[$r['source']][$type] ?? 0) + 1;
            $byType[$type]['leads']++;
        }
        foreach ($won as $r) {
            $type = $r['client_type'] === 'publico' ? 'publico' : 'privado';
            $byType[$type]['won']++;
            $byType[$type]['value'] += (float) $r['value'];
        }
        foreach ($lost as $r) {
            $byType[$r['client_type'] === 'publico' ? 'publico' : 'privado']['lost']++;
        }
        uasort($byChannel, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        $editaisPendentes = 0;
        $ciclos = 0;
        foreach ($rows as $r) {
            if ((int) $r['status'] === Conversa::OPEN && $r['client_type'] === 'publico' && empty($docSummary[(int) $r['id']]['edital'])) {
                $editaisPendentes++;
            }
            if ((int) $r['ciclo_id']) {
                $ciclos++;
            }
        }

        // ranking de atendentes
        $rank = [];
        foreach ($rows as $r) {
            $u = (int) $r['users_id_attendant'];
            $rank[$u] ??= ['open' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0];
            if ((int) $r['status'] === Conversa::OPEN) {
                $rank[$u]['open']++;
            }
        }
        foreach ($won as $r) {
            $rank[(int) $r['users_id_attendant']]['won']++;
            $rank[(int) $r['users_id_attendant']]['value'] += (float) $r['value'];
        }
        foreach ($lost as $r) {
            $rank[(int) $r['users_id_attendant']]['lost']++;
        }
        uasort($rank, fn ($a, $b) => [$b['value'], $b['won'], $b['open']] <=> [$a['value'], $a['won'], $a['open']]);
        $topValue = max(1.0, ...array_column($rank ?: [['value' => 1.0]], 'value'));

        $today = (int) ($DB->request([
            'COUNT'      => 'cnt',
            'FROM'       => Mensagem::getTable() . ' AS m',
            'INNER JOIN' => [$t . ' AS c' => ['ON' => ['c' => 'id', 'm' => 'plugin_crm_conversas_id']]],
            'WHERE'      => ['m.date' => ['>=', date('Y-m-d 00:00:00', $now)]] + Conversa::scope('c'),
        ])->current()['cnt'] ?? 0);

        // ---- gaveta lateral e pop-up: listas de oportunidades por trás de cada número
        $items = [];
        $deal = function (array $r) use (&$items, $docSummary, $lastById): int {
            $id = (int) $r['id'];
            $items[$id] ??= Conversa::dealData($r, $docSummary, $lastById[$id] ?? null);
            return $id;
        };
        $byExpected = function (array $list) use ($etapas): array {
            usort($list, function ($a, $b) use ($etapas) {
                $ea = (float) $a['value'] * ($etapas[(int) $a['plugin_crm_etapas_id']]['probability'] ?? 0);
                $eb = (float) $b['value'] * ($etapas[(int) $b['plugin_crm_etapas_id']]['probability'] ?? 0);
                return $eb <=> $ea;
            });
            return $list;
        };
        $byDate = function (array $list, string $field): array {
            usort($list, fn ($a, $b) => strcmp((string) $b[$field], (string) $a[$field]));
            return $list;
        };
        $ids = fn (array $list): array => array_slice(array_map($deal, array_values($list)), 0, 80);

        $openSorted = $byExpected($open);
        $lists = [
            'pipeline' => ['title' => 'Pipeline em aberto', 'sub' => 'Oportunidades abertas, das que mais pesam na previsão para as que menos pesam.', 'icon' => 'ti-currency-real', 'color' => '#3b82f6', 'ids' => $ids($openSorted)],
            'won'      => ['title' => 'Vendas no período', 'sub' => 'Oportunidades fechadas com sucesso nos últimos ' . $dias . ' dias.', 'icon' => 'ti-trophy', 'color' => '#22c55e', 'ids' => $ids($byDate($won, 'date_closed'))],
            'closed'   => ['title' => 'Conversão no período', 'sub' => 'Vendas e perdas dos últimos ' . $dias . ' dias.', 'icon' => 'ti-chart-pie', 'color' => '#14b8a6', 'ids' => $ids($byDate(array_merge($won, $lost), 'date_closed'))],
            'leads'    => ['title' => 'Novos leads', 'sub' => 'Entradas registradas nos últimos ' . $dias . ' dias.', 'icon' => 'ti-user-plus', 'color' => '#0ea5e9', 'ids' => $ids($byDate($createdNow, 'date_creation'))],
            'noatt'    => ['title' => 'Sem atendente', 'sub' => 'Oportunidades abertas que ninguém assumiu ainda.', 'icon' => 'ti-user-question', 'color' => '#f97316', 'ids' => $ids($byExpected(array_filter($open, fn ($r) => !(int) $r['users_id_attendant'])))],
            'waiting'  => ['title' => 'Aguardando resposta', 'sub' => 'O cliente falou por último: responda antes que esfrie.', 'icon' => 'ti-message-circle-exclamation', 'color' => '#ef4444',
                'ids' => $ids($byExpected(array_filter($open, fn ($r) => ($lastById[(int) $r['id']]['direction'] ?? '') === 'in')))],
        ];
        foreach ($byStage as $sid => $s) {
            $lists["stage:$sid"] = ['title' => $s['name'], 'sub' => 'Oportunidades abertas nesta etapa (' . $s['probability'] . '% de chance de fechar).', 'icon' => 'ti-layout-kanban', 'color' => $s['color'],
                'ids' => $ids($byExpected(array_filter($open, fn ($r) => (int) $r['plugin_crm_etapas_id'] === (int) $sid)))];
        }
        foreach (array_keys($byChannel) as $source) {
            $lists["channel:$source"] = ['title' => 'Canal: ' . Conversa::sourceLabel($source), 'sub' => 'Entradas deste canal nos últimos ' . $dias . ' dias.', 'icon' => Conversa::SOURCE_ICONS[$source] ?? 'ti-dots', 'color' => Conversa::SOURCE_COLORS[$source] ?? '#64748b',
                'ids' => $ids($byDate(array_filter($createdNow, fn ($r) => $r['source'] === $source), 'date_creation'))];
        }
        foreach (['privado', 'publico'] as $type) {
            $lists["type:$type"] = ['title' => 'Clientes ' . ($type === 'publico' ? 'públicos' : 'privados'), 'sub' => 'Entradas nos últimos ' . $dias . ' dias.', 'icon' => Conversa::CLIENT_TYPES[$type][2], 'color' => Conversa::CLIENT_TYPES[$type][1],
                'ids' => $ids($byDate(array_filter($createdNow, fn ($r) => $r['client_type'] === $type), 'date_creation'))];
        }

        // oportunidades quentes: as de maior valor esperado (valor × probabilidade da etapa), com ao menos 25% de chance
        $hot = array_slice(array_values(array_filter($openSorted, fn ($r) => ($etapas[(int) $r['plugin_crm_etapas_id']]['probability'] ?? 0) >= 25 && (float) $r['value'] > 0)), 0, 5);
        $hotIds = array_map($deal, $hot);

        // ---- saída
        $periodBtns = '';
        foreach (self::PERIODS as $d => $label) {
            $periodBtns .= "<a class='btn btn-sm " . ($d === $dias ? 'btn-primary' : 'btn-outline-secondary') . "' href='?dias=$d'>" . Menu::e($label) . "</a>";
        }
        echo "<div class='crm'>";
        echo Menu::title('Dashboard CRM', 'dashboard', "<div class='btn-group'>$periodBtns</div>"
            . ($hotIds ? "<button type='button' class='btn btn-warning crm-hotbtn' id='crm-hot-open'><i class='ti ti-flame me-1'></i>Oportunidades quentes</button>" : '')
            . Menu::button('Relatório executivo', Menu::page('/front/relatorio.php?tipo=executivo&dias=' . $dias), 'ti-file-type-pdf', 'btn-outline-secondary')
            . Menu::button('Funil de vendas', Menu::page('/front/funil.php'), 'ti-layout-kanban', 'btn-outline-primary'));

        $vs = "<span class='text-secondary'>vs. período anterior</span>";
        echo "<div class='row row-cards mb-3'>"
            . self::kpi('pipeline', 'Pipeline em aberto', self::brl($pipeline), 'ti-currency-real', '#3b82f6', count($open) . ' oportunidades')
            . self::kpi('pipeline', 'Previsão ponderada', self::brl($weighted), 'ti-target-arrow', '#6366f1', 'valor × probabilidade da etapa')
            . self::kpi('won', 'Vendas no período', self::brl($wonValue), 'ti-trophy', '#22c55e', count($won) . ' fechadas ' . self::delta($wonValue, $prevWonValue), self::spark($wonValueSeries, '#22c55e'))
            . self::kpi('leads', 'Novos leads', (string) $leads, 'ti-user-plus', '#0ea5e9', self::delta($leads, $prevLeads) . ' ' . $vs, self::spark($leadSeries, '#0ea5e9'))
            . self::kpi('closed', 'Taxa de conversão', round($rate) . '%', 'ti-chart-pie', '#14b8a6', count($won) . ' vendas · ' . count($lost) . ' perdas ' . self::delta($rate, $prevRate))
            . self::kpi('won', 'Ticket médio', self::brl($ticket), 'ti-receipt', '#f59e0b', self::delta($ticket, $prevTicket) . ' ' . $vs)
            . self::kpi('noatt', 'Sem atendente', (string) $noAttendant, 'ti-user-question', '#f97316', 'oportunidades abertas sem dono')
            . self::kpi('waiting', 'Aguardando resposta', (string) $waiting, 'ti-message-circle-exclamation', '#ef4444', $today . ' mensagens hoje')
            . "</div>";

        // funil + entrada de clientes por dia
        $maxN = max(1, ...array_values(array_column($byStage ?: [['n' => 1]], 'n')));
        echo "<div class='row row-cards mb-3'><div class='col-xl-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Funil de vendas</h3><div class='card-subtitle ms-auto'>oportunidades abertas · clique para ver</div></div><div class='card-body'><div class='crm-funnel'>";
        foreach ($byStage as $sid => $s) {
            $pct = max(16, round($s['n'] / $maxN * 100));
            $tip = Menu::e("{$s['name']}|{$s['n']} oportunidade(s)|" . self::brl($s['value']) . " em aberto|{$s['probability']}% de chance de fechar");
            echo "<div class='crm-fn-row' role='button' tabindex='0' data-list='stage:$sid' data-tip='$tip' style='--c:{$s['color']}'>"
                . "<div class='crm-fn-name'>" . Menu::e($s['name']) . "<small>{$s['probability']}%</small></div>"
                . "<div class='crm-fn-track'><div class='crm-fn-bar' style='width:{$pct}%'><b>{$s['n']}</b></div></div>"
                . "<div class='crm-fn-val'>" . self::brl($s['value']) . "</div></div>";
        }
        echo "</div></div></div></div>";

        echo "<div class='col-xl-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Entrada de clientes e vendas por dia</h3>"
            . "<div class='ms-auto crm-legend'><span><i style='background:#3b82f6'></i>Leads privados</span><span><i style='background:#a855f7'></i>Leads públicos</span><span><i style='background:#22c55e'></i>Vendas</span></div></div>"
            . "<div class='card-body'>" . self::barsChart($days, $privSeries, $pubSeries, $wonSeries, $wonValueSeries) . "</div></div></div></div>";

        // ranking + resultado
        echo "<div class='row row-cards mb-3'><div class='col-xl-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Ranking de atendentes</h3><div class='card-subtitle ms-auto'>vendas no período</div></div>"
            . "<div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr><th>Atendente</th><th class='text-end'>Abertas</th><th class='text-end'>Vendas</th><th class='text-end'>Perdidas</th><th style='width:32%'>Valor vendido</th></tr></thead><tbody>";
        $pos = 0;
        foreach ($rank as $uid => $r) {
            $label = $uid ? Conversa::attendantName($uid) : 'Sem atendente';
            $bar = round($r['value'] / $topValue * 100);
            $medal = $uid && $pos < 3 && $r['value'] > 0 ? "<span class='crm-medal m" . ($pos + 1) . "'>" . ($pos + 1) . "</span>" : '';
            $pos += $uid ? 1 : 0;
            echo "<tr><td><div class='d-flex align-items-center gap-2'>$medal" . Conversa::avatar($label, 'sm') . Menu::e($label) . "</div></td>"
                . "<td class='text-end'>{$r['open']}</td><td class='text-end'>{$r['won']}</td><td class='text-end'>{$r['lost']}</td>"
                . "<td><div class='d-flex align-items-center gap-2'><div class='progress flex-grow-1' style='height:7px'><div class='progress-bar bg-success' style='width:{$bar}%'></div></div><span class='small'>" . self::brl($r['value']) . "</span></div></td></tr>";
        }
        if (!$rank) {
            echo "<tr><td colspan='5' class='text-center text-secondary py-4'>Sem oportunidades ainda.</td></tr>";
        }
        echo "</tbody></table></div></div></div>";

        $openN = count($open);
        echo "<div class='col-xl-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Resultado e perdas</h3></div><div class='card-body'>"
            . "<div class='d-flex align-items-center gap-3 mb-3'><div class='crm-donut-box'>" . self::donut([[count($won), '#22c55e', 'Vendas', 'won'], [count($lost), '#ef4444', 'Perdidas', 'closed'], [$openN, '#3b82f6', 'Em aberto', 'pipeline']]) . "</div>"
            . "<div class='crm-legend column'><span><i style='background:#22c55e'></i>Vendas · " . count($won) . "</span><span><i style='background:#ef4444'></i>Perdidas · " . count($lost) . "</span><span><i style='background:#3b82f6'></i>Em aberto · $openN</span></div></div>"
            . "<div class='crm-side-title'>Principais motivos de perda</div>";
        foreach (array_slice($reasons, 0, 4, true) as $reason => $n) {
            $pct = round($n / max(1, count($lost)) * 100);
            echo "<div class='mb-2' data-tip='" . Menu::e("$reason|$n perda(s) · {$pct}% do total") . "'><div class='d-flex justify-content-between small'><span class='text-truncate'>" . Menu::e($reason) . "</span><strong>$n</strong></div><div class='progress' style='height:6px'><div class='progress-bar bg-danger' style='width:{$pct}%'></div></div></div>";
        }
        if (!$reasons) {
            echo "<div class='text-secondary small'>Nenhuma perda no período.</div>";
        }
        echo "</div></div></div></div>";

        // canais de entrada e tipo de cliente
        $maxChannel = max(1, ...array_values(array_map('array_sum', $byChannel ?: [[1]])));
        echo "<div class='row row-cards mb-3'><div class='col-xl-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Canais de entrada</h3>"
            . "<div class='ms-auto crm-legend'><span><i style='background:#3b82f6'></i>Privado</span><span><i style='background:#a855f7'></i>Público</span></div></div><div class='card-body'>";
        foreach ($byChannel as $source => $by) {
            $priv = $by['privado'] ?? 0;
            $pub = $by['publico'] ?? 0;
            $width = max(8, round(($priv + $pub) / $maxChannel * 100));
            $pp = round($priv / max(1, $priv + $pub) * 100);
            $tip = Menu::e(Conversa::sourceLabel($source) . "|$priv privado(s)|$pub público(s)");
            echo "<div class='crm-chrow' role='button' tabindex='0' data-list='channel:$source' data-tip='$tip'><div class='crm-chname'>" . Conversa::sourceBadge($source) . "</div>"
                . "<div class='crm-chtrack'><div class='crm-stack' style='width:{$width}%'><span style='width:{$pp}%;background:linear-gradient(90deg,#2563eb,#60a5fa)'></span><span style='width:" . (100 - $pp) . "%;background:linear-gradient(90deg,#9333ea,#c084fc)'></span></div></div>"
                . "<div class='crm-chval'>" . ($priv + $pub) . "</div></div>";
        }
        if (!$byChannel) {
            echo "<div class='text-secondary text-center py-3'>Sem entradas no período.</div>";
        }
        echo "</div></div></div><div class='col-xl-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Tipo de cliente</h3><div class='card-subtitle ms-auto'>no período</div></div><div class='card-body'>"
            . "<div class='d-flex align-items-center gap-3 mb-3'><div class='crm-donut-box'>" . self::donut([[$byType['privado']['leads'], '#3b82f6', 'Privado', 'type:privado'], [$byType['publico']['leads'], '#a855f7', 'Público', 'type:publico']]) . "</div>"
            . "<div class='crm-legend column'><span><i style='background:#3b82f6'></i>Privado · {$byType['privado']['leads']} entradas</span><span><i style='background:#a855f7'></i>Público · {$byType['publico']['leads']} entradas</span></div></div>"
            . "<table class='table table-sm'><thead><tr><th></th><th class='text-end'>Vendas</th><th class='text-end'>Perdas</th><th class='text-end'>Vendido</th></tr></thead><tbody>";
        foreach ($byType as $k => $t2) {
            echo "<tr><td>" . Conversa::clientBadge($k) . "</td><td class='text-end'>{$t2['won']}</td><td class='text-end'>{$t2['lost']}</td><td class='text-end'>" . self::brl($t2['value']) . "</td></tr>";
        }
        echo "</tbody></table><div class='d-flex flex-wrap gap-2 mt-2'><span class='crm-chip" . ($editaisPendentes ? ' crm-chip-red' : '') . "'><i class='ti ti-gavel'></i> Editais pendentes · <b>$editaisPendentes</b></span>"
            . "<span class='crm-chip'><i class='ti ti-coin'></i> Ciclos financeiros iniciados · <b>$ciclos</b></span></div></div></div></div></div>";

        // atividade recente
        usort($rows, fn ($a, $b) => strcmp((string) $b['last_message_at'], (string) $a['last_message_at']));
        $recent = array_slice($rows, 0, 6);
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>Atividade recente</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-hover'><tbody>";
        foreach ($recent as $r) {
            $id = (int) $r['id'];
            $deal($r);
            $name = Conversa::title($r);
            echo "<tr style='cursor:pointer' data-item='$id'>"
                . "<td style='width:30%'><div class='d-flex align-items-center gap-2'>" . Conversa::avatar($name, 'sm') . "<strong>" . Menu::e($name) . "</strong></div></td>"
                . "<td class='text-secondary'><div class='text-truncate' style='max-width:380px'>" . Menu::e(Conversa::lastMessage($id) ?: ($r['subject'] ?: '—')) . "</div></td>"
                . "<td>" . Conversa::etapaBadge((int) $r['plugin_crm_etapas_id']) . "</td>"
                . "<td class='text-end'>" . ((float) $r['value'] ? self::brl((float) $r['value']) : '') . "</td>"
                . "<td>" . Conversa::statusBadge((int) $r['status']) . "</td>"
                . "<td class='text-end text-secondary small'>" . Menu::e(Conversa::ago($r['last_message_at'])) . "</td></tr>";
        }
        if (!$recent) {
            echo "<tr><td class='text-center text-secondary py-4'>Sem atividade ainda.</td></tr>";
        }
        echo "</tbody></table></div></div></div>";

        $boot = ['lists' => $lists, 'items' => $items, 'hot' => $hotIds, 'days' => $dias, 'funil' => Menu::url('/front/funil.php')];
        echo "<script type='application/json' id='crm-dash'>" . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
    }
}
