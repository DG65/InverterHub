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
    private const WINDOW_DAYS = 8;   // navigierbares Tages-Fenster
    private const AGG_5MIN = 5;      // IP-Symcon-Aggregationsstufe 5-Minuten

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
        $leftUnit = ''; $rightUnit = '';
        foreach ($rows as $r) {
            if ($r['axis'] === 'right' && $rightUnit === '') { $rightUnit = $r['unit']; }
            if ($r['axis'] !== 'right' && $leftUnit === '')  { $leftUnit = $r['unit']; }
        }

        $days = [];
        for ($k = 0; $k < self::WINDOW_DAYS; $k++) {
            $start = strtotime("today -{$k} days 00:00:00");
            $end   = min(time(), $start + 86400);
            $series = [];
            foreach ($rows as $r) {
                $series[] = $this->DaySeries($aid, $r['id'], $start, $end);
            }
            $days[] = ['id' => date('Y-m-d', $start), 'range' => date('d.m.Y', $start), 'series' => $series];
        }

        return json_encode(array_merge($style, [
            'ok'         => true,
            'seriesMeta' => $meta,
            'leftUnit'   => $leftUnit,
            'rightUnit'  => $rightUnit,
            'oldest'     => $days[count($days) - 1]['id'],
            'newest'     => $days[0]['id'],
            'days'       => $days,
        ]));
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
