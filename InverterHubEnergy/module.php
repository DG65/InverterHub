<?php

// ---------------------------------------------------------------------------
// InverterHubEnergy — Energiefluss-/Sankey-Kachel über einen wählbaren Zeitraum.
// Zeigt, WOHIN die Energie geflossen ist: Quellen (Solar, Batterie-Entladung,
// Netzbezug) links, Verbraucher (Batterie-Ladung, Hausverbrauch/Einzel-
// verbraucher, Netzeinspeisung) rechts. Die Energiewerte werden NICHT selbst
// mitgeführt, sondern aus dem IP-Symcon-Archiv der zugewiesenen Energie-
// (Zähler-)Variablen über den Zeitraum gelesen (AC_GetAggregatedValues).
// ---------------------------------------------------------------------------

class InverterHubEnergy extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    private const CONSUMER_TYPES = [
        'wallbox'  => ['label' => 'Wallbox',         'color' => 0x9575CD],
        'heatpump' => ['label' => 'Wärmepumpe',      'color' => 0xFF7A18],
        'ac'       => ['label' => 'Klimaanlage',     'color' => 0x26C6DA],
        'poolheat' => ['label' => 'Pool-Wärmepumpe', 'color' => 0xFF8A50],
        'poolpump' => ['label' => 'Pool-Pumpe',      'color' => 0x26A69A],
        'sauna'    => ['label' => 'Sauna',           'color' => 0xF4511E],
        'boiler'   => ['label' => 'Warmwasser',      'color' => 0xFFA726],
        'dryer'    => ['label' => 'Trockner',        'color' => 0x78909C],
        'other'    => ['label' => 'Verbraucher',     'color' => 0x90A4AE],
    ];

    // Request-lokaler Cache für Archiv-Grenzwerte (Perioden teilen sich Grenzen).
    private $valCache = [];

    // Semantische Farben der festen Knoten.
    private const COL_SOLAR = '#F2C230';
    private const COL_BAT   = '#5FCB6B';
    private const COL_GRID  = '#4AA3E0';
    private const COL_LOAD  = '#E8823C';

    public function Create()
    {
        parent::Create();

        // Zeitraum: day | week | month | year | all | custom
        $this->RegisterPropertyString('Period', 'day');
        $this->RegisterPropertyInteger('CustomStart', 0);
        $this->RegisterPropertyInteger('CustomEnd', 0);

        // Energie-(Zähler-)Variablen der Quellen/Senken. Erwartet werden
        // akkumulierende Zähler mit aktivierter Archivierung (Aggregation
        // „Zähler"). Alle optional - fehlt ein Wert, entfällt der Knoten.
        $this->RegisterPropertyInteger('PvEnergyID', 0);
        $this->RegisterPropertyInteger('GridImportID', 0);
        $this->RegisterPropertyInteger('GridExportID', 0);
        $this->RegisterPropertyInteger('BatChargeID', 0);
        $this->RegisterPropertyInteger('BatDischargeID', 0);
        $this->RegisterPropertyInteger('HouseLoadID', 0);

        // Einzelverbraucher (Energie): [{Type,Name,EnergyID,Color}]
        $this->RegisterPropertyString('Consumers', '[]');

        // Diagramm-Engine: echarts | highcharts (wie in der Prognosekachel).
        $this->RegisterPropertyString('Engine', 'echarts');
        $this->RegisterPropertyInteger('ColorBackground', -1);
        $this->RegisterPropertyString('FontFamily', '');

        // Archiv-Auswertung ist teurer (mehrere Perioden × Variablen) - daher
        // per Timer gebündelt statt bei jeder Zähler-Änderung.
        $this->RegisterTimer('Refresh', 0, 'IHUBNRG_Refresh($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        $any = false;
        foreach ($this->AllEnergyVarIDs() as $vid) {
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterReference($vid);
                $any = true;
            }
        }
        $this->SetStatus($any ? 102 : 201);

        // Alle 2 min neu auswerten (nur wenn Datenpunkte zugewiesen sind).
        // Historische Perioden ändern sich nicht; nur der aktuelle Zeitraum läuft.
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
    // Payload
    // -----------------------------------------------------------------------

    private function BuildPayload()
    {
        $engine = ($this->ReadPropertyString('Engine') === 'highcharts') ? 'highcharts' : 'echarts';
        $style = [
            'engine' => $engine,
            'bg'     => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'font'   => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'unit'   => 'kWh',
        ];

        if (count($this->AllEnergyVarIDs()) === 0) {
            return json_encode(array_merge($style, ['ok' => false, 'stateLabel' => 'Keine Datenquelle']));
        }

        // Navigations-Fenster je Typ vorab berechnen (Index 0 = aktuell, höhere
        // Indizes = weiter zurück). So funktionieren Vor/Zurück UND die gezielte
        // Auswahl im Webfront ohne Server-Rückfrage. Der Boundary-Cache hält die
        // Archiv-Zugriffe gering (jeder Perioden-Grenzwert wird nur einmal
        // gelesen). Historische Perioden ändern sich nicht - die Neuberechnung
        // läuft per Timer nur alle 2 Minuten.
        $this->valCache = [];
        $win = ['day' => 31, 'week' => 26, 'month' => 24, 'year' => 6];

        $series = [];
        foreach ($win as $type => $count) {
            $arr = [];
            foreach ($this->PeriodDefs($type, $count) as $def) {
                [$s, $e, $plabel, $rlabel] = $def;
                $arr[] = array_merge($this->ComputeFlow($s, $e), ['label' => $plabel, 'range' => $rlabel]);
            }
            $series[$type] = $arr;
        }
        [$s, $e, $pl, $rl] = $this->ResolveRange('all');
        $series['all'] = [array_merge($this->ComputeFlow($s, $e), ['label' => $pl, 'range' => $rl])];
        if ($this->ReadPropertyString('Period') === 'custom' || $this->ReadPropertyInteger('CustomStart') > 0) {
            [$s, $e, $pl, $rl] = $this->ResolveRange('custom');
            $series['custom'] = [array_merge($this->ComputeFlow($s, $e), ['label' => $pl, 'range' => $rl])];
        }

        $default = $this->ReadPropertyString('Period');
        if (!isset($series[$default])) {
            $default = 'day';
        }

        return json_encode(array_merge($style, [
            'ok'          => true,
            'defaultType' => $default,
            'series'      => $series,
        ]));
    }

    // Perioden-Definitionen eines Typs (Index 0 = aktuell, dann rückwärts).
    // Rückgabe je Eintrag: [start, end, typLabel, bereichLabel].
    private function PeriodDefs(string $type, int $count): array
    {
        $now = time();
        $out = [];
        $mBase = strtotime(date('Y-m-01 00:00:00'));
        $yBase = strtotime(date('Y-01-01 00:00:00'));
        for ($k = 0; $k < $count; $k++) {
            switch ($type) {
                case 'week':
                    $s = strtotime("monday this week -{$k} weeks 00:00:00");
                    $eN = ($k === 0) ? $now : strtotime('monday this week -' . ($k - 1) . ' weeks 00:00:00');
                    $out[] = [$s, min($now, $eN), 'Woche', 'KW ' . date('W', $s) . ' / ' . date('Y', $s)];
                    break;
                case 'month':
                    $s = strtotime("-{$k} months", $mBase);
                    $eN = ($k === 0) ? $now : strtotime('-' . ($k - 1) . ' months', $mBase);
                    $out[] = [$s, min($now, $eN), 'Monat', $this->MonthName((int)date('n', $s)) . ' ' . date('Y', $s)];
                    break;
                case 'year':
                    $s = strtotime("-{$k} years", $yBase);
                    $eN = ($k === 0) ? $now : strtotime('-' . ($k - 1) . ' years', $yBase);
                    $out[] = [$s, min($now, $eN), 'Jahr', date('Y', $s)];
                    break;
                case 'day':
                default:
                    $s = strtotime("today -{$k} days 00:00:00");
                    $out[] = [$s, min($now, $s + 86400), 'Tag', date('d.m.Y', $s)];
                    break;
            }
        }
        return $out;
    }

    // Berechnet Knoten/Links des Sankeys für einen Zeitraum aus dem Archiv.
    private function ComputeFlow(int $start, int $end): array
    {
        $pv      = $this->PeriodEnergy($this->ReadPropertyInteger('PvEnergyID'),      $start, $end);
        $gridImp = $this->PeriodEnergy($this->ReadPropertyInteger('GridImportID'),    $start, $end);
        $gridExp = $this->PeriodEnergy($this->ReadPropertyInteger('GridExportID'),    $start, $end);
        $batCh   = $this->PeriodEnergy($this->ReadPropertyInteger('BatChargeID'),     $start, $end);
        $batDis  = $this->PeriodEnergy($this->ReadPropertyInteger('BatDischargeID'),  $start, $end);
        $houseE  = $this->PeriodEnergy($this->ReadPropertyInteger('HouseLoadID'),     $start, $end);

        $solar   = max(0.0, (float)$pv);
        $gridImp = max(0.0, (float)$gridImp);
        $gridExp = max(0.0, (float)$gridExp);
        $batCh   = max(0.0, (float)$batCh);
        $batDis  = max(0.0, (float)$batDis);

        // Aufteilungsmodell (Energiebilanz): Netzeinspeisung und Batterie-
        // Ladung stammen aus PV; der PV-Rest sowie Batterie-Entladung und
        // Netzbezug decken den Verbrauch.
        $pvToLoad = max(0.0, $solar - $gridExp - $batCh);
        $load     = ($houseE !== null && $houseE > 0)
            ? (float)$houseE
            : $pvToLoad + $batDis + $gridImp;

        // Einzelverbraucher (archivierte Energie je Zeile).
        $consumers = [];
        $consSum   = 0.0;
        foreach ($this->ReadConsumerRows() as $i => $row) {
            $e = $this->PeriodEnergy($row['id'], $start, $end);
            if ($e === null) {
                continue;
            }
            $e = max(0.0, (float)$e);
            $consumers[] = ['key' => 'c' . $i, 'label' => $row['name'], 'color' => $row['color'], 'val' => $e];
            $consSum += $e;
        }
        $rest = max(0.0, $load - $consSum);

        // 3-stufiges Sankey (Variante B): Erzeugung/Bezug (Spalte 0) → Batterie
        // als Puffer (Spalte 1) → Verbrauch/Einspeisung (Spalte 2). Die Batterie
        // ist EIN Knoten: Zufluss = Ladung (aus PV), Abfluss = Entladung (an den
        // Verbrauch). So wird der Speicher als Zwischenstufe sichtbar, statt
        // links und rechts doppelt aufzutauchen.
        $nodes    = [];
        $links    = [];
        $batNode  = ($batCh > 0 || $batDis > 0);

        // Spalte 0
        if ($solar   > 0) { $nodes[] = ['id' => 'solar',   'name' => 'Solar',      'color' => self::COL_SOLAR, 'col' => 0]; }
        if ($gridImp > 0) { $nodes[] = ['id' => 'gridimp', 'name' => 'Netzbezug',  'color' => self::COL_GRID,  'col' => 0]; }
        // Spalte 1
        if ($batNode)     { $nodes[] = ['id' => 'bat',     'name' => 'Batterie',   'color' => self::COL_BAT,   'col' => 1]; }
        // Spalte 2
        foreach ($consumers as $c) {
            $nodes[] = ['id' => $c['key'], 'name' => $c['label'], 'color' => $c['color'], 'col' => 2];
        }
        if ($rest > 0)    { $nodes[] = ['id' => 'rest',    'name' => ($consSum > 0 ? 'Sonstiger Verbrauch' : 'Hausverbrauch'), 'color' => self::COL_LOAD, 'col' => 2]; }
        if ($gridExp > 0) { $nodes[] = ['id' => 'gridexp', 'name' => 'Netzeinspeisung', 'color' => self::COL_GRID, 'col' => 2]; }

        $addLink = function ($from, $to, $val) use (&$links) {
            if ($val > 0.0001) {
                $links[] = ['from' => $from, 'to' => $to, 'val' => round($val, 3)];
            }
        };
        // PV → Batterie-Ladung / Netzeinspeisung
        if ($solar > 0 && $batCh > 0)   { $addLink('solar', 'bat', $batCh); }
        if ($solar > 0 && $gridExp > 0) { $addLink('solar', 'gridexp', $gridExp); }
        // Verbrauchs-Senken anteilig aus PV-Direkt / Batterie-Entladung / Netzbezug.
        $sinkList = [];
        foreach ($consumers as $c) { $sinkList[$c['key']] = $c['val']; }
        if ($rest > 0) { $sinkList['rest'] = $rest; }
        if ($load > 0) {
            $fPv   = $pvToLoad / $load;
            $fBat  = $batDis   / $load;
            $fGrid = $gridImp  / $load;
            foreach ($sinkList as $k => $v) {
                if ($solar > 0 && $pvToLoad > 0) { $addLink('solar',   $k, $v * $fPv); }
                if ($batNode && $batDis > 0)     { $addLink('bat',     $k, $v * $fBat); }
                if ($gridImp > 0)                { $addLink('gridimp', $k, $v * $fGrid); }
            }
        }

        return [
            'hasData' => (count($links) > 0),
            'totalIn' => round($solar + $gridImp, 2),
            'nodes'   => $nodes,
            'links'   => $links,
        ];
    }

    // Energie einer Zähler-Variable im Zeitraum. Robust über die ZÄHLER-
    // DIFFERENZ (Wert am Periodenende − Wert am Periodenanfang) statt über
    // aggregierte Mittelwerte: Das funktioniert unabhängig von der Archiv-
    // Aggregationsart (Zähler ODER Standard). Aggregierte 'Avg'-Werte sind bei
    // Standard-Variablen der Durchschnittswert und als Energiesumme unbrauchbar.
    // null, wenn keine Variable/kein Logging vorhanden.
    private function PeriodEnergy(int $vid, int $start, int $end): ?float
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $aid = $this->ArchiveID();
        if ($aid <= 0 || !@AC_GetLoggingStatus($aid, $vid)) {
            return null;
        }

        // Endwert: bei laufendem Zeitraum der aktuelle Wert, sonst aus dem Archiv.
        $endVal = ($end >= time() - 5) ? (float)GetValue($vid) : $this->ArchiveValueAt($aid, $vid, $end);
        if ($endVal === null) {
            return null;
        }
        // Startwert: jüngster geloggter Wert bei/vor Periodenbeginn.
        $startVal = $this->ArchiveValueAt($aid, $vid, $start);
        if ($startVal === null) {
            // Kein Wert vor Periodenbeginn (z. B. „Gesamt" ab 0) -> ältesten
            // geloggten Wert als Basis nehmen.
            $startVal = $this->ArchiveEarliest($aid, $vid, $end);
        }
        if ($startVal === null) {
            return null;
        }

        $delta = $endVal - $startVal;
        // Negative Differenz = Zählerrücksetzung/Ausreißer -> nicht auswerten.
        return ($delta >= 0) ? $delta : null;
    }

    // Jüngster geloggter Wert bei/vor Zeitpunkt $t (mit Request-Cache, da sich
    // aufeinanderfolgende Perioden ihre Grenzwerte teilen).
    private function ArchiveValueAt(int $aid, int $vid, int $t): ?float
    {
        if ($t <= 0) {
            return null;
        }
        $key = $vid . '|' . $t;
        if (array_key_exists($key, $this->valCache)) {
            return $this->valCache[$key];
        }
        $r = @AC_GetLoggedValues($aid, $vid, 0, $t, 1);
        $v = (is_array($r) && count($r)) ? (float)$r[0]['Value'] : null;
        return $this->valCache[$key] = $v;
    }

    // Ältester geloggter Wert (für „Gesamt"/Zeitraum ohne Wert vor Beginn).
    private function ArchiveEarliest(int $aid, int $vid, int $end): ?float
    {
        $r = @AC_GetLoggedValues($aid, $vid, 0, $end, 0);
        return (is_array($r) && count($r)) ? (float)$r[count($r) - 1]['Value'] : null;
    }

    private function ArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $ids[0] ?? 0;
    }

    // [start, end, periodLabel, rangeLabel]
    private function ResolveRange(string $period): array
    {
        $now = time();
        switch ($period) {
            case 'week':
                $start = strtotime('monday this week 00:00:00');
                return [$start, $now, 'Woche', 'KW ' . date('W', $start) . ' / ' . date('Y', $start)];
            case 'month':
                $start = strtotime(date('Y-m-01 00:00:00'));
                return [$start, $now, 'Monat', $this->MonthName((int)date('n', $start)) . ' ' . date('Y', $start)];
            case 'year':
                $start = strtotime(date('Y-01-01 00:00:00'));
                return [$start, $now, 'Jahr', date('Y', $start)];
            case 'all':
                return [0, $now, 'Gesamt', 'seit Aufzeichnung'];
            case 'custom':
                $s = $this->ReadPropertyInteger('CustomStart');
                $e = $this->ReadPropertyInteger('CustomEnd');
                if ($e <= 0) {
                    $e = $now;
                }
                if ($s <= 0) {
                    $s = $e - 86400;
                }
                return [$s, $e, 'Zeitraum', date('d.m.Y', $s) . ' – ' . date('d.m.Y', $e)];
            case 'day':
            default:
                $start = strtotime('today 00:00:00');
                return [$start, $now, 'Tag', date('d.m.Y', $start)];
        }
    }

    private function MonthName(int $m): string
    {
        $names = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
            'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        return $names[$m] ?? (string)$m;
    }

    private function ReadConsumerRows(): array
    {
        $rows = json_decode($this->ReadPropertyString('Consumers'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $vid = (int)($row['EnergyID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $type = (string)($row['Type'] ?? 'other');
            if (!isset(self::CONSUMER_TYPES[$type])) {
                $type = 'other';
            }
            $name  = trim((string)($row['Name'] ?? ''));
            $color = array_key_exists('Color', $row) ? (int)$row['Color'] : -1;
            if ($color < 0) {
                $color = self::CONSUMER_TYPES[$type]['color'];
            }
            $out[] = [
                'id'    => $vid,
                'name'  => ($name !== '' ? $name : self::CONSUMER_TYPES[$type]['label']),
                'color' => sprintf('#%06x', $color),
            ];
        }
        return $out;
    }

    private function AllEnergyVarIDs(): array
    {
        $ids = [
            $this->ReadPropertyInteger('PvEnergyID'),
            $this->ReadPropertyInteger('GridImportID'),
            $this->ReadPropertyInteger('GridExportID'),
            $this->ReadPropertyInteger('BatChargeID'),
            $this->ReadPropertyInteger('BatDischargeID'),
            $this->ReadPropertyInteger('HouseLoadID'),
        ];
        foreach ($this->ReadConsumerRows() as $row) {
            $ids[] = $row['id'];
        }
        return array_values(array_unique(array_filter($ids, fn ($v) => $v > 0)));
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
