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

    // Ident-Fallback-Ketten je Größe (erster gefundener Ident gewinnt)
    private const IDENT_PV     = ['pv_total'];
    private const IDENT_AC     = ['ac_power'];
    private const IDENT_GRID   = ['meter_total'];
    private const IDENT_BATPWR = ['bat_total_pwr', 'bat_power'];
    private const IDENT_SOC    = ['soc', 'bat_soc'];
    private const IDENT_CONN   = ['connected'];

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';
    private const DEF_TRANSITION = 800;

    // Auswählbare Verbraucher-Arten. Der Schlüssel steht in der Konfiguration,
    // 'label' dient als Vorgabe-Bezeichnung (wenn der Nutzer keine eigene
    // vergibt) und 'icon' verweist auf den Icon-Zeichner in module.html.
    private const CONSUMER_TYPES = [
        'wallbox'  => ['label' => 'Wallbox',         'icon' => 'wallbox'],
        'heatpump' => ['label' => 'Wärmepumpe',      'icon' => 'heatpump'],
        'ac'       => ['label' => 'Klimaanlage',     'icon' => 'ac'],
        'poolheat' => ['label' => 'Pool-Wärmepumpe', 'icon' => 'poolheat'],
        'poolpump' => ['label' => 'Pool-Pumpe',      'icon' => 'poolpump'],
        'sauna'    => ['label' => 'Sauna',           'icon' => 'sauna'],
        'boiler'   => ['label' => 'Warmwasser',      'icon' => 'boiler'],
        'dryer'    => ['label' => 'Trockner',        'icon' => 'dryer'],
        'other'    => ['label' => 'Verbraucher',     'icon' => 'other'],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('SourceInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily',       self::DEF_FONT);
        $this->RegisterPropertyInteger('TransitionMs',    self::DEF_TRANSITION);
        // Zusätzliche Verbraucher, die nicht aus dem Wechselrichter kommen,
        // sondern als vorhandene Leistungs-Variablen ausgewählt werden.
        // Frei erweiterbare Tabelle: je Zeile Art, Bezeichnung und Variable.
        $this->RegisterPropertyString('Consumers', '[]');

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

        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    // Liefert die IDs aller konfigurierten Verbraucher-Variablen, gefiltert auf
    // tatsächlich existierende Variablen.
    private function CollectConsumerVarIDs()
    {
        $ids = [];
        foreach ($this->ReadConsumerRows() as $row) {
            $ids[] = $row['id'];
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
            $out[] = [
                'id'    => $vid,
                'type'  => $type,
                'name'  => ($name !== '' ? $name : self::CONSUMER_TYPES[$type]['label']),
                'icon'  => self::CONSUMER_TYPES[$type]['icon'],
            ];
        }
        return $out;
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
            'transMs' => $this->TransitionValue(),
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

        // Last (Hausverbrauch) per Bilanz: PV + Batterie-Entladung - Netzeinspeisung.
        // Vorzeichenkonvention Batterie (bat_total_pwr/bat_power, alle Treiber):
        // positiv = Entladung (Leistung wird ans System abgegeben), negativ =
        // Ladung (Leistung wird aufgenommen) - daher ADDITION, nicht Subtraktion.
        // Nur berechenbar, wenn mindestens PV und Netz bekannt sind; sonst als
        // Notlösung AC-Wirkleistung zeigen (ungenau bei vorhandener Batterie),
        // andernfalls Kreis ausgrauen statt falsche Werte zu zeigen.
        $houseHave = $pvHave && $gridHave;
        $houseBalanceW = 0.0;
        if ($houseHave) {
            $houseBalanceW = max(0.0, $pvW - $gridW + $batW);
        } elseif ($pvHave && $ac !== null) {
            $houseHave     = true;
            $houseBalanceW = (float)$ac;
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
            $realHouseW = (float)GetValue($meterID);
            $houseHave  = true;
            $houseW     = $realHouseW;
            if ($pvHave && $gridHave) {
                $lossHave = true;
                $lossW    = max(0.0, $houseBalanceW - $realHouseW);
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
        $out = [];
        $i   = 0;
        foreach ($this->ReadConsumerRows() as $row) {
            $out[] = [
                'key'   => 'c' . $i++,
                'label' => $row['name'],
                'icon'  => $row['icon'],
                'w'     => round((float)GetValue($row['id'])),
            ];
        }
        return $out;
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

    private function TransitionValue()
    {
        $v = (int)$this->ReadPropertyInteger('TransitionMs');
        return ($v >= 0 && $v <= 5000) ? $v : self::DEF_TRANSITION;
    }
}
