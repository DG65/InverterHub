<?php
/**
 * Prüfstand IHUB_ModbusTcpClient: Batch-Modus + Transaktions-ID-Prüfung.
 *
 *   php .tools/test-modbus-client.php    # 0 = alle Prüfungen bestanden
 *
 * Startet einen echten kleinen Modbus-TCP-Server in einem eigenen PHP-Prozess
 * (Register = eigene Adresse) und zählt, wie viele Verbindungen der Client
 * tatsächlich aufbaut. Übernimmt Aufbau und "stray"-Servermodus von MeterHubs
 * .tools/test-modbus-client.php (0.26.5) - danke für die Vorlage.
 */

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        public $InstanceID = 0;
        public function __construct($id = 0) { $this->InstanceID = $id; }
    }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}
require_once dirname(__DIR__) . '/InverterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

/** Server-Prozess starten; Modi: normal | dropafter1 | exception | silent | stray */
function startServer(string $mode): array
{
    $port = random_int(20000, 60000);
    $cnt  = tempnam(sys_get_temp_dir(), 'ihubcnt');
    $code = <<<'PHP'
[$port, $mode, $cnt] = [(int)$argv[1], $argv[2], $argv[3]];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) { exit(1); }
file_put_contents($cnt, '0');
$conns = 0;
$rx = function ($c, $n) { $b = ''; while (strlen($b) < $n) { $x = fread($c, $n - strlen($b)); if ($x === false || $x === '') { return null; } $b .= $x; } return $b; };
while ($c = @stream_socket_accept($srv, 30)) {
    $conns++;
    file_put_contents($cnt, (string)$conns);
    while (true) {
        $head = $rx($c, 7);
        if ($head === null) { break; }
        $h = unpack('ntid/npid/nlen/Cunit', $head);
        $pdu = $rx($c, $h['len'] - 1);
        if ($pdu === null) { break; }
        if ($mode === 'silent') { continue; }
        $fc = ord($pdu[0]);
        if ($mode === 'exception') {
            $resp = chr($fc | 0x80) . chr(2);
        } elseif ($fc === 3 || $fc === 4) {
            $a = unpack('nstart/ncount', substr($pdu, 1, 4));
            $data = '';
            for ($i = 0; $i < $a['count']; $i++) { $data .= pack('n', ($a['start'] + $i) & 0xFFFF); }
            $resp = chr($fc) . chr(strlen($data)) . $data;
        } else {
            $resp = substr($pdu, 0, 5);
        }
        if ($mode === 'stray') {
            // Verspätete Antwort einer "frueheren" Anfrage: fremde TID, Muellwert.
            $bad = chr($fc) . chr(2) . pack('n', 0xDEAD);
            fwrite($c, pack('nnn', ($h['tid'] + 1000) & 0xFFFF, 0, strlen($bad) + 1) . chr($h['unit']) . $bad);
        }
        fwrite($c, pack('nnn', $h['tid'], 0, strlen($resp) + 1) . chr($h['unit']) . $resp);
        if ($mode === 'dropafter1') { break; }
    }
    fclose($c);
}
PHP;
    $proc = proc_open([PHP_BINARY, '-r', $code, (string)$port, $mode, $cnt], [], $pipes);
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents($cnt) === '0') { break; }
        usleep(100000);
    }
    return [$proc, $port, $cnt];
}
function stopServer(array $srv): void
{
    proc_terminate($srv[0]);
    proc_close($srv[0]);
    @unlink($srv[2]);
}
function serverConns(array $srv): int
{
    usleep(150000);
    return (int)@file_get_contents($srv[2]);
}

echo "1) Batch-Modus: mehrere Reads über EINE Verbindung, endBatch() beendet sie\n";
$s = startServer('normal');
$mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$mb->beginBatch();
$r1 = $mb->readHolding(41000, 14);
$r2 = $mb->readHolding(40000, 1);
$r3 = $mb->readInput(1011, 4);
check('Werte korrekt (Register = Adresse)', ($r1[0] ?? null) === 41000 && ($r1[13] ?? null) === 41013 && ($r2[0] ?? null) === 40000 && ($r3[3] ?? null) === 1014, json_encode([$r1[0] ?? null, $r2, $r3]));
check('nur eine Verbindung fuer 3 Reads (Server-Zaehler)', serverConns($s) === 1, (string)serverConns($s));
$mb->endBatch();
$mb->readHolding(100, 2);
check('nach endBatch() oeffnet der naechste Read eine neue (Einzel-)Verbindung', serverConns($s) === 2, (string)serverConns($s));
stopServer($s);

echo "2) Kein Batch: jeder Read oeffnet und schliesst seine eigene Verbindung\n";
$s = startServer('normal');
$mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$mb->readHolding(1, 1);
$mb->readHolding(2, 1);
$mb->readHolding(3, 1);
check('drei Einzel-Verbindungen (kein Batch aktiv)', serverConns($s) === 3, (string)serverConns($s));
stopServer($s);

echo "3) Modbus-Exception: gueltige Antwort, aber Nutzdaten null\n";
$s = startServer('exception');
$mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('Exception -> null', $mb->readHolding(1, 1) === null);
check('zweite Abfrage ebenfalls null', $mb->readHolding(2, 1) === null);
stopServer($s);

echo "4) Stummes Geraet: Zeitueberschreitung liefert null, kein Haengen\n";
$s = startServer('silent');
$mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$t0 = microtime(true);
$res = $mb->readHolding(1, 1);
$dt = microtime(true) - $t0;
check('liefert null', $res === null);
check('wartet ca. 3s, nicht laenger', $dt < 4.5, round($dt, 2) . ' s');
stopServer($s);

echo "5) Fremde Antwort mit falscher Transaktions-ID (261,5-MW-Befund, 12.09.2026)\n";
$s = startServer('stray');
$mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$mb->beginBatch();
$st1 = $mb->readHolding(41000, 1);
$st2 = $mb->readHolding(41001, 1);
check('fremder Frame verworfen, jede Abfrage bekommt ihren eigenen Wert', ($st1[0] ?? null) === 41000 && ($st2[0] ?? null) === 41001, json_encode([$st1, $st2]));
check('kein 0xDEAD durchgerutscht, dieselbe (Batch-)Verbindung', !in_array(0xDEAD, array_merge((array)$st1, (array)$st2), true) && serverConns($s) === 1);
$mb->endBatch();
stopServer($s);

echo "6) Kein Server erreichbar: sauber null, kein Haengen\n";
$mb = new IHUB_ModbusTcpClient('127.0.0.1', 1, 1);
check('null bei nicht erreichbarem Server', $mb->readHolding(1, 1) === null);

echo "\n" . ($fails === 0 ? "ALLE PRUEFUNGEN BESTANDEN\n" : "$fails PRUEFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
