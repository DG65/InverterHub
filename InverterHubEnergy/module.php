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

        $this->RegisterPropertyInteger('ColorBackground', -1);
        $this->RegisterPropertyString('FontFamily', '');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        // Alte Abos lösen.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        $any = false;
        foreach ($this->AllEnergyVarIDs() as $vid) {
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterReference($vid);
                $this->RegisterMessage($vid, VM_UPDATE);
                $any = true;
            }
        }
        $this->SetStatus($any ? 102 : 201);

        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->BuildPayload());
        }
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
        $style = [
            'bg'   => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'font' => $this->FontStack($this->ReadPropertyString('FontFamily')),
        ];

        [$start, $end, $periodLabel, $rangeLabel] = $this->ResolveRange();

        $pv      = $this->PeriodEnergy($this->ReadPropertyInteger('PvEnergyID'),      $start, $end);
        $gridImp = $this->PeriodEnergy($this->ReadPropertyInteger('GridImportID'),    $start, $end);
        $gridExp = $this->PeriodEnergy($this->ReadPropertyInteger('GridExportID'),    $start, $end);
        $batCh   = $this->PeriodEnergy($this->ReadPropertyInteger('BatChargeID'),     $start, $end);
        $batDis  = $this->PeriodEnergy($this->ReadPropertyInteger('BatDischargeID'),  $start, $end);
        $houseE  = $this->PeriodEnergy($this->ReadPropertyInteger('HouseLoadID'),     $start, $end);

        // Kein einziger Wert verfügbar -> keine Datenquelle.
        if ($pv === null && $gridImp === null && $gridExp === null && $batCh === null && $batDis === null) {
            return json_encode(array_merge($style, [
                'ok'        => false,
                'stateLabel'=> 'Keine Datenquelle',
                'period'    => $periodLabel,
                'range'     => $rangeLabel,
            ]));
        }

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

        // ---- Quellen (links) ----
        $sources = [];
        if ($solar   > 0) { $sources['solar'] = ['key' => 'solar', 'label' => 'Solar',    'color' => self::COL_SOLAR, 'val' => $solar]; }
        if ($batDis  > 0) { $sources['bat']   = ['key' => 'bat',   'label' => 'Batterie', 'color' => self::COL_BAT,   'val' => $batDis]; }
        if ($gridImp > 0) { $sources['grid']  = ['key' => 'grid',  'label' => 'Netz',     'color' => self::COL_GRID,  'val' => $gridImp]; }

        // ---- Senken (rechts) ----
        $sinks = [];
        if ($batCh > 0)   { $sinks['batch']  = ['key' => 'batch', 'label' => 'Batterie', 'color' => self::COL_BAT, 'val' => $batCh]; }
        foreach ($consumers as $c) {
            $sinks[$c['key']] = $c;
        }
        if ($rest > 0)    { $sinks['rest']   = ['key' => 'rest',  'label' => ($consSum > 0 ? 'Sonstiger Verbrauch' : 'Hausverbrauch'), 'color' => self::COL_LOAD, 'val' => $rest]; }
        if ($gridExp > 0) { $sinks['gridexp']= ['key' => 'gridexp','label' => 'Netz',    'color' => self::COL_GRID, 'val' => $gridExp]; }

        // ---- Flüsse ----
        $flows = [];
        $add = function ($from, $to, $val) use (&$flows) {
            if ($val > 0.0001 && isset($from) && isset($to)) {
                $flows[] = ['from' => $from, 'to' => $to, 'val' => $val];
            }
        };
        // PV -> Batterie-Ladung / Netzeinspeisung
        if (isset($sources['solar'])) {
            if (isset($sinks['batch']))   { $add('solar', 'batch', $batCh); }
            if (isset($sinks['gridexp'])) { $add('solar', 'gridexp', $gridExp); }
        }
        // Verbrauchs-Senken anteilig aus PV/Batterie/Netz.
        if ($load > 0) {
            $fPv   = $pvToLoad / $load;
            $fBat  = $batDis   / $load;
            $fGrid = $gridImp  / $load;
            foreach ($sinks as $k => $s) {
                if ($k === 'batch' || $k === 'gridexp') {
                    continue;
                }
                if (isset($sources['solar'])) { $add('solar', $k, $s['val'] * $fPv); }
                if (isset($sources['bat']))   { $add('bat',   $k, $s['val'] * $fBat); }
                if (isset($sources['grid']))  { $add('grid',  $k, $s['val'] * $fGrid); }
            }
        }

        $sumSrc  = array_sum(array_column($sources, 'val'));
        $sumSink = array_sum(array_column($sinks, 'val'));

        $fmtNodes = function (array $nodes, float $total) {
            $out = [];
            foreach ($nodes as $n) {
                $n['pct'] = $total > 0 ? round($n['val'] / $total * 100, 2) : 0.0;
                $n['val'] = round($n['val'], 2);
                $out[] = $n;
            }
            return array_values($out);
        };

        return json_encode(array_merge($style, [
            'ok'      => true,
            'period'  => $periodLabel,
            'range'   => $rangeLabel,
            'unit'    => 'kWh',
            'sources' => $fmtNodes($sources, $sumSrc),
            'sinks'   => $fmtNodes($sinks, $sumSink),
            'flows'   => array_map(function ($f) {
                $f['val'] = round($f['val'], 3);
                return $f;
            }, $flows),
        ]));
    }

    // Energie einer Zähler-Variable im Zeitraum aus dem Archiv (Summe der
    // Bucket-Differenzen; für Counter-Aggregation steht die Differenz im Feld
    // 'Avg'). null, wenn keine Variable/kein Logging vorhanden.
    private function PeriodEnergy(int $vid, int $start, int $end): ?float
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $aid = $this->ArchiveID();
        if ($aid <= 0 || !@AC_GetLoggingStatus($aid, $vid)) {
            return null;
        }
        // Aggregationsstufe je Periodenlänge (0=Stunde,1=Tag,3=Monat,4=Jahr).
        $span = $this->AggregationSpan($start, $end);
        $data = @AC_GetAggregatedValues($aid, $vid, $span, $start, $end, 0);
        if (!is_array($data)) {
            return null;
        }
        $sum = 0.0;
        foreach ($data as $row) {
            $sum += (float)($row['Avg'] ?? 0.0);
        }
        return $sum;
    }

    private function AggregationSpan(int $start, int $end): int
    {
        $days = max(1, (int)ceil(($end - $start) / 86400));
        if ($days <= 2)   { return 0; } // stündlich
        if ($days <= 62)  { return 1; } // täglich
        if ($days <= 400) { return 3; } // monatlich
        return 4;                        // jährlich
    }

    private function ArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $ids[0] ?? 0;
    }

    // [start, end, periodLabel, rangeLabel]
    private function ResolveRange(): array
    {
        $now = time();
        $period = $this->ReadPropertyString('Period');
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
