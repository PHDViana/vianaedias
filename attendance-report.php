<?php
namespace VDAttendance;

if (!function_exists('db_query')) {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/attendance-calculation.php';
require_once __DIR__ . '/attendance-adjustments.php';
require_once __DIR__ . '/attendance-access.php';

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rows(string $sql): array
{
    $result = [];
    $query = \db_query($sql);
    while ($row = \db_fetch_array($query)) {
        $result[] = $row;
    }
    return $result;
}

function render(array $filters): string
{
    global $app_user, $report_page_filters;
    $scope = employeeScope(is_array($app_user) ? $app_user : []);
    if ($scope['error']) {
        return '<div class="alert alert-danger" role="alert">' . esc($scope['error']) . '</div>';
    }
    $manager = $scope['manager'];
    $month = $filters['filter_by_date'] ?? date('Y-m');
    if (!is_string($month) || !preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D', $month)) {
        return '<div class="alert alert-danger" role="alert">Mês inválido. Selecione um mês entre 2000 e 2099.</div>';
    }
    $selectedIds = [];
    if (!$manager) {
        if (!is_scalar($scope['employee']) || !preg_match('/^[0-9]+$/D', (string) $scope['employee'])) {
            return '<div class="alert alert-danger" role="alert">Colaborador inválido.</div>';
        }
        $selectedIds = [(int) $scope['employee']];
    } elseif (PHP_SAPI !== 'cli') {
        if (isset($_POST['vd_attendance_employees_sent'])) {
            $posted = $_POST['vd_attendance_employees'] ?? [];
            if (!is_array($posted)) {
                return '<div class="alert alert-danger" role="alert">Colaboradores inválidos.</div>';
            }
            foreach ($posted as $value) {
                if (!is_string($value) || !preg_match('/^[0-9]+$/D', $value)) {
                    return '<div class="alert alert-danger" role="alert">Colaboradores inválidos.</div>';
                }
                if ((int) $value > 0) {
                    $selectedIds[] = (int) $value;
                }
            }
            $selectedIds = array_values(array_unique($selectedIds));
            $report_page_filters[29]['employees'] = $selectedIds;
        } else {
            $legacy = (int) ($report_page_filters[29]['manager_employee'] ?? 0);
            $selectedIds = $report_page_filters[29]['employees'] ?? ($legacy ? [$legacy] : []);
        }
    }
    $detail = null;
    if (PHP_SAPI !== 'cli' && isset($_POST['vd_attendance_detail'])) {
        if (!is_string($_POST['vd_attendance_detail']) || !preg_match('/^([0-9]+)\|(20[0-9]{2}-(?:0[1-9]|1[0-2]))$/D', $_POST['vd_attendance_detail'], $detailMatch)) {
            return '<div class="alert alert-danger" role="alert">Detalhe mensal inválido.</div>';
        }
        $detail = ['employee' => (int) $detailMatch[1], 'month' => $detailMatch[2]];
        if (!$manager && $detail['employee'] !== (int) $scope['employee']) {
            return '<div class="alert alert-danger" role="alert">Sem acesso ao detalhe pedido.</div>';
        }
    }
    $activeOnly = true;
    $options = [];
    if ($manager) {
        if (isset($_POST['vd_attendance_active'])) {
            if (!is_string($_POST['vd_attendance_active']) || !in_array($_POST['vd_attendance_active'], ['0', '1'], true)) {
                return '<div class="alert alert-danger" role="alert">Filtro de colaboradores inválido.</div>';
            }
            $report_page_filters[29]['active_only'] = $_POST['vd_attendance_active'] === '1';
        }
        $activeOnly = $report_page_filters[29]['active_only'] ?? true;
        $options = rows("SELECT id,field_1876,field_1878 FROM app_entity_141 WHERE field_6623='true' ORDER BY field_1876,id");
        if ($activeOnly && $selectedIds) {
            $activeIds = [];
            foreach ($options as $option) {
                if ($option['field_1878'] === 'true') {
                    $activeIds[] = (int) $option['id'];
                }
            }
            $selectedIds = array_values(array_intersect($selectedIds, $activeIds));
            $report_page_filters[29]['employees'] = $selectedIds;
        }
    }
    $view = 'month';
    if (PHP_SAPI !== 'cli') {
        if (isset($_POST['vd_attendance_view'])) {
            if (!is_string($_POST['vd_attendance_view']) || !in_array($_POST['vd_attendance_view'], ['month', 'year'], true)) {
                return '<div class="alert alert-danger" role="alert">Vista inválida.</div>';
            }
            $report_page_filters[29]['view'] = $_POST['vd_attendance_view'];
        }
        $view = $report_page_filters[29]['view'] ?? 'month';
    }
    $annual = $view === 'year';
    $yearChoice = (int) substr($month, 0, 4);
    if (PHP_SAPI !== 'cli') {
        if (isset($_POST['vd_attendance_year'])) {
            if (!is_string($_POST['vd_attendance_year']) || !preg_match('/^20[0-9]{2}$/D', $_POST['vd_attendance_year'])) {
                return '<div class="alert alert-danger" role="alert">Ano inválido. Selecione um ano entre 2000 e 2099.</div>';
            }
            $report_page_filters[29]['year'] = (int) $_POST['vd_attendance_year'];
        }
        $yearChoice = $report_page_filters[29]['year'] ?? $yearChoice;
    }
    if ($detail) {
        $annual = false;
        $month = $detail['month'];
        $selectedIds = [$detail['employee']];
    }
    $zone = new \DateTimeZone(CFG_APP_TIMEZONE);
    if ($annual) {
        $first = new \DateTimeImmutable($yearChoice . '-01-01 00:00:00', $zone);
        $next = $first->modify('+1 year');
    } else {
        $first = new \DateTimeImmutable($month . '-01 00:00:00', $zone);
        $next = $first->modify('+1 month');
    }
    $now = time();
    $conditions = ["field_6623='true'"];
    if ($selectedIds) $conditions[] = 'id IN(' . implode(',', $selectedIds) . ')';
    if ($manager && $activeOnly && !$detail) $conditions[] = "field_1878='true'";
    $employees = rows("SELECT id,field_1876,field_1877,field_1900,field_1893,field_2224,field_6589,field_6590,field_6591,field_6592,field_6593 FROM app_entity_141" .
        (' WHERE ' . implode(' AND ', $conditions)) . " ORDER BY field_1876,id");
    $functions = [];
    foreach (rows('SELECT id,field_2697 FROM app_entity_182') as $row) {
        $functions[(int) $row['id']] = $row['field_2697'];
    }
    $departments = [];
    foreach (rows('SELECT id,name FROM app_global_lists_choices WHERE lists_id=20') as $row) {
        $departments[(int) $row['id']] = $row['name'];
    }
    $holidays = rows("SELECT name,start_date,end_date FROM app_holidays WHERE start_date<'" . $next->format('Y-m-d') . "' AND end_date>='" . $first->format('Y-m-d') . "'");
    $year = $first->format('Y');
    $statutory = statutoryHolidays((int) $year);
    $holidays = array_values(array_filter($holidays, function ($holiday) use ($statutory) {
        return $holiday['start_date'] !== $holiday['end_date'] || !isset($statutory[$holiday['start_date']]);
    }));
    $holidays = array_merge($holidays, array_values($statutory));
    $userCounts = [];
    foreach (rows('SELECT field_1900,COUNT(*) n FROM app_entity_141 WHERE field_1900>0 GROUP BY field_1900') as $row) {
        $userCounts[(int) $row['field_1900']] = (int) $row['n'];
    }
    // Dias úteis já decorridos sem qualquer registo de ponto em toda a empresa contam
    // como encerramento provável (férias coletivas de agosto/dezembro, pontes).
    $coverageDate = '2026-03-01';
    $activityDates = [];
    $windowFrom = $first->getTimestamp() - 172800;
    $windowTo = $next->getTimestamp() + 172800;
    foreach (rows("SELECT date_added,field_5792 FROM app_entity_135 WHERE date_added>$windowFrom AND date_added<$windowTo") as $row) {
        $activityDates[dateInZone((int) $row['date_added'], $zone, 'Y-m-d')] = true;
        if ((int) $row['field_5792'] > 0) {
            $activityDates[dateInZone((int) $row['field_5792'], $zone, 'Y-m-d')] = true;
        }
    }
    foreach (rows("SELECT field_6608,field_6609 FROM app_entity_409 WHERE field_6611='true' AND field_6606 IN(1633,1634) AND field_6608>$windowFrom AND field_6608<$windowTo") as $row) {
        $activityDates[dateInZone((int) $row['field_6608'], $zone, 'Y-m-d')] = true;
        if ((int) $row['field_6609'] > 0) {
            $activityDates[dateInZone((int) $row['field_6609'], $zone, 'Y-m-d')] = true;
        }
    }
    $currentYear = (int) date('Y');
    $yearFrom = $yearTo = $currentYear;
    if ($annual) {
        $bounds = rows('SELECT YEAR(FROM_UNIXTIME(MIN(date_added))) y0,YEAR(FROM_UNIXTIME(MAX(date_added))) y1 FROM app_entity_135');
        $yearFrom = min(((int) ($bounds[0]['y0'] ?? 0)) ?: $currentYear, $yearChoice);
        $yearTo = max((int) ($bounds[0]['y1'] ?? 0), $currentYear, $yearChoice);
    }
    $html = '<link rel="stylesheet" href="includes/vd_quote_workflow/attendance-report.css?v=8">';
    $html .= '<div class="vd-attendance">';
    if (!$detail) {
    $html .= '<form class="from-report-page-filters form-inline noprint vd-attendance-filters">';
    if ($manager) {
        $html .= '<div class="form-group"><label for="vd-attendance-employee">Colaboradores: </label> <input type="hidden" name="vd_attendance_employees_sent" value="1"><select id="vd-attendance-employee" class="form-control" name="vd_attendance_employees[]" multiple size="4" data-placeholder="Todos os colaboradores" onchange="load_report_page()">';
        foreach ($options as $option) {
            if ($activeOnly && $option['field_1878'] !== 'true') continue;
            $html .= '<option value="' . (int) $option['id'] . '"' . (in_array((int) $option['id'], $selectedIds, true) ? ' selected' : '') . '>' . esc($option['field_1876']) . '</option>';
        }
        $html .= '</select> <span class="vd-attendance-hint">vazio = todos</span></div> <label class="vd-attendance-active"><input type="hidden" name="vd_attendance_active" value="0"><input type="checkbox" name="vd_attendance_active" value="1"' . ($activeOnly ? ' checked' : '') . ' onchange="load_report_page()"> Apenas ativos</label>';
    }
    $html .= ' <div class="form-group"><label for="vd-attendance-view">Vista: </label> <select id="vd-attendance-view" class="form-control" name="vd_attendance_view" onchange="load_report_page()"><option value="month"' . ($annual ? '' : ' selected') . '>Mensal — detalhe diário</option><option value="year"' . ($annual ? ' selected' : '') . '>Anual — resumo por mês</option></select></div>';
    if ($annual) {
        $html .= ' <div class="form-group"><label for="vd-attendance-year">Ano: </label> <select id="vd-attendance-year" class="form-control" name="vd_attendance_year" onchange="load_report_page()">';
        for ($yearOption = $yearTo; $yearOption >= $yearFrom; $yearOption--) {
            $html .= '<option value="' . $yearOption . '"' . ($yearOption === $yearChoice ? ' selected' : '') . '>' . $yearOption . '</option>';
        }
        $html .= '</select></div>';
    }
    $html .= '</form>';
    $html .= '<script>(function(){var form=document.querySelector("#report_page_29 .vd-attendance-filters"),month=document.querySelector("#filter_by_date");if(!form||!month)return;var monthForm=month.closest("form");if(!monthForm)return;document.querySelectorAll(".vd-attendance-filters").forEach(function(old){if(old!==form)old.remove()});monthForm.classList.add("vd-attendance-month-filter");monthForm.style.display=' . ($annual ? '"none"' : '""') . ';monthForm.insertAdjacentElement("afterend",form);if(window.jQuery&&jQuery.fn.chosen){jQuery("#vd-attendance-employee").chosen({width:"360px",placeholder_text_multiple:"Todos os colaboradores",no_results_text:"Sem resultados"})}})();</script>';
    if ($annual) {
        $html .= '<script>window.vdAttendanceModalBody=function(){var overlay=document.getElementById("vd-attendance-modal");if(!overlay){overlay=document.createElement("div");overlay.id="vd-attendance-modal";overlay.className="vd-attendance-modal noprint";overlay.innerHTML=\'<div class="vd-attendance-modal-box"><button type="button" class="vd-attendance-modal-close" aria-label="Fechar">&times;</button><div class="vd-attendance-modal-body"></div></div>\';document.body.appendChild(overlay);overlay.addEventListener("click",function(e){if(e.target===overlay||e.target.classList.contains("vd-attendance-modal-close")){overlay.hidden=true;}});}overlay.hidden=false;return overlay.querySelector(".vd-attendance-modal-body");};window.vdAttendanceShow=function(id){var source=document.getElementById(id);if(!source)return;vdAttendanceModalBody().innerHTML=source.innerHTML;};window.vdAttendanceDetail=function(link){var body=vdAttendanceModalBody();body.innerHTML="<p>A carregar o detalhe do mês…</p>";var data=jQuery(".from-report-page-filters").serializeArray();data.push({name:"vd_attendance_detail",value:link.getAttribute("data-detail")});var info=jQuery("#report_page_content").data();jQuery.post(url_for("report_page/view","action=load&id="+info.id+"&path="+info.path),data,function(markup){body.innerHTML=markup;});};</script>';
    }
    }
    if (!$employees) {
        return $html . '<div class="alert alert-warning" role="alert">Não foram encontrados recursos humanos para este filtro.</div></div>';
    }
    if (!$detail) {
    $instructions = $manager ?
        'Selecione a vista (mensal ou anual), o período e o colaborador. Em cada página, utilize <b>Acrescentar marcação</b> ou abra <b>Marcações e correções</b> para corrigir/anular um original. Depois de guardar, atualize o relatório.' :
        'Está a consultar apenas o seu resumo de ponto, em modo de leitura. Para solicitar uma correção, contacte a direção. Selecione o mês pretendido ou a vista anual.';
    $html .= '<div class="vd-attendance-guide noprint"><strong>' . ($manager ? 'Gestão de assiduidade' : 'O meu resumo de ponto') . '</strong><span>Feriados nacionais + São João de Braga · Minutos reais, sem tolerância · Correções separadas dos originais</span><p>' . $instructions . ' Imprima ou guarde como PDF em A4 vertical.</p><button type="button" class="btn btn-default" onclick="load_report_page()"><i class="fa fa-refresh" aria-hidden="true"></i> Atualizar relatório</button></div>';
    }
    $globalTotals = ['planned' => 0, 'worked' => 0, 'extra' => 0, 'missing' => 0, 'balance' => 0];
    $globalCount = 0;
    $globalIncomplete = 0;
    $globalPartial = false;
    $globalRows = [];
    if ($annual) {
        $html .= '%%VD_GLOBAL_TOTALS%%';
    }
    foreach ($employees as $pageIndex => $employee) {
        $scheduleError = '';
        try {
            $periods = schedule($employee);
        } catch (\InvalidArgumentException $error) {
            $periods = [];
            $scheduleError = $error->getMessage();
        }
        $userId = (int) $employee['field_1900'];
        $linkError = $userId <= 0 || ($userCounts[$userId] ?? 0) !== 1 ? 'Ligação ao utilizador do relógio de ponto ausente ou ambígua.' : '';
        $originals = [];
        if (!$linkError) {
            foreach (rows("SELECT id,date_added,field_5792 FROM app_entity_135 WHERE created_by=$userId ORDER BY date_added,id") as $row) {
                $originals[] = ['id' => (int) $row['id'], 'start' => (int) $row['date_added'], 'end' => (int) $row['field_5792']];
            }
        }
        $id = (int) $employee['id'];
        $corrections = [];
        foreach (rows("SELECT id,field_6606,field_6607,field_6608,field_6609,field_6610,field_6611 FROM app_entity_409 WHERE parent_item_id=$id ORDER BY id") as $row) {
            $corrections[] = [
                'id' => (int) $row['id'],
                'action' => [1633 => 'add', 1634 => 'replace', 1635 => 'cancel'][(int) $row['field_6606']] ?? '',
                'original' => $row['field_6607'], 'start' => (int) $row['field_6608'], 'end' => (int) $row['field_6609'],
                'reason' => $row['field_6610'], 'active' => $row['field_6611'] === 'true',
            ];
        }
        $adjusted = adjustedPunches($originals, $corrections);
        $punches = $adjusted['punches'];
        $correctionError = implode(' ', $adjusted['errors']);
        $from = $first->getTimestamp();
        $to = $next->getTimestamp();
        $absences = [];
        foreach (rows("SELECT a.id,a.field_1617,a.field_1618,a.field_3771,a.field_1614,c.name FROM app_entity_127 a LEFT JOIN app_fields_choices c ON c.id=a.field_1614 AND c.fields_id=1614 WHERE a.parent_item_id=$id AND a.field_1620 IN(37,39,3654) AND a.field_1617<$to AND a.field_1618>$from ORDER BY a.field_1617,a.id") as $row) {
            $absences[] = [
                'start' => (int) $row['field_1617'], 'end' => (int) $row['field_1618'],
                'label' => $row['field_3771'] === 'true' || (int) $row['field_1614'] === 351 ? 'Férias' : ($row['name'] ?: 'Ausência aprovada'),
            ];
        }
        $roles = [];
        foreach (explode(',', (string) $employee['field_1893']) as $roleId) {
            if (isset($functions[(int) $roleId])) {
                $roles[] = $functions[(int) $roleId];
            }
        }
        $html .= '<section class="vd-attendance-sheet">';
        $listUrl = 'index.php?module=items/items&path=141-' . $id . '/409';
        $addUrl = 'index.php?module=items/form&path=141-' . $id . '/409&fields[6606]=1633';
        if ($manager && !$detail) {
            $html .= '<div class="vd-attendance-tools noprint"><a class="btn btn-primary" onclick="open_dialog(this.href); return false;" href="' . esc($addUrl) . '"><i class="fa fa-plus" aria-hidden="true"></i> Acrescentar marcação</a> <a class="btn btn-default" href="' . esc($listUrl) . '">Gerir correções</a></div>';
        }
        if ($correctionError) {
            $html .= '<div class="alert alert-danger" role="alert"><b>Correções por resolver:</b> ' . esc($correctionError) . ($manager ? ' Corrija ou desative os ajustes inválidos em Gerir correções.' : ' Contacte a direção para resolver as correções.') . ' Os totais de assiduidade ficam indisponíveis para evitar valores incorretos.</div>';
        }
        $html .= '<header><div class="vd-attendance-company">Viana<span>&amp;</span>Dias</div><div class="vd-attendance-eyebrow">RECURSOS HUMANOS · ASSIDUIDADE</div><h1>Resumo de ponto</h1><div class="vd-attendance-month"><span>' . ($annual ? 'Ano de referência' : 'Mês de referência') . '</span>' . ($annual ? $first->format('Y') : $first->format('m/Y')) . '</div></header>';
        $html .= '<div class="vd-attendance-identity"><div><b>Colaborador</b> ' . esc($employee['field_1877']) . ' — ' . esc($employee['field_1876']) . '</div><div><b>Centro de custo / Departamento</b> ' . esc($departments[(int) $employee['field_2224']] ?? '—') . '</div><div><b>Categoria / Funções</b> ' . esc(implode(', ', $roles) ?: '—') . '</div></div>';
        $totals = ['planned' => 0, 'worked' => 0, 'extra' => 0, 'missing' => 0, 'balance' => 0, 'unclassified' => 0];
        $breakdown = [];
        $partial = false;
        $unknown = $linkError || $correctionError;
        if ($annual) {
            $monthNames = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
            // Registos de ponto considerados apenas a partir de março de 2026.
            $coverageStart = new \DateTimeImmutable($coverageDate . ' 00:00:00', $zone);
            $html .= '<table class="vd-attendance-days vd-attendance-months"><colgroup><col style="width:15%"><col span="5" style="width:11%"><col style="width:30%"></colgroup><thead><tr><th>Mês</th><th>Horas Previstas</th><th>Trabalho<br>Extraordinário</th><th>Faltas</th><th>H.Trab</th><th>Saldo</th><th>Observações</th></tr></thead><tbody>';
            for ($monthStart = $first; $monthStart < $next; $monthStart = $monthStart->modify('+1 month')) {
                $monthEnd = $monthStart->modify('+1 month');
                if ($monthEnd <= $coverageStart) {
                    $html .= '<tr class="is-weekend"><td class="vd-attendance-date">' . $monthNames[(int) $monthStart->format('n')] . ' ' . $monthStart->format('Y') . '</td>' .
                        str_repeat('<td class="vd-attendance-number">—</td>', 5) .
                        '<td class="vd-attendance-notes">Não considerado — registos de ponto a partir de ' . $coverageStart->format('m/Y') . '</td></tr>';
                    continue;
                }
                $monthTotals = ['planned' => 0, 'worked' => 0, 'extra' => 0, 'missing' => 0, 'balance' => 0, 'unclassified' => 0];
                $monthBreakdown = [];
                $monthPartial = false;
                $monthClosures = 0;
                for ($day = $monthStart; $day < $monthEnd; $day = $day->modify('+1 day')) {
                    $date = $day->format('Y-m-d');
                    $isHoliday = false;
                    foreach ($holidays as $holiday) {
                        if ($date >= $holiday['start_date'] && $date <= $holiday['end_date']) {
                            $isHoliday = true;
                            break;
                        }
                    }
                    $workingDay = (int) $day->format('N') <= 5 && !$isHoliday;
                    if ($workingDay && $date >= $coverageDate && !isset($activityDates[$date]) && $day->modify('+1 day')->getTimestamp() <= $now) {
                        $workingDay = false;
                        $monthClosures++;
                    }
                    $result = dayCalculation($date, $periods, $punches, $absences, $workingDay, $zone, $now);
                    if ($result['future']) {
                        $result['planned'] = null;
                    }
                    if ($scheduleError) {
                        foreach (['extra', 'missing', 'balance', 'planned', 'unclassified'] as $key) {
                            $result[$key] = null;
                        }
                        $result['breakdown'] = [];
                    }
                    if ($unknown) {
                        foreach (['worked', 'extra', 'missing', 'balance', 'unclassified'] as $key) {
                            $result[$key] = null;
                        }
                        $result['breakdown'] = [];
                    }
                    foreach ($monthTotals as $key => $unused) {
                        $monthTotals[$key] += $result[$key] ?? 0;
                    }
                    foreach ($result['breakdown'] as $label => $minutes) {
                        $monthBreakdown[$label] = ($monthBreakdown[$label] ?? 0) + $minutes;
                    }
                    if ($result['balance'] === null) {
                        $monthPartial = true;
                    }
                }
                foreach ($totals as $key => $unused) {
                    $totals[$key] += $monthTotals[$key];
                }
                foreach ($monthBreakdown as $label => $minutes) {
                    $breakdown[$label] = ($breakdown[$label] ?? 0) + $minutes;
                }
                if ($monthPartial) {
                    $partial = true;
                }
                $monthFuture = $monthStart->getTimestamp() > $now;
                $monthCurrent = !$monthFuture && $monthEnd->getTimestamp() > $now;
                $notes = [];
                foreach ($monthBreakdown as $label => $minutes) {
                    if ($minutes) {
                        $notes[] = $label . ' ' . formatMinutes($minutes);
                    }
                }
                if ($monthClosures) {
                    $notes[] = 'Encerramento provável (sem registos): ' . $monthClosures . ($monthClosures === 1 ? ' dia útil' : ' dias úteis');
                }
                if ($monthFuture) {
                    $notes[] = 'Mês futuro';
                } elseif ($monthCurrent) {
                    $notes[] = 'Mês em curso (dias fechados)';
                } elseif ($monthPartial && !$scheduleError && !$unknown) {
                    $notes[] = 'Mês com dias por calcular';
                }
                $unavailable = $monthFuture || $scheduleError || $unknown;
                $monthLabel = $monthNames[(int) $monthStart->format('n')] . ' ' . $monthStart->format('Y');
                $html .= '<tr class="' . ($monthFuture ? 'is-weekend' : '') . '"><td class="vd-attendance-date"><a href="#" data-detail="' . $id . '|' . $monthStart->format('Y-m') . '" onclick="vdAttendanceDetail(this); return false;">' . $monthLabel . '</a></td>' .
                    '<td class="vd-attendance-number">' . esc(formatMinutes($monthFuture || $scheduleError ? null : $monthTotals['planned'])) . '</td>' .
                    '<td class="vd-attendance-number">' . esc(formatMinutes($unavailable ? null : $monthTotals['extra'])) . '</td>' .
                    '<td class="vd-attendance-number">' . esc(formatMinutes($unavailable ? null : $monthTotals['missing'])) . '</td>' .
                    '<td class="vd-attendance-number">' . esc(formatMinutes($monthFuture || $unknown ? null : $monthTotals['worked'])) . '</td>' .
                    '<td class="vd-attendance-number">' . esc(formatMinutes($unavailable ? null : $monthTotals['balance'])) . '</td>' .
                    '<td class="vd-attendance-notes">' . esc(implode('; ', $notes)) . '</td></tr>';
            }
            $html .= '<tr class="vd-attendance-totals"><td class="vd-attendance-date">Totais' . ($partial ? ' (parcial)' : '') . '</td>' .
                '<td class="vd-attendance-number">' . esc(formatMinutes($scheduleError ? null : $totals['planned'])) . '</td>' .
                '<td class="vd-attendance-number">' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['extra'])) . '</td>' .
                '<td class="vd-attendance-number">' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['missing'])) . '</td>' .
                '<td class="vd-attendance-number">' . esc(formatMinutes($unknown ? null : $totals['worked'])) . '</td>' .
                '<td class="vd-attendance-number">' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['balance'])) . '</td>' .
                '<td class="vd-attendance-notes"></td></tr>';
            $html .= '</tbody></table>';
        } else {
        $html .= '<table class="vd-attendance-days"><colgroup><col style="width:10%"><col span="4" style="width:3.4%"><col span="8" style="width:3.5%"><col span="4" style="width:6%"><col style="width:24.4%"></colgroup><thead><tr><th rowspan="2">Data</th><th colspan="4">Horário Previsto</th><th colspan="8">Assiduidade</th><th rowspan="2">Trabalho<br>Extraordinário</th><th rowspan="2">Faltas</th><th rowspan="2">H.Trab</th><th rowspan="2">Saldo</th><th rowspan="2">Observações</th></tr><tr><th>Entr.</th><th colspan="2">Intervalo</th><th>Saída</th>';
        for ($i = 0; $i < 4; $i++) {
            $html .= '<th>Entr.</th><th>Saída</th>';
        }
        $html .= '</tr></thead><tbody>';
        $weekday = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
        for ($day = $first; $day < $next; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $holidayNames = [];
            foreach ($holidays as $holiday) {
                if ($date >= $holiday['start_date'] && $date <= $holiday['end_date']) {
                    $holidayNames[] = 'Feriado: ' . $holiday['name'];
                }
            }
            $workingDay = (int) $day->format('N') <= 5 && !$holidayNames;
            $closedDay = $workingDay && $date >= $coverageDate && !isset($activityDates[$date]) && $day->modify('+1 day')->getTimestamp() <= $now;
            if ($closedDay) {
                $workingDay = false;
            }
            $result = dayCalculation($date, $periods, $punches, $absences, $workingDay, $zone, $now);
            if ($result['future']) {
                $result['planned'] = null;
            }
            if ($scheduleError) {
                foreach (['extra', 'missing', 'balance', 'planned', 'unclassified'] as $key) {
                    $result[$key] = null;
                }
                $result['breakdown'] = [];
            }
            if ($linkError || $correctionError) {
                foreach (['worked', 'extra', 'missing', 'balance', 'unclassified'] as $key) {
                    $result[$key] = null;
                }
                $result['breakdown'] = [];
            }
            foreach ($totals as $key => $unused) {
                $totals[$key] += $result[$key] ?? 0;
            }
            foreach ($result['breakdown'] as $label => $minutes) {
                $breakdown[$label] = ($breakdown[$label] ?? 0) + $minutes;
            }
            if ($result['balance'] === null) {
                $partial = true;
            }
            $notes = array_merge($holidayNames, $result['labels'], $result['warnings']);
            if ($closedDay) {
                $notes[] = 'Provável encerramento da empresa (sem registos de ponto)';
            }
            foreach ($adjusted['events'] as $event) {
                if (dateInZone($event['start'], $zone, 'Y-m-d') === $date) {
                    $notes[] = $event['label'];
                }
            }
            if ($result['future']) {
                $notes[] = 'Dia futuro';
            } elseif ($result['provisional']) {
                $notes[] = 'Dia em curso';
            }
            foreach ($result['marks'] as $mark) {
                if ($mark[1] && dateInZone($mark[0], $zone, 'Y-m-d') !== dateInZone($mark[1], $zone, 'Y-m-d')) {
                    $notes[] = 'Marcação entre dias (horas repartidas por data)';
                }
            }
            $rowClass = $holidayNames ? 'is-holiday' : ((int) $day->format('N') > 5 || $closedDay ? 'is-weekend' : '');
            if ($result['warnings']) $rowClass .= ' has-warning';
            $html .= '<tr class="' . $rowClass . '"><td class="vd-attendance-date">' . $weekday[(int) $day->format('N')] . ' ' . $day->format('d/m/Y') . '</td>';
            $slots = ['', '', '', ''];
            if ($workingDay) {
                if (count($periods) === 1) {
                    $slots = [$periods[0][0], '', '', $periods[0][1]];
                } elseif (count($periods) === 2) {
                    $slots = [$periods[0][0], $periods[0][1], $periods[1][0], $periods[1][1]];
                }
            }
            foreach ($slots as $slot) {
                $html .= '<td>' . esc($slot) . '</td>';
            }
            for ($i = 0; $i < 4; $i++) {
                $mark = $result['marks'][$i] ?? null;
                $html .= '<td>' . ($mark ? esc(dateInZone($mark[0], $zone, 'H:i')) : '') . '</td><td>' .
                    ($mark ? ($mark[1] ? esc(dateInZone($mark[1], $zone, 'H:i')) : '—') : '') . '</td>';
            }
            foreach (array_slice($result['marks'], 4) as $mark) {
                $notes[] = dateInZone($mark[0], $zone, 'H:i') . '–' . ($mark[1] ? dateInZone($mark[1], $zone, 'H:i') : 'sem saída');
            }
            foreach (['extra', 'missing', 'worked', 'balance'] as $key) {
                $html .= '<td class="vd-attendance-number">' . esc(formatMinutes($result[$key])) . '</td>';
            }
            $html .= '<td class="vd-attendance-notes">' . esc(implode('; ', array_unique($notes))) . '</td></tr>';
        }
        $html .= '<tr class="vd-attendance-totals"><td class="vd-attendance-date">Totais' . ($partial ? ' (parcial)' : '') . '</td>' .
            '<td colspan="4" class="vd-attendance-number">' . esc(formatMinutes($scheduleError ? null : $totals['planned'])) . '</td><td colspan="8"></td>' .
            '<td class="vd-attendance-number">' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['extra'])) . '</td>' .
            '<td class="vd-attendance-number">' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['missing'])) . '</td>' .
            '<td class="vd-attendance-number">' . esc(formatMinutes($unknown ? null : $totals['worked'])) . '</td>' .
            '<td class="vd-attendance-number">' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['balance'])) . '</td>' .
            '<td class="vd-attendance-notes"></td></tr>';
        $html .= '</tbody></table>';
        }
        if ($annual) {
        $globalCount++;
        $globalRows[] = [
            'name' => $employee['field_1876'],
            'valid' => !$scheduleError && !$unknown,
            'extra' => $totals['extra'],
            'missing' => $totals['missing'],
            'breakdown' => $breakdown,
            'unclassified' => $totals['unclassified'],
        ];
        if (!$scheduleError) $globalTotals['planned'] += $totals['planned'];
        if (!$unknown) $globalTotals['worked'] += $totals['worked'];
        if (!$scheduleError && !$unknown) {
            $globalTotals['extra'] += $totals['extra'];
            $globalTotals['missing'] += $totals['missing'];
            $globalTotals['balance'] += $totals['balance'];
        } else {
            $globalIncomplete++;
        }
        if ($partial) $globalPartial = true;
        }
        $html .= '<div class="vd-attendance-bottom"><div><h2>Geral' . ($partial ? ' (parcial)' : '') . '</h2><dl><dt>Horas previstas</dt><dd>' . esc(formatMinutes($scheduleError ? null : $totals['planned'])) . '</dd><dt>Horas trabalhadas</dt><dd>' . esc(formatMinutes($unknown ? null : $totals['worked'])) . '</dd><dt>Saldo' . ($partial ? ' (dias fechados)' : '') . '</dt><dd>' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['balance'])) . '</dd></dl></div>';
        $html .= '<div><h2>Faltas</h2><dl><dt>Total</dt><dd>' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['missing'])) . '</dd>';
        foreach ($breakdown as $label => $minutes) {
            if ($minutes) {
                $html .= '<dt>' . esc($label) . '</dt><dd>' . esc(formatMinutes($minutes)) . '</dd>';
            }
        }
        $html .= '<dt>Sem ausência aprovada</dt><dd>' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['unclassified'])) . '</dd></dl></div>';
        $html .= '<div><h2>Extra</h2><dl><dt>Fora do horário</dt><dd>' . esc(formatMinutes($scheduleError || $unknown ? null : $totals['extra'])) . '</dd></dl></div><div class="vd-attendance-signature">Concordo com as marcações acima registadas<div class="vd-attendance-signature-line"></div>Assinatura do colaborador</div></div>';
        $footerNotes = array_filter([$scheduleError, $linkError, $correctionError]);
        if ($partial) {
            $footerNotes[] = 'Resumo parcial: dias futuros, em curso ou com marcações inválidas/incompletas não geram faltas, extra nem saldo. Horas previstas contam apenas os dias até hoje.';
        }
        $footerNotes[] = 'Horário atual; segunda a sexta-feira, exceto feriados nacionais, São João de Braga (24/06) e feriados adicionais configurados. Minutos reais, sem tolerâncias. Correções ativas aplicadas sem alterar os originais.';
        $html .= '<footer><div>' . esc(implode(' ', $footerNotes)) . '</div><div class="vd-attendance-page">Página ' . ($pageIndex + 1) . ' de ' . count($employees) . '</div></footer>';
        if ($manager && !$detail) {
        $html .= '<details class="vd-attendance-records noprint"><summary>Marcações e correções · ' . esc($employee['field_1876']) . '</summary><p>Os registos do relógio permanecem intactos. Substitua/anule um original, ou acrescente uma marcação em falta. Apenas correções ativas são usadas. Para rever um ajuste, abra Gerir correções.</p><table><thead><tr><th>ID original</th><th>Entrada</th><th>Saída</th><th>Ações</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($originals as $original) {
            if ($original['start'] >= $to || ($original['end'] > 0 ? $original['end'] < $from : $original['start'] < $from)) continue;
            $shown++;
            $baseUrl = 'index.php?module=items/form&path=141-' . $id . '/409&fields[6607]=' . $original['id'];
            $replaceUrl = $baseUrl . '&fields[6606]=1634&fields[6608]=' . $original['start'] . '&fields[6609]=' . $original['end'];
            $cancelUrl = $baseUrl . '&fields[6606]=1635';
            $activeCorrections = array_filter($corrections, function ($correction) use ($original) {
                return $correction['active'] && (int) $correction['original'] === $original['id'];
            });
            $actions = '<a onclick="open_dialog(this.href); return false;" href="' . esc($replaceUrl) . '">Corrigir</a> · <a onclick="open_dialog(this.href); return false;" href="' . esc($cancelUrl) . '">Anular</a>';
            if ($activeCorrections) {
                $links = [];
                foreach ($activeCorrections as $correction) {
                    $editUrl = 'index.php?module=items/form&path=141-' . $id . '/409&id=' . $correction['id'];
                    $links[] = '<a onclick="open_dialog(this.href); return false;" href="' . esc($editUrl) . '">Editar correção ativa #' . $correction['id'] . '</a>';
                }
                $actions = implode(' · ', $links);
            }
            $html .= '<tr><td>#' . $original['id'] . '</td><td>' . esc(dateInZone($original['start'], $zone, 'd/m/Y H:i')) . '</td><td>' . ($original['end'] ? esc(dateInZone($original['end'], $zone, 'd/m/Y H:i')) : '<span class="vd-attendance-badge">Sem saída</span>') . '</td><td>' . $actions . '</td></tr>';
        }
        if (!$shown) $html .= '<tr><td colspan="4">Sem marcações originais neste mês. Utilize Acrescentar marcação para registar uma falta de marcação.</td></tr>';
        $html .= '</tbody></table><a href="' . esc($listUrl) . '">Consultar e editar correções guardadas (' . count($corrections) . ')</a></details>';
        }
        $html .= '</section>';
    }
    if ($annual) {
        $validRows = array_values(array_filter($globalRows, function ($row) { return $row['valid']; }));
        $byExtra = $validRows;
        usort($byExtra, function ($a, $b) { return $b['extra'] <=> $a['extra']; });
        $extraDetail = '<div class="vd-attendance-global-detail"><h3>Trabalho extraordinário por colaborador · ' . $first->format('Y') . '</h3><table><thead><tr><th>Colaborador</th><th class="num">Trabalho extraordinário</th></tr></thead><tbody>';
        foreach ($byExtra as $row) {
            $extraDetail .= '<tr><td>' . esc($row['name']) . '</td><td class="num">' . esc(formatMinutes($row['extra'])) . '</td></tr>';
        }
        $extraDetail .= '<tr class="total"><td>Total</td><td class="num">' . esc(formatMinutes($globalTotals['extra'])) . '</td></tr></tbody></table>' .
            ($globalIncomplete ? '<p>' . $globalIncomplete . ' colaborador(es) sem totais completos não incluídos.</p>' : '') . '</div>';
        $typeTotals = [];
        $unclassifiedTotal = 0;
        foreach ($validRows as $row) {
            foreach ($row['breakdown'] as $label => $minutes) {
                $typeTotals[$label] = ($typeTotals[$label] ?? 0) + $minutes;
            }
            $unclassifiedTotal += $row['unclassified'];
        }
        $typeParts = [];
        foreach ($typeTotals as $label => $minutes) {
            if ($minutes) $typeParts[] = esc($label) . ' ' . esc(formatMinutes($minutes));
        }
        if ($unclassifiedTotal) $typeParts[] = 'Sem ausência aprovada ' . esc(formatMinutes($unclassifiedTotal));
        $byMissing = $validRows;
        usort($byMissing, function ($a, $b) { return $b['missing'] <=> $a['missing']; });
        $missingDetail = '<div class="vd-attendance-global-detail"><h3>Faltas por colaborador · ' . $first->format('Y') . '</h3>' .
            ($typeParts ? '<p class="vd-attendance-global-types"><b>Por tipo:</b> ' . implode(' · ', $typeParts) . '</p>' : '') .
            '<table><thead><tr><th>Colaborador</th><th class="num">Faltas</th><th>Discriminação</th></tr></thead><tbody>';
        foreach ($byMissing as $row) {
            $parts = [];
            foreach ($row['breakdown'] as $label => $minutes) {
                if ($minutes) $parts[] = $label . ' ' . formatMinutes($minutes);
            }
            if ($row['unclassified']) $parts[] = 'Sem ausência aprovada ' . formatMinutes($row['unclassified']);
            $missingDetail .= '<tr><td>' . esc($row['name']) . '</td><td class="num">' . esc(formatMinutes($row['missing'])) . '</td><td>' . esc(implode('; ', $parts)) . '</td></tr>';
        }
        $missingDetail .= '<tr class="total"><td>Total</td><td class="num">' . esc(formatMinutes($globalTotals['missing'])) . '</td><td></td></tr></tbody></table>' .
            ($globalIncomplete ? '<p>' . $globalIncomplete . ' colaborador(es) sem totais completos não incluídos.</p>' : '') . '</div>';
        $globalBox = '<section class="vd-attendance-global noprint"><h2>Total global · ' . $first->format('Y') . ($globalPartial ? ' (parcial)' : '') . '</h2><dl>' .
            '<div><dt>Colaboradores</dt><dd>' . $globalCount . '</dd></div>' .
            '<div><dt>Horas previstas</dt><dd>' . esc(formatMinutes($globalTotals['planned'])) . '</dd></div>' .
            '<div><dt>Horas trabalhadas</dt><dd>' . esc(formatMinutes($globalTotals['worked'])) . '</dd></div>' .
            '<div><dt>Trabalho extraordinário</dt><dd><a href="#" onclick="vdAttendanceShow(\'vd-global-extra\'); return false;" title="Ver detalhe por colaborador">' . esc(formatMinutes($globalTotals['extra'])) . '</a></dd></div>' .
            '<div><dt>Faltas</dt><dd><a href="#" onclick="vdAttendanceShow(\'vd-global-missing\'); return false;" title="Ver detalhe por colaborador">' . esc(formatMinutes($globalTotals['missing'])) . '</a></dd></div>' .
            '<div><dt>Saldo</dt><dd>' . esc(formatMinutes($globalTotals['balance'])) . '</dd></div></dl>' .
            ($globalIncomplete ? '<p>' . $globalIncomplete . ' colaborador(es) sem totais completos (horário em falta ou correções por resolver); esses valores não estão incluídos.</p>' : '') .
            '<div id="vd-global-extra" hidden>' . $extraDetail . '</div><div id="vd-global-missing" hidden>' . $missingDetail . '</div>' .
            '</section>';
        $html = str_replace('%%VD_GLOBAL_TOTALS%%', $globalBox, $html);
    }
    return $html . '</div>';
}

function dateInZone(int $timestamp, \DateTimeZone $zone, string $format): string
{
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format($format);
}
