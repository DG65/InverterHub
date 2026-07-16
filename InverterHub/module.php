<?php

// ---------------------------------------------------------------------------
// InverterHub — generisches Wechselrichter-Modul mit austauschbaren
// Hersteller-Treibern. Erster Treiber: GoodWe (portiert aus GoodweET).
// Alles in einer Datei: IPS lädt alle .php-Dateien im Modulordner, zwei
// Dateien mit derselben Klasse führen zu "Cannot redeclare class".
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// ModbusTcpClient — gemeinsame Modbus-TCP-Grundfunktionen für alle Treiber
// ---------------------------------------------------------------------------

class ModbusTcpClient
{
    public $host;
    public $port;
    public $unitId;

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
    }

    public function readHolding($startReg, $count)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x03, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + 3.0;
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

        $fc = ord($response[7]);
        if ($fc & 0x80 || $fc !== 0x03) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    // Read Input Registers (FC 0x04) — Sungrow, Solis, Growatt, Solax trennen
    // Mess-/Input-Register (0x04) von Holding-/Steuerregistern (0x03).
    public function readInput($startReg, $count)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x04, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + 3.0;
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

        $fc = ord($response[7]);
        if ($fc & 0x80 || $fc !== 0x04) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    public function writeSingle($reg, $value)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x06, $reg, $value & 0xFFFF);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        return ($resp !== false && strlen($resp) >= 8 && ord($resp[7]) === 0x06);
    }

    public function writeMultiple($startReg, $values)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, 3);

        $count     = count($values);
        $byteCount = $count * 2;
        $dataPart  = '';
        foreach ($values as $v) {
            $dataPart .= pack('n', $v & 0xFFFF);
        }
        $tid  = mt_rand(1, 65535);
        $pdu  = pack('CnnC', 0x10, $startReg, $count, $byteCount) . $dataPart;
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        return ($resp !== false && strlen($resp) >= 8 && ord($resp[7]) === 0x10);
    }

    public function u16($regs, $offset)
    {
        return isset($regs[$offset]) ? ($regs[$offset] & 0xFFFF) : 0;
    }

    public function s16($regs, $offset)
    {
        $v = $this->u16($regs, $offset);
        return $v > 32767 ? $v - 65536 : $v;
    }

    public function u32($regs, $offset)
    {
        return (($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1));
    }

    public function s32($regs, $offset)
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    public function readStr($regs, $offset, int $regCount)
    {
        $s = '';
        for ($i = 0; $i < $regCount; $i++) {
            $r  = $this->u16($regs, $offset + $i);
            $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
        }
        return rtrim($s, "\x00 ");
    }

    // IEEE-754 Float32 über 2 Register, Big-Endian (SunSpec-Konvention).
    public function readFloat32($regs, $offset)
    {
        $hi  = $this->u16($regs, $offset);
        $lo  = $this->u16($regs, $offset + 1);
        $raw = pack('nn', $hi, $lo);
        $val = unpack('G', $raw);
        return (float)($val[1] ?? 0.0);
    }
}

// ---------------------------------------------------------------------------
// InverterDriverInterface — Vertrag, den jeder Hersteller-Treiber erfüllt
// ---------------------------------------------------------------------------

interface InverterDriverInterface
{
    /**
     * Immer aktive Basisvariablen.
     * [ident, caption, type(F/I/B/S), profile, archive, group, reg]
     */
    public function getBaseVars();

    /**
     * Optionale Variablengruppen, je Property-Name (Checkbox in der Instanz).
     * ['GroupXYZ' => ['caption' => '...', 'vars' => [...]]]
     */
    public function getOptionalGroups();

    /** Zusätzliche boolesche Properties, die dieser Treiber braucht (Default-Werte). */
    public function getExtraBooleanProperties();

    /** Custom-Profile, die dieser Treiber anlegt: [name => [type, suffix, min, max, step, digits]] */
    public function getProfiles();

    /** Enum-Profile (Assoziationen): [name => [wert => [label, farbe]]] */
    public function getEnumProfiles();

    /** Liest die schnellen (Leistungs-)Werte. Rückgabe: Verbindung erfolgreich? */
    public function readFast($mb, $hub);

    /** Liest die langsamen Werte (Zählerstände, Fehler). */
    public function readSlow($mb, $hub);

    /** Liest einmalig Geräteinformationen (Seriennummer, Modell, Firmware). */
    public function readDeviceInfo($mb, $hub);

    /** Verarbeitet einen Schreibzugriff (RequestAction) auf ein Steuer-Ident. */
    public function writeControl($mb, $hub, string $ident, $value);
}

// ---------------------------------------------------------------------------
// GoodweDriver — Treiber für GoodWe GW-ET/EH/BT/BH-Hybridwechselrichter
// Portiert 1:1 aus GoodweET/module.php (inkl. dokumentierter Firmware-Quirks)
// ---------------------------------------------------------------------------

class GoodweDriver implements InverterDriverInterface
{
    const REG_WORK_MODE         = 47000;
    const REG_EMS_ENABLE        = 47505;
    const REG_FEED_POWER_ENABLE = 47509;
    const REG_FEED_POWER_LIMIT  = 47510;
    const REG_EMS_POWER_MODE    = 47511;
    const REG_EMS_POWER_SET     = 47512;
    const REG_SOC_MIN           = 45356;
    const REG_INTERNET_MODE     = 47017;
    const REG_RESTART           = 45220;

    const EMS_POWER_MAX = 34500;

    const WORK_MODES = [
        0 => 'Selbstverbrauch', 1 => 'Inselbetrieb', 2 => 'Backup',
        3 => 'Wirtschaftlich',  4 => 'Peak-Shaving',  5 => 'Erw. Selbstverbrauch',
    ];

    const EMS_MODES = [
        0 => 'Gestoppt', 1 => 'Automatik', 2 => 'Laden - Solar', 3 => 'Entladen + Solar',
        4 => 'AC - Import', 5 => 'AC - Export', 6 => 'Energiesparen', 7 => 'Inselbetrieb',
        8 => 'Batterie - Bereitschaft', 9 => 'Stromeinkauf', 10 => 'Stromverkauf',
        11 => 'Batterie - Laden', 12 => 'Batterie - Entladen',
    ];

    const BAT_MODES = [0 => 'No Battery', 1 => 'Standby', 2 => 'entlädt', 3 => 'lädt'];

    const GRID_MODES = [
        0 => 'Warten', 1 => 'Einspeisung', 2 => 'Einspeisung: Limit', 3 => 'Einspeisung: Entsätt.',
        4 => 'Einspeisung: PV-Limit', 5 => 'Einspeisung: Reaktiv', 6 => 'Einspeisung: Blindl.',
        7 => 'Einspeisung: Absch.', 8 => 'Einspeisung: PV-Opt.', 9 => 'Einspeisung: ECO',
        10 => 'Fehler: HW-Schutz', 11 => 'Fehler', 17 => 'Bypass', 18 => 'Inselbetrieb',
    ];

