# InverterHub

IP-Symcon-Modul, das Wechselrichter verschiedener Hersteller direkt per **Modbus TCP** ausliest
und steuert — ein generisches Treiber-Framework statt eines Moduls pro Hersteller.

**Status: Beta.** Die Register-Zuordnungen basieren auf den öffentlich verfügbaren
Modbus-Protokolldokumenten der Hersteller und wurden, soweit möglich, gegen reale Anlagen
geprüft (aktuell GoodWe live verifiziert). Rückmeldungen zu falschen/fehlenden Werten sind
willkommen — bitte mit Hersteller, Modell und betroffenem Register melden.

## Unterstützte Hersteller

| Hersteller | Umfang | Anmerkung |
|---|---|---|
| **GoodWe** | PV (3 MPPT-Tracker), Netz, Batterie 1+2, Meter, Energie, Backup/Insel, EMS-Steuerung | Live verifiziert, produktiv im Einsatz |
| **Sungrow** | PV (4 MPPT), Netz, Batterie, Meter, Energie, Backup, Start/Stop | SH-Hybrid-Serie |
| **Solis** | PV (4 Strings), Netz, Batterie, Meter, Energie | Nur Hybrid-Serie (33000er-Register); reine String-Wechselrichter (3000er) noch nicht unterstützt |
| **Growatt** | PV (3 Strings), Netz, Energie, Temperatur, Fehlercodes | Deckt den über TL-X/TL3-X/MOD/MIX/SPH/WIT gemeinsamen Basisregisterbereich ab |
| **SolaX** | PV (6 Strings), Netz ein-/dreiphasig, Batterie-Systemwerte, Meter/CT | **Wichtig:** Der Wechselrichter spricht nur Modbus RTU. Modbus TCP läuft ausschließlich über ein zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als Gateway — dessen IP-Adresse eintragen, nicht die des Wechselrichters |
| **SMA** | PV (2 DC-Eingänge), Temperatur, Energie, Health-Status | Bewusst unvollständig: AC-/Netzmessregister lagen beim Erstellen nicht vollständig dokumentiert vor und wurden nicht geraten |
| **Fronius** | PV (2 MPPT), Netz, Meter, Energie, Gerätename/Seriennummer | Reine SunSpec-Implementierung mit Laufzeit-Discovery (keine festen Registeradressen, siehe unten) |

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch oder für eigene Skripte.

## Module in diesem Repository

### InverterHub

Die eigentliche Datenauslese-Instanz. Ein Modul, ein `Manufacturer`-Auswahlfeld — je nach
gewähltem Hersteller werden die passenden Datenpunkt-Gruppen (Checkboxen) und Register
freigeschaltet. Architektur:

- **`ModbusTcpClient`** — gemeinsame Modbus-TCP-Grundfunktionen (Read Holding/Input Register,
  Write Single/Multiple, Datentyp-Hilfsfunktionen), von allen Treibern genutzt.
- **`InverterDriverInterface`** — Vertrag, den jeder Hersteller-Treiber erfüllt (Basisvariablen,
  optionale Gruppen, Profile, `readFast`/`readSlow`/`readDeviceInfo`/`writeControl`).
- **Ein Treiber je Hersteller** (`GoodweDriver`, `SungrowDriver`, `SolisDriver`, `GrowattDriver`,
  `SolaxDriver`, `SmaDriver`, `FroniusDriver`) — kapselt die herstellerspezifischen
  Registeradressen, Skalierungsfaktoren und Eigenheiten.

Einrichtung: Instanz anlegen, Hersteller wählen, IP-Adresse (und bei Bedarf Port/Unit-ID)
eintragen, gewünschte Datenpunkt-Gruppen aktivieren, übernehmen.

### InverterHubDiscovery

Ein **Configurator**-Modul, das einen IP-Bereich im lokalen Netz nach Wechselrichtern auf
Modbus-TCP-Port 502 durchsucht:

1. Start- und End-IP eintragen (wird beim Anlegen anhand des eigenen Netzwerks vorbelegt,
   bleibt aber änderbar), optional eine Namens-Vorlage für neu anzulegende Instanzen.
2. „Netzwerk durchsuchen" klicken — nicht-blockierender Parallel-Scan auf Port 502.
3. Für jede offene IP wird der Hersteller anhand weniger dokumentierter Standard-Unit-IDs und
   eines charakteristischen Registers pro Hersteller erkannt (kein voller 1-247-Scan).
4. Treffer erscheinen in der Ergebnistabelle — Klick auf „Erstellen" legt eine
   `InverterHub`-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.

**Namens-Vorlage:** leer lassen für den Standard „Hersteller + laufende Nummer" (z. B.
„GoodWe 1", „GoodWe 2"), oder ein eigenes Muster mit den Platzhaltern `{hersteller}` `{ip}`
`{unitid}` `{nr}` eintragen (z. B. `{hersteller} Dach ({ip})`).

**Bekannte Einschränkung:** Filter/Aktualisieren (oberhalb) und Erstellen/Alle erstellen/
Zielkategorie-Auswahl (unterhalb) der Ergebnistabelle sind fester Bestandteil der nativen
IP-Symcon-Konfigurator-Ansicht — ihre Position und ein „einzeln als gesehen markieren" lassen
sich modulseitig nicht beeinflussen bzw. ergänzen (IP-Symcon-API-Grenze, keine Dokumentation
dafür vorhanden).

## Fronius: Hinweis zur SunSpec-Discovery

Fronius dokumentiert explizit, dass Registeradressen **nicht konstant** sind — sie hängen von
der jeweiligen SunSpec-Modellkette ab. Der `FroniusDriver` durchläuft deshalb bei jedem
Lesezyklus die Modellkette ab Basisregister 40000 (Common Block, dann Model-ID + Länge je
Block), statt feste Adressen zu verwenden. Das ist langsamer als bei den anderen Herstellern,
aber die einzige laut Fronius-Dokumentation zulässige Vorgehensweise.

## Installation

Über die IP-Symcon Modulverwaltung „Hinzufügen" mit der URL dieses Repositories:

```
https://github.com/DG65/InverterHub
```

Für den Beta-Kanal den Zweig `beta` auswählen.

## Mitwirken / Fehler melden

Rückmeldungen zu falschen Registerwerten, fehlenden Datenpunkten oder neuen unterstützten
Modellen gerne als Issue auf GitHub. Besonders hilfreich: Hersteller, Modellbezeichnung,
betroffenes Register/Ident und beobachteter vs. erwarteter Wert.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
