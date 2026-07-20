<?php

// ---------------------------------------------------------------------------
// InverterHubMonitor — Monitoring-Kachel mit Intraday-Zeitreihen aus dem
// IP-Symcon-Archiv (à la Meteocontrol VCOM „Tatsächliche Leistung"). Stellt
// beliebige archivierte Variablen (z. B. PV-Leistung) zusammen mit einem
// Einstrahlungssensor (2. Y-Achse) über einen wählbaren Tag dar - so lassen
// sich Verschmutzung/Defekte am Abweichen von Leistung und Einstrahlung
// erkennen. Rendering wahlweise Highcharts oder ECharts.
// ---------------------------------------------------------------------------

class InverterHubMonitor extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const WINDOW_DAYS = 8;    // navigierbares Tages-Fenster (Verlauf)
    private const WINDOW_MONTHS = 12; // Monats-Fenster (Energie-Balken)
    private const WINDOW_YEARS = 5;   // Jahres-Fenster (Energie-Balken)
    private const AGG_5MIN = 5;       // IP-Symcon-Aggregationsstufe 5-Minuten
    private const AGG_DAY = 1;        // täglich
    private const AGG_MONTH = 3;      // monatlich

    private $defaultColors = ['#e0a020', '#2bb3c0', '#e05b4a', '#5fcb6b', '#9575cd', '#78909c'];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Engine', 'echarts');
        // Kurven-Tabelle: [{Label, VariableID, Color, Axis(left|right), Unit}]
        $this->RegisterPropertyString('Series', '[]');
        $this->RegisterPropertyInteger('ColorBackground', -1);
        $this->RegisterPropertyString('FontFamily', '');

        $this->RegisterTimer('Refresh', 0, 'IHUBMON_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        $any = false;
        foreach ($this->ReadSeriesRows() as $row) {
            $this->RegisterReference($row['id']);
            $any = true;
        }
        $this->SetStatus($any ? 102 : 201);
        // Intraday-Werte ändern sich laufend - alle 2 min neu laden.
        $this->SetTimerInterval('Refresh', $any ? 120000 : 0);

        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    public function Refresh()
    {
        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->BuildPayload()) . ');</script>';
        return $html;
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    // -----------------------------------------------------------------------

    private function BuildPayload()
    {
        $engine = ($this->ReadPropertyString('Engine') === 'highcharts') ? 'highcharts' : 'echarts';
        $style = [
            'engine' => $engine,
            'bg'     => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'font'   => $this->FontStack($this->ReadPropertyString('FontFamily')),
        ];

        $rows = $this->ReadSeriesRows();
        if (count($rows) === 0) {
            return json_encode(array_merge($style, ['ok' => false, 'stateLabel' => 'Keine Kurven konfiguriert']));
        }
        $aid = $this->ArchiveID();
        if ($aid <= 0) {
            return json_encode(array_merge($style, ['ok' => false, 'stateLabel' => 'Kein Archiv gefunden']));
        }

        $meta = [];
        foreach ($rows as $r) {
            $meta[] = ['label' => $r['label'], 'color' => $r['color'], 'axis' => $r['axis'], 'unit' => $r['unit']];
        }
        // Achsen-Einheiten für Verlauf (Leistung) und Energie (integriert).
        $leftUnit = ''; $rightUnit = ''; $leftEUnit = 'kWh'; $rightEUnit = 'kWh';
        foreach ($rows as $r) {
            $isM2 = (stripos($r['unit'], '/m²') !== false || stripos($r['unit'], '/m2') !== false);
            if ($r['axis'] === 'right' && $rightUnit === '') { $rightUnit = $r['unit']; $rightEUnit = $isM2 ? 'kWh/m²' : 'kWh'; }
            if ($r['axis'] !== 'right' && $leftUnit === '')  { $leftUnit = $r['unit'];  $leftEUnit = $isM2 ? 'kWh/m²' : 'kWh'; }
        }

        // --- Verlauf je Tag (5-Minuten-Linie) ---
        $dayPeriods = [];
        for ($k = 0; $k < self::WINDOW_DAYS; $k++) {
            $start = strtotime("today -{$k} days 00:00:00");
            $end   = min(time(), $start + 86400);
            $series = [];
            foreach ($rows as $r) {
                $series[] = $this->DaySeries($aid, $r['id'], $start, $end);
            }
            $dayPeriods[] = ['id' => date('Y-m-d', $start), 'range' => date('d.m.Y', $start), 'series' => $series];
        }

        // --- Energie je Monat (Tages-Balken) ---
        $monthPeriods = [];
        $mBase = strtotime(date('Y-m-01 00:00:00'));
        for ($k = 0; $k < self::WINDOW_MONTHS; $k++) {
            $mStart = strtotime("-{$k} months", $mBase);
            $mEnd   = min(time(), strtotime('+1 month', $mStart));
            $days   = (int)date('t', $mStart);
            $cats = [];
            for ($d = 1; $d <= $days; $d++) { $cats[] = (string)$d; }
            $series = [];
            foreach ($rows as $r) {
                $series[] = $this->EnergyBars($aid, $r['id'], $mStart, $mEnd, self::AGG_DAY, $days, 'j');
            }
            $monthPeriods[] = ['id' => date('Y-m', $mStart), 'range' => $this->MonthName((int)date('n', $mStart)) . ' ' . date('Y', $mStart), 'cats' => $cats, 'series' => $series];
        }

        // --- Energie je Jahr (Monats-Balken) ---
        $yearPeriods = [];
        $yBase = strtotime(date('Y-01-01 00:00:00'));
        $mNames = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        for ($k = 0; $k < self::WINDOW_YEARS; $k++) {
            $yStart = strtotime("-{$k} years", $yBase);
            $yEnd   = min(time(), strtotime('+1 year', $yStart));
            $series = [];
            foreach ($rows as $r) {
                $series[] = $this->EnergyBars($aid, $r['id'], $yStart, $yEnd, self::AGG_MONTH, 12, 'n');
            }
            $yearPeriods[] = ['id' => date('Y', $yStart), 'range' => date('Y', $yStart), 'cats' => $mNames, 'series' => $series];
        }

        return json_encode(array_merge($style, [
            'ok'          => true,
            'defaultType' => 'day',
            'seriesMeta'  => $meta,
            'types'       => [
                'day'   => ['mode' => 'line', 'leftUnit' => $leftUnit,  'rightUnit' => $rightUnit,  'periods' => $dayPeriods],
                'month' => ['mode' => 'bar',  'leftUnit' => $leftEUnit, 'rightUnit' => $rightEUnit, 'periods' => $monthPeriods],
                'year'  => ['mode' => 'bar',  'leftUnit' => $leftEUnit, 'rightUnit' => $rightEUnit, 'periods' => $yearPeriods],
            ],
        ]));
    }

    // Energie-Balken: Ø-Leistung je Bucket × Bucketdauer -> Energie (kWh).
    // $slot = 'j' (Tag im Monat) oder 'n' (Monat im Jahr). $count = Anzahl Slots.
    private function EnergyBars(int $aid, int $vid, int $start, int $end, int $span, int $count, string $slot): array
    {
        $out = array_fill(0, $count, null);
        if (!IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return $out;
        }
        $data = @AC_GetAggregatedValues($aid, $vid, $span, $start, $end, 0);
        if (!is_array($data)) {
            return $out;
        }
        foreach ($data as $row) {
            $ts = (int)$row['TimeStamp'];
            $avg = (float)$row['Avg'];
            // Stunden im Bucket: Tag = 24, Monat = Tage×24.
            $hours = ($span === self::AGG_MONTH) ? ((int)date('t', $ts) * 24) : 24;
            $kwh = $avg * $hours / 1000.0;
            $idx = (int)date($slot, $ts) - 1; // Tag/Monat 1-basiert -> 0-basiert
            if ($idx >= 0 && $idx < $count) {
                $out[$idx] = round($kwh, 2);
            }
        }
        return $out;
    }

    private function MonthName(int $m): string
    {
        $n = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        return $n[$m] ?? (string)$m;
    }

    // 5-Minuten-Zeitreihe (Mittelwert je Bucket) eines Tages als [[tsMs,val],…].
    private function DaySeries(int $aid, int $vid, int $start, int $end): array
    {
        if (!IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_5MIN, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $pts = [];
        foreach ($data as $row) {
            $pts[] = [((int)$row['TimeStamp']) * 1000, round((float)$row['Avg'], 2)];
        }
        // Aggregierte Werte kommen neueste-zuerst - für die Kurve aufsteigend.
        usort($pts, function ($a, $b) { return $a[0] <=> $b[0]; });
        return $pts;
    }

    private function ReadSeriesRows(): array
    {
        $rows = json_decode($this->ReadPropertyString('Series'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $i => $row) {
            $vid = (int)($row['VariableID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $label = trim((string)($row['Label'] ?? ''));
            if ($label === '') {
                $label = IPS_GetName($vid);
            }
            $color = array_key_exists('Color', $row) ? (int)$row['Color'] : -1;
            $hex = ($color >= 0) ? sprintf('#%06x', $color) : $this->defaultColors[$i % count($this->defaultColors)];
            $axis = ((string)($row['Axis'] ?? 'left') === 'right') ? 'right' : 'left';
            $out[] = [
                'id'    => $vid,
                'label' => $label,
                'color' => $hex,
                'axis'  => $axis,
                'unit'  => trim((string)($row['Unit'] ?? '')),
            ];
        }
        return $out;
    }

    private function ArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $ids[0] ?? 0;
    }

    private function ColorOrEmpty(int $color): string
    {
        return ($color >= 0) ? sprintf('#%06x', $color) : '';
    }

    private function FontStack(string $font): string
    {
        $font = trim($font);
        return ($font !== '') ? $font : '';
    }
}
