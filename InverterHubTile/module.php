<?php

/**
 * InverterHubTile
 *
 * HTML-Kachel für InverterHub. Liest Variablen einer InverterHub-Instanz
 * (beliebiger Hersteller) und stellt sie als animierte Energiefluss-Kachel
 * dar (Solar / Netz / Last / Batterie). Da die Datenpunkte je nach
 * Hersteller-Treiber unterschiedlich heißen bzw. teils fehlen (nicht jeder
 * Treiber liefert Netzmessung oder Batteriewerte), wird pro Größe eine
 * Ident-Fallback-Kette probiert; fehlt eine Größe komplett, wird der
 * zugehörige Kreis ausgegraut statt mit falschen Werten befüllt.
 *
 * Pattern identisch zu GoodweETTile (DG65).
 */
class InverterHubTile extends IPSModule
{
    private const SOURCE_MODULE = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';

    // Ident-Fallback-Ketten je Größe (erster gefundener Ident gewinnt).
    // pv_real (berechnete PV-Erzeugung, z. B. SolarEdge StorEdge) hat Vorrang
    // vor pv_total, da Letzteres bei Batteriebetrieb die reine PV nicht abbildet.
    private const IDENT_PV     = ['pv_real', 'pv_total'];
    private const IDENT_AC     = ['ac_power'];
    private const IDENT_GRID   = ['meter_total'];
    private const IDENT_BATPWR = ['bat_total_pwr', 'bat_power'];
    private const IDENT_SOC    = ['soc', 'bat_soc'];
    private const IDENT_CONN   = ['connected'];

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';
    private const DEF_TRANSITION = 800;
    private const DEF_TOLERANCE  = 300;
    private const DEF_FLOWREF    = 10000;

