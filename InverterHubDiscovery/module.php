<?php

// ---------------------------------------------------------------------------
// InverterHubDiscovery — Configurator-Modul: durchsucht einen IP-Bereich nach
// Wechselrichtern auf Modbus-TCP-Port 502, erkennt den Hersteller anhand
// weniger charakteristischer Register/Unit-IDs und legt auf Klick eine
// InverterHub-Instanz mit vorausgefüllten Werten an.
// Eigenständige, kompakte Modbus-Hilfsfunktionen (kein Zugriff auf die
// Klassen aus dem InverterHub-Modulordner — Module sind bewusst getrennt).
// ---------------------------------------------------------------------------

class InverterHubDiscovery extends IPSModule
{
    private const INVERTERHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';

    // Kandidaten je Hersteller: Unit-IDs, die typischerweise/dokumentiert
    // Standard sind (kleine Liste statt vollem 1-247-Bereich).
    private const VENDOR_UNIT_IDS = [
        'goodwe'    => [247, 1],
        'sungrow'   => [1, 247, 246],
        'solis'     => [1],
        'growatt'   => [1],
        'solax'     => [1],
        'sma'       => [3, 1, 126],
        'fronius'   => [1, 100],
        'solaredge' => [1],
        'deye'      => [1, 2],
        'solplanet' => [3, 1],
        'kostal'    => [71, 1],
    ];

