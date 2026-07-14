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
        'goodwe'  => [247, 1],
        'sungrow' => [1, 247, 246],
        'solis'   => [1],
        'growatt' => [1],
        'solax'   => [1],
        'sma'     => [3, 1, 126],
        'fronius' => [1, 100],
    ];

    private const VENDOR_LABELS = [
        'goodwe'  => 'GoodWe',
        'sungrow' => 'Sungrow',
        'solis'   => 'Solis',
        'growatt' => 'Growatt',
        'solax'   => 'SolaX',
        'sma'     => 'SMA',
        'fronius' => 'Fronius (SunSpec)',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('RangeStart', '');
        $this->RegisterPropertyString('RangeEnd', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterAttributeString('ResultsJSON', '[]');
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

        $values = [];
        foreach ($results as $r) {
            $values[] = [
                'ip'           => $r['ip'],
                'unitId'       => $r['unitId'],
                'manufacturer' => $r['label'],
                'instanceID'   => 0,
                'name'         => $r['label'] . ' @ ' . $r['ip'] . ' (Unit ' . $r['unitId'] . ')',
                'moduleID'     => self::INVERTERHUB_GUID,
                'configuration' => [
                    'Host'         => $r['ip'],
                    'Port'         => $this->ReadPropertyInteger('Port'),
                    'UnitId'       => $r['unitId'],
                    'Manufacturer' => $r['vendor'],
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
                        ['type' => 'Label', 'caption' => 'Start- und End-IP eintragen (z. B. 192.168.1.1 bis 192.168.1.254), dann „Netzwerk durchsuchen" klicken. Gefundene Geräte erscheinen unten in der Liste — Klick auf das „+" legt eine InverterHub-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.'],
                        ['type' => 'Label', 'caption' => 'Der Scan prüft nur wenige dokumentierte Standard-Unit-IDs je Hersteller, keinen vollen 1-247-Bereich — bei exotisch konfigurierter Unit-ID bitte die InverterHub-Instanz manuell anlegen.'],
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
                    ],
                ],
                [
                    'type'     => 'Configurator',
                    'name'     => 'DiscoveryList',
                    'caption'  => 'Gefundene Wechselrichter',
                    'rowCount' => 20,
                    'add'      => true,
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
            'actions' => [
                ['type' => 'Button', 'caption' => '🔎  Netzwerk durchsuchen', 'onClick' => 'IHUBD_Discover($id);'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Bereit.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte Such-IP-Bereich eintragen.'],
            ],
        ];

        return json_encode($form);
    }

    // -----------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------

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

        $openIps = $this->scanPortOpen($ips, $port, 2.0);

        $results = [];
        foreach ($openIps as $ip) {
            $found = $this->identifyVendor($ip, $port);
            if ($found !== null) {
                $results[] = $found;
            }
        }

        $this->WriteAttributeString('ResultsJSON', json_encode($results));
        $this->SetStatus(102);
        $this->ReloadForm();
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

        $open     = [];
        $deadline = microtime(true) + $timeoutSec;
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

    private function probeVendor($vendor, $ip, $port, $unitId)
    {
        switch ($vendor) {
            case 'goodwe':
                // DSP 35001: Nennleistung, sollte > 0 sein
                $r = $this->readHolding($ip, $port, $unitId, 35001, 1, 1.0);
                return ($r !== null && $r[0] > 0);

            case 'sungrow':
                // Input 5000 (PDU 4999): Gerätetyp-Code, sollte > 0 sein
                $r = $this->readInput($ip, $port, $unitId, 4999, 1, 1.0);
                return ($r !== null && $r[0] > 0);

            case 'solis':
                // Input 33000 (PDU 32999): Modell-Nr., sollte > 0 sein
                $r = $this->readInput($ip, $port, $unitId, 32999, 1, 1.0);
                return ($r !== null && $r[0] > 0);

            case 'growatt':
                // Input 0: Inverter-Status, plausibel 0/1/3
                $r = $this->readInput($ip, $port, $unitId, 0, 1, 1.0);
                return ($r !== null && in_array($r[0], [0, 1, 3], true));

            case 'solax':
                // Holding 0x0015: InverterType, sollte > 0 sein
                $r = $this->readHolding($ip, $port, $unitId, 0x0015, 1, 1.0);
                return ($r !== null && $r[0] > 0);

            case 'sma':
                // Holding 30200 (Reg 30201): Operation.Health, plausible Enum-Werte
                $r = $this->readHolding($ip, $port, $unitId, 30200, 2, 1.0);
                if ($r === null) {
                    return false;
                }
                $val = ($r[0] << 16) | $r[1];
                return in_array($val, [35, 303, 307, 455], true);

            case 'fronius':
                // SunSpec-Marker "SunS" an Basisregister 40000
                $r = $this->readHolding($ip, $port, $unitId, 40000, 2, 1.0);
                return ($r !== null && $r[0] === 0x5375 && $r[1] === 0x6e53);
        }
        return false;
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