    public function getBaseVars(){
        return [
            ['soc',           'SOC',                'I', '~Battery.100',      true,  'batcommon', 'BMS Ø'],
            ['work_mode',     'Betriebsmodus',       'I', 'GWH.WorkMode',      true,  'device',    'RW 47000'],
            ['grid_mode',     'Netzmodus',           'I', 'GWH.GridMode',      false, 'grid',      'DSP 35136'],
            ['island',        'Netzgetrennt (Insel)', 'B', '~Alert',           true,  'grid',      'calc'],
            ['pv_total',      'PV Gesamtleistung',   'F', 'GWH.Watt',          true,  'pv',        'DSP 35301'],
            ['ac_power',      'AC Wirkleistung',     'F', 'GWH.Watt',          true,  'device',    'DSP 35139'],
            ['bat_total_pwr', 'Bat. Gesamtleistg.',  'F', 'GWH.Watt',          true,  'batcommon', 'Σ Bat1+Bat2'],
            ['meter_total',   'Netz Leistung',       'F', 'GWH.Watt',          true,  'grid',      'SM 36025'],
            ['bat_charge_max_w',    'Bat. max. Ladeleistung',   'F', 'GWH.Watt', false, 'batcommon', 'BMS calc'],
            ['bat_discharge_max_w', 'Bat. max. Entladeleistung','F', 'GWH.Watt', false, 'batcommon', 'BMS calc'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed',   false, 'errors',    ''],
            ['riso',          'Isolationswiderstand','F', 'GWH.KOhm',          true,  'device',    'DSP 35365'],
        ];
    }

    public function getOptionalGroups(){
        return [
            'EnableTracker1' => ['caption' => 'MPPT-Tracker 1 (PV1+PV2)', 'vars' => [
                ['pv1_voltage', 'PV1 Spannung (Tracker1 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35103'],
                ['pv1_current', 'PV1 Strom (Tracker1 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35104'],
                ['pv2_voltage', 'PV2 Spannung (Tracker1 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35107'],
                ['pv2_current', 'PV2 Strom (Tracker1 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35108'],
                ['mppt1_power',   'MPPT1 Leistung', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35337'],
                ['mppt1_current', 'MPPT1 Strom',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35345'],
            ]],
            'EnableTracker2' => ['caption' => 'MPPT-Tracker 2 (PV3+PV4)', 'vars' => [
                ['pv3_voltage', 'PV3 Spannung (Tracker2 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35111'],
                ['pv3_current', 'PV3 Strom (Tracker2 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35112'],
                ['pv4_voltage', 'PV4 Spannung (Tracker2 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35115'],
                ['pv4_current', 'PV4 Strom (Tracker2 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35116'],
                ['mppt2_power',   'MPPT2 Leistung', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35338'],
                ['mppt2_current', 'MPPT2 Strom',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35346'],
            ]],
            'EnableTracker3' => ['caption' => 'MPPT-Tracker 3 (PV5+PV6)', 'vars' => [
                ['pv5_voltage', 'PV5 Spannung (Tracker3 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35304'],
                ['pv5_current', 'PV5 Strom (Tracker3 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35305'],
                ['pv6_voltage', 'PV6 Spannung (Tracker3 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35306'],
                ['pv6_current', 'PV6 Strom (Tracker3 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35307'],
                ['mppt3_power',   'MPPT3 Leistung', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35339'],
                ['mppt3_current', 'MPPT3 Strom',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35347'],
            ]],
            'GroupGrid' => ['caption' => 'Netz L1/L2/L3 (Spannung, Strom, Frequenz, Leistung)', 'vars' => [
                ['grid_l1_volt', 'Netz L1 Spannung', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35121'],
                ['grid_l1_curr', 'Netz L1 Strom',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35122'],
                ['grid_l1_freq', 'Netz L1 Frequenz', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35123'],
                ['grid_l1_pwr',  'Netz L1 Leistung', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35124'],
                ['grid_l2_volt', 'Netz L2 Spannung', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35126'],
                ['grid_l2_curr', 'Netz L2 Strom',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35127'],
                ['grid_l2_freq', 'Netz L2 Frequenz', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35128'],
                ['grid_l2_pwr',  'Netz L2 Leistung', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35129'],
                ['grid_l3_volt', 'Netz L3 Spannung', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35131'],
                ['grid_l3_curr', 'Netz L3 Strom',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35132'],
                ['grid_l3_freq', 'Netz L3 Frequenz', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35133'],
                ['grid_l3_pwr',  'Netz L3 Leistung', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35134'],
                ['inv_total',    'Inverter Gesamt',   'F', 'GWH.Watt',   true,  'grid', 'DSP 35137'],
                ['grid_freq',    'Netzfrequenz',      'F', 'GWH.Hertz',  false, 'grid', 'SM 36014'],
            ]],
            'GroupBat1' => ['caption' => 'Batterie 1 (Spannung, Strom, Leistung, Modus, SOC)', 'vars' => [
                ['bat1_volt', 'Bat.1 Spannung', 'F', 'GWH.Volt',   false, 'bat1', 'DSP 35180'],
                ['bat1_curr', 'Bat.1 Strom',    'F', 'GWH.Ampere', true,  'bat1', 'DSP 35181'],
                ['bat1_pwr',  'Bat.1 Leistung', 'F', 'GWH.Watt',   true,  'bat1', 'DSP 35182'],
                ['bat1_mode', 'Bat.1 Modus',    'I', 'GWH.BatMode', true,  'bat1', 'DSP 35184'],
                ['bat1_soc',  'Bat.1 SOC',      'I', '~Battery.100', true, 'bat1', 'BMS 47908'],
                ['bat1_soh',  'Bat.1 SOH',      'I', '~Intensity.100', true, 'bat1', 'BMS 47909'],
                ['bat1_temp', 'Bat.1 Temperatur', 'F', '~Temperature', true, 'bat1', 'BMS 47910'],
                ['bat1_bms_volt', 'Bat.1 Spannung (BMS)', 'F', 'GWH.Volt',   false, 'bat1', 'BMS 47906'],
                ['bat1_bms_curr', 'Bat.1 Strom (BMS)',    'F', 'GWH.Ampere', true,  'bat1', 'BMS 47907'],
                ['bat1_chg_max_a','Bat.1 max. Ladestrom',   'F', 'GWH.Ampere', false, 'bat1', 'BMS 47903'],
                ['bat1_dis_max_a','Bat.1 max. Entladestrom', 'F', 'GWH.Ampere', false, 'bat1', 'BMS 47905'],
                ['bat1_bms_warn', 'Bat.1 BMS Warnung',       'I', '', true,  'bat1', 'BMS 47911'],
                ['bat1_bms_alarm','Bat.1 BMS Alarm',         'I', '', true,  'bat1', 'BMS 47913'],
            ]],
            'GroupBat2' => ['caption' => 'Batterie 2 (Spannung, Strom, Leistung, Modus)', 'vars' => [
                ['bat2_volt', 'Bat.2 Spannung', 'F', 'GWH.Volt',   false, 'bat2', 'DSP 35262'],
                ['bat2_curr', 'Bat.2 Strom',    'F', 'GWH.Ampere', true,  'bat2', 'DSP 35263'],
                ['bat2_pwr',  'Bat.2 Leistung', 'F', 'GWH.Watt',   true,  'bat2', 'DSP 35264'],
                ['bat2_mode', 'Bat.2 Modus',    'I', 'GWH.BatMode', true,  'bat2', 'DSP 35266'],
                ['bat2_soc',  'Bat.2 SOC',      'I', '~Battery.100', true, 'bat2', 'BMS 47926'],
                ['bat2_soh',  'Bat.2 SOH',      'I', '~Intensity.100', true, 'bat2', 'BMS 47927'],
                ['bat2_temp', 'Bat.2 Temperatur', 'F', '~Temperature', true, 'bat2', 'BMS 47928'],
                ['bat2_bms_volt', 'Bat.2 Spannung (BMS)', 'F', 'GWH.Volt',   false, 'bat2', 'BMS 47924'],
                ['bat2_bms_curr', 'Bat.2 Strom (BMS)',    'F', 'GWH.Ampere', true,  'bat2', 'BMS 47925'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (kWh Heute/Gesamt: PV, Netz, Last, Batterie)', 'vars' => [
                ['e_pv_day',       'PV Heute',           'F', '~Electricity', true, 'energy', 'DSP 35193'],
                ['e_pv_total',     'PV Gesamt',           'F', '~Electricity', true, 'energy', 'DSP 35191'],
                ['e_sell_day',     'Einspeisung Heute',   'F', '~Electricity', true, 'energy', 'DSP 35199'],
                ['e_buy_day',      'Bezug Heute',         'F', '~Electricity', true, 'energy', 'DSP 35202'],
                ['e_load_day',     'Last Heute',          'F', '~Electricity', true, 'energy', 'DSP 35205'],
                ['e_load_total',   'Last Gesamt',         'F', '~Electricity', true, 'energy', 'DSP 35203'],
                ['e_charge_day',   'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'DSP 35208'],
                ['e_charge_total', 'Bat. Laden Gesamt',   'F', '~Electricity', true, 'energy', 'DSP 35206'],
                ['e_disch_day',    'Bat. Entl. Heute',    'F', '~Electricity', true, 'energy', 'DSP 35211'],
                ['e_disch_total',  'Bat. Entl. Gesamt',   'F', '~Electricity', true, 'energy', 'DSP 35209'],
                ['work_hours',     'Betriebsstunden',     'F', '', false, 'energy', 'DSP 35197'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung, Spannung, Strom je Phase)', 'vars' => [
                ['mt_l1_volt', 'Netz L1 Spannung', 'F', 'GWH.Volt',   false, 'meter', 'SM 36052'],
                ['mt_l2_volt', 'Netz L2 Spannung', 'F', 'GWH.Volt',   false, 'meter', 'SM 36053'],
                ['mt_l3_volt', 'Netz L3 Spannung', 'F', 'GWH.Volt',   false, 'meter', 'SM 36054'],
                ['mt_l1_curr', 'Netz L1 Strom',    'F', 'GWH.Ampere', false, 'meter', 'SM 36055'],
                ['mt_l2_curr', 'Netz L2 Strom',    'F', 'GWH.Ampere', false, 'meter', 'SM 36056'],
                ['mt_l3_curr', 'Netz L3 Strom',    'F', 'GWH.Ampere', false, 'meter', 'SM 36057'],
                ['mt_l1_pwr',  'Netz L1 Leistung', 'F', 'GWH.Watt',   true,  'meter', 'SM 36019'],
                ['mt_l2_pwr',  'Netz L2 Leistung', 'F', 'GWH.Watt',   true,  'meter', 'SM 36021'],
                ['mt_l3_pwr',  'Netz L3 Leistung', 'F', 'GWH.Watt',   true,  'meter', 'SM 36023'],
            ]],
            'GroupTemp' => ['caption' => 'Temperaturen (Luft, Modul, Kühlkörper)', 'vars' => [
                ['temp_air',      'Lufttemperatur',  'F', '~Temperature', false, 'device', 'DSP 35174'],
                ['temp_module',   'Modultemperatur', 'F', '~Temperature', true,  'device', 'DSP 35175'],
                ['temp_heatsink', 'Kuehlkoerper',    'F', '~Temperature', true,  'device', 'DSP 35176'],
            ]],
            'GroupBackup' => ['caption' => 'Backup / Inselbetrieb (Leistung, Spannung je Phase)', 'vars' => [
                ['backup_total', 'Backup Leistung',  'F', 'GWH.Watt', true,  'backup', 'DSP 35169'],
                ['backup_active','Backup aktiv',     'B', '~Switch',   false, 'backup', 'RW 45252'],
                ['backup_l1_volt','Backup Spannung L1', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35145'],
                ['backup_l1_curr','Backup Strom L1',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35146'],
                ['backup_l1_freq','Backup Frequenz L1', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35147'],
                ['backup_l1_pwr', 'Backup Leistung L1',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35149'],
                ['backup_l2_volt','Backup Spannung L2', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35151'],
                ['backup_l2_curr','Backup Strom L2',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35152'],
                ['backup_l2_freq','Backup Frequenz L2', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35153'],
                ['backup_l2_pwr', 'Backup Leistung L2',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35155'],
                ['backup_l3_volt','Backup Spannung L3', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35157'],
                ['backup_l3_curr','Backup Strom L3',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35158'],
                ['backup_l3_freq','Backup Frequenz L3', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35159'],
                ['backup_l3_pwr', 'Backup Leistung L3',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35161'],
            ]],
            'GroupErrors' => ['caption' => 'Fehler- und Warncodes', 'vars' => [
                ['warn_code',  'Warncode',      'I', '', true, 'errors', 'DSP 32000'],
                ['err_msg',    'Fehlercode',    'I', '', true, 'errors', 'DSP 32002'],
                ['err_detail', 'Fehler Detail', 'S', '', true, 'errors', ''],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation (Seriennummer, Modell, Firmware)', 'vars' => [
                ['dev_sn',      'Seriennummer', 'S', '', false, 'device', 'DSP 35003'],
                ['dev_model',   'Modell',       'S', '', false, 'device', 'DSP 35011'],
                ['dev_rated_w', 'Nennleistung', 'I', '', false, 'device', 'DSP 35001'],
                ['dev_fw_arm',  'Firmware ARM', 'I', '', false, 'device', 'DSP 35019'],
                ['dev_fw_dsp',  'Firmware DSP', 'I', '', false, 'device', 'DSP 35016'],
            ]],
            'GroupControl' => ['caption' => 'EMS-Steuerung (Betriebsmodus, Einspeisegrenze, SOC-Limits)', 'vars' => [
                ['ctl_work_mode',     'Steuermodus',          'I', 'GWH.WorkMode', false, 'control', 'RW 47000'],
                ['ctl_ems_enable',    'EMS-Steuerung aktiv',  'B', '~Switch',      false, 'control', 'RW 47505'],
                ['ctl_ems_mode',      'EMS Leistungsmodus',   'I', 'GWH.EMSMode',  false, 'control', 'RW 47511'],
                ['ctl_ems_power',     'EMS Leistung (W)',     'I', 'GWH.WattEMS',  false, 'control', 'RW 47512'],
                ['ctl_export_enable', 'Einspeisung Ja/Nein',  'B', '~Switch',      false, 'control', 'RW 47509'],
                ['ctl_export_limit',  'Einspeisung Max. (W)', 'I', 'GWH.WattEMS',  false, 'control', 'RW 47510'],
                ['ctl_soc_min',       'SOC Min. Entladung',   'I', 'GWH.Percent',  false, 'control', 'RW 45356'],
                ['ctl_internet',      'Cloud-Verbindung',     'B', '~Switch',      false, 'control', 'RW 47017'],
                ['ctl_restart',       'WR Neustart',          'B', '~Switch',      false, 'control', 'WO 45220'],
            ]],
        ];
    }

    public function getExtraBooleanProperties(){
        return [
            'EnableTracker1' => true,
            'EnableTracker2' => true,
            'EnableTracker3' => true,
        ];
    }

    public function getProfiles(){
        return [
            'GWH.Watt'      => [VARIABLETYPE_FLOAT,   ' W',  -40000.0, 40000.0, 1.0,  0],
            'GWH.Volt'      => [VARIABLETYPE_FLOAT,   ' V',       0.0,  1000.0, 0.1,  1],
            'GWH.Ampere'    => [VARIABLETYPE_FLOAT,   ' A',    -200.0,   200.0, 0.1,  1],
            'GWH.Hertz'     => [VARIABLETYPE_FLOAT,   ' Hz',     45.0,    65.0, 0.01, 2],
            'GWH.Percent'   => [VARIABLETYPE_INTEGER, ' %',          0,     100, 1,    0],
            'GWH.WattEMS'   => [VARIABLETYPE_INTEGER, ' W',          0,   34500, 1,    0],
            'GWH.KOhm'      => [VARIABLETYPE_FLOAT,   ' kΩ',       0.0,  5000.0, 0.1,  1],
        ];
    }

    public function getEnumProfiles(){
        $wmColors = [0xF5A623, 0x7A8A99, 0x2BB3C0, 0x27D07F, 0xE74C3C, 0xF39C12];
        $workMode = [];
        foreach (self::WORK_MODES as $k => $label) {
            $workMode[$k] = [$label, $wmColors[$k] ?? 0x7A8A99];
        }
        $emsMode = [];
        foreach (self::EMS_MODES as $k => $label) {
            $emsMode[$k] = [$label, 0x7A8A99];
        }
        $batMode = [];
        foreach (self::BAT_MODES as $k => $label) {
            $batMode[$k] = [$label, 0x7A8A99];
        }
        $gridMode = [];
        foreach (self::GRID_MODES as $k => $label) {
            $gridMode[$k] = [$label, 0x7A8A99];
        }
        return [
            'GWH.WorkMode' => $workMode,
            'GWH.EMSMode'  => $emsMode,
            'GWH.BatMode'  => $batMode,
            'GWH.GridMode' => $gridMode,
        ];
    }

    public function readFast($mb, $hub){
        $inv     = $mb->readHolding(35103, 42);
        $bat1blk = $mb->readHolding(35174, 18);
        $bat2blk = $mb->readHolding(35262, 7);
        $pvext   = $mb->readHolding(35301, 41);
        $meter   = $mb->readHolding(36019, 39);
        // WBMS Bat1+Bat2 in einem Block ab 47900 (count 34). Ab 47900 lesen,
        // sonst liefern 47908/47909 (SOC/SOH) 0xFFFF!
        $bms     = $mb->readHolding(47900, 34);

        $ok = ($inv !== null && $bat1blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $pvTotal = ($pvext !== null) ? (float)$mb->u32($pvext, 0) : 0.0;
        $hub->SetVarFloat('pv_total', $pvTotal);

        $risoBlk = $mb->readHolding(35365, 1);
        if ($risoBlk !== null) {
            $hub->SetVarFloat('riso', $mb->u16($risoBlk, 0) / 10.0);
        }

        $wm = $mb->readHolding(47000, 1);
        if ($wm !== null) {
            $hub->SetVarInt('work_mode', $mb->u16($wm, 0));
        }

        $bat2Active = $hub->GetPropBool('GroupBat2') && ($bat2blk !== null);
        $soc1 = ($bms !== null) ? (float)$mb->u16($bms, 8)  : 0.0;
        $soc2 = ($bms !== null) ? (float)$mb->u16($bms, 26) : 0.0;
        $hub->SetVarInt('soc', (int)round($bat2Active ? (($soc1 + $soc2) / 2.0) : $soc1));

        if ($hub->GetPropBool('EnableTracker1')) {
            $hub->SetVarFloat('pv1_voltage', $mb->u16($inv, 0) / 10.0);
            $hub->SetVarFloat('pv1_current', $mb->u16($inv, 1) / 10.0);
            $hub->SetVarFloat('pv2_voltage', $mb->u16($inv, 4) / 10.0);
            $hub->SetVarFloat('pv2_current', $mb->u16($inv, 5) / 10.0);
        }
        if ($hub->GetPropBool('EnableTracker2')) {
            $hub->SetVarFloat('pv3_voltage', $mb->u16($inv, 8)  / 10.0);
            $hub->SetVarFloat('pv3_current', $mb->u16($inv, 9)  / 10.0);
            $hub->SetVarFloat('pv4_voltage', $mb->u16($inv, 12) / 10.0);
            $hub->SetVarFloat('pv4_current', $mb->u16($inv, 13) / 10.0);
        }
        if ($hub->GetPropBool('EnableTracker3') && $pvext !== null) {
            $hub->SetVarFloat('pv5_voltage', $mb->u16($pvext, 3) / 10.0);
            $hub->SetVarFloat('pv5_current', $mb->u16($pvext, 4) / 10.0);
            $hub->SetVarFloat('pv6_voltage', $mb->u16($pvext, 6) / 10.0);
            $hub->SetVarFloat('pv6_current', $mb->u16($pvext, 7) / 10.0);
        }

        // Firmware-Quirk (live verifiziert): Requests ab Register 35333+
        // liefern für 35338/35339 nur 0xFFFF. Ab 35332 oder früher stabil.
        $mpptBlk = $mb->readHolding(35332, 16);
        if ($mpptBlk !== null) {
            if ($hub->GetPropBool('EnableTracker1')) {
                $hub->SetVarFloat('mppt1_power',   (float)$mb->u16($mpptBlk, 5));
                $hub->SetVarFloat('mppt1_current', (float)$mb->u16($mpptBlk, 13));
            }
            if ($hub->GetPropBool('EnableTracker2')) {
                $hub->SetVarFloat('mppt2_power',   (float)$mb->u16($mpptBlk, 6));
                $hub->SetVarFloat('mppt2_current', (float)$mb->u16($mpptBlk, 14));
            }
            if ($hub->GetPropBool('EnableTracker3')) {
                $hub->SetVarFloat('mppt3_power',   (float)$mb->u16($mpptBlk, 7));
                $hub->SetVarFloat('mppt3_current', (float)$mb->u16($mpptBlk, 15));
            }
        }

        $gridMode = $mb->u16($inv, 33);
        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_l1_volt', $mb->u16($inv, 18) / 10.0);
            $hub->SetVarFloat('grid_l1_curr', $mb->u16($inv, 19) / 10.0);
            $hub->SetVarFloat('grid_l1_freq', $mb->u16($inv, 20) / 100.0);
            $hub->SetVarFloat('grid_l1_pwr',  (float)$mb->s32($inv, 21));
            $hub->SetVarFloat('grid_l2_volt', $mb->u16($inv, 23) / 10.0);
            $hub->SetVarFloat('grid_l2_curr', $mb->u16($inv, 24) / 10.0);
            $hub->SetVarFloat('grid_l2_freq', $mb->u16($inv, 25) / 100.0);
            $hub->SetVarFloat('grid_l2_pwr',  (float)$mb->s32($inv, 26));
            $hub->SetVarFloat('grid_l3_volt', $mb->u16($inv, 28) / 10.0);
            $hub->SetVarFloat('grid_l3_curr', $mb->u16($inv, 29) / 10.0);
            $hub->SetVarFloat('grid_l3_freq', $mb->u16($inv, 30) / 100.0);
            $hub->SetVarFloat('grid_l3_pwr',  (float)$mb->s32($inv, 31));
            $hub->SetVarFloat('inv_total',    (float)$mb->s32($inv, 34));
        }
        $hub->SetVarInt('grid_mode',  $gridMode);
        $hub->SetVarFloat('ac_power', (float)$mb->s32($inv, 36));
        $hub->SetVarBool('island', ($gridMode === 17 || $gridMode === 18));

        if ($meter !== null) {
            $hub->SetVarFloat('meter_total', (float)$mb->s32($meter, 6));
        }

        if ($hub->GetPropBool('GroupBat1')) {
            $hub->SetVarFloat('bat1_volt', $mb->u16($bat1blk, 6)  / 10.0);
            $hub->SetVarFloat('bat1_curr', $mb->s16($bat1blk, 7)  / 10.0);
            $hub->SetVarFloat('bat1_pwr',  (float)$mb->s32($bat1blk, 8));
            $hub->SetVarInt('bat1_mode',   $mb->u16($bat1blk, 10));
            $hub->SetVarInt('bat1_soc',  (int)round($soc1));
            if ($bms !== null) {
                $hub->SetVarInt('bat1_soh',  $mb->u16($bms, 9));
                $hub->SetVarFloat('bat1_temp', $mb->s16($bms, 10) / 10.0);
                $hub->SetVarFloat('bat1_bms_volt', $mb->u16($bms, 6) / 10.0);
                $hub->SetVarFloat('bat1_bms_curr', $mb->s16($bms, 7) / 10.0);
                $hub->SetVarFloat('bat1_chg_max_a', $mb->u16($bms, 3) / 10.0);
                $hub->SetVarFloat('bat1_dis_max_a', $mb->u16($bms, 5) / 10.0);
                $hub->SetVarInt('bat1_bms_warn',  $mb->u32($bms, 11));
                $hub->SetVarInt('bat1_bms_alarm', $mb->u32($bms, 13));
            }
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp_air',      $mb->s16($bat1blk, 0) / 10.0);
            $hub->SetVarFloat('temp_module',   $mb->s16($bat1blk, 1) / 10.0);
            $hub->SetVarFloat('temp_heatsink', $mb->s16($bat1blk, 2) / 10.0);
        }

        if ($bat2Active) {
            $hub->SetVarFloat('bat2_volt', $mb->u16($bat2blk, 0)  / 10.0);
            $hub->SetVarFloat('bat2_curr', $mb->s16($bat2blk, 1)  / 10.0);
            $hub->SetVarFloat('bat2_pwr',  (float)$mb->s32($bat2blk, 2));
            $hub->SetVarInt('bat2_mode',   $mb->u16($bat2blk, 4));
            $hub->SetVarInt('bat2_soc',  (int)round($soc2));
            if ($bms !== null) {
                $hub->SetVarInt('bat2_soh',  $mb->u16($bms, 27));
                $hub->SetVarFloat('bat2_temp', $mb->s16($bms, 28) / 10.0);
                $hub->SetVarFloat('bat2_bms_volt', $mb->u16($bms, 24) / 10.0);
                $hub->SetVarFloat('bat2_bms_curr', $mb->s16($bms, 25) / 10.0);
            }
        }

        $b1p = $mb->s32($bat1blk, 8);
        $b2p = ($bat2blk !== null) ? $mb->s32($bat2blk, 2) : 0;
        $hub->SetVarFloat('bat_total_pwr', (float)($b1p + $b2p));

        if ($bms !== null) {
            $v1      = $mb->u16($bms, 6) / 10.0;
            $chgMaxW = ($mb->u16($bms, 3) / 10.0) * $v1;
            $disMaxW = ($mb->u16($bms, 5) / 10.0) * $v1;
            if ($bat2Active) {
                $v2 = $mb->u16($bms, 24) / 10.0;
                $chgMaxW += ($mb->u16($bms, 21) / 10.0) * $v2;
                $disMaxW += ($mb->u16($bms, 23) / 10.0) * $v2;
            }
            $hub->SetVarFloat('bat_charge_max_w',    $chgMaxW);
            $hub->SetVarFloat('bat_discharge_max_w', $disMaxW);
        }

        if ($hub->GetPropBool('GroupMeter') && $meter !== null) {
            $hub->SetVarFloat('mt_l1_pwr',  (float)$mb->s32($meter, 0));
            $hub->SetVarFloat('mt_l2_pwr',  (float)$mb->s32($meter, 2));
            $hub->SetVarFloat('mt_l3_pwr',  (float)$mb->s32($meter, 4));
            $hub->SetVarFloat('mt_l1_volt', $mb->u16($meter, 33) / 10.0);
            $hub->SetVarFloat('mt_l2_volt', $mb->u16($meter, 34) / 10.0);
            $hub->SetVarFloat('mt_l3_volt', $mb->u16($meter, 35) / 10.0);
            $hub->SetVarFloat('mt_l1_curr', $mb->u16($meter, 36) / 10.0);
            $hub->SetVarFloat('mt_l2_curr', $mb->u16($meter, 37) / 10.0);
            $hub->SetVarFloat('mt_l3_curr', $mb->u16($meter, 38) / 10.0);
            $freqBlk = $mb->readHolding(36014, 1);
            if ($freqBlk !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freqBlk, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBackup')) {
            $bkPhase = $mb->readHolding(35145, 26);
            if ($bkPhase !== null) {
                $hub->SetVarFloat('backup_l1_volt', $mb->u16($bkPhase, 0)  / 10.0);
                $hub->SetVarFloat('backup_l1_curr', $mb->u16($bkPhase, 1)  / 10.0);
                $hub->SetVarFloat('backup_l1_freq', $mb->u16($bkPhase, 2)  / 100.0);
                $hub->SetVarFloat('backup_l1_pwr',  (float)$mb->s32($bkPhase, 4));
                $hub->SetVarFloat('backup_l2_volt', $mb->u16($bkPhase, 6)  / 10.0);
                $hub->SetVarFloat('backup_l2_curr', $mb->u16($bkPhase, 7)  / 10.0);
                $hub->SetVarFloat('backup_l2_freq', $mb->u16($bkPhase, 8)  / 100.0);
                $hub->SetVarFloat('backup_l2_pwr',  (float)$mb->s32($bkPhase, 10));
                $hub->SetVarFloat('backup_l3_volt', $mb->u16($bkPhase, 12) / 10.0);
                $hub->SetVarFloat('backup_l3_curr', $mb->u16($bkPhase, 13) / 10.0);
                $hub->SetVarFloat('backup_l3_freq', $mb->u16($bkPhase, 14) / 100.0);
                $hub->SetVarFloat('backup_l3_pwr',  (float)$mb->s32($bkPhase, 16));
                $hub->SetVarFloat('backup_total',   (float)$mb->s32($bkPhase, 24));
            }
            $bkSt = $mb->readHolding(45252, 1);
            if ($bkSt !== null) {
                $hub->SetVarBool('backup_active', $mb->u16($bkSt, 0) > 0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub){
        if ($hub->GetPropBool('GroupEnergy')) {
            $e = $mb->readHolding(35191, 22);
            if ($e !== null) {
                $hub->SetVarFloat('e_pv_total',     $mb->u32($e, 0)  / 10.0);
                $hub->SetVarFloat('e_pv_day',       $mb->u32($e, 2)  / 10.0);
                $hub->SetVarFloat('work_hours',     (float)$mb->u32($e, 6));
                $hub->SetVarFloat('e_sell_day',     $mb->u16($e, 8)  / 10.0);
                $hub->SetVarFloat('e_buy_day',      $mb->u16($e, 11) / 10.0);
                $hub->SetVarFloat('e_load_total',   $mb->u32($e, 12) / 10.0);
                $hub->SetVarFloat('e_load_day',     $mb->u16($e, 14) / 10.0);
                $hub->SetVarFloat('e_charge_total', $mb->u32($e, 15) / 10.0);
                $hub->SetVarFloat('e_charge_day',   $mb->u16($e, 17) / 10.0);
                $hub->SetVarFloat('e_disch_total',  $mb->u32($e, 18) / 10.0);
                $hub->SetVarFloat('e_disch_day',    $mb->u16($e, 20) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupErrors')) {
            $err = $mb->readHolding(32000, 17);
            if ($err !== null) {
                $hub->SetVarInt('warn_code', $mb->u16($err, 0));
                $hub->SetVarInt('err_msg',   $mb->u16($err, 2));
                $utility = $mb->u16($err, 0);
                $detail  = [];
                if ($utility & 0x01) { $detail[] = 'Netz-Ueberspannung'; }
                if ($utility & 0x02) { $detail[] = 'Netz-Unterspannung'; }
                if ($utility & 0x04) { $detail[] = 'Netz-Ueberfrequenz'; }
                if ($utility & 0x08) { $detail[] = 'Netz-Unterfrequenz'; }
                if ($utility & 0x10) { $detail[] = 'Netz-Ueberstrom'; }
                $sys = $mb->u16($err, 2);
                if ($sys & 0x01) { $detail[] = 'Systemfehler 1'; }
                $hub->SetVarStr('err_detail', empty($detail) ? 'OK' : implode(', ', $detail));
            }
        }
    }

    public function readDeviceInfo($mb, $hub){
        $dev = $mb->readHolding(35001, 27);
        if ($dev === null) {
            return;
        }
        $hub->SetVarInt('dev_rated_w', $mb->u16($dev, 0));
        $hub->SetVarStr('dev_sn',      $mb->readStr($dev, 2, 8));
        $hub->SetVarStr('dev_model',   $mb->readStr($dev, 10, 5));
        $hub->SetVarInt('dev_fw_dsp',  $mb->u16($dev, 15));
        $hub->SetVarInt('dev_fw_arm',  $mb->u16($dev, 18));
    }

    public function writeControl($mb, $hub, string $ident, $value){
        switch ($ident) {
            case 'ctl_work_mode':
                $val = (int)$value;
                if ($val < 0 || $val > 5) { return; }
                if ($mb->writeSingle(self::REG_WORK_MODE, $val)) {
                    $hub->SetVarInt('ctl_work_mode', $val);
                    $hub->SetVarInt('work_mode', $val);
                }
                break;

            case 'ctl_ems_enable':
                $val = (bool)$value ? 2 : 0;
                if ($mb->writeSingle(self::REG_EMS_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_ems_enable', (bool)$value);
                }
                break;

            case 'ctl_ems_mode':
                $val = (int)$value;
                if ($val < 0 || $val > 12) { return; }
                if ($mb->writeSingle(self::REG_EMS_POWER_MODE, $val)) {
                    $hub->SetVarInt('ctl_ems_mode', $val);
                }
                break;

            case 'ctl_ems_power':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($mb->writeSingle(self::REG_EMS_POWER_SET, $val)) {
                    $hub->SetVarInt('ctl_ems_power', $val);
                }
                break;

            case 'ctl_export_enable':
                $val = (bool)$value ? 1 : 0;
                if ($mb->writeSingle(self::REG_FEED_POWER_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_export_enable', (bool)$value);
                }
                break;

            case 'ctl_export_limit':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($mb->writeSingle(self::REG_FEED_POWER_LIMIT, $val)) {
                    $hub->SetVarInt('ctl_export_limit', $val);
                }
                break;

            case 'ctl_soc_min':
                $val = max(0, min(100, (int)$value));
                if ($mb->writeSingle(self::REG_SOC_MIN, $val)) {
                    $hub->SetVarInt('ctl_soc_min', $val);
                }
                break;

            case 'ctl_internet':
                $val = (bool)$value ? 0 : 1;
                if ($mb->writeSingle(self::REG_INTERNET_MODE, $val)) {
                    $hub->SetVarBool('ctl_internet', (bool)$value);
                }
                break;

            case 'ctl_restart':
                if ((bool)$value) {
                    $mb->writeSingle(self::REG_RESTART, 1);
                    IPS_Sleep(500);
                    $hub->SetVarBool('ctl_restart', false);
                }
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// SungrowDriver — Sungrow SH-Hybrid-Serie (SH5.0RT...SH10RT, SH5.0RS...SH10RS)
// Eigene, weit verstreute Einzelregister. Mess-/Statuswerte: Read Input
// Register (FC 0x04). Steuerung: Holding Register (FC 0x03/0x06).
// Registeradressen werden direkt verwendet (kein Offset -1 dokumentiert).
// ---------------------------------------------------------------------------

class SungrowDriver implements InverterDriverInterface
{
    const REG_START_STOP = 13000; // Holding: 0xCF=Start, 0xCE=Stop

    const RUN_STATES = [
        0 => 'Aus', 1 => 'Läuft', 2 => 'Fehler', 3 => 'Standby',
    ];

    public function getBaseVars()
    {
        return [
            ['connected',   'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['running_state','Betriebsstatus',   'I', 'SGW.RunState',     true,  'device', 'RO 13000'],
            ['power_flow_status', 'Leistungsfluss-Status', 'I', '',       true,  'device', 'RO 13001'],
            ['pv_total',    'PV Gesamtleistung', 'F', 'SGW.Watt',         true,  'pv',     'RO 5017-5018'],
            ['ac_power',    'AC Wirkleistung',   'F', 'SGW.Watt',         true,  'device', 'RO 13034-13035'],
            ['meter_total', 'Netz Leistung',     'F', 'SGW.Watt',         true,  'grid',   'RO 5601-5602'],
            ['bat_power',   'Bat. Leistung',     'F', 'SGW.Watt',         true,  'bat',    'RO 5214-5215'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (MPPT 1-4, Spannung/Strom)', 'vars' => [
                ['mppt1_volt', 'MPPT1 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5011'],
                ['mppt1_curr', 'MPPT1 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5012'],
                ['mppt2_volt', 'MPPT2 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5013'],
                ['mppt2_curr', 'MPPT2 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5014'],
                ['mppt3_volt', 'MPPT3 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5015'],
                ['mppt3_curr', 'MPPT3 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5016'],
                ['mppt4_volt', 'MPPT4 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5115'],
                ['mppt4_curr', 'MPPT4 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5116'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Blindleistung, Power Factor, Frequenz)', 'vars' => [
                ['grid_v1',      'Netz Spannung 1', 'F', 'SGW.Volt',   false, 'grid', 'RO 5019'],
                ['grid_v2',      'Netz Spannung 2', 'F', 'SGW.Volt',   false, 'grid', 'RO 5020'],
                ['grid_v3',      'Netz Spannung 3', 'F', 'SGW.Volt',   false, 'grid', 'RO 5021'],
                ['grid_reactive','Blindleistung',    'F', 'SGW.WattReactive', false, 'grid', 'RO 5033-5034'],
                ['power_factor', 'Power Factor',     'F', 'SGW.PowerFactor',  false, 'grid', 'RO 5035'],
                ['grid_freq',    'Netzfrequenz',     'F', 'SGW.Hertz',        false, 'grid', 'RO 5242'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOC, SOH, Temperatur)', 'vars' => [
                ['bat_volt', 'Bat. Spannung',    'F', 'SGW.Volt',     false, 'bat', 'RO 13020'],
                ['bat_curr', 'Bat. Strom',       'F', 'SGW.Ampere',   false, 'bat', 'RO 13021'],
                ['bat_soc',  'Bat. SOC',         'I', '~Battery.100', true,  'bat', 'RO 13023'],
                ['bat_soh',  'Bat. SOH',         'I', '~Intensity.100', true, 'bat', 'RO 13024'],
                ['bat_temp', 'Bat. Temperatur',  'F', '~Temperature', true,  'bat', 'RO 13025'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung je Phase)', 'vars' => [
                ['mt_l1_pwr', 'Meter L1 Leistung', 'F', 'SGW.Watt', true, 'meter', 'RO 5603'],
                ['mt_l2_pwr', 'Meter L2 Leistung', 'F', 'SGW.Watt', true, 'meter', 'RO 5605'],
                ['mt_l3_pwr', 'Meter L3 Leistung', 'F', 'SGW.Watt', true, 'meter', 'RO 5607'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (PV, Last, Export)', 'vars' => [
                ['e_pv_day',   'PV Heute',   'F', '~Electricity', true, 'energy', 'RO 13002'],
                ['e_pv_total', 'PV Gesamt',  'F', '~Electricity', true, 'energy', 'RO 13003-13004'],
                ['load_power', 'Lastleistung', 'F', 'SGW.Watt',   true, 'energy', 'RO 13008-13009'],
                ['export_power','Einspeiseleistung', 'F', 'SGW.Watt', true, 'energy', 'RO 13010-13011'],
            ]],
            'GroupBackup' => ['caption' => 'Backup / Notstrom (Spannung, Strom, Leistung je Phase)', 'vars' => [
                ['backup_total', 'Backup Gesamtleistung', 'F', 'SGW.Watt', true, 'backup', 'RO 5726-5727'],
                ['backup_freq',  'Backup Frequenz',       'F', 'SGW.Hertz', false, 'backup', 'RO 5734'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation (Typ, Nennleistung, Seriennummer)', 'vars' => [
                ['dev_type',    'Gerätetyp-Code', 'I', '', false, 'device', 'RO 5000'],
                ['dev_rated_w', 'Nennleistung',    'I', '', false, 'device', 'RO 5001'],
                ['dev_sn',      'Seriennummer',    'S', '', false, 'device', 'RO 4990-4999'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Start/Stop)', 'vars' => [
                ['ctl_run', 'Wechselrichter Ein/Aus', 'B', '~Switch', false, 'control', 'RW 13000'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SGW.Watt'          => [VARIABLETYPE_FLOAT,   ' W',   -40000.0, 40000.0, 1.0,  0],
            'SGW.Volt'          => [VARIABLETYPE_FLOAT,   ' V',        0.0,  1000.0, 0.1,  1],
            'SGW.Ampere'        => [VARIABLETYPE_FLOAT,   ' A',     -200.0,   200.0, 0.1,  1],
            'SGW.Hertz'         => [VARIABLETYPE_FLOAT,   ' Hz',      45.0,    65.0, 0.01, 2],
            'SGW.WattReactive'  => [VARIABLETYPE_FLOAT,   ' var', -40000.0, 40000.0, 1.0,  0],
            'SGW.PowerFactor'   => [VARIABLETYPE_FLOAT,   '',        -1.0,     1.0, 0.001, 3],
        ];
    }

    public function getEnumProfiles()
    {
        $runState = [];
        foreach (self::RUN_STATES as $k => $label) {
            $runState[$k] = [$label, 0x7A8A99];
        }
        return ['SGW.RunState' => $runState];
    }

    public function readFast($mb, $hub)
    {
        $dc      = $mb->readInput(5011, 12);   // 5011-5022 (MPPT1-3 + DC total + Grid Volt)
        $mppt4   = $mb->readInput(5115, 2);    // 5115-5116
        $reactive= $mb->readInput(5033, 4);    // 5033-5036 (reactive/PF/freq)
        $battery = $mb->readInput(5214, 2);    // 5214-5215 Bat power wide range
        $meterTotal = $mb->readInput(5601, 2); // 5601-5602 Meter Active Power (Gesamt, real bestätigt)
        $meter   = $mb->readInput(5603, 6);    // 5603,5605,5607 (+ Reserve dazwischen) — unbestätigt
        $running = $mb->readInput(13000, 2);   // 13000 running state + 13001 power flow
        $freqhi  = $mb->readInput(5242, 1);    // 5242 high precision freq
        $sum     = $mb->readInput(13034, 2);   // 13034-13035 total active power

        $ok = ($dc !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        if ($running !== null) {
            $hub->SetVarInt('running_state', $mb->u16($running, 0));
            $hub->SetVarInt('power_flow_status', $mb->u16($running, 1));
        }
        $hub->SetVarFloat('pv_total', (float)$mb->u32($dc, 6));
        if ($sum !== null) {
            $hub->SetVarFloat('ac_power', (float)$mb->s32($sum, 0));
        }
        if ($battery !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s32($battery, 0));
        }
        if ($meterTotal !== null) {
            $hub->SetVarFloat('meter_total', (float)$mb->s32($meterTotal, 0));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('mppt1_volt', $mb->u16($dc, 0) / 10.0);
            $hub->SetVarFloat('mppt1_curr', $mb->u16($dc, 1) / 10.0);
            $hub->SetVarFloat('mppt2_volt', $mb->u16($dc, 2) / 10.0);
            $hub->SetVarFloat('mppt2_curr', $mb->u16($dc, 3) / 10.0);
            $hub->SetVarFloat('mppt3_volt', $mb->u16($dc, 4) / 10.0);
            $hub->SetVarFloat('mppt3_curr', $mb->u16($dc, 5) / 10.0);
            if ($mppt4 !== null) {
                $hub->SetVarFloat('mppt4_volt', $mb->u16($mppt4, 0) / 10.0);
                $hub->SetVarFloat('mppt4_curr', $mb->u16($mppt4, 1) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_v1', $mb->u16($dc, 9)  / 10.0);
            $hub->SetVarFloat('grid_v2', $mb->u16($dc, 10) / 10.0);
            $hub->SetVarFloat('grid_v3', $mb->u16($dc, 11) / 10.0);
            if ($reactive !== null) {
                $hub->SetVarFloat('grid_reactive', (float)$mb->s32($reactive, 0));
                $hub->SetVarFloat('power_factor',  $mb->s16($reactive, 2) / 1000.0);
            }
            if ($freqhi !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freqhi, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupMeter') && $meter !== null) {
            $hub->SetVarFloat('mt_l1_pwr', (float)$mb->s32($meter, 0));
            $hub->SetVarFloat('mt_l2_pwr', (float)$mb->s32($meter, 2));
            $hub->SetVarFloat('mt_l3_pwr', (float)$mb->s32($meter, 4));
        }

        if ($hub->GetPropBool('GroupBat')) {
            $batBlk = $mb->readInput(13020, 7); // 13020-13026
            if ($batBlk !== null) {
                $hub->SetVarFloat('bat_volt', $mb->u16($batBlk, 0) / 10.0);
                $hub->SetVarFloat('bat_curr', $mb->u16($batBlk, 1) / 10.0);
                $hub->SetVarInt('bat_soc', (int)round($mb->u16($batBlk, 3) / 10.0));
                $hub->SetVarInt('bat_soh', (int)round($mb->u16($batBlk, 4) / 10.0));
                $hub->SetVarFloat('bat_temp', $mb->s16($batBlk, 5) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupBackup')) {
            $bk = $mb->readInput(5726, 10); // 5726-5735
            if ($bk !== null) {
                $hub->SetVarFloat('backup_total', (float)$mb->s32($bk, 0));
                $hub->SetVarFloat('backup_freq',  $mb->u16($bk, 8) / 100.0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupEnergy')) {
            $e = $mb->readInput(13002, 11); // 13002-13012
            if ($e !== null) {
                $hub->SetVarFloat('e_pv_day',   $mb->u16($e, 0) / 10.0);
                $hub->SetVarFloat('e_pv_total', $mb->u32($e, 1) / 10.0);
                $hub->SetVarFloat('load_power',   (float)$mb->s32($e, 6));
                $hub->SetVarFloat('export_power', (float)$mb->s32($e, 8));
            }
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $dev = $mb->readInput(5000, 2); // 5000-5001
        if ($dev !== null) {
            $hub->SetVarInt('dev_type',    $mb->u16($dev, 0));
            $hub->SetVarInt('dev_rated_w', $mb->u16($dev, 1) * 100);
        }
        $sn = $mb->readInput(4989, 10); // 4990-4999 UTF-8
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 10));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        if ($ident === 'ctl_run') {
            $val = (bool)$value ? 0xCF : 0xCE;
            if ($mb->writeSingle(SungrowDriver::REG_START_STOP, $val)) {
                $hub->SetVarBool('ctl_run', (bool)$value);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// SolisDriver — Solis Hybrid-Wechselrichter (33000er-Registerblock).
// Registeradressen direkt verwendet. Mess-/Statuswerte: Read Input Register
// (FC 0x04). Reine String-Wechselrichter (3000er-Block) werden derzeit
// nicht unterstützt.
// ---------------------------------------------------------------------------

class SolisDriver implements InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected',   'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',    'PV Gesamtleistung', 'F', 'SLS.Watt', true,  'pv',       'RO 33057-33058'],
            ['ac_power',    'AC Wirkleistung',   'F', 'SLS.Watt', true,  'device',   'RO 33079-33080'],
            ['meter_total', 'Netz Leistung',      'F', 'SLS.Watt', true,  'grid',    'RO 33151-33152'],
            ['bat_power',   'Bat. Leistung',     'F', 'SLS.Watt', true,  'bat',      'RO 33149-33150'],
            ['bat_soc',     'Bat. SOC',          'I', '~Battery.100', true, 'bat',   'RO 33139'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (bis zu 4 Strings)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33049'],
                ['pv1_curr', 'PV1 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33050'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33051'],
                ['pv2_curr', 'PV2 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33052'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33053'],
                ['pv3_curr', 'PV3 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33054'],
                ['pv4_volt', 'PV4 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33055'],
                ['pv4_curr', 'PV4 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33056'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Blind-/Scheinleistung je Phase)', 'vars' => [
                ['grid_l1_volt', 'Netz L1 Spannung', 'F', 'SLS.Volt',   false, 'grid', 'RO 33073'],
                ['grid_l2_volt', 'Netz L2 Spannung', 'F', 'SLS.Volt',   false, 'grid', 'RO 33074'],
                ['grid_l3_volt', 'Netz L3 Spannung', 'F', 'SLS.Volt',   false, 'grid', 'RO 33075'],
                ['grid_l1_curr', 'Netz L1 Strom',    'F', 'SLS.Ampere', false, 'grid', 'RO 33076'],
                ['grid_l2_curr', 'Netz L2 Strom',    'F', 'SLS.Ampere', false, 'grid', 'RO 33077'],
                ['grid_l3_curr', 'Netz L3 Strom',    'F', 'SLS.Ampere', false, 'grid', 'RO 33078'],
                ['grid_freq',    'Netzfrequenz',      'F', 'SLS.Hertz', false, 'grid', 'RO 33094'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOH)', 'vars' => [
                ['bat_volt', 'Bat. Spannung', 'F', 'SLS.Volt',   false, 'bat', 'RO 33133'],
                ['bat_curr', 'Bat. Strom',    'F', 'SLS.Ampere', false, 'bat', 'RO 33134'],
                ['bat_soh',  'Bat. SOH',      'I', '~Intensity.100', true, 'bat', 'RO 33140'],
                ['household_load', 'Hausverbrauch', 'F', 'SLS.Watt', true, 'bat', 'RO 33147'],
                ['backup_load',    'Backup-Last',   'F', 'SLS.Watt', true, 'bat', 'RO 33148'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Spannung, Strom je Phase)', 'vars' => [
                ['mt_l1_volt', 'Meter L1 Spannung', 'F', 'SLS.Volt',   false, 'meter', 'RO 33251'],
                ['mt_l2_volt', 'Meter L2 Spannung', 'F', 'SLS.Volt',   false, 'meter', 'RO 33253'],
                ['mt_l3_volt', 'Meter L3 Spannung', 'F', 'SLS.Volt',   false, 'meter', 'RO 33255'],
                ['mt_total_pwr', 'Meter Gesamtleistung', 'F', 'SLS.Watt', true, 'meter', 'RO 33263-33264'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_inv', 'Wechselrichter-Temperatur', 'F', '~Temperature', false, 'device', 'RO 33093'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (PV, Batterie, Netz, Verbrauch)', 'vars' => [
                ['e_pv_day',       'PV Heute',            'F', '~Electricity', true, 'energy', 'RO 33035'],
                ['e_pv_total',     'PV Gesamt',           'F', '~Electricity', true, 'energy', 'RO 33029-33030'],
                ['e_charge_day',   'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'RO 33163'],
                ['e_charge_total', 'Bat. Laden Gesamt',   'F', '~Electricity', true, 'energy', 'RO 33161-33162'],
                ['e_disch_day',    'Bat. Entl. Heute',    'F', '~Electricity', true, 'energy', 'RO 33167'],
                ['e_disch_total',  'Bat. Entl. Gesamt',   'F', '~Electricity', true, 'energy', 'RO 33165-33166'],
                ['e_buy_day',      'Bezug Heute',         'F', '~Electricity', true, 'energy', 'RO 33171'],
                ['e_buy_total',    'Bezug Gesamt',        'F', '~Electricity', true, 'energy', 'RO 33169-33170'],
                ['e_sell_day',     'Einspeisung Heute',   'F', '~Electricity', true, 'energy', 'RO 33175'],
                ['e_sell_total',   'Einspeisung Gesamt',  'F', '~Electricity', true, 'energy', 'RO 33173-33174'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell-Nr.', 'I', '', false, 'device', 'RO 33000'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'RO 33004-33019'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLS.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLS.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLS.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'SLS.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $pv     = $mb->readInput(33049, 12);   // 33049-33060 (PV1-4 V/I + Total DC-Bereich)
        $ac     = $mb->readInput(33079, 6);    // 33079-33084 Wirk/Blind/Scheinleistung
        $bat    = $mb->readInput(33132, 20);   // 33132-33151 Batterie + Household/Backup Load
        $meter  = $mb->readInput(33151, 3);    // 33151-33152 (+33153 Reserve) Grid Port Power

        $ok = ($pv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', (float)$mb->u32($pv, 8));  // 33057-33058
        if ($ac !== null) {
            $hub->SetVarFloat('ac_power', (float)$mb->s32($ac, 0));
        }
        if ($bat !== null) {
            $hub->SetVarInt('bat_soc', $mb->u16($bat, 7));                // 33139
            $hub->SetVarFloat('bat_power', (float)$mb->s32($bat, 17));    // 33149-33150
        }
        if ($meter !== null) {
            $hub->SetVarFloat('meter_total', (float)$mb->s32($meter, 0));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt', $mb->u16($pv, 0) / 10.0);
            $hub->SetVarFloat('pv1_curr', $mb->u16($pv, 1) / 10.0);
            $hub->SetVarFloat('pv2_volt', $mb->u16($pv, 2) / 10.0);
            $hub->SetVarFloat('pv2_curr', $mb->u16($pv, 3) / 10.0);
            $hub->SetVarFloat('pv3_volt', $mb->u16($pv, 4) / 10.0);
            $hub->SetVarFloat('pv3_curr', $mb->u16($pv, 5) / 10.0);
            $hub->SetVarFloat('pv4_volt', $mb->u16($pv, 6) / 10.0);
            $hub->SetVarFloat('pv4_curr', $mb->u16($pv, 7) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $grid = $mb->readInput(33073, 24); // 33073-33096
            if ($grid !== null) {
                $hub->SetVarFloat('grid_l1_volt', $mb->u16($grid, 0) / 10.0);
                $hub->SetVarFloat('grid_l2_volt', $mb->u16($grid, 1) / 10.0);
                $hub->SetVarFloat('grid_l3_volt', $mb->u16($grid, 2) / 10.0);
                $hub->SetVarFloat('grid_l1_curr', $mb->u16($grid, 3) / 10.0);
                $hub->SetVarFloat('grid_l2_curr', $mb->u16($grid, 4) / 10.0);
                $hub->SetVarFloat('grid_l3_curr', $mb->u16($grid, 5) / 10.0);
                $hub->SetVarFloat('grid_freq', $mb->u16($grid, 21) / 100.0);
                if ($hub->GetPropBool('GroupTemp')) {
                    $hub->SetVarFloat('temp_inv', $mb->s16($grid, 20) / 10.0);
                }
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 1) / 10.0);       // 33133
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 2) / 10.0);       // 33134
            $hub->SetVarInt('bat_soh',    $mb->u16($bat, 8));              // 33140
            $hub->SetVarFloat('household_load', (float)$mb->u16($bat, 15)); // 33147
            $hub->SetVarFloat('backup_load',    (float)$mb->u16($bat, 16)); // 33148
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $mtV = $mb->readInput(33251, 14); // 33251-33264
            if ($mtV !== null) {
                $hub->SetVarFloat('mt_l1_volt', $mb->u16($mtV, 0) / 10.0);
                $hub->SetVarFloat('mt_l2_volt', $mb->u16($mtV, 2) / 10.0);
                $hub->SetVarFloat('mt_l3_volt', $mb->u16($mtV, 4) / 10.0);
                $hub->SetVarFloat('mt_total_pwr', (float)$mb->s32($mtV, 12));
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupEnergy')) {
            $e1 = $mb->readInput(33029, 12);  // 33029-33040
            if ($e1 !== null) {
                $hub->SetVarFloat('e_pv_total', $mb->u32($e1, 0) / 10.0);
                $hub->SetVarFloat('e_pv_day',   $mb->u16($e1, 6) / 10.0);
            }
            $e2 = $mb->readInput(33161, 20);  // 33161-33180
            if ($e2 !== null) {
                $hub->SetVarFloat('e_charge_total', $mb->u32($e2, 0)  / 10.0);
                $hub->SetVarFloat('e_charge_day',   $mb->u16($e2, 2)  / 10.0);
                $hub->SetVarFloat('e_disch_total',  $mb->u32($e2, 4)  / 10.0);
                $hub->SetVarFloat('e_disch_day',    $mb->u16($e2, 6)  / 10.0);
                $hub->SetVarFloat('e_buy_total',    $mb->u32($e2, 8)  / 10.0);
                $hub->SetVarFloat('e_buy_day',      $mb->u16($e2, 10) / 10.0);
                $hub->SetVarFloat('e_sell_total',   $mb->u32($e2, 12) / 10.0);
                $hub->SetVarFloat('e_sell_day',     $mb->u16($e2, 14) / 10.0);
            }
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $model = $mb->readInput(33000, 1);
        if ($model !== null) {
            $hub->SetVarInt('dev_model', $mb->u16($model, 0));
        }
        $sn = $mb->readInput(33004, 16);
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 16));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// GrowattDriver — Growatt Wechselrichter (TL-X/TL3-X/MOD/MIX/SPH/WIT-Serie).
// Durchnummerierte Input-Register (FC 0x04), 32-Bit-Werte als H/L-Paar.
// Modellfamilien unterscheiden sich in Registerbereichen (siehe Growatt-
// Protokoll Kapitel 1.2) — abgedeckt ist der gemeinsame Basisbereich
// (Register-Index 0-107), der bei praktisch allen Modellfamilien gilt.
// ---------------------------------------------------------------------------

class GrowattDriver implements InverterDriverInterface
{
    const STATUS = [0 => 'Warte', 1 => 'Normal', 3 => 'Fehler'];

    public function getBaseVars()
    {
        return [
            ['connected',  'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',     'Betriebsstatus',    'I', 'GRW.Status',       true,  'device', 'Input 0'],
            ['pv_total',   'PV Gesamtleistung', 'F', 'GRW.Watt',         true,  'pv',     'Input 1-2'],
            ['ac_power',   'AC Wirkleistung',   'F', 'GRW.Watt',         true,  'device', 'Input 35-36'],
            ['grid_freq',  'Netzfrequenz',      'F', 'GRW.Hertz',        false, 'grid',   'Input 37'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (String 1-3, Spannung/Strom/Leistung)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'GRW.Volt',   false, 'pv', 'Input 3'],
                ['pv1_curr', 'PV1 Strom',    'F', 'GRW.Ampere', false, 'pv', 'Input 4'],
                ['pv1_power','PV1 Leistung', 'F', 'GRW.Watt',   true,  'pv', 'Input 5-6'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'GRW.Volt',   false, 'pv', 'Input 7'],
                ['pv2_curr', 'PV2 Strom',    'F', 'GRW.Ampere', false, 'pv', 'Input 8'],
                ['pv2_power','PV2 Leistung', 'F', 'GRW.Watt',   true,  'pv', 'Input 9-10'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'GRW.Volt',   false, 'pv', 'Input 11'],
                ['pv3_curr', 'PV3 Strom',    'F', 'GRW.Ampere', false, 'pv', 'Input 12'],
                ['pv3_power','PV3 Leistung', 'F', 'GRW.Watt',   true,  'pv', 'Input 13-14'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Leistung je Phase)', 'vars' => [
                ['vac1', 'Netz L1 Spannung', 'F', 'GRW.Volt',   false, 'grid', 'Input 38'],
                ['iac1', 'Netz L1 Strom',    'F', 'GRW.Ampere', false, 'grid', 'Input 39'],
                ['pac1', 'Netz L1 Leistung', 'F', 'GRW.Watt',   true,  'grid', 'Input 40-41'],
                ['vac2', 'Netz L2 Spannung', 'F', 'GRW.Volt',   false, 'grid', 'Input 42'],
                ['iac2', 'Netz L2 Strom',    'F', 'GRW.Ampere', false, 'grid', 'Input 43'],
                ['pac2', 'Netz L2 Leistung', 'F', 'GRW.Watt',   true,  'grid', 'Input 44-45'],
                ['vac3', 'Netz L3 Spannung', 'F', 'GRW.Volt',   false, 'grid', 'Input 46'],
                ['iac3', 'Netz L3 Strom',    'F', 'GRW.Ampere', false, 'grid', 'Input 47'],
                ['pac3', 'Netz L3 Leistung', 'F', 'GRW.Watt',   true,  'grid', 'Input 48-49'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Gesamt)', 'vars' => [
                ['e_day',   'Ertrag Heute',  'F', '~Electricity', true, 'energy', 'Input 53-54'],
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'Input 55-56'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp1', 'Wechselrichter-Temperatur', 'F', '~Temperature', false, 'device', 'Input 93'],
            ]],
            'GroupErrors' => ['caption' => 'Fehlercodes', 'vars' => [
                ['fault_main', 'Fehler-Hauptcode', 'I', '', true, 'errors', 'Input 105'],
                ['fault_sub',  'Fehler-Subcode',   'I', '', true, 'errors', 'Input 107'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'GRW.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'GRW.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'GRW.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'GRW.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['GRW.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        $blk = $mb->readInput(0, 108); // Index 0-107

        $ok = ($blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($blk, 0));
        $hub->SetVarFloat('pv_total', $mb->u32($blk, 1) / 10.0);
        $hub->SetVarFloat('ac_power', $mb->u32($blk, 35) / 10.0);
        $hub->SetVarFloat('grid_freq', $mb->u16($blk, 37) / 100.0);

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt',  $mb->u16($blk, 3) / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($blk, 4) / 10.0);
            $hub->SetVarFloat('pv1_power', $mb->u32($blk, 5) / 10.0);
            $hub->SetVarFloat('pv2_volt',  $mb->u16($blk, 7) / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($blk, 8) / 10.0);
            $hub->SetVarFloat('pv2_power', $mb->u32($blk, 9) / 10.0);
            $hub->SetVarFloat('pv3_volt',  $mb->u16($blk, 11) / 10.0);
            $hub->SetVarFloat('pv3_curr',  $mb->u16($blk, 12) / 10.0);
            $hub->SetVarFloat('pv3_power', $mb->u32($blk, 13) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('vac1', $mb->u16($blk, 38) / 10.0);
            $hub->SetVarFloat('iac1', $mb->u16($blk, 39) / 10.0);
            $hub->SetVarFloat('pac1', $mb->u32($blk, 40) / 10.0);
            $hub->SetVarFloat('vac2', $mb->u16($blk, 42) / 10.0);
            $hub->SetVarFloat('iac2', $mb->u16($blk, 43) / 10.0);
            $hub->SetVarFloat('pac2', $mb->u32($blk, 44) / 10.0);
            $hub->SetVarFloat('vac3', $mb->u16($blk, 46) / 10.0);
            $hub->SetVarFloat('iac3', $mb->u16($blk, 47) / 10.0);
            $hub->SetVarFloat('pac3', $mb->u32($blk, 48) / 10.0);
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            $hub->SetVarFloat('e_day',   $mb->u32($blk, 53) / 10.0);
            $hub->SetVarFloat('e_total', $mb->u32($blk, 55) / 10.0);
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp1', $mb->s16($blk, 93) / 10.0);
        }

        if ($hub->GetPropBool('GroupErrors')) {
            $hub->SetVarInt('fault_main', $mb->u16($blk, 105));
            $hub->SetVarInt('fault_sub',  $mb->u16($blk, 107));
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Alle Werte werden bereits in readFast() in einem Block gelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// SolaxDriver — SolaX Hybrid X1/X3-Serie. WICHTIG: Der Wechselrichter selbst
// spricht nur Modbus RTU — Modbus TCP läuft ausschließlich über ein
// zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als RTU/TCP-Gateway.
// Registeradressen direkt verwendet, Read Input Register (FC 0x04).
// ---------------------------------------------------------------------------

class SolaxDriver implements InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected',   'Verbindung',         'B', '~Alert.Reversed', false, 'errors', ''],
            ['grid_status', 'Netzstatus',         'I', 'SLX.GridStatus',  false, 'grid',   'RO 0x001A'],
            ['pv_total',    'PV Gesamtleistung',  'F', 'SLX.Watt',        true,  'pv',     'RO 0x0032-0x0033'],
            ['ongrid_total','On-Grid Gesamtleistung', 'F', 'SLX.Watt',    true,  'device', 'RO 0x0034-0x0035'],
            ['bat_power',   'Bat. Leistung',      'F', 'SLX.Watt',        true,  'bat',    'RO 0x0038-0x0039'],
            ['bat_soc',     'Bat. SOC',           'I', '~Battery.100',    true,  'bat',    'RO 0x003C'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (bis zu 6 Strings)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0003'],
                ['pv1_curr', 'PV1 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0005'],
                ['pv1_power','PV1 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x000A'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0004'],
                ['pv2_curr', 'PV2 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0006'],
                ['pv2_power','PV2 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x000B'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0122'],
                ['pv3_curr', 'PV3 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0123'],
                ['pv3_power','PV3 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x0124'],
                ['pv4_volt', 'PV4 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0028'],
                ['pv5_volt', 'PV5 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0029'],
                ['pv6_volt', 'PV6 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x002A'],
                ['pv4_curr', 'PV4 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002B'],
                ['pv5_curr', 'PV5 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002C'],
                ['pv6_curr', 'PV6 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002D'],
                ['pv4_power','PV4 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x002E'],
                ['pv5_power','PV5 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x002F'],
                ['pv6_power','PV6 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x0030'],
            ]],
            'GroupGridX1' => ['caption' => 'Netz einphasig (X1-Modelle)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'SLX.Volt',   false, 'grid', 'RO 0x0000'],
                ['grid_curr', 'Netz Strom',     'F', 'SLX.Ampere', false, 'grid', 'RO 0x0001'],
                ['grid_pwr',  'Netz Leistung',  'F', 'SLX.Watt',   true,  'grid', 'RO 0x0002'],
                ['grid_freq', 'Netzfrequenz',   'F', 'SLX.Hertz',  false, 'grid', 'RO 0x0007'],
            ]],
            'GroupGridX3' => ['caption' => 'Netz dreiphasig (X3-Modelle)', 'vars' => [
                ['grid_r_volt', 'Netz R Spannung', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x006A'],
                ['grid_r_curr', 'Netz R Strom',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x006B'],
                ['grid_r_pwr',  'Netz R Leistung', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x006C'],
                ['grid_s_volt', 'Netz S Spannung', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x006E'],
                ['grid_s_curr', 'Netz S Strom',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x006F'],
                ['grid_s_pwr',  'Netz S Leistung', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x0070'],
                ['grid_t_volt', 'Netz T Spannung', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x0072'],
                ['grid_t_curr', 'Netz T Strom',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x0073'],
                ['grid_t_pwr',  'Netz T Leistung', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x0074'],
            ]],
            'GroupBat' => ['caption' => 'Batterie-Systemwerte (Gesamt)', 'vars' => [
                ['bat_capacity', 'Bat. installierte Kapazität', 'F', '~Electricity', false, 'bat', 'RO 0x003A-0x003B'],
                ['bat_soh',      'Bat. SOH', 'I', '~Intensity.100', true, 'bat', 'RO 0x003D'],
            ]],
            'GroupMeter' => ['caption' => 'Meter/CT (Einspeiseleistung)', 'vars' => [
                ['feedin_total', 'Einspeiseleistung Gesamt', 'F', 'SLX.Watt', true, 'meter', 'RO 0x0046-0x0047'],
                ['feedin_r',     'Einspeiseleistung R',      'F', 'SLX.Watt', true, 'meter', 'RO 0x0082-0x0083'],
                ['feedin_s',     'Einspeiseleistung S',      'F', 'SLX.Watt', true, 'meter', 'RO 0x0084-0x0085'],
            ]],
            'GroupOffgrid' => ['caption' => 'Off-Grid / EPS (Notstrom)', 'vars' => [
                ['offgrid_total', 'Off-Grid Gesamtleistung', 'F', 'SLX.Watt', true, 'backup', 'RO 0x0036-0x0037'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_type', 'Wechselrichter-Typ', 'I', '', false, 'device', 'RO 0x0015'],
                ['dev_sn',   'Modul-Seriennummer', 'S', '', false, 'device', 'RO 0x00AA-0x00AE'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLX.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLX.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLX.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'SLX.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [
            'SLX.GridStatus' => [
                0 => ['On-Grid', 0x27D07F],
                1 => ['Off-Grid', 0xE74C3C],
            ],
        ];
    }

    public function readFast($mb, $hub)
    {
        $pv1  = $mb->readInput(0x0000, 12);      // 0x0000-0x000B (Grid X1 + PV1/2 V/I/P)
        $sys  = $mb->readInput(0x001A, 36);       // 0x001A-0x003D (Status, PV total, Bat total)
        $ext3 = $mb->readInput(0x0028, 9);        // 0x0028-0x0030 (PV4-6)
        $pv3  = $mb->readInput(0x0122, 3);        // 0x0122-0x0124 (PV3)

        $ok = ($sys !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('grid_status', $mb->u16($sys, 0));                  // 0x001A
        $hub->SetVarFloat('pv_total',     (float)$mb->u32($sys, 24));       // 0x0032-33
        $hub->SetVarFloat('ongrid_total', (float)$mb->s32($sys, 26));       // 0x0034-35
        $hub->SetVarFloat('bat_power',    (float)$mb->s32($sys, 30));       // 0x0038-39
        $hub->SetVarInt('bat_soc', $mb->u16($sys, 34));                     // 0x003C

        if ($hub->GetPropBool('GroupPV') && $pv1 !== null) {
            $hub->SetVarFloat('pv1_volt',  $mb->u16($pv1, 3)  / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($pv1, 5)  / 10.0);
            $hub->SetVarFloat('pv1_power', (float)$mb->u16($pv1, 10));
            $hub->SetVarFloat('pv2_volt',  $mb->u16($pv1, 4)  / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($pv1, 6)  / 10.0);
            $hub->SetVarFloat('pv2_power', (float)$mb->u16($pv1, 11));
            if ($pv3 !== null) {
                $hub->SetVarFloat('pv3_volt',  $mb->u16($pv3, 0) / 10.0);
                $hub->SetVarFloat('pv3_curr',  $mb->u16($pv3, 1) / 10.0);
                $hub->SetVarFloat('pv3_power', (float)$mb->u16($pv3, 2));
            }
            if ($ext3 !== null) {
                $hub->SetVarFloat('pv4_volt',  $mb->u16($ext3, 0) / 10.0);
                $hub->SetVarFloat('pv5_volt',  $mb->u16($ext3, 1) / 10.0);
                $hub->SetVarFloat('pv6_volt',  $mb->u16($ext3, 2) / 10.0);
                $hub->SetVarFloat('pv4_curr',  $mb->u16($ext3, 3) / 10.0);
                $hub->SetVarFloat('pv5_curr',  $mb->u16($ext3, 4) / 10.0);
                $hub->SetVarFloat('pv6_curr',  $mb->u16($ext3, 5) / 10.0);
                $hub->SetVarFloat('pv4_power', (float)$mb->u16($ext3, 6));
                $hub->SetVarFloat('pv5_power', (float)$mb->u16($ext3, 7));
                $hub->SetVarFloat('pv6_power', (float)$mb->u16($ext3, 8));
            }
        }

        if ($hub->GetPropBool('GroupGridX1') && $pv1 !== null) {
            $hub->SetVarFloat('grid_volt', $mb->u16($pv1, 0) / 10.0);
            $hub->SetVarFloat('grid_curr', $mb->s16($pv1, 1) / 10.0);
            $hub->SetVarFloat('grid_pwr',  (float)$mb->s16($pv1, 2));
            $hub->SetVarFloat('grid_freq', $mb->u16($pv1, 7) / 100.0);
        }

        if ($hub->GetPropBool('GroupGridX3')) {
            $g3 = $mb->readInput(0x006A, 12); // 0x006A-0x0075
            if ($g3 !== null) {
                $hub->SetVarFloat('grid_r_volt', $mb->u16($g3, 0) / 10.0);
                $hub->SetVarFloat('grid_r_curr', $mb->s16($g3, 1) / 10.0);
                $hub->SetVarFloat('grid_r_pwr',  (float)$mb->s16($g3, 2));
                $hub->SetVarFloat('grid_s_volt', $mb->u16($g3, 4) / 10.0);
                $hub->SetVarFloat('grid_s_curr', $mb->s16($g3, 5) / 10.0);
                $hub->SetVarFloat('grid_s_pwr',  (float)$mb->s16($g3, 6));
                $hub->SetVarFloat('grid_t_volt', $mb->u16($g3, 8) / 10.0);
                $hub->SetVarFloat('grid_t_curr', $mb->s16($g3, 9) / 10.0);
                $hub->SetVarFloat('grid_t_pwr',  (float)$mb->s16($g3, 10));
            }
        }

        if ($hub->GetPropBool('GroupBat')) {
            $hub->SetVarFloat('bat_capacity', $mb->u32($sys, 32) / 1000.0); // 0x003A-3B, Wh -> kWh
            $hub->SetVarInt('bat_soh', $mb->u16($sys, 35));                 // 0x003D
        }

        if ($hub->GetPropBool('GroupOffgrid')) {
            $hub->SetVarFloat('offgrid_total', (float)$mb->s32($sys, 28));  // 0x0036-37
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $mb->readInput(0x0046, 64); // 0x0046-0x0085
            if ($meter !== null) {
                $hub->SetVarFloat('feedin_total', (float)$mb->s32($meter, 0));
                $hub->SetVarFloat('feedin_r', (float)$mb->s32($meter, 0x3C)); // 0x0082-83
                $hub->SetVarFloat('feedin_s', (float)$mb->s32($meter, 0x3E)); // 0x0084-85
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Keine zusätzlichen langsamen Werte in der ersten Ausbaustufe.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $type = $mb->readHolding(0x0015, 1);
        if ($type !== null) {
            $hub->SetVarInt('dev_type', $mb->u16($type, 0));
        }
        $sn = $mb->readHolding(0x00AA, 5);
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 5));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// SmaDriver — SMA-Wechselrichter über SunSpec (wie von OpenEMS für SMA
// Sunny Tripower verwendet — SMA-eigene native Register wurden zugunsten
// von SunSpec verworfen, siehe Erkenntnis vom 2026-07-16). Laufzeit-
// Discovery ab Basisregister 40000, keine festen Adressen — analog zu
// FroniusDriver, Feldoffsets gegen OpenEMS-SunSpec-Referenz verifiziert.
// ---------------------------------------------------------------------------

class SmaDriver implements InverterDriverInterface
{
    const STATUS = [
        1 => 'Aus', 2 => 'Auto-Shutdown', 3 => 'Startet', 4 => 'Normal (MPPT)',
        5 => 'Leistungsreduktion', 6 => 'Schaltet ab', 7 => 'Fehler', 8 => 'Standby',
    ];

    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002;
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null;
            }
            if ($modelId === $wantedModelId) {
                return [$addr + 2, $len];
            }
            $addr += 2 + $len;
        }
        return null;
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'SMA.Status',      true,  'device', 'SunSpec St'],
            ['ac_power',  'AC Wirkleistung',   'F', 'SMA.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'SMA.Watt',        true,  'pv',     'SunSpec DCW (Model 101/103)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'SMA.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Netz Strom',     'F', 'SMA.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Netzfrequenz',   'F', 'SMA.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Gesamtertrag)', 'vars' => [
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_cab', 'Gehäusetemperatur', 'F', '~Temperature', false, 'device', 'SunSpec TmpCab (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung)', 'vars' => [
                ['meter_total', 'Netz Leistung (Meter)', 'F', 'SMA.Watt', true, 'meter', 'SunSpec Meter Model 201/203'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'SunSpec Common Block'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SMA.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SMA.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SMA.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'SMA.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['SMA.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        // Float-Inverter-Model bevorzugt (111/112/113), sonst Int+SF (101/102/103)
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }

        $ok = ($inv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        [$base, $len] = $inv;
        $blk = $mb->readHolding($base, min($len, 60));
        if ($blk === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // Offsets siehe FroniusDriver (identische SunSpec-Modelle 101/103/111/113),
        // gegen OpenEMS-SunSpec-Referenz verifiziert. Zusätzlich hier genutzt:
        // DCW (aggregierte DC-Leistung) Float @36, Int+SF @29; TmpCab Float @38, Int+SF @31.
        if ($isFloat) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($blk, 20));
            $hub->SetVarInt('status', $mb->u16($blk, 46));
            $hub->SetVarFloat('pv_total', $mb->readFloat32($blk, 36));
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->readFloat32($blk, 38));
            }
        } else {
            $hub->SetVarFloat('ac_power', (float)$mb->s16($blk, 12));
            $hub->SetVarInt('status', $mb->u16($blk, 36));
            $hub->SetVarFloat('pv_total', (float)$mb->s16($blk, 29));
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', (float)$mb->u16($blk, 0));
                $hub->SetVarFloat('grid_volt', (float)$mb->u16($blk, 8));
                $hub->SetVarFloat('grid_freq', $mb->u16($blk, 14) / 100.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->s16($blk, 31) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $this->findModel($mb, 201) ?: $this->findModel($mb, 203) ?: $this->findModel($mb, 211) ?: $this->findModel($mb, 213);
            if ($meter !== null) {
                [$mtbase, $mtlen] = $meter;
                $mtblk = $mb->readHolding($mtbase, min($mtlen, 20));
                if ($mtblk !== null) {
                    $hub->SetVarFloat('meter_total', (float)$mb->s16($mtblk, 16));
                }
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $common = $this->findModel($mb, 1);
        if ($common === null) {
            return;
        }
        [$base, $len] = $common;
        $blk = $mb->readHolding($base, min($len, 66));
        if ($blk === null) {
            return;
        }
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// FroniusDriver — Fronius SunSpec-Modbus (Datamanager). Reine SunSpec-
// Implementierung mit DYNAMISCHEN Registeradressen: Modelle werden zur
// Laufzeit ab Basisregister 40000 durchlaufen (Model-ID + Länge), keine
// festen Adressen. Adressen werden pro Zyklus neu ermittelt und im Attribut
// gecacht, da sie laut Fronius-Doku über Firmware-Versionen hinweg nicht
// garantiert stabil sind.
// ---------------------------------------------------------------------------

class FroniusDriver implements InverterDriverInterface
{
    const STATUS = [
        1 => 'Aus', 2 => 'Auto-Shutdown', 3 => 'Startet', 4 => 'Normal (MPPT)',
        5 => 'Leistungsreduktion', 6 => 'Schaltet ab', 7 => 'Fehler', 8 => 'Standby',
    ];

    // Sucht ab Basisregister 40000 (SunSpec "SunS"-Marker + Common Block) nach
    // dem gewünschten Model und gibt [Startadresse Nutzdaten, Länge] zurück.
    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002; // hinter dem 2-Register-Common-Marker "SunS"
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null; // End Block erreicht
            }
            if ($modelId === $wantedModelId) {
                return [$addr + 2, $len];
            }
            $addr += 2 + $len;
        }
        return null;
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'FRO.Status',      true,  'device', 'SunSpec St'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'FRO.Watt',        true,  'pv',     'SunSpec DCW (Model 160)'],
            ['ac_power',  'AC Wirkleistung',   'F', 'FRO.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (MPPT laut Multiple MPPT Extension Model 160)', 'vars' => [
                ['mppt1_volt',  'MPPT1 Spannung', 'F', 'FRO.Volt',   false, 'pv', 'SunSpec Model 160'],
                ['mppt1_power','MPPT1 Leistung',  'F', 'FRO.Watt',   true,  'pv', 'SunSpec Model 160'],
                ['mppt2_volt',  'MPPT2 Spannung', 'F', 'FRO.Volt',   false, 'pv', 'SunSpec Model 160'],
                ['mppt2_power','MPPT2 Leistung',  'F', 'FRO.Watt',   true,  'pv', 'SunSpec Model 160'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'FRO.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Netz Strom',     'F', 'FRO.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Netzfrequenz',   'F', 'FRO.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Gesamtertrag)', 'vars' => [
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung)', 'vars' => [
                ['meter_total', 'Netz Leistung (Meter)', 'F', 'FRO.Watt', true, 'meter', 'SunSpec Meter Model 201/203'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'SunSpec Common Block'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'FRO.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'FRO.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'FRO.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'FRO.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['FRO.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        // Float-Inverter-Model bevorzugt (111/112/113), sonst Int+SF (101/102/103)
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }

        $ok = ($inv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        [$base, $len] = $inv;
        $blk = $mb->readHolding($base, min($len, 60));
        if ($blk === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // Offsets ab Modellstart gemäß offizieller SunSpec-Modelldefinition
        // (Model 113/103 "Inverter Three Phase" — Feldreihenfolge, gegen die
        // OpenEMS-SunSpec-Implementierung verifiziert 2026-07-16):
        // Float (Model 113, je Feld 2 Register): 0 A, 14 PhVphA(Netzspannung),
        // 22 Hz(Frequenz), 20 W(Wirkleistung), 30 WH(Gesamtertrag), 46 St(Status)
        // Int+SF (Model 103, je Feld 1 Register + eigenes SF-Register):
        // 0 A, 8 PhVphA, 14 Hz(+SF@15), 12 W(+SF@13), 36 St
        if ($isFloat) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($blk, 20));
            $hub->SetVarInt('status', $mb->u16($blk, 46));
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
        } else {
            // Int+SF-Variante: Werte ganzzahlig mit separatem Skalierungsfaktor-
            // Register (SF), hier vereinfacht ohne SF-Auswertung (Rohwert).
            $hub->SetVarFloat('ac_power', (float)$mb->s16($blk, 12));
            $hub->SetVarInt('status', $mb->u16($blk, 36));
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', (float)$mb->u16($blk, 0));
                $hub->SetVarFloat('grid_volt', (float)$mb->u16($blk, 8));
                $hub->SetVarFloat('grid_freq', $mb->u16($blk, 14) / 100.0);
            }
        }

        $mppt1Power = 0.0;
        $mppt2Power = 0.0;
        if ($hub->GetPropBool('GroupPV')) {
            $mppt = $this->findModel($mb, 160);
            if ($mppt !== null) {
                [$mbase, $mlen] = $mppt;
                $mblk = $mb->readHolding($mbase, min($mlen, 40));
                if ($mblk !== null) {
                    // Multiple MPPT Extension: je Modul ab Offset 8+n*20:
                    // ID(1),IDStr(8, string),DCA(1),DCV(1),DCW(1),...
                    $mppt1Power = (float)$mb->u16($mblk, 12);
                    $mppt2Power = (float)$mb->u16($mblk, 32);
                    $hub->SetVarFloat('mppt1_volt',  $mb->u16($mblk, 10) / 10.0);
                    $hub->SetVarFloat('mppt1_power', $mppt1Power);
                    $hub->SetVarFloat('mppt2_volt',  $mb->u16($mblk, 30) / 10.0);
                    $hub->SetVarFloat('mppt2_power', $mppt2Power);
                    $hub->SetVarFloat('pv_total', $mppt1Power + $mppt2Power);
                }
            }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $this->findModel($mb, 201) ?: $this->findModel($mb, 203) ?: $this->findModel($mb, 211) ?: $this->findModel($mb, 213);
            if ($meter !== null) {
                [$mtbase, $mtlen] = $meter;
                $mtblk = $mb->readHolding($mtbase, min($mtlen, 20));
                if ($mtblk !== null) {
                    // Model 203/213 "Total Real Power" (W) liegt bei Offset 16
                    // (verifiziert gegen OpenEMS-SunSpec-Modelldefinition).
                    $hub->SetVarFloat('meter_total', (float)$mb->s16($mtblk, 16));
                }
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $common = $this->findModel($mb, 1);
        if ($common === null) {
            return;
        }
        [$base, $len] = $common;
        $blk = $mb->readHolding($base, min($len, 66));
        if ($blk === null) {
            return;
        }
        // Common Block: Mn(16 Zeichen ab Offset 0), Md(16 ab Offset 16),
        // Vr(8 ab Offset 32... Herstellerabhängig), SN(16 ab Offset 40)
        // SunSpec Common Block (Model 1): MN(0-15) MD(16-31) OPT(32-39)
        // VR(40-47) SN(48-63) — verifiziert gegen OpenEMS-SunSpec-Definition.
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// SolarEdgeDriver — SolarEdge-Wechselrichter über SunSpec. Registeradressen
// sind bei SolarEdge in der Praxis stabil (Common Block ab 40000), es wird
// trotzdem dieselbe Laufzeit-Discovery wie bei Fronius/SMA verwendet — das
// funktioniert für sowohl feste als auch dynamische Layouts gleichermaßen.
// Registerauswahl/-offsets 2026-07-16 gegen eine community-getestete
// SolarEdge-Modbus-Vorlage aus dem IP-Symcon-Forum verifiziert.
// ---------------------------------------------------------------------------

class SolarEdgeDriver implements InverterDriverInterface
{
    const STATUS = [
        1 => 'Aus', 2 => 'Auto-Shutdown', 3 => 'Startet', 4 => 'Normal (MPPT)',
        5 => 'Leistungsreduktion', 6 => 'Schaltet ab', 7 => 'Fehler', 8 => 'Standby',
    ];

    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002;
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null;
            }
            if ($modelId === $wantedModelId) {
                return [$addr + 2, $len];
            }
            $addr += 2 + $len;
        }
        return null;
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'SLE.Status',      true,  'device', 'SunSpec St'],
            ['ac_power',  'AC Wirkleistung',   'F', 'SLE.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'SLE.Watt',        true,  'pv',     'SunSpec DCW (Model 101/103)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'SLE.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Netz Strom',     'F', 'SLE.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Netzfrequenz',   'F', 'SLE.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Gesamtertrag)', 'vars' => [
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_cab', 'Kühlkörpertemperatur', 'F', '~Temperature', false, 'device', 'SunSpec TmpSnk (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung)', 'vars' => [
                ['meter_total', 'Netz Leistung (Meter)', 'F', 'SLE.Watt', true, 'meter', 'SunSpec Meter Model 201/203'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'SunSpec Common Block'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLE.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLE.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLE.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'SLE.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['SLE.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }

        $ok = ($inv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        [$base, $len] = $inv;
        $blk = $mb->readHolding($base, min($len, 60));
        if ($blk === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // Offsets siehe FroniusDriver/SmaDriver (identische SunSpec-Modelle),
        // zusätzlich gegen eine reale SolarEdge-Registertabelle verifiziert.
        if ($isFloat) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($blk, 20));
            $hub->SetVarInt('status', $mb->u16($blk, 46));
            $hub->SetVarFloat('pv_total', $mb->readFloat32($blk, 36));
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->readFloat32($blk, 40));
            }
        } else {
            $hub->SetVarFloat('ac_power', (float)$mb->s16($blk, 12));
            $hub->SetVarInt('status', $mb->u16($blk, 36));
            $hub->SetVarFloat('pv_total', (float)$mb->s16($blk, 29));
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', (float)$mb->u16($blk, 0));
                $hub->SetVarFloat('grid_volt', (float)$mb->u16($blk, 8));
                $hub->SetVarFloat('grid_freq', $mb->u16($blk, 14) / 100.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->s16($blk, 32) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $this->findModel($mb, 201) ?: $this->findModel($mb, 203) ?: $this->findModel($mb, 211) ?: $this->findModel($mb, 213);
            if ($meter !== null) {
                [$mtbase, $mtlen] = $meter;
                $mtblk = $mb->readHolding($mtbase, min($mtlen, 20));
                if ($mtblk !== null) {
                    $hub->SetVarFloat('meter_total', (float)$mb->s16($mtblk, 16));
                }
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $common = $this->findModel($mb, 1);
        if ($common === null) {
            return;
        }
        [$base, $len] = $common;
        $blk = $mb->readHolding($base, min($len, 66));
        if ($blk === null) {
            return;
        }
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// DeyeDriver — Deye Hybrid-Wechselrichter (SG04LP3-Serie). Direkte
// Registeradressierung, ausschließlich Einzelregister (FC 0x03).
// Register 2026-07-16 gegen eine community-getestete Deye-Modbus-Vorlage
// aus dem IP-Symcon-Forum übernommen (getestet an einem Deye 8K-SG04LP3).
// ---------------------------------------------------------------------------

class DeyeDriver implements InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', '',                true,  'device', 'RO 500'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'DYE.Watt',        true,  'pv',     'Σ RO 672+673'],
            ['bat_power', 'Bat. Leistung',     'F', 'DYE.Watt',        true,  'bat',    'RO 590'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (String 1+2)', 'vars' => [
                ['pv1_power', 'PV1 Leistung', 'F', 'DYE.Watt',   true,  'pv', 'RO 672'],
                ['pv1_volt',  'PV1 Spannung', 'F', 'DYE.Volt',   false, 'pv', 'RO 676'],
                ['pv1_curr',  'PV1 Strom',    'F', 'DYE.Ampere', false, 'pv', 'RO 677'],
                ['pv2_power', 'PV2 Leistung', 'F', 'DYE.Watt',   true,  'pv', 'RO 673'],
                ['pv2_volt',  'PV2 Spannung', 'F', 'DYE.Volt',   false, 'pv', 'RO 678'],
                ['pv2_curr',  'PV2 Strom',    'F', 'DYE.Ampere', false, 'pv', 'RO 679'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom je Phase, Frequenz)', 'vars' => [
                ['grid_l1_volt', 'Netz L1 Spannung', 'F', 'DYE.Volt',   false, 'grid', 'RO 598'],
                ['grid_l2_volt', 'Netz L2 Spannung', 'F', 'DYE.Volt',   false, 'grid', 'RO 599'],
                ['grid_l3_volt', 'Netz L3 Spannung', 'F', 'DYE.Volt',   false, 'grid', 'RO 600'],
                ['grid_l1_curr', 'Netz L1 Strom',    'F', 'DYE.Ampere', false, 'grid', 'RO 616'],
                ['grid_l2_curr', 'Netz L2 Strom',    'F', 'DYE.Ampere', false, 'grid', 'RO 617'],
                ['grid_l3_curr', 'Netz L3 Strom',    'F', 'DYE.Ampere', false, 'grid', 'RO 618'],
                ['grid_total',   'Netzbezug Gesamt',  'F', 'DYE.Watt',   true,  'grid', 'RO 619'],
                ['grid_freq',    'Netzfrequenz',      'F', 'DYE.Hertz',  false, 'grid', 'RO 638'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOC, Temperatur)', 'vars' => [
                ['bat_volt', 'Bat. Spannung',   'F', 'DYE.Volt',     false, 'bat', 'RO 587'],
                ['bat_soc',  'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 588'],
                ['bat_curr', 'Bat. Strom',      'F', 'DYE.Ampere',   false, 'bat', 'RO 591'],
                ['bat_temp', 'Bat. Temperatur', 'F', '~Temperature', true,  'bat', 'RO 586'],
            ]],
            'GroupLoad' => ['caption' => 'Hausverbrauch (Last)', 'vars' => [
                ['load_l1',    'Last L1',      'F', 'DYE.Watt', true, 'device', 'RO 650'],
                ['load_l2',    'Last L2',      'F', 'DYE.Watt', true, 'device', 'RO 651'],
                ['load_l3',    'Last L3',      'F', 'DYE.Watt', true, 'device', 'RO 652'],
                ['load_total', 'Last Gesamt',  'F', 'DYE.Watt', true, 'device', 'RO 653'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Gesamt)', 'vars' => [
                ['e_charge_day',    'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'RO 514'],
                ['e_disch_day',     'Bat. Entladen Heute', 'F', '~Electricity', true, 'energy', 'RO 515'],
                ['e_charge_total',  'Bat. Laden Gesamt',   'F', '~Electricity', true, 'energy', 'RO 516'],
                ['e_disch_total',   'Bat. Entladen Gesamt','F', '~Electricity', true, 'energy', 'RO 518'],
                ['e_buy_day',       'Netzbezug Heute',     'F', '~Electricity', true, 'energy', 'RO 520'],
                ['e_sell_day',      'Einspeisung Heute',   'F', '~Electricity', true, 'energy', 'RO 521'],
                ['e_buy_total',     'Netzbezug Gesamt',    'F', '~Electricity', true, 'energy', 'RO 522'],
                ['e_sell_total',    'Einspeisung Gesamt',  'F', '~Electricity', true, 'energy', 'RO 524'],
                ['e_load_day',      'Hausverbrauch Heute', 'F', '~Electricity', true, 'energy', 'RO 526'],
                ['e_load_total',    'Hausverbrauch Gesamt','F', '~Electricity', true, 'energy', 'RO 527'],
                ['e_pv_day',        'PV Heute',            'F', '~Electricity', true, 'energy', 'RO 529'],
                ['e_pv_total',      'PV Gesamt',           'F', '~Electricity', true, 'energy', 'RO 534'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung', 'vars' => [
                ['ctl_onoff', 'Wechselrichter Ein/Aus', 'B', '~Switch', false, 'control', 'RW 80'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'DYE.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'DYE.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'DYE.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'DYE.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $status = $mb->readHolding(500, 1);
        $pv     = $mb->readHolding(672, 8);   // 672-679: PV1/2 power,volt,curr interleaved
        $bat    = $mb->readHolding(586, 6);   // 586-591: temp,volt,soc,-,power,current
        $grid   = $mb->readHolding(598, 3);   // 598-600: L1-L3 Spannung
        $gcurr  = $mb->readHolding(616, 4);   // 616-619: L1-L3 Strom + Gesamt
        $freq   = $mb->readHolding(638, 1);
        $load   = $mb->readHolding(650, 4);   // 650-653

        $ok = ($status !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($status, 0));

        $pv1w = ($pv !== null) ? $mb->s16($pv, 0) : 0;
        $pv2w = ($pv !== null) ? $mb->s16($pv, 1) : 0;
        $hub->SetVarFloat('pv_total', (float)($pv1w + $pv2w));

        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s16($bat, 4));
        }

        if ($hub->GetPropBool('GroupPV') && $pv !== null) {
            $hub->SetVarFloat('pv1_power', (float)$pv1w);
            $hub->SetVarFloat('pv1_volt',  $mb->u16($pv, 4) / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($pv, 5) / 10.0);
            $hub->SetVarFloat('pv2_power', (float)$pv2w);
            $hub->SetVarFloat('pv2_volt',  $mb->u16($pv, 6) / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($pv, 7) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            if ($grid !== null) {
                $hub->SetVarFloat('grid_l1_volt', $mb->u16($grid, 0) / 10.0);
                $hub->SetVarFloat('grid_l2_volt', $mb->u16($grid, 1) / 10.0);
                $hub->SetVarFloat('grid_l3_volt', $mb->u16($grid, 2) / 10.0);
            }
            if ($gcurr !== null) {
                $hub->SetVarFloat('grid_l1_curr', (float)$mb->s16($gcurr, 0));
                $hub->SetVarFloat('grid_l2_curr', (float)$mb->s16($gcurr, 1));
                $hub->SetVarFloat('grid_l3_curr', (float)$mb->s16($gcurr, 2));
                $hub->SetVarFloat('grid_total',   (float)$mb->s16($gcurr, 3));
            }
            if ($freq !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freq, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_temp', $mb->s16($bat, 0) / 10.0);
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 1) / 100.0);
            $hub->SetVarInt('bat_soc',    $mb->u16($bat, 2));
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 5) / 100.0);
        }

        if ($hub->GetPropBool('GroupLoad') && $load !== null) {
            $hub->SetVarFloat('load_l1',    (float)$mb->s16($load, 0));
            $hub->SetVarFloat('load_l2',    (float)$mb->s16($load, 1));
            $hub->SetVarFloat('load_l3',    (float)$mb->s16($load, 2));
            $hub->SetVarFloat('load_total', (float)$mb->s16($load, 3));
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e1 = $mb->readHolding(514, 11); // 514-524 (mit Lücken)
        if ($e1 !== null) {
            $hub->SetVarFloat('e_charge_day',   $mb->u16($e1, 0) / 10.0);
            $hub->SetVarFloat('e_disch_day',    $mb->u16($e1, 1) / 10.0);
            $hub->SetVarFloat('e_charge_total', $mb->u16($e1, 2) / 10.0);
            $hub->SetVarFloat('e_disch_total',  $mb->u16($e1, 4) / 10.0);
            $hub->SetVarFloat('e_buy_day',      $mb->u16($e1, 6) / 10.0);
            $hub->SetVarFloat('e_sell_day',     $mb->u16($e1, 7) / 10.0);
            $hub->SetVarFloat('e_buy_total',    $mb->u16($e1, 8) / 10.0);
            $hub->SetVarFloat('e_sell_total',   $mb->u16($e1, 10) / 10.0);
        }
        $e2 = $mb->readHolding(526, 9); // 526-534 (mit Lücken)
        if ($e2 !== null) {
            $hub->SetVarFloat('e_load_day',   $mb->u16($e2, 0) / 10.0);
            $hub->SetVarFloat('e_load_total', $mb->u16($e2, 1) / 10.0);
            $hub->SetVarFloat('e_pv_day',     $mb->u16($e2, 3) / 10.0);
            $hub->SetVarFloat('e_pv_total',   $mb->u16($e2, 8) / 10.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        if ($ident === 'ctl_onoff') {
            $val = (bool)$value ? 1 : 0;
            if ($mb->writeSingle(80, $val)) {
                $hub->SetVarBool('ctl_onoff', (bool)$value);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// SolplanetDriver — Solplanet/AISWEI ASW-Gen-Serie. Direkte Adressierung,
// Read Input Register (FC 0x04). Register 2026-07-16 gegen eine community-
// getestete Solplanet-Modbus-Vorlage aus dem IP-Symcon-Forum übernommen.
// ---------------------------------------------------------------------------

class SolplanetDriver implements InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV Gesamtleistung', 'F', 'SPL.Watt',        true,  'pv',     'RO 1600'],
            ['ac_power',  'AC Wirkleistung',   'F', 'SPL.Watt',        true,  'device', 'RO 1370'],
            ['bat_power', 'Bat. Leistung',     'F', 'SPL.Watt',        true,  'bat',    'RO 1618'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (String 1-3)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'SPL.Volt',   false, 'pv', 'RO 1318'],
                ['pv1_curr', 'PV1 Strom',    'F', 'SPL.Ampere', false, 'pv', 'RO 1319'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'SPL.Volt',   false, 'pv', 'RO 1320'],
                ['pv2_curr', 'PV2 Strom',    'F', 'SPL.Ampere', false, 'pv', 'RO 1321'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'SPL.Volt',   false, 'pv', 'RO 1322'],
                ['pv3_curr', 'PV3 Strom',    'F', 'SPL.Ampere', false, 'pv', 'RO 1323'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOC, SOH, Temperatur)', 'vars' => [
                ['bat_volt', 'Bat. Spannung',   'F', 'SPL.Volt',       false, 'bat', 'RO 1616'],
                ['bat_curr', 'Bat. Strom',      'F', 'SPL.Ampere',     false, 'bat', 'RO 1617'],
                ['bat_soc',  'Bat. SOC',        'I', '~Battery.100',   true,  'bat', 'RO 1621'],
                ['bat_soh',  'Bat. SOH',        'I', '~Intensity.100', true,  'bat', 'RO 1622'],
                ['bat_temp', 'Bat. Temperatur', 'F', '~Temperature',   true,  'bat', 'RO 1620'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_internal', 'Innentemperatur', 'F', '~Temperature', false, 'device', 'RO 1310'],
                ['temp_env',      'Umgebungstemperatur', 'F', '~Temperature', false, 'device', 'RO 1680'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Gesamt)', 'vars' => [
                ['e_pv_day',      'PV Heute',            'F', '~Electricity', true, 'energy', 'RO 1602'],
                ['e_pv_total',    'PV Gesamt',           'F', '~Electricity', true, 'energy', 'RO 1604'],
                ['e_inv_day',     'Wechselrichter Heute','F', '~Electricity', true, 'energy', 'RO 1302'],
                ['e_inv_total',   'Wechselrichter Gesamt','F', '~Electricity', true, 'energy', 'RO 1304'],
                ['e_charge_day',  'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'RO 1625'],
                ['e_disch_day',   'Bat. Entladen Heute', 'F', '~Electricity', true, 'energy', 'RO 1627'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SPL.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0, 0],
            'SPL.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1, 1],
            'SPL.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1, 1],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $sys = $mb->readInput(1368, 4);   // 1368-1371: Apparent(2)+Active(2)
        $pv  = $mb->readInput(1600, 2);   // 1600-1601: PV total power (U32)
        $bat = $mb->readInput(1616, 7);   // 1616-1622

        $ok = ($sys !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('ac_power', (float)$mb->s32($sys, 2));
        if ($pv !== null) {
            $hub->SetVarFloat('pv_total', (float)$mb->u32($pv, 0));
        }
        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s32($bat, 2));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $pvd = $mb->readInput(1318, 6);
            if ($pvd !== null) {
                $hub->SetVarFloat('pv1_volt', $mb->u16($pvd, 0) / 10.0);
                $hub->SetVarFloat('pv1_curr', $mb->u16($pvd, 1) / 100.0);
                $hub->SetVarFloat('pv2_volt', $mb->u16($pvd, 2) / 10.0);
                $hub->SetVarFloat('pv2_curr', $mb->u16($pvd, 3) / 100.0);
                $hub->SetVarFloat('pv3_volt', $mb->u16($pvd, 4) / 10.0);
                $hub->SetVarFloat('pv3_curr', $mb->u16($pvd, 5) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 0) / 100.0);
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 1) / 10.0);
            $hub->SetVarInt('bat_soc',    $mb->u16($bat, 5));
            $hub->SetVarInt('bat_soh',    $mb->u16($bat, 6));
        }
        if ($hub->GetPropBool('GroupBat')) {
            $batTemp = $mb->readInput(1620, 1);
            if ($batTemp !== null) {
                $hub->SetVarFloat('bat_temp', $mb->s16($batTemp, 0) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $t1 = $mb->readInput(1310, 1);
            if ($t1 !== null) {
                $hub->SetVarFloat('temp_internal', $mb->s16($t1, 0) / 10.0);
            }
            $t2 = $mb->readInput(1680, 1);
            if ($t2 !== null) {
                $hub->SetVarFloat('temp_env', $mb->u16($t2, 0) / 10.0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e1 = $mb->readInput(1302, 4); // 1302-1305
        if ($e1 !== null) {
            $hub->SetVarFloat('e_inv_day',   $mb->u32($e1, 0) / 10.0);
            $hub->SetVarFloat('e_inv_total', $mb->u32($e1, 2) / 10.0);
        }
        $e2 = $mb->readInput(1602, 4); // 1602-1605
        if ($e2 !== null) {
            $hub->SetVarFloat('e_pv_day',   $mb->u32($e2, 0) / 10.0);
            $hub->SetVarFloat('e_pv_total', $mb->u32($e2, 2) / 10.0);
        }
        $e3 = $mb->readInput(1625, 4); // 1625-1628
        if ($e3 !== null) {
            $hub->SetVarFloat('e_charge_day', $mb->u32($e3, 0) / 10.0);
            $hub->SetVarFloat('e_disch_day',  $mb->u32($e3, 2) / 10.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// KostalDriver — Kostal PLENTICORE plus (Generation 1). Direkte Adressierung,
// Float32-Register (FC 0x03), Wert bereits in physikalischer SI-Einheit
// (kein separates Skalierungsfaktor-Register wie bei SunSpec Int+SF).
// Register 2026-07-16 gegen eine community-getestete Kostal-Modbus-Vorlage
// aus dem IP-Symcon-Forum übernommen.
// ---------------------------------------------------------------------------

class KostalDriver implements InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV Gesamtleistung (DC)', 'F', 'KST.Watt',   true,  'pv',     'RO 100 (Float32)'],
            ['ac_power',  'AC Wirkleistung Gesamt',  'F', 'KST.Watt',  true,  'device', 'RO 172 (Float32)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (DC-Eingang 1-3)', 'vars' => [
                ['pv1_curr',  'PV1 Strom',    'F', 'KST.Ampere', false, 'pv', 'RO 258 (Float32)'],
                ['pv1_power', 'PV1 Leistung', 'F', 'KST.Watt',   true,  'pv', 'RO 260 (Float32)'],
                ['pv1_volt',  'PV1 Spannung', 'F', 'KST.Volt',   false, 'pv', 'RO 266 (Float32)'],
                ['pv2_curr',  'PV2 Strom',    'F', 'KST.Ampere', false, 'pv', 'RO 268 (Float32)'],
                ['pv2_power', 'PV2 Leistung', 'F', 'KST.Watt',   true,  'pv', 'RO 270 (Float32)'],
                ['pv2_volt',  'PV2 Spannung', 'F', 'KST.Volt',   false, 'pv', 'RO 276 (Float32)'],
                ['pv3_curr',  'PV3 Strom',    'F', 'KST.Ampere', false, 'pv', 'RO 278 (Float32)'],
                ['pv3_power', 'PV3 Leistung', 'F', 'KST.Watt',   true,  'pv', 'RO 280 (Float32)'],
                ['pv3_volt',  'PV3 Spannung', 'F', 'KST.Volt',   false, 'pv', 'RO 286 (Float32)'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Leistung je Phase, Frequenz)', 'vars' => [
                ['grid_freq',    'Netzfrequenz',      'F', 'KST.Hertz',  false, 'grid', 'RO 152 (Float32)'],
                ['grid_l1_curr', 'Netz L1 Strom',     'F', 'KST.Ampere', false, 'grid', 'RO 154 (Float32)'],
                ['grid_l1_pwr',  'Netz L1 Leistung',  'F', 'KST.Watt',   true,  'grid', 'RO 156 (Float32)'],
                ['grid_l1_volt', 'Netz L1 Spannung',  'F', 'KST.Volt',   false, 'grid', 'RO 158 (Float32)'],
                ['grid_l2_curr', 'Netz L2 Strom',     'F', 'KST.Ampere', false, 'grid', 'RO 160 (Float32)'],
                ['grid_l2_pwr',  'Netz L2 Leistung',  'F', 'KST.Watt',   true,  'grid', 'RO 162 (Float32)'],
                ['grid_l2_volt', 'Netz L2 Spannung',  'F', 'KST.Volt',   false, 'grid', 'RO 164 (Float32)'],
                ['grid_l3_curr', 'Netz L3 Strom',     'F', 'KST.Ampere', false, 'grid', 'RO 166 (Float32)'],
                ['grid_l3_pwr',  'Netz L3 Leistung',  'F', 'KST.Watt',   true,  'grid', 'RO 168 (Float32)'],
                ['grid_l3_volt', 'Netz L3 Spannung',  'F', 'KST.Volt',   false, 'grid', 'RO 170 (Float32)'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOC, Temperatur)', 'vars' => [
                ['bat_soc',  'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 210 (Float32)'],
                ['bat_temp', 'Bat. Temperatur', 'F', '~Temperature', true,  'bat', 'RO 214 (Float32)'],
                ['bat_volt', 'Bat. Spannung',   'F', 'KST.Volt',     false, 'bat', 'RO 216 (Float32)'],
                ['bat_curr', 'Bat. Strom (Laden -/Entladen +)', 'F', 'KST.Ampere', false, 'bat', 'RO 200 (Float32)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung je Phase)', 'vars' => [
                ['meter_l1_pwr',  'Meter L1 Leistung', 'F', 'KST.Watt', true, 'meter', 'RO 224 (Float32)'],
                ['meter_l2_pwr',  'Meter L2 Leistung', 'F', 'KST.Watt', true, 'meter', 'RO 234 (Float32)'],
                ['meter_l3_pwr',  'Meter L3 Leistung', 'F', 'KST.Watt', true, 'meter', 'RO 244 (Float32)'],
                ['meter_total',   'Meter Gesamtleistung', 'F', 'KST.Watt', true, 'meter', 'RO 252 (Float32)'],
            ]],
            'GroupHome' => ['caption' => 'Hausverbrauch (aufgeteilt nach Quelle)', 'vars' => [
                ['home_total',    'Hausverbrauch Gesamt',       'F', '~Electricity', true, 'device', 'RO 118 (Float32, Wh)'],
                ['home_from_pv',  'Hausverbrauch aus PV',       'F', 'KST.Watt', true, 'device', 'RO 116 (Float32)'],
                ['home_from_bat', 'Hausverbrauch aus Batterie', 'F', 'KST.Watt', true, 'device', 'RO 106 (Float32)'],
                ['home_from_grid','Hausverbrauch aus Netz',     'F', 'KST.Watt', true, 'device', 'RO 108 (Float32)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Monat/Jahr/Gesamt)', 'vars' => [
                ['e_total',   'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'RO 320 (Float32, Wh)'],
                ['e_day',     'Ertrag Heute',  'F', '~Electricity', true, 'energy', 'RO 322 (Float32, Wh)'],
                ['e_year',    'Ertrag Jahr',   'F', '~Electricity', true, 'energy', 'RO 324 (Float32, Wh)'],
                ['e_month',   'Ertrag Monat',  'F', '~Electricity', true, 'energy', 'RO 326 (Float32, Wh)'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_name', 'Produktname', 'S', '', false, 'device', 'RO 768 (String)'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'KST.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'KST.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'KST.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'KST.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $pv  = $mb->readHolding(100, 2);
        $ac  = $mb->readHolding(172, 2);

        $ok = ($pv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', $mb->readFloat32($pv, 0));
        if ($ac !== null) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($ac, 0));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $dc1 = $mb->readHolding(258, 2);
            $dc1p = $mb->readHolding(260, 2);
            $dc1v = $mb->readHolding(266, 2);
            $dc2 = $mb->readHolding(268, 2);
            $dc2p = $mb->readHolding(270, 2);
            $dc2v = $mb->readHolding(276, 2);
            $dc3 = $mb->readHolding(278, 2);
            $dc3p = $mb->readHolding(280, 2);
            $dc3v = $mb->readHolding(286, 2);
            if ($dc1 !== null)  { $hub->SetVarFloat('pv1_curr',  $mb->readFloat32($dc1, 0)); }
            if ($dc1p !== null) { $hub->SetVarFloat('pv1_power', $mb->readFloat32($dc1p, 0)); }
            if ($dc1v !== null) { $hub->SetVarFloat('pv1_volt',  $mb->readFloat32($dc1v, 0)); }
            if ($dc2 !== null)  { $hub->SetVarFloat('pv2_curr',  $mb->readFloat32($dc2, 0)); }
            if ($dc2p !== null) { $hub->SetVarFloat('pv2_power', $mb->readFloat32($dc2p, 0)); }
            if ($dc2v !== null) { $hub->SetVarFloat('pv2_volt',  $mb->readFloat32($dc2v, 0)); }
            if ($dc3 !== null)  { $hub->SetVarFloat('pv3_curr',  $mb->readFloat32($dc3, 0)); }
            if ($dc3p !== null) { $hub->SetVarFloat('pv3_power', $mb->readFloat32($dc3p, 0)); }
            if ($dc3v !== null) { $hub->SetVarFloat('pv3_volt',  $mb->readFloat32($dc3v, 0)); }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $grid = $mb->readHolding(152, 20); // 152-171
            if ($grid !== null) {
                $hub->SetVarFloat('grid_freq',    $mb->readFloat32($grid, 0));
                $hub->SetVarFloat('grid_l1_curr', $mb->readFloat32($grid, 2));
                $hub->SetVarFloat('grid_l1_pwr',  $mb->readFloat32($grid, 4));
                $hub->SetVarFloat('grid_l1_volt', $mb->readFloat32($grid, 6));
                $hub->SetVarFloat('grid_l2_curr', $mb->readFloat32($grid, 8));
                $hub->SetVarFloat('grid_l2_pwr',  $mb->readFloat32($grid, 10));
                $hub->SetVarFloat('grid_l2_volt', $mb->readFloat32($grid, 12));
                $hub->SetVarFloat('grid_l3_curr', $mb->readFloat32($grid, 14));
                $hub->SetVarFloat('grid_l3_pwr',  $mb->readFloat32($grid, 16));
                $hub->SetVarFloat('grid_l3_volt', $mb->readFloat32($grid, 18));
            }
        }

        if ($hub->GetPropBool('GroupBat')) {
            $soc  = $mb->readHolding(210, 2);
            $temp = $mb->readHolding(214, 2);
            $volt = $mb->readHolding(216, 2);
            $curr = $mb->readHolding(200, 2);
            if ($soc !== null)  { $hub->SetVarInt('bat_soc', (int)round($mb->readFloat32($soc, 0))); }
            if ($temp !== null) { $hub->SetVarFloat('bat_temp', $mb->readFloat32($temp, 0)); }
            if ($volt !== null) { $hub->SetVarFloat('bat_volt', $mb->readFloat32($volt, 0)); }
            if ($curr !== null) { $hub->SetVarFloat('bat_curr', $mb->readFloat32($curr, 0)); }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $m1 = $mb->readHolding(224, 2);
            $m2 = $mb->readHolding(234, 2);
            $m3 = $mb->readHolding(244, 2);
            $mt = $mb->readHolding(252, 2);
            if ($m1 !== null) { $hub->SetVarFloat('meter_l1_pwr', $mb->readFloat32($m1, 0)); }
            if ($m2 !== null) { $hub->SetVarFloat('meter_l2_pwr', $mb->readFloat32($m2, 0)); }
            if ($m3 !== null) { $hub->SetVarFloat('meter_l3_pwr', $mb->readFloat32($m3, 0)); }
            if ($mt !== null) { $hub->SetVarFloat('meter_total',  $mb->readFloat32($mt, 0)); }
        }

        if ($hub->GetPropBool('GroupHome')) {
            $home = $mb->readHolding(106, 14); // 106-118 (mit Lücke bei 120)
            if ($home !== null) {
                $hub->SetVarFloat('home_from_bat',  $mb->readFloat32($home, 0));
                $hub->SetVarFloat('home_from_grid', $mb->readFloat32($home, 2));
                $hub->SetVarFloat('home_from_pv',   $mb->readFloat32($home, 10));
                // Register 118 ist It. offizieller KOSTAL-Doku eine kumulierte
                // Energie in Wh (nicht W wie ursprünglich fälschlich
                // angenommen) - daher Umrechnung auf kWh wie bei allen
                // anderen ~Electricity-Datenpunkten im Modul.
                $hub->SetVarFloat('home_total',     $mb->readFloat32($home, 12) / 1000.0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        // Register 320-327 sind It. offizieller KOSTAL-Doku Wh, nicht kWh -
        // Umrechnung fehlte bisher komplett (Bugfix: Werte erschienen um
        // Faktor 1000 zu groß, z.B. "Milliarden Watt" bei älteren Anlagen
        // mit hohem Gesamtertrag).
        $e = $mb->readHolding(320, 8); // 320-327
        if ($e !== null) {
            $hub->SetVarFloat('e_total', $mb->readFloat32($e, 0) / 1000.0);
            $hub->SetVarFloat('e_day',   $mb->readFloat32($e, 2) / 1000.0);
            $hub->SetVarFloat('e_year',  $mb->readFloat32($e, 4) / 1000.0);
            $hub->SetVarFloat('e_month', $mb->readFloat32($e, 6) / 1000.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $name = $mb->readHolding(768, 16);
        if ($name !== null) {
            $hub->SetVarStr('dev_name', $mb->readStr($name, 0, 16));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// InverterHub — Hauptmodul, lädt den Treiber laut Manufacturer-Property
// ---------------------------------------------------------------------------

class InverterHub extends IPSModule
{
    private const DRIVERS = [
        'goodwe'    => 'GoodweDriver',
        'sungrow'   => 'SungrowDriver',
        'solis'     => 'SolisDriver',
        'growatt'   => 'GrowattDriver',
        'solax'     => 'SolaxDriver',
        'sma'       => 'SmaDriver',
        'fronius'   => 'FroniusDriver',
        'solaredge' => 'SolarEdgeDriver',
        'deye'      => 'DeyeDriver',
        'solplanet' => 'SolplanetDriver',
        'kostal'    => 'KostalDriver',
    ];

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-inverterhub-multi-wechselrichter-ein-modbus-tcp-modul-fuer-goodwe-sma-fronius-sungrow-solis-growatt-solax/144121';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    private $driver = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Manufacturer', 'goodwe');
        $this->RegisterPropertyInteger('HouseLoadMeterID', 0);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 247);
        $this->RegisterPropertyInteger('IntervalFast', 5);
        $this->RegisterPropertyInteger('IntervalSlow', 300);
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);

        // Treiber-spezifische Properties werden hier für ALLE Treiber (nicht nur
        // den aktuell gewählten) registriert. Grund: Create() legt die Properties
        // einmalig zum Erstellungszeitpunkt an, aber der tatsächlich gewünschte
        // Manufacturer-Wert (z.B. vom Discovery-Modul mitgegeben) wird oft erst
        // NACH Create() über die Konfiguration gesetzt. Würden nur die Properties
        // des zu diesem Zeitpunkt aktiven (Default-)Treibers registriert, fehlten
        // bei jedem anderen Hersteller sämtliche Datenpunkt-Gruppen-Checkboxen
        // ("Eigenschaft GroupPV nicht gefunden" etc.) — ungenutzte Properties
        // anderer Treiber bleiben einfach unbenutzt, das ist unschädlich.
        $allProps = [];
        foreach (self::DRIVERS as $driverClass) {
            $drv = new $driverClass();
            foreach ($drv->getExtraBooleanProperties() as $name => $default) {
                if (!array_key_exists($name, $allProps)) {
                    $allProps[$name] = $default;
                }
            }
            foreach ($drv->getOptionalGroups() as $propName => $group) {
                if (!array_key_exists($propName, $allProps)) {
                    $allProps[$propName] = true;
                }
            }
        }
        foreach ($allProps as $name => $default) {
            $this->RegisterPropertyBoolean($name, $default);
        }

        $this->RegisterTimer('FastTimer', 0, 'IHUB_ReadFast($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlowTimer', 0, 'IHUB_ReadSlow($_IPS[\'TARGET\']);');
        // IPS_SetVariableCustomAction() schlägt fehl, wenn die Instanz noch
        // innerhalb derselben Erstellungs-/Konfigurations-Transaktion nicht
        // als gültiges Ziel sichtbar ist (z.B. wenn ein Configurator-Modul
        // Instanz+Konfiguration in einem einzigen synchronen Ablauf anlegt —
        // da hilft auch "erst beim zweiten ApplyChanges" nichts, weil beide
        // Aufrufe noch zur selben Transaktion gehören). Custom Actions
        // werden deshalb einmalig per Kurz-Timer NACH der Transaktion gesetzt.
        $this->RegisterTimer('EnableActionsTimer', 0, 'IHUB_EnableActions($_IPS[\'TARGET\']);');

        $this->RegisterAttributeBoolean('DeviceInfoRead', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->RegisterVariables();

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }

        $host = $this->ReadPropertyString('Host');
        if ($host === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }

        $this->SetTimerInterval('FastTimer', $this->ReadPropertyInteger('IntervalFast') * 1000);
        $this->SetTimerInterval('SlowTimer', $this->ReadPropertyInteger('IntervalSlow') * 1000);
        $this->SetTimerInterval('EnableActionsTimer', 200);
        $this->WriteAttributeBoolean('DeviceInfoRead', false);
        $this->SetStatus(102);
    }

    // Wird kurz nach ApplyChanges einmalig vom EnableActionsTimer aufgerufen,
    // sobald die Instanz die Erstellungstransaktion sicher verlassen hat.
    public function EnableActions()
    {
        $this->SetTimerInterval('EnableActionsTimer', 0);

        $driver = $this->GetDriver();
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                if ($v[5] === 'control') {
                    $vid = $this->FindVarByIdent($v[0]);
                    if ($vid) {
                        IPS_SetVariableCustomAction($vid, $this->InstanceID);
                    }
                }
            }
        }
    }

    public function ReadFast()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $driver = $this->GetDriver();
        if (!$this->ReadAttributeBoolean('DeviceInfoRead')) {
            $driver->readDeviceInfo($this->GetModbusClient(), $this);
            $this->WriteAttributeBoolean('DeviceInfoRead', true);
        }
        $driver->readFast($this->GetModbusClient(), $this);
    }

    public function ReadSlow()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $this->GetDriver()->readSlow($this->GetModbusClient(), $this);
    }

    public function RequestAction($Ident, $Value)
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $this->GetDriver()->writeControl($this->GetModbusClient(), $this, $Ident, $Value);
    }

    public function GetConfigurationForm()
    {
        $driver = $this->GetDriver();

        $groupItems = [];
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            $groupItems[] = [
                'type'    => 'CheckBox',
                'name'    => $propName,
                'caption' => $group['caption'],
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'InverterHub liest Wechselrichter verschiedener Hersteller direkt per Modbus TCP aus. Hersteller wählen, IP-Adresse eintragen, Datenpunkt-Gruppen je nach Anlage aktivieren.'],
                        ['type' => 'Label', 'caption' => 'Unterstützt: GoodWe (GW-ET/EH/BT/BH), Sungrow (SH-Hybrid), Solis (Hybrid-Serie, 33000er-Register), Growatt (TL-X/TL3-X/MOD/MIX/SPH/WIT), SMA (STP/STPS/SI, PV/Energie/Temperatur – Netzmessung folgt), Fronius (SunSpec über Datamanager, dynamische Registeradressen).'],
                        ['type' => 'Label', 'caption' => 'SolaX: Der Wechselrichter selbst spricht nur Modbus RTU. Modbus TCP läuft nur über ein zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als Gateway – dessen IP-Adresse hier eintragen, nicht die des Wechselrichters direkt.'],
                        ['type' => 'Label', 'caption' => 'Registeradressen stehen im Beschreibungsfeld jeder Variable (Objekt-Manager, Spalte „Beschreibung").'],
                    ],
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Kommunikation aktiv',
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Manufacturer',
                    'caption' => 'Hersteller',
                    'options' => [
                        ['label' => 'GoodWe',  'value' => 'goodwe'],
                        ['label' => 'Sungrow', 'value' => 'sungrow'],
                        ['label' => 'Solis',   'value' => 'solis'],
                        ['label' => 'Growatt', 'value' => 'growatt'],
                        ['label' => 'SolaX (nur über SolaX-Monitoring-Dongle)', 'value' => 'solax'],
                        ['label' => 'SMA',     'value' => 'sma'],
                        ['label' => 'Fronius', 'value' => 'fronius'],
                        ['label' => 'SolarEdge', 'value' => 'solaredge'],
                        ['label' => 'Deye', 'value' => 'deye'],
                        ['label' => 'Solplanet / AISWEI', 'value' => 'solplanet'],
                        ['label' => 'Kostal (PLENTICORE plus Gen. 1)', 'value' => 'kostal'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔌  Verbindung',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🏠  Hauslastzähler (optional)',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Optional: eine bereits vorhandene Variable mit real gemessener Hauslast (z. B. ein separater Energiezähler/Shelly am Hausanschluss) auswählen. Ist ein Zähler gewählt, zeigt die InverterHubTile-Kachel damit eine genauere Last sowie die Differenz zur PV/Netz/Batterie-Bilanz als „Wandlungsverluste" (Wechselrichter-Eigenverbrauch, Leitungsverluste). Ohne Auswahl bleibt es bei der reinen Bilanzschätzung.'],
                        ['type' => 'SelectVariable', 'name' => 'HouseLoadMeterID', 'caption' => 'Hauslastzähler-Variable'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⏱️  Polling',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Schnell-Intervall (Sekunden)', 'minimum' => 5, 'maximum' => 60, 'suffix' => 's'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalSlow', 'caption' => 'Langsam-Intervall (Sekunden)', 'minimum' => 60, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📊  Datenpunkte',
                    'expanded' => true,
                    'items' => $groupItems,
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Verbindung testen / Daten sofort lesen', 'onClick' => 'IHUB_ReadFast($id);'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte IP-Adresse eintragen.'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Verbindung aktiv.'],
                ['code' => 201, 'icon' => 'error',     'caption' => 'Verbindungsfehler – Wechselrichter nicht erreichbar.'],
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
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'IHUB_DismissReviewHint($id);'],
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
    // Treiber-Auswahl
    // -----------------------------------------------------------------------

    private function GetDriver(){
        if ($this->driver !== null) {
            return $this->driver;
        }
        $key   = $this->ReadPropertyString('Manufacturer');
        $class = self::DRIVERS[$key] ?? self::DRIVERS['goodwe'];
        $this->driver = new $class();
        return $this->driver;
    }

    private function GetModbusClient(): ModbusTcpClient
    {
        return new ModbusTcpClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('UnitId')
        );
    }

    // -----------------------------------------------------------------------
    // Variablen-Registrierung (generisch, treiberunabhängig)
    // -----------------------------------------------------------------------

    private function RegisterVariables()
    {
        $driver = $this->GetDriver();
        $pos = 0;

        foreach ($driver->getBaseVars() as $v) {
            $this->RegisterVar($v, $pos++);
        }

        foreach ($driver->getOptionalGroups() as $propName => $group) {
            $enabled = $this->ReadPropertyBoolean($propName);
            foreach ($group['vars'] as $v) {
                if ($enabled) {
                    $this->RegisterVar($v, $pos++);
                } else {
                    $this->UnregVarIfExists($v[0]);
                }
            }
        }
    }

    // Custom Actions werden hier bewusst NICHT gesetzt — das übernimmt
    // EnableActions() per Kurz-Timer nach Abschluss der Transaktion
    // (siehe Kommentar in Create()).
    private function RegisterVar(array $def, int $pos)
    {
        [$ident, $caption, $type, $profile, $archive, $group] = $def;
        $reg = isset($def[6]) ? $def[6] : '';

        $vid = $this->FindVarByIdent($ident);
        if (!$vid) {
            $vtype = [
                'F' => VARIABLETYPE_FLOAT,
                'I' => VARIABLETYPE_INTEGER,
                'B' => VARIABLETYPE_BOOLEAN,
                'S' => VARIABLETYPE_STRING,
            ][$type];
            $vid = IPS_CreateVariable($vtype);
            IPS_SetIdent($vid, $ident);
        }

        $catID = $this->EnsureCategory($group);
        IPS_SetParent($vid, $catID);
        IPS_SetPosition($vid, $pos);
        IPS_SetName($vid, $caption);
        if ($profile !== '') {
            IPS_SetVariableCustomProfile($vid, $profile);
        }
        if ($reg !== '') {
            IPS_SetInfo($vid, $reg);
        }
        if ($archive) {
            $this->SetArchive($vid);
        }
    }

    private function UnregVarIfExists($ident)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            IPS_DeleteVariable($vid);
        }
    }

    private const CATEGORY_LABELS = [
        'pv'        => 'PV / MPPT',
        'bat1'      => 'Batterie 1',
        'bat2'      => 'Batterie 2',
        'batcommon' => 'Batterie (gemeinsam)',
        'grid'      => 'Netz',
        'meter'     => 'Smart Meter',
        'backup'    => 'Backup / Insel',
        'energy'    => 'Energiezähler',
        'device'    => 'Wechselrichter / Gerät',
        'control'   => 'EMS-Steuerung',
        'errors'    => 'Fehler / Verbindung',
    ];

    private function EnsureCategory($key){
        $catIdent = 'cat_' . $key;
        $catID = $this->FindIdentRecursive($this->InstanceID, $catIdent);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, $catIdent);
            $pos = array_search($key, array_keys(self::CATEGORY_LABELS));
            IPS_SetPosition($catID, $pos !== false ? $pos : 99);
        }
        IPS_SetName($catID, self::CATEGORY_LABELS[$key] ?? $key);
        return $catID;
    }

    private function FindVarByIdent($ident){
        return $this->FindIdentRecursive($this->InstanceID, $ident);
    }

    private function FindIdentRecursive(int $parentID, $ident){
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

    private function SetArchive($vid)
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            AC_SetLoggingStatus($archiveIDs[0], $vid, true);
            AC_SetAggregationType($archiveIDs[0], $vid, 0);
        }
    }

    // -----------------------------------------------------------------------
    // Variable setzen (public, damit Treiber sie via $hub->... aufrufen können)
    // -----------------------------------------------------------------------

    public function SetVarFloat(string $ident, float $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueFloat($vid, $value);
        }
    }

    public function SetVarInt(string $ident, int $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueInteger($vid, $value);
        }
    }

    public function SetVarBool(string $ident, bool $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueBoolean($vid, $value);
        }
    }

    public function SetVarStr(string $ident, string $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueString($vid, $value);
        }
    }

    // Öffentlicher Wrapper: ReadPropertyBoolean() ist bei IPSModule protected
    // und daher von externen Treiber-Klassen (GoodweDriver etc.) nicht direkt
    // aufrufbar.
    public function GetPropBool(string $name)
    {
        return $this->ReadPropertyBoolean($name);
    }

    // -----------------------------------------------------------------------
    // Profile (treiberdefiniert)
    // -----------------------------------------------------------------------

    private function CreateProfiles()
    {
        $driver = $this->GetDriver();

        foreach ($driver->getProfiles() as $name => $def) {
            [$type, $suffix, $min, $max, $step, $digits] = $def;
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
            }
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }

        foreach ($driver->getEnumProfiles() as $name => $assocs) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            }
            foreach ($assocs as $value => $def) {
                [$label, $color] = $def;
                IPS_SetVariableProfileAssociation($name, $value, $label, '', $color);
            }
        }
    }
}
