# Changelog

## 0.4.1-beta.1 (2026-07-14)

- Beta-Hinweis mit Link zum Symcon-Forum-Thread in beiden Modulen (InverterHub,
  InverterHubDiscovery) ergänzt, dismissable per „Nicht mehr anzeigen"

## 0.4.0-beta.1 (2026-07-14)

- Freie Namens-Vorlage für neu angelegte Instanzen im Discovery-Modul: leer = Default
  „Hersteller + laufende Nummer" (z. B. „GoodWe 1", „GoodWe 2"), oder eigenes Muster mit
  Platzhaltern `{hersteller}` `{ip}` `{unitid}` `{nr}` (z. B. `{hersteller} Dach ({ip})`)

## 0.3.1 (2026-07-14)

- Bug: bereits über die Discovery angelegte Instanzen erschienen beim erneuten Öffnen des
  Suchformulars wieder als „Kein(e)" statt mit ihrer InstanzID — Ergebnisliste wird jetzt gegen
  vorhandene InverterHub-Instanzen (Host + Unit-ID) abgeglichen
- Sprechenderer Instanzname bei der Erstellung („GoodWe Wechselrichter (192.168.2.102)")

## 0.3.0 (2026-07-14)

- Custom Actions auf Steuervariablen werden per einmaligem Kurz-Timer nach `ApplyChanges`
  gesetzt statt synchron währenddessen — der bisherige Ansatz („erst ab dem zweiten
  ApplyChanges") griff nicht, wenn eine Instanz inklusive Konfiguration in einem einzigen
  synchronen Ablauf entsteht (genau das tut das Discovery-Modul beim Erstellen)
- Discovery-Layout vereinfacht: Suchfelder normal gestapelt in einem Panel, Ergebnistabelle in
  einem eigenen, vollbreiten Panel darunter (statt zweispaltig mit viel Leerraum)
- Modultyp von `InverterHubDiscovery` auf `5` (Discovery) korrigiert — Instanz erscheint jetzt
  unter „Discovery Instanzen" statt „Splitter Instanzen"

## 0.2.0 (2026-07-14)

- Neues Modul `InverterHubDiscovery` (Configurator): durchsucht einen IP-Bereich per
  nicht-blockierendem Parallel-Scan nach offenem Modbus-TCP-Port 502, erkennt den Hersteller
  anhand weniger dokumentierter Standard-Unit-IDs pro Hersteller über ein charakteristisches
  Register, legt per Klick eine `InverterHub`-Instanz mit vorausgefüllter IP-Adresse/Unit-ID/
  Hersteller an. Start-/End-IP werden anhand des eigenen Netzwerks vorbelegt
  (`create`-Objekt-Struktur gemäß IP-Symcon-Konfigurator-Schema)
- Icons vor den ExpansionPanel-Überschriften in `InverterHub` (🔌 Verbindung, ⏱️ Polling,
  📊 Datenpunkte, 📖 Dokumentation & Hilfe)

## 0.1.1 (2026-07-14)

- Sechs weitere Hersteller-Treiber ergänzt: **Sungrow** (SH-Hybrid, Read Input Register),
  **Solis** (Hybrid-Serie, 33000er-Register), **Growatt** (TL-X/TL3-X/MOD/MIX/SPH/WIT-
  Basisbereich, durchnummerierte Register mit H/L-Paaren), **SolaX** (Hinweis: nur über
  zusätzliches Monitoring-Modul per Modbus TCP erreichbar, Wechselrichter selbst spricht nur
  RTU), **SMA** (PV/Energie/Temperatur/Status; AC-/Netzregister bewusst ausgespart, da beim
  Erstellen nicht vollständig dokumentiert vorliegend), **Fronius** (reine
  SunSpec-Implementierung mit Laufzeit-Modell-Discovery ab Basisregister 40000 statt fester
  Adressen, wie von Fronius dokumentiert gefordert)
- `ModbusTcpClient` um `readInput()` (Read Input Register, FC 0x04) und `readFloat32()`
  (IEEE-754, SunSpec-Konvention) erweitert

## 0.1.0 (2026-07-14)

Erstveröffentlichung.

- `InverterHub`: generisches Wechselrichter-Modul mit austauschbaren Hersteller-Treibern
  (`InverterDriverInterface`) statt eines separaten Moduls pro Hersteller. Gemeinsame
  Modbus-TCP-Grundfunktionen in `ModbusTcpClient`
- Erster Treiber **GoodWe**, 1:1 aus dem produktiven GoodweET-Modul portiert (inklusive
  dokumentierter Firmware-Quirks, BMS-Sonderlogik, Inselerkennung) — das ursprüngliche
  GoodweET-Modul läuft unverändert und unabhängig weiter
- Registeradressen je Variable im Beschreibungsfeld sichtbar (Objekt-Manager)
- Dokumentations-Panel „📖 Dokumentation & Hilfe" in der Instanzkonfiguration
- Diverse Kompatibilitätskorrekturen während der Erstinbetriebnahme: PHP-Type-Hints auf die von
  IP-Symcon geforderten Skalartypen (bool/int/float/string) für öffentliche, gebridgte
  Instanzmethoden reduziert; Protected-Method-Zugriff aus Treiber-Klassen auf
  `ReadPropertyBoolean()` behoben (öffentlicher Wrapper `GetPropBool()`);
  `soc`/`bat1_soc`/`bat2_soc`/`bat1_soh`/`bat2_soh` von Float auf Integer korrigiert
  (`~Battery.100`/`~Intensity.100` sind Integer-Profile)

---

Bekannte Lücken: SolaX-BAT-Detailregister (Einzelspannung/-strom) nicht abgedeckt, Solis nur
Hybrid-Serie, SMA ohne AC-/Netzmessung, Fronius ohne Batterie-Monitoring (siehe README).
