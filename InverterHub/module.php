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

        $bat2Active = $hub->ReadPropertyBoolean('GroupBat2') && ($bat2blk !== null);
        $soc1 = ($bms !== null) ? (float)$mb->u16($bms, 8)  : 0.0;
        $soc2 = ($bms !== null) ? (float)$mb->u16($bms, 26) : 0.0;
        $hub->SetVarInt('soc', (int)round($bat2Active ? (($soc1 + $soc2) / 2.0) : $soc1));

        if ($hub->ReadPropertyBoolean('EnableTracker1')) {
            $hub->SetVarFloat('pv1_voltage', $mb->u16($inv, 0) / 10.0);
            $hub->SetVarFloat('pv1_current', $mb->u16($inv, 1) / 10.0);
            $hub->SetVarFloat('pv2_voltage', $mb->u16($inv, 4) / 10.0);
            $hub->SetVarFloat('pv2_current', $mb->u16($inv, 5) / 10.0);
        }
        if ($hub->ReadPropertyBoolean('EnableTracker2')) {
            $hub->SetVarFloat('pv3_voltage', $mb->u16($inv, 8)  / 10.0);
            $hub->SetVarFloat('pv3_current', $mb->u16($inv, 9)  / 10.0);
            $hub->SetVarFloat('pv4_voltage', $mb->u16($inv, 12) / 10.0);
            $hub->SetVarFloat('pv4_current', $mb->u16($inv, 13) / 10.0);
        }
        if ($hub->ReadPropertyBoolean('EnableTracker3') && $pvext !== null) {
            $hub->SetVarFloat('pv5_voltage', $mb->u16($pvext, 3) / 10.0);
            $hub->SetVarFloat('pv5_current', $mb->u16($pvext, 4) / 10.0);
            $hub->SetVarFloat('pv6_voltage', $mb->u16($pvext, 6) / 10.0);
            $hub->SetVarFloat('pv6_current', $mb->u16($pvext, 7) / 10.0);
        }

        // Firmware-Quirk (live verifiziert): Requests ab Register 35333+
        // liefern für 35338/35339 nur 0xFFFF. Ab 35332 oder früher stabil.
        $mpptBlk = $mb->readHolding(35332, 16);
        if ($mpptBlk !== null) {
            if ($hub->ReadPropertyBoolean('EnableTracker1')) {
                $hub->SetVarFloat('mppt1_power',   (float)$mb->u16($mpptBlk, 5));
                $hub->SetVarFloat('mppt1_current', (float)$mb->u16($mpptBlk, 13));
            }
            if ($hub->ReadPropertyBoolean('EnableTracker2')) {
                $hub->SetVarFloat('mppt2_power',   (float)$mb->u16($mpptBlk, 6));
                $hub->SetVarFloat('mppt2_current', (float)$mb->u16($mpptBlk, 14));
            }
            if ($hub->ReadPropertyBoolean('EnableTracker3')) {
                $hub->SetVarFloat('mppt3_power',   (float)$mb->u16($mpptBlk, 7));
                $hub->SetVarFloat('mppt3_current', (float)$mb->u16($mpptBlk, 15));
            }
        }

        $gridMode = $mb->u16($inv, 33);
        if ($hub->ReadPropertyBoolean('GroupGrid')) {
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

        if ($hub->ReadPropertyBoolean('GroupBat1')) {
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

        if ($hub->ReadPropertyBoolean('GroupTemp')) {
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

        if ($hub->ReadPropertyBoolean('GroupMeter') && $meter !== null) {
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

        if ($hub->ReadPropertyBoolean('GroupBackup')) {
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
        if ($hub->ReadPropertyBoolean('GroupEnergy')) {
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

        if ($hub->ReadPropertyBoolean('GroupErrors')) {
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
// InverterHub — Hauptmodul, lädt den Treiber laut Manufacturer-Property
// ---------------------------------------------------------------------------

class InverterHub extends IPSModule
{
    private const DRIVERS = [
        'goodwe' => 'GoodweDriver',
    ];

    private $driver = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Manufacturer', 'goodwe');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 247);
        $this->RegisterPropertyInteger('IntervalFast', 5);
        $this->RegisterPropertyInteger('IntervalSlow', 300);

        // Treiber-spezifische Properties werden ab hier dynamisch registriert.
        $driver = $this->GetDriver();
        foreach ($driver->getExtraBooleanProperties() as $name => $default) {
            $this->RegisterPropertyBoolean($name, $default);
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if (!isset($driver->getExtraBooleanProperties()[$propName])) {
                $this->RegisterPropertyBoolean($propName, true);
            }
        }

        $this->RegisterTimer('FastTimer', 0, 'IHUB_ReadFast($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlowTimer', 0, 'IHUB_ReadSlow($_IPS[\'TARGET\']);');

        $this->RegisterAttributeBoolean('DeviceInfoRead', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->RegisterVariables();

        $host = $this->ReadPropertyString('Host');
        if ($host === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            return;
        }

        $this->SetTimerInterval('FastTimer', $this->ReadPropertyInteger('IntervalFast') * 1000);
        $this->SetTimerInterval('SlowTimer', $this->ReadPropertyInteger('IntervalSlow') * 1000);
        $this->WriteAttributeBoolean('DeviceInfoRead', false);
        $this->SetStatus(102);
    }

    public function ReadFast()
    {
        $driver = $this->GetDriver();
        if (!$this->ReadAttributeBoolean('DeviceInfoRead')) {
            $driver->readDeviceInfo($this->GetModbusClient(), $this);
            $this->WriteAttributeBoolean('DeviceInfoRead', true);
        }
        $driver->readFast($this->GetModbusClient(), $this);
    }

    public function ReadSlow()
    {
        $this->GetDriver()->readSlow($this->GetModbusClient(), $this);
    }

    public function RequestAction($Ident, $Value)
    {
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
                    'caption' => '📖 Dokumentation & Hilfe',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'InverterHub liest Wechselrichter verschiedener Hersteller direkt per Modbus TCP aus. Hersteller wählen, IP-Adresse eintragen, Datenpunkt-Gruppen je nach Anlage aktivieren.'],
                        ['type' => 'Label', 'caption' => 'Aktuell unterstützt: GoodWe GW-ET/EH/BT/BH-Hybridwechselrichter. Weitere Hersteller (SMA, Fronius, Sungrow, Solis, Growatt, Solax) folgen als zusätzliche Treiber.'],
                        ['type' => 'Label', 'caption' => 'Registeradressen stehen im Beschreibungsfeld jeder Variable (Objekt-Manager, Spalte „Beschreibung").'],
                    ],
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Manufacturer',
                    'caption' => 'Hersteller',
                    'options' => [
                        ['label' => 'GoodWe', 'value' => 'goodwe'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Verbindung',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Polling',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Schnell-Intervall (Sekunden)', 'minimum' => 5, 'maximum' => 60, 'suffix' => 's'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalSlow', 'caption' => 'Langsam-Intervall (Sekunden)', 'minimum' => 60, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Datenpunkte',
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

        return json_encode($form);
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
            $this->RegisterVar($v, $pos++, false);
        }

        foreach ($driver->getOptionalGroups() as $propName => $group) {
            $enabled = $this->ReadPropertyBoolean($propName);
            foreach ($group['vars'] as $v) {
                if ($enabled) {
                    $isCtrl = ($v[5] === 'control');
                    $this->RegisterVar($v, $pos++, $isCtrl);
                } else {
                    $this->UnregVarIfExists($v[0]);
                }
            }
        }
    }

    private function RegisterVar(array $def, int $pos, bool $withAction)
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
        if ($withAction) {
            IPS_SetVariableCustomAction($vid, $this->InstanceID);
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