    // Auswählbare Verbraucher-Arten. Der Schlüssel steht in der Konfiguration,
    // 'label' dient als Vorgabe-Bezeichnung (wenn der Nutzer keine eigene
    // vergibt), 'icon' verweist auf den Icon-Zeichner in module.html und
    // 'color' ist die Vorgabefarbe der Art (je Zeile überschreibbar).
    // Farbwahl: Wärme in Feuertönen, Kühlung/Wasser in Türkis, Fahrzeuge in
    // Violett (bewusst abgesetzt von der blauen Hausbatterie).
    private const CONSUMER_TYPES = [
        'wallbox'  => ['label' => 'Wallbox',         'icon' => 'car',      'color' => 0x9575CD],
        'heatpump' => ['label' => 'Wärmepumpe',      'icon' => 'heatpump', 'color' => 0xFF7A18],
        'ac'       => ['label' => 'Klimaanlage',     'icon' => 'ac',       'color' => 0x26C6DA],
        'poolheat' => ['label' => 'Pool-Wärmepumpe', 'icon' => 'poolheat', 'color' => 0xFF8A50],
        'poolpump' => ['label' => 'Pool-Pumpe',      'icon' => 'poolpump', 'color' => 0x26A69A],
        'sauna'    => ['label' => 'Sauna',           'icon' => 'sauna',    'color' => 0xF4511E],
        'boiler'   => ['label' => 'Warmwasser',      'icon' => 'boiler',   'color' => 0xFFA726],
        'dryer'    => ['label' => 'Trockner',        'icon' => 'dryer',    'color' => 0x78909C],
        'other'    => ['label' => 'Verbraucher',     'icon' => 'other',    'color' => 0x90A4AE],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('SourceInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily',       self::DEF_FONT);
        $this->RegisterPropertyInteger('TransitionMs',    self::DEF_TRANSITION);
        // Referenzleistung fürs Fluss-Tempo: bei dieser Leistung laufen die
        // Dreiecke mit Höchsttempo. Kleinerer Wert = Unterschiede im
        // Alltagsbereich (1-10 kW) deutlicher sichtbar.
        $this->RegisterPropertyInteger('FlowRefW', self::DEF_FLOWREF);
        // Zusätzliche Verbraucher, die nicht aus dem Wechselrichter kommen,
        // sondern als vorhandene Leistungs-Variablen ausgewählt werden.
        // Frei erweiterbare Tabelle: je Zeile Art, Bezeichnung und Variable.
        $this->RegisterPropertyString('Consumers', '[]');
        // Fahrzeuge (für Wallboxen): Bezeichnung, Verbunden-Bedingung, SOC.
        $this->RegisterPropertyString('Vehicles', '[]');
        // Zeitfenster für die automatische Zuordnung Fahrzeug <-> Wallbox.
        $this->RegisterPropertyInteger('MatchToleranceSec', self::DEF_TOLERANCE);
        // Berechnete Hauslast zusätzlich in eine eigene Variable schreiben.
        $this->RegisterPropertyBoolean('WriteHouseLoad', false);

        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        $src = $this->ResolveSource();
        if ($src > 0 && IPS_InstanceExists($src)) {
            $allIdents = array_merge(
                self::IDENT_PV, self::IDENT_AC, self::IDENT_GRID,
                self::IDENT_BATPWR, self::IDENT_SOC, self::IDENT_CONN
            );
            foreach (array_unique($allIdents) as $ident) {
                $vid = $this->FindIdentRecursive($src, $ident);
                if ($vid && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
            $this->SetStatus(102);
        } else {
            $this->SetStatus(201);
        }

        // Zusätzliche Verbraucher (Wärmepumpe/Wallboxen) liegen außerhalb der
        // Quell-Instanz und müssen separat abonniert werden.
        foreach ($this->CollectConsumerVarIDs() as $vid) {
            $this->RegisterReference($vid);
            $this->RegisterMessage($vid, VM_UPDATE);
        }

        // Optionale Ausgabe der berechneten Hauslast als eigene Variable.
        $this->MaintainVariable(
            'house_load',
            'Hauslast (berechnet)',
            VARIABLETYPE_FLOAT,
            '~Watt',
            10,
            $this->ReadPropertyBoolean('WriteHouseLoad')
        );

        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    // Liefert die IDs aller konfigurierten Verbraucher-Variablen, gefiltert auf
    // tatsächlich existierende Variablen.
    private function CollectConsumerVarIDs()
    {
        $ids = [];
        foreach ($this->ReadConsumerRows() as $row) {
            $ids[] = $row['id'];
            if ($row['plugID'] > 0 && IPS_VariableExists($row['plugID'])) {
                $ids[] = $row['plugID'];
            }
        }
        foreach ($this->ReadVehicleRows() as $v) {
            $ids[] = $v['socID'];
            if ($v['plugID'] > 0 && IPS_VariableExists($v['plugID'])) {
                $ids[] = $v['plugID'];
            }
        }
        return array_unique($ids);
    }

    // Verbraucher-Tabelle aus der Konfiguration lesen und auf gültige Zeilen
    // reduzieren (existierende Variable). Unbekannte Arten fallen auf
    // 'other' zurück, eine leere Bezeichnung auf die Vorgabe der Art.
    private function ReadConsumerRows()
    {
        $rows = json_decode($this->ReadPropertyString('Consumers'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $vid = (int)($row['VariableID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $type = (string)($row['Type'] ?? 'other');
            if (!isset(self::CONSUMER_TYPES[$type])) {
                $type = 'other';
            }
            $name = trim((string)($row['Name'] ?? ''));
            // Eigene Farbe je Zeile; -1 (bzw. nicht gesetzt) = Vorgabe der Art.
            $color = array_key_exists('Color', $row) ? (int)$row['Color'] : -1;
            if ($color < 0) {
                $color = self::CONSUMER_TYPES[$type]['color'];
            }
            $out[] = [
                'id'      => $vid,
                'type'    => $type,
                'name'    => ($name !== '' ? $name : self::CONSUMER_TYPES[$type]['label']),
                'icon'    => self::CONSUMER_TYPES[$type]['icon'],
                'color'   => sprintf('#%06x', $color),
                'unit'    => (string)($row['Unit'] ?? 'auto'),
                // Nur für Wallboxen relevant, sonst unbenutzt.
                'plugID'  => (int)($row['PlugID'] ?? 0),
                'plugOp'  => (string)($row['PlugOp'] ?? 'truthy'),
                'plugVal' => (string)($row['PlugVal'] ?? ''),
            ];
        }
        return $out;
    }

    // Fahrzeug-Tabelle lesen.
    private function ReadVehicleRows()
    {
        $rows = json_decode($this->ReadPropertyString('Vehicles'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $socID = (int)($row['SocID'] ?? 0);
            if ($socID <= 0 || !IPS_VariableExists($socID)) {
                continue;
            }
            $name = trim((string)($row['Name'] ?? ''));
            $out[] = [
                'name'    => ($name !== '' ? $name : 'Fahrzeug'),
                'socID'   => $socID,
                'plugID'  => (int)($row['PlugID'] ?? 0),
                'plugOp'  => (string)($row['PlugOp'] ?? 'truthy'),
                'plugVal' => (string)($row['PlugVal'] ?? ''),
            ];
        }
        return $out;
    }

    // Ordnet Fahrzeuge den Wallboxen zu - ohne dass irgendwo ein Datenpunkt
    // "welches Auto steht hier" existieren müsste.
    //
    // Idee: Wallbox und Fahrzeug melden das Verbinden BEIDE, nur eben jedes für
    // sich. Wird ein Auto eingesteckt, wechseln daher beide Zustände praktisch
    // gleichzeitig. Als Zeitpunkt dient der von IP-Symcon ohnehin geführte
    // Zeitstempel der letzten Wertänderung ('VariableChanged'). Die Paare
    // werden nach zeitlicher Nähe sortiert und eindeutig (1:1) vergeben, sodass
    // bei zwei Autos an zwei Wallboxen jedes dort landet, wo es eingesteckt
    // wurde.
    //
    // Rückgabe: [ Index der Verbraucher-Zeile => Index des Fahrzeugs ]
    private function AssignVehicles($rows, $vehicles)
    {
        $tol = max(0, (int)$this->ReadPropertyInteger('MatchToleranceSec'));

        $wbConnected  = [];   // Zeilen-Index => Zeitpunkt des Verbindens
        $wbAllIdx     = [];
        foreach ($rows as $i => $row) {
            if ($row['type'] !== 'wallbox') {
                continue;
            }
            $wbAllIdx[] = $i;
            if ($this->CondMet($row['plugID'], $row['plugOp'], $row['plugVal']) === true) {
                $wbConnected[$i] = $this->ChangedAt($row['plugID']);
            }
        }

        $vConnected = [];
        foreach ($vehicles as $j => $v) {
            if ($this->CondMet($v['plugID'], $v['plugOp'], $v['plugVal']) === true) {
                $vConnected[$j] = $this->ChangedAt($v['plugID']);
            }
        }

        // Alle möglichen Paare innerhalb des Zeitfensters bilden und nach
        // zeitlicher Nähe aufsteigend eindeutig vergeben.
        $pairs = [];
        foreach ($wbConnected as $i => $tw) {
            foreach ($vConnected as $j => $tv) {
                $d = abs($tw - $tv);
                if ($tol > 0 && $d > $tol) {
                    continue;
                }
                $pairs[] = ['d' => $d, 'w' => $i, 'v' => $j];
            }
        }
        usort($pairs, function ($a, $b) {
            return $a['d'] <=> $b['d'];
        });

        $map   = [];
        $usedV = [];
        foreach ($pairs as $p) {
            if (isset($map[$p['w']]) || isset($usedV[$p['v']])) {
                continue;
            }
            $map[$p['w']]   = $p['v'];
            $usedV[$p['v']] = true;
        }

        // Sonderfall genau eine Wallbox / genau ein Fahrzeug: Da ist die Lage
        // auch ohne Zeitkorrelation eindeutig - hier darf die
        // Verbunden-Bedingung des Fahrzeugs also auch fehlen.
        if (count($map) === 0 && count($wbAllIdx) === 1 && count($vehicles) === 1) {
            $i = $wbAllIdx[0];
            $wbState = $this->CondMet($rows[$i]['plugID'], $rows[$i]['plugOp'], $rows[$i]['plugVal']);
            $vState  = $this->CondMet($vehicles[0]['plugID'], $vehicles[0]['plugOp'], $vehicles[0]['plugVal']);
            if ($wbState !== false && $vState !== false) {
                $map[$i] = 0;
            }
        }

        return $map;
    }

    // Wertet eine "verbunden"-Bedingung aus: Variable + Operator + Vergleichswert.
    // Nötig, weil jede Quelle das anders meldet - z. B. Boolean (Ladeklappe),
    // String (Ladekabeltyp, leer = kein Kabel) oder Integer (go-e
    // "Kabel-Leistungsfähigkeit", 0 = kein Kabel). Rückgabe null = nicht
    // konfiguriert (unbekannt), sonst true/false.
    private function CondMet($varID, $op, $val)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return null;
        }
        $v = GetValue($varID);

        switch ($op) {
            case 'eq': return $this->Equals($v, $val);
            case 'ne': return !$this->Equals($v, $val);
            case 'gt': return $this->Num($v) >  (float)$val;
            case 'ge': return $this->Num($v) >= (float)$val;
            case 'lt': return $this->Num($v) <  (float)$val;
            case 'le': return $this->Num($v) <= (float)$val;
            default:   return $this->Truthy($v);   // 'truthy'
        }
    }

    // Gleichheit: numerisch vergleichen, wenn beide Seiten Zahlen sind
    // (sonst wäre 0 != "0.0"), ansonsten als getrimmter Text ohne
    // Beachtung der Groß-/Kleinschreibung.
    private function Equals($v, $val)
    {
        if (is_bool($v)) {
            return $v === $this->Truthy($val);
        }
        if (is_numeric($v) && is_numeric($val)) {
            return ((float)$v) == ((float)$val);
        }
        return strcasecmp(trim((string)$v), trim((string)$val)) === 0;
    }

    private function Num($v)
    {
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        return is_numeric($v) ? (float)$v : 0.0;
    }

    // "Belegt/verbunden" ohne expliziten Vergleichswert: true, ungleich 0
    // bzw. nicht-leerer Text.
    private function Truthy($v)
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((float)$v) != 0.0;
        }
        $s = strtolower(trim((string)$v));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'nein');
    }

    // Zeitpunkt der letzten WERT-Änderung einer Variable. IP-Symcon führt das
    // von Haus aus mit ('VariableChanged' ändert sich nur bei echtem
    // Wertwechsel, nicht bei jeder Aktualisierung) - genau das brauchen wir
    // als "verbunden seit", ganz ohne eigenen Datenpunkt.
    private function ChangedAt($varID)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return 0;
        }
        $info = @IPS_GetVariable($varID);
        return $info ? (int)$info['VariableChanged'] : 0;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->BuildPayload());
        }
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    // Idents der Quell-Instanz liegen ggf. in Unterkategorien, daher
    // rekursive Suche statt IPS_GetObjectIDByIdent (nur direkte Kinder).
    private function FindIdentRecursive(int $parentID, string $ident): int
    {
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectIdent'] === $ident) {
                return $childID;
            }
            if ($obj['ObjectType'] === 0) {
                $found = $this->FindIdentRecursive($childID, $ident);
                if ($found) {
                    return $found;
                }
            }
        }
        return 0;
    }

    public function ResetStyle()
    {
        $id = $this->InstanceID;
        IPS_SetProperty($id, 'ColorBackground', self::DEF_BACKGROUND);
        IPS_SetProperty($id, 'FontFamily',      self::DEF_FONT);
        IPS_SetProperty($id, 'TransitionMs',    self::DEF_TRANSITION);
        IPS_SetProperty($id, 'FlowRefW',        self::DEF_FLOWREF);
        IPS_ApplyChanges($id);
        $this->ReloadForm();
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->BuildPayload()) . ');</script>';
        return $html;
    }

    // -----------------------------------------------------------------------
    // Payload-Aufbau
    // -----------------------------------------------------------------------

    private function BuildPayload()
    {
        $style = [
            'bg'      => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'font'    => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'transMs'  => $this->TransitionValue(),
            'flowRefW' => $this->FlowRefValue(),
        ];

        $src = $this->ResolveSource();
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode(array_merge($style, [
                'ok'        => false,
                'stateLabel'=> 'Keine Datenquelle',
            ]));
        }

        $find = function (array $idents) use ($src) {
            foreach ($idents as $ident) {
                $vid = $this->FindIdentRecursive($src, $ident);
                if ($vid && $vid > 0) {
                    return GetValue($vid);
                }
            }
            return null;
        };

        $connected = $find(self::IDENT_CONN);
        $connected = ($connected === null) ? true : (bool)$connected; // Treiber ohne 'connected' gelten als verbunden

        $pv    = $find(self::IDENT_PV);
        $ac    = $find(self::IDENT_AC);
        $grid  = $find(self::IDENT_GRID);
        $bat   = $find(self::IDENT_BATPWR);
        $soc   = $find(self::IDENT_SOC);

        $pvHave   = ($pv !== null);
        $gridHave = ($grid !== null);
        $batHave  = ($bat !== null);
        $socHave  = ($soc !== null);

        $pvW   = $pvHave ? (float)$pv : 0.0;
        $gridW = $gridHave ? (float)$grid : 0.0;
        $batW  = $batHave ? (float)$bat : 0.0;
        // Hat die Quell-Instanz die Batterie invertiert (Nutzer-Konvention),
        // rechnet die Kachel intern wieder auf ihre kanonische Konvention
        // zurück (+ = Entladen), damit die Flussrichtung stimmt. Der angezeigte
        // Betrag ist ohnehin identisch (|value|).
        if ($batHave && (bool)@IPS_GetProperty($src, 'BatInvert')) {
            $batW = -$batW;
        }
        // Analog fürs Netz: Dreht der Nutzer die Meter-Leistung um (Schalter
        // „Netz-Leistung invertieren", z. B. für die Konvention Einspeisung
        // negativ), rechnet die Kachel intern wieder auf ihre kanonische
        // Konvention (+ = Einspeisung) zurück, sonst zeigt der Netz-Kreis die
        // Flussrichtung verkehrt und die Hauslast-Bilanz wird falsch.
        if ($gridHave && (bool)@IPS_GetProperty($src, 'MeterInvert')) {
            $gridW = -$gridW;
        }

        // Last (Hausverbrauch) per Bilanz. Bevorzugt über die AC-Wirkleistung:
        //   Hauslast = AC-Leistung − Netzeinspeisung   (gridW: + = Einspeisung)
        // Die AC-Wirkleistung ist bereits das, was der Wechselrichter NACH der
        // Batterie ans Hausnetz abgibt - dadurch braucht diese Bilanz keine
        // Batteriedaten und ist auch dann korrekt, wenn die Batterie gerade
        // lädt (z. B. PV 8 kW, Ladung 7 kW -> AC 1 kW -> Hauslast 1 kW). Die
        // frühere PV-basierte Formel überschätzte die Last um die Ladeleistung,
        // sobald die Batteriedaten fehlten oder die Batterie-Gruppe aus war.
        //
        // Rückfall auf die DC-Bilanz (PV + Batterie-Entladung − Einspeisung)
        // nur, wenn keine AC-Wirkleistung vorliegt. Vorzeichen Batterie:
        // positiv = Entladung, negativ = Ladung.
        $houseHave = false;
        $houseBalanceW = 0.0;
        if ($ac !== null && $gridHave) {
            $houseHave     = true;
            $houseBalanceW = max(0.0, (float)$ac - $gridW);
        } elseif ($pvHave && $gridHave) {
            $houseHave     = true;
            $houseBalanceW = max(0.0, $pvW - $gridW + $batW);
        } elseif ($ac !== null) {
            $houseHave     = true;
            $houseBalanceW = max(0.0, (float)$ac);
        }
        $houseW = $houseBalanceW;

        // Optionaler externer Hauslastzähler (z. B. Shelly am Hausanschluss):
        // liefert die tatsächlich gemessene Last (genauer als die Bilanz) und
        // erlaubt, die Differenz als "Wandlungsverluste" auszuweisen
        // (Wechselrichter-Eigenverbrauch, Leitungsverluste, Messtoleranzen).
        $lossHave = false;
        $lossW    = 0.0;
        $meterID  = (int)@IPS_GetProperty($src, 'HouseLoadMeterID');
        if ($meterID > 0 && IPS_VariableExists($meterID)) {
            $realHouseW = $this->VarWatts($meterID, 'auto');
            // Ein Hausverbrauch ist nie negativ. Liefert die gewählte Variable
            // einen negativen Wert, ist es kein Hausverbrauchszähler, sondern
            // z. B. ein Netz-/Einspeisezähler (negativ = Einspeisung) - dann
            // ignorieren wir sie und bleiben bei der berechneten Bilanz-Hauslast
            // (sonst erschiene eine negative Hauslast und absurde "Verluste").
            if ($realHouseW >= 0.0) {
                $houseHave  = true;
                $houseW     = $realHouseW;
                if ($pvHave && $gridHave) {
                    $lossHave = true;
                    $lossW    = max(0.0, $houseBalanceW - $realHouseW);
                }
            }
        }

        // Berechnete Hauslast optional in die eigene Variable schreiben, damit
        // sie außerhalb der Kachel (Automationen, Charts) nutzbar ist.
        if ($houseHave && $this->ReadPropertyBoolean('WriteHouseLoad')) {
            $vid = @$this->GetIDForIdent('house_load');
            if ($vid) {
                $this->SetValue('house_load', round($houseW));
            }
        }

        $payload = array_merge($style, [
            'ok'         => $connected,
            'stateLabel' => $connected ? 'Verbunden' : 'Getrennt',
            'pvHave'     => $pvHave,
            'pvW'        => round($pvW),
            'gridHave'   => $gridHave,
            'gridW'      => round($gridW),
            'houseHave'  => $houseHave,
            'houseW'     => round($houseW),
            'batHave'    => $batHave,
            'batW'       => round($batW),
            'socHave'    => $socHave,
            'soc'        => $socHave ? round((float)$soc) : null,
            'lossHave'   => $lossHave,
            'lossW'      => round($lossW),
            'consumers'  => $this->BuildConsumers(),
        ]);

        return json_encode($payload);
    }

    // Zusätzliche Verbraucher als Liste für die Kachel. Die Kachel verteilt
    // alle vorhandenen Knoten selbst radial - die Anzahl ist daher frei.
    private function BuildConsumers()
    {
        $rows     = $this->ReadConsumerRows();
        $vehicles = $this->ReadVehicleRows();
        $assign   = $this->AssignVehicles($rows, $vehicles);

        $out = [];
        foreach ($rows as $i => $row) {
            $entry = [
                'key'   => 'c' . $i,
                'label' => $row['name'],
                'icon'  => $row['icon'],
                'color' => $row['color'],
                'w'     => round($this->VarWatts($row['id'], $row['unit'])),
            ];

            // Wallboxen: Auto-Symbol mit dem Ladestand des Fahrzeugs, das
            // gerade an DIESER Wallbox steht (automatisch ermittelt). Der Name
            // des erkannten Fahrzeugs wird als Zusatzzeile angezeigt, damit die
            // Zuordnung nachvollziehbar bleibt.
            if ($row['type'] === 'wallbox') {
                if (isset($assign[$i])) {
                    $v = $vehicles[$assign[$i]];
                    $entry['socHave'] = true;
                    $entry['soc']     = round((float)GetValue($v['socID']));
                    $entry['sub']     = $v['name'];
                } else {
                    $entry['socHave'] = false;
                    $entry['soc']     = null;
                }
            }

            $out[] = $entry;
        }
        return $out;
    }

    // Liest eine Leistungs-Variable und rechnet sie einheitlich in Watt um.
    // $unit: 'w' | 'kw' | 'mw' erzwingt die Einheit, 'auto' (Vorgabe) errät sie
    // aus dem Profil-Suffix der Variable. So werden Fremdquellen, die z. B.
    // Kilowatt liefern (viele Wallboxen), korrekt behandelt - intern rechnet
    // die Kachel durchgängig in Watt.
    private function VarWatts($vid, $unit = 'auto')
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return 0.0;
        }
        $v = (float)GetValue($vid);
        switch (strtolower(trim((string)$unit))) {
            case 'kw': return $v * 1000.0;
            case 'mw': return $v * 1000000.0;
            case 'w':  return $v;
            default:   return $v * $this->UnitFactorFromProfile($vid);
        }
    }

    // Einheiten-Faktor (auf Watt) aus dem Profil-Suffix einer Variable: " kW"
    // -> 1000, " MW" -> 1e6, sonst 1 (W oder unbekannt). Berücksichtigt ein
    // etwaiges eigenes Profil (VariableCustomProfile) vorrangig.
    private function UnitFactorFromProfile($vid)
    {
        $var = @IPS_GetVariable($vid);
        if (!is_array($var)) {
            return 1.0;
        }
        $prof = ($var['VariableCustomProfile'] ?? '') !== ''
            ? $var['VariableCustomProfile']
            : ($var['VariableProfile'] ?? '');
        if ($prof === '') {
            return 1.0;
        }
        $p = @IPS_GetVariableProfile($prof);
        if (!is_array($p)) {
            return 1.0;
        }
        $suffix = strtolower(trim((string)($p['Suffix'] ?? '')));
        if (strpos($suffix, 'kw') !== false) {
            return 1000.0;
        }
        if (strpos($suffix, 'mw') !== false) {
            return 1000000.0;
        }
        return 1.0;
    }

    // -----------------------------------------------------------------------
    // Hilfsfunktionen (identisch mit GoodweETTile)
    // -----------------------------------------------------------------------

    private function ResolveSource()
    {
        return (int)$this->ReadPropertyInteger('SourceInstance');
    }

    private function ColorOrEmpty(int $color)
    {
        return $color < 0 ? '' : sprintf('#%06x', $color);
    }

    private function FontStack(string $family)
    {
        if ($family === 'system' || $family === '') {
            return '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        }
        return $family;
    }

    private function FlowRefValue()
    {
        $v = (int)$this->ReadPropertyInteger('FlowRefW');
        return ($v >= 500 && $v <= 100000) ? $v : self::DEF_FLOWREF;
    }

    private function TransitionValue()
    {
        $v = (int)$this->ReadPropertyInteger('TransitionMs');
        return ($v >= 0 && $v <= 5000) ? $v : self::DEF_TRANSITION;
    }
}