    private const VENDOR_LABELS = [
        'goodwe'    => 'GoodWe',
        'sungrow'   => 'Sungrow',
        'solis'     => 'Solis',
        'growatt'   => 'Growatt',
        'solax'     => 'SolaX',
        'sma'       => 'SMA',
        'fronius'   => 'Fronius (SunSpec)',
        'solaredge' => 'SolarEdge (SunSpec)',
        'deye'      => 'Deye',
        'solplanet' => 'Solplanet / AISWEI',
        'kostal'    => 'Kostal',
    ];

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-inverterhub-multi-wechselrichter-ein-modbus-tcp-modul-fuer-goodwe-sma-fronius-sungrow-solis-growatt-solax/144121';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    public function Create()
    {
        parent::Create();

        $prefix = $this->guessLocalSubnetPrefix();
        $this->RegisterPropertyString('RangeStart', $prefix !== '' ? $prefix . '.1'   : '');
        $this->RegisterPropertyString('RangeEnd',   $prefix !== '' ? $prefix . '.254' : '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyString('NameTemplate', '');
        $this->RegisterAttributeString('ResultsJSON', '[]');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
    }

    // Ermittelt heuristisch die ersten drei Oktette des lokalen Subnetzes
    // (z.B. "192.168.1"), um Start-/End-IP sinnvoll vorzubelegen. Nur ein
    // Vorschlag — der Nutzer kann ihn jederzeit überschreiben.
    private function guessLocalSubnetPrefix()
    {
        $ip = @gethostbyname(gethostname());
        if ($ip === false || $ip === gethostname()) {
            return '';
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        $isPrivate = ($parts[0] === '10')
            || ($parts[0] === '192' && $parts[1] === '168')
            || ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31);
        if (!$isPrivate) {
            return '';
        }
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $results = json_decode($this->ReadAttributeString('ResultsJSON'), true);
        if (!is_array($results)) {
            $results = [];
        }

        $existing = $this->findExistingInstances();
        $template = trim($this->ReadPropertyString('NameTemplate'));

        // Laufende Nummer je Hersteller (1, 2, 3 ...) für den Namens-Default
        // und für den {nr}-Platzhalter der freien Vorlage.
        $vendorCounter = [];

        $values = [];
        foreach ($results as $r) {
            $key = $r['ip'] . '|' . $r['unitId'];
            $vendorCounter[$r['vendor']] = ($vendorCounter[$r['vendor']] ?? 0) + 1;
            $nr = $vendorCounter[$r['vendor']];

            if ($template !== '') {
                $instanceName = str_replace(
                    ['{hersteller}', '{ip}', '{unitid}', '{nr}'],
                    [$r['label'], $r['ip'], $r['unitId'], $nr],
                    $template
                );
            } else {
                $instanceName = $r['label'] . ' ' . $nr;
            }

            $values[] = [
                'name'         => $r['label'] . ' @ ' . $r['ip'] . ' (Unit ' . $r['unitId'] . ')',
                'manufacturer' => $r['label'],
                'ip'           => $r['ip'],
                'unitId'       => $r['unitId'],
                'instanceID'   => $existing[$key] ?? 0,
                'create'       => [
                    'moduleID'      => self::INVERTERHUB_GUID,
                    'name'          => $instanceName,
                    'configuration' => [
                        'Host'         => $r['ip'],
                        'Port'         => $this->ReadPropertyInteger('Port'),
                        'UnitId'       => $r['unitId'],
                        'Manufacturer' => $r['vendor'],
                    ],
                ],
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Durchsucht einen IP-Bereich im lokalen Netz nach Wechselrichtern auf Modbus-TCP-Port 502 und erkennt den Hersteller anhand weniger typischer Register/Unit-IDs pro Hersteller.'],
                        ['type' => 'Label', 'caption' => 'Start- und End-IP eintragen (Vorschlag anhand des eigenen Netzwerks ist schon ausgefüllt), dann „Netzwerk durchsuchen" klicken. Gefundene Geräte erscheinen unten in der Liste — Klick auf „Erstellen" legt eine InverterHub-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.'],
                        ['type' => 'Label', 'caption' => 'Der Scan prüft nur wenige dokumentierte Standard-Unit-IDs je Hersteller, keinen vollen 1-247-Bereich — bei exotisch konfigurierter Unit-ID bitte die InverterHub-Instanz manuell anlegen.'],
                        ['type' => 'Label', 'caption' => 'Hinweis: „Filter"/„Aktualisieren" oberhalb und „Erstellen"/„Alle erstellen" unterhalb der Tabelle sind fester Bestandteil der IP-Symcon-Konfigurator-Ansicht selbst — ihre Position lässt sich modulseitig nicht verändern.'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔎  Suchbereich',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'RangeStart', 'caption' => 'Start-IP', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'ValidationTextBox', 'name' => 'RangeEnd',   'caption' => 'End-IP',   'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Modbus-TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'ValidationTextBox', 'name' => 'NameTemplate', 'caption' => 'Name-Vorlage (leer = Hersteller + lfd. Nr.)'],
                        ['type' => 'Label', 'caption' => 'Platzhalter für die Vorlage: {hersteller} {ip} {unitid} {nr} — z.B. "{hersteller} Dach ({ip})"'],
                        ['type' => 'Button', 'caption' => '🔎  Netzwerk durchsuchen', 'onClick' => 'IHUBD_Discover($id);'],
                        [
                            'type'          => 'ProgressBar',
                            'name'          => 'ScanProgress',
                            'caption'       => 'Bereit.',
                            'minimum'       => 0,
                            'maximum'       => 100,
                            'current'       => 0,
                            'indeterminate' => false,
                            'visible'       => false,
                        ],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🛠️  Erstellen',
                    'expanded' => true,
                    'items' => [
                        [
                            'type'     => 'Configurator',
                            'name'     => 'DiscoveryList',
                            'caption'  => 'Gefundene Wechselrichter',
                            'rowCount' => 6,
                            'delete'   => false,
                            'sort'     => ['column' => 'ip', 'direction' => 'ascending'],
                            'columns'  => [
                                ['caption' => 'Hersteller', 'name' => 'manufacturer', 'width' => '200px'],
                                ['caption' => 'IP-Adresse', 'name' => 'ip',           'width' => '150px'],
                                ['caption' => 'Unit ID',    'name' => 'unitId',       'width' => '100px'],
                            ],
                            'values' => $values,
                        ],
                    ],
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Bereit.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte Such-IP-Bereich eintragen.'],
            ],
        ];

        // Einmaliger Beta-Hinweis mit Link zum Symcon-Forum-Thread, bis er
        // per Button ausgeblendet wird (Attribut, kein Übernehmen nötig).
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 InverterHub ist Beta — Rückmeldungen und Testberichte sind im Symcon-Forum-Thread willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::FORUM_THREAD_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'IHUBD_DismissReviewHint($id);'],
                ],
            ];
        }

        return json_encode($form);
    }

    public function DismissReviewHint()
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    // -----------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------

    // Aktualisiert die Fortschrittsanzeige im GEÖFFNETEN Formular, während
    // Discover() noch läuft (UpdateFormField pusht sofort über die
    // WebSocket-Verbindung zur Konsole, unabhängig vom RPC-Rückgabewert).
    private function ShowProgress($caption, $current, $indeterminate = false)
    {
        // UpdateFormField meldet ein PHP-Warning ("Instanz #<id> existiert
        // nicht"), wenn das Konfigurationsformular zwischenzeitlich
        // geschlossen wurde, während Discover() noch läuft — der Scan selbst
        // läuft unabhängig vom offenen Formular weiter. Da IPS das als
        // E_WARNING statt als Exception ausgibt, hilft try/catch nicht;
        // stattdessen hier bewusst mit @ unterdrücken.
        @$this->UpdateFormField('ScanProgress', 'visible', true);
        @$this->UpdateFormField('ScanProgress', 'caption', $caption);
        @$this->UpdateFormField('ScanProgress', 'indeterminate', $indeterminate);
        @$this->UpdateFormField('ScanProgress', 'current', $current);
    }

    public function Discover()
    {
        $start = $this->ReadPropertyString('RangeStart');
        $end   = $this->ReadPropertyString('RangeEnd');
        $port  = $this->ReadPropertyInteger('Port');

        if ($start === '' || $end === '') {
            $this->SetStatus(104);
            return;
        }

        $ips = $this->expandRange($start, $end);
        if (count($ips) > 1024) {
            // Sicherheitslimit gegen versehentlich riesige Bereiche
            $ips = array_slice($ips, 0, 1024);
        }

        $this->ShowProgress('Durchsuche ' . count($ips) . ' IP-Adressen auf Port ' . $port . ' …', 0);

        $openIps = $this->scanPortOpen($ips, $port, 2.0);

        $results = [];
        $total   = count($openIps);
        $i       = 0;
        foreach ($openIps as $ip) {
            $i++;
            $this->ShowProgress("Prüfe Hersteller: $ip ($i von $total offenen Ports) …", (int)round(($i / max(1, $total)) * 100));
            $found = $this->identifyVendor($ip, $port);
            if ($found !== null) {
                $results[] = $found;
            }
        }

        $this->ShowProgress('Fertig: ' . count($results) . ' Wechselrichter gefunden (von ' . $total . ' offenen Ports).', 100);

        $this->WriteAttributeString('ResultsJSON', json_encode($results));
        $this->SetStatus(102);
        $this->ReloadForm();
    }

    // Gleicht Suchergebnisse gegen bereits existierende InverterHub-Instanzen
    // ab (Host+UnitId), damit bereits angelegte Wechselrichter in der
    // Ergebnisliste als solche erkannt werden (InstanzID statt "Kein(e)").
    private function findExistingInstances()
    {
        $map = [];
        foreach (IPS_GetInstanceListByModuleID(self::INVERTERHUB_GUID) as $iid) {
            $host   = @IPS_GetProperty($iid, 'Host');
            $unitId = @IPS_GetProperty($iid, 'UnitId');
            if ($host !== false && $host !== null && $host !== '') {
                $map[$host . '|' . $unitId] = $iid;
            }
        }
        return $map;
    }

    private function expandRange($startIp, $endIp)
    {
        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }
        $ips = [];
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    // Nicht-blockierender Parallel-Scan: testet alle IPs gleichzeitig, ob
    // Port 502 offen ist, statt sie nacheinander mit vollem Timeout abzuklopfen.
    private function scanPortOpen($ips, $port, $timeoutSec)
    {
        $pending = [];
        foreach ($ips as $ip) {
            $s = @stream_socket_client(
                "tcp://$ip:$port",
                $errno,
                $errstr,
                0.01,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($s !== false) {
                stream_set_blocking($s, false);
                $pending[$ip] = $s;
            }
        }

        $open      = [];
        $totalOpen = count($pending);
        $startTime = microtime(true);
        $deadline  = $startTime + $timeoutSec;
        while (count($pending) > 0 && microtime(true) < $deadline) {
            $write  = array_values($pending);
            $read   = [];
            $except = [];
            $n = @stream_select($read, $write, $except, 0, 200000);
            if ($n === false) {
                break;
            }
            foreach ($pending as $ip => $sock) {
                if (in_array($sock, $write, true)) {
                    // Nach Abschluss des async Connect zeigt eine gültige
                    // Peer-Adresse Erfolg an, false bedeutet Verbindungsfehler.
                    $peer = @stream_socket_get_name($sock, true);
                    if ($peer !== false) {
                        $open[] = $ip;
                    }
                    fclose($sock);
                    unset($pending[$ip]);
                }
            }
            // Fortschritt anhand verbrauchtem Zeitbudget (grobe Schätzung,
            // da einzelne IPs unterschiedlich schnell antworten/timeouten).
            $elapsed = microtime(true) - $startTime;
            $pct     = (int)round(min(95, ($elapsed / $timeoutSec) * 90));
            $this->ShowProgress(
                "Portscan läuft … " . count($open) . " offen, " . count($pending) . " von $totalOpen noch offen",
                $pct
            );
        }
        foreach ($pending as $sock) {
            @fclose($sock);
        }
        return $open;
    }

    private function identifyVendor($ip, $port)
    {
        foreach (self::VENDOR_UNIT_IDS as $vendor => $unitIds) {
            foreach ($unitIds as $unitId) {
                if ($this->probeVendor($vendor, $ip, $port, $unitId)) {
                    return [
                        'ip'     => $ip,
                        'unitId' => $unitId,
                        'vendor' => $vendor,
                        'label'  => self::VENDOR_LABELS[$vendor],
                    ];
                }
            }
        }
        return null;
    }

    // Ein einzelnes "Register > 0"-Kriterium ist zu schwach — Zähler,
    // RTU/TCP-Konverter und andere Modbus-Geräte erfüllen das leicht zufällig
    // (real gemeldet: ein Janitza PAC2200 wurde als SolaX erkannt, ein
    // RTU/TCP-Konverter als GoodWe). Wo der Hersteller ein Seriennummer-/
    // Modell-Textregister dokumentiert, wird das als zweites, deutlich
    // härteres Kriterium verlangt: das Register muss zu einem plausiblen
    // ASCII-Text dekodieren, kein Zufallswert.
    private function probeVendor($vendor, $ip, $port, $unitId)
    {
        switch ($vendor) {
            case 'goodwe':
                // DSP 35001: Nennleistung, sollte > 0 sein
                $r = $this->readHolding($ip, $port, $unitId, 35001, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // DSP 35003: Seriennummer (8 Register, ASCII)
                $sn = $this->readHolding($ip, $port, $unitId, 35003, 8, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'sungrow':
                // Input 5000: Gerätetyp-Code, sollte > 0 sein
                $r = $this->readInput($ip, $port, $unitId, 5000, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // Input 4990-4999: Seriennummer (10 Register, UTF-8/ASCII)
                $sn = $this->readInput($ip, $port, $unitId, 4990, 10, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'solis':
                // Input 33000: Modell-Nr., sollte > 0 sein
                $r = $this->readInput($ip, $port, $unitId, 33000, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // Input 33004-33019: Seriennummer (16 Register, ASCII)
                $sn = $this->readInput($ip, $port, $unitId, 33004, 16, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'growatt':
                // Input 0: Inverter-Status, plausibel 0/1/3
                $r = $this->readInput($ip, $port, $unitId, 0, 1, 1.0);
                if ($r === null || !in_array($r[0], [0, 1, 3], true)) {
                    return false;
                }
                // Input 93: Wechselrichter-Temperatur (0.1°C), plausibel -40..90°C
                $t = $this->readInput($ip, $port, $unitId, 93, 1, 1.0);
                if ($t === null) {
                    return false;
                }
                $temp = $t[0] > 32767 ? $t[0] - 65536 : $t[0];
                if ($temp <= -400 || $temp >= 900) {
                    return false;
                }
                // Status 0/1/3 + Temperatur in Plausibelbereich reicht nicht als
                // Alleinstellungsmerkmal — reale Fehlmeldung: go-e-Wallboxen (die
                // ebenfalls auf Unit-ID 1 antworten) erfüllten beide Kriterien
                // zufällig. Zusätzlich Holding 23-27: Seriennummer (5 Register,
                // ASCII) verlangen — ein Wallbox-Register an dieser Adresse
                // dekodiert nicht zu plausiblem Text.
                $sn = $this->readHolding($ip, $port, $unitId, 23, 5, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'solax':
                // Holding 0x0015: InverterType, sollte > 0 sein
                $r = $this->readHolding($ip, $port, $unitId, 0x0015, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // Holding 0x00AA-0x00AE: Modul-Seriennummer (5 Register, ASCII)
                $sn = $this->readHolding($ip, $port, $unitId, 0x00AA, 5, 1.0);
                return $this->looksLikeAsciiText($sn, 3);

            case 'sma':
                // Holding 30200 (Reg 30201): Operation.Health, plausible Enum-Werte
                $r = $this->readHolding($ip, $port, $unitId, 30200, 2, 1.0);
                if ($r === null) {
                    return false;
                }
                $val = ($r[0] << 16) | $r[1];
                return in_array($val, [35, 303, 307, 455], true);

            case 'fronius':
                // SunSpec-Marker "SunS" allein reicht nicht — SolarEdge nutzt
                // denselben Marker. Zusätzlich der Herstellername im Common
                // Block (Model 1, Feld MN ab Offset 2 hinter Marker+Header).
                return $this->probeSunSpecManufacturer($ip, $port, $unitId, 'fronius');

            case 'solaredge':
                return $this->probeSunSpecManufacturer($ip, $port, $unitId, 'solaredge');

            case 'deye':
                // Holding 0: Inverter-Typ, sollte > 0 sein; Holding 500:
                // Status-Register muss lesbar sein (zweites Kriterium).
                $r = $this->readHolding($ip, $port, $unitId, 0, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                $s = $this->readHolding($ip, $port, $unitId, 500, 1, 1.0);
                return ($s !== null);

            case 'solplanet':
                // Input 1600: PV-Gesamtleistung (U32), muss lesbar sein;
                // Input 1026: Netzcode, plausibel ein kleiner Enum-Wert.
                $r = $this->readInput($ip, $port, $unitId, 1600, 2, 1.0);
                if ($r === null) {
                    return false;
                }
                $g = $this->readInput($ip, $port, $unitId, 1026, 1, 1.0);
                return ($g !== null && $g[0] < 100);

            case 'kostal':
                // Holding 100: PV-Gesamtleistung (Float32), muss lesbar sein;
                // Holding 768: Produktname, muss zu ASCII-Text dekodieren.
                $r = $this->readHolding($ip, $port, $unitId, 100, 2, 1.0);
                if ($r === null) {
                    return false;
                }
                $name = $this->readHolding($ip, $port, $unitId, 768, 16, 1.0);
                return $this->looksLikeAsciiText($name, 4);
        }
        return false;
    }

    // Dekodiert Registerpaare als Big-Endian-ASCII und prüft, ob mindestens
    // $minPrintable druckbare, nicht-Leerzeichen-Zeichen enthalten sind —
    // filtert Zufallswerte fremder Modbus-Geräte zuverlässig heraus.
    private function looksLikeAsciiText($regs, $minPrintable)
    {
        if ($regs === null) {
            return false;
        }
        $printable = 0;
        foreach ($regs as $r) {
            foreach ([($r >> 8) & 0xFF, $r & 0xFF] as $byte) {
                if ($byte >= 0x21 && $byte <= 0x7E) {
                    $printable++;
                } elseif ($byte !== 0x00 && $byte !== 0x20) {
                    // Nicht-druckbares, nicht-Null/Leerzeichen-Byte spricht
                    // stark gegen echten Text -> sofort verwerfen.
                    return false;
                }
            }
        }
        return $printable >= $minPrintable;
    }

    // Prüft den SunSpec-Marker "SunS" an Basisregister 40000 und liest
    // anschließend den Herstellernamen aus dem Common Block (Model 1,
    // Feld MN direkt ab Modelldatenbeginn 40004) — unterscheidet Fronius
    // von SolarEdge, die beide denselben "SunS"-Marker verwenden.
    private function probeSunSpecManufacturer($ip, $port, $unitId, $wantVendor)
    {
        $marker = $this->readHolding($ip, $port, $unitId, 40000, 2, 1.0);
        if ($marker === null || $marker[0] !== 0x5375 || $marker[1] !== 0x6e53) {
            return false;
        }
        $mn = $this->readHolding($ip, $port, $unitId, 40004, 16, 1.0);
        if ($mn === null) {
            return false;
        }
        $text = strtolower($this->decodeAsciiText($mn));
        return (strpos($text, $wantVendor) !== false);
    }

    private function decodeAsciiText($regs)
    {
        $s = '';
        foreach ($regs as $r) {
            $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
        }
        return trim($s, "\x00 ");
    }

    // -----------------------------------------------------------------------
    // Minimale Modbus-TCP-Hilfsfunktionen (nur für die kurzen Scan-Proben)
    // -----------------------------------------------------------------------

    private function readHolding($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x03, $startReg, $count, $timeout);
    }

    private function readInput($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x04, $startReg, $count, $timeout);
    }

    private function modbusRead($host, $port, $unitId, $fc, $startReg, $count, $timeout)
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, $timeout);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', $fc, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unitId);

        fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        fclose($sock);

        if (strlen($response) < 9) {
            return null;
        }
        $rfc = ord($response[7]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);
        $regs      = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }
}
