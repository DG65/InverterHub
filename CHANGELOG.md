# Changelog

## 0.1.0-beta.1 (2026-07-14)

Erste Beta-Veröffentlichung.

**Neu:**

- `InverterHub`: generisches Wechselrichter-Modul mit austauschbaren Hersteller-Treibern
  (`InverterDriverInterface`) statt eines Moduls pro Hersteller. Gemeinsame
  Modbus-TCP-Grundfunktionen in `ModbusTcpClient` (Read Holding/Input Register, Write
  Single/Multiple).
- Treiber für sieben Hersteller: **GoodWe** (1:1 aus dem produktiven GoodweET-Modul portiert,
  inkl. dokumentierter Firmware-Quirks), **Sungrow**, **Solis** (Hybrid-Serie), **Growatt**
  (TL-X/TL3-X/MOD/MIX/SPH/WIT-Basisbereich), **SolaX** (Hinweis: nur über zusätzliches
  Monitoring-Modul per Modbus TCP erreichbar), **SMA** (PV/Energie/Temperatur; AC-/Netzregister
  bewusst ausgespart, da nicht vollständig dokumentiert vorliegend), **Fronius** (reine
  SunSpec-Implementierung mit Laufzeit-Modell-Discovery statt fester Registeradressen).
- Registeradressen je Variable im Beschreibungsfeld sichtbar (Objekt-Manager).
- Dokumentations-Panel „📖 Dokumentation & Hilfe" in der Instanzkonfiguration.
- `InverterHubDiscovery`: Configurator-Modul, das einen IP-Bereich parallel auf Modbus-TCP-Port
  502 durchsucht, den Hersteller anhand weniger dokumentierter Standard-Unit-IDs und eines
  charakteristischen Registers je Hersteller erkennt, und per Klick eine `InverterHub`-Instanz
  mit vorausgefüllter IP-Adresse/Unit-ID/Hersteller anlegt. Start-/End-IP werden anhand des
  eigenen Netzwerks vorbelegt. Freie Namens-Vorlage für neu angelegte Instanzen
  (Platzhalter `{hersteller}` `{ip}` `{unitid}` `{nr}`, Default „Hersteller + lfd. Nr.").
  Erkennt bereits angelegte Instanzen wieder (InstanzID statt „Kein(e)").

**Behoben (während der Entwicklung):**

- PHP-Type-Hints entfernt bzw. auf die von IP-Symcon geforderten Skalartypen (bool/int/
  float/string) für öffentliche, gebridgte Instanzmethoden reduziert — verhinderten sonst das
  Anlegen von Instanzen.
- Protected-Method-Zugriff aus den Treiber-Klassen auf `ReadPropertyBoolean()` behoben
  (öffentlicher Wrapper `GetPropBool()`), der den Fast-Timer bei jedem Zyklus abstürzen ließ.
- `soc`/`bat1_soc`/`bat2_soc`/`bat1_soh`/`bat2_soh` von Float auf Integer korrigiert
  (`~Battery.100`/`~Intensity.100` sind Integer-Profile, nicht Float).
- Custom Actions auf Steuervariablen werden per einmaligem Kurz-Timer nach `ApplyChanges`
  gesetzt statt synchron währenddessen — verhinderte sonst insbesondere die Erstellung über
  das Discovery-Modul (Instanz + Konfiguration in einem einzigen synchronen Ablauf).
- Modultyp von `InverterHubDiscovery` auf `5` (Discovery) korrigiert, damit die Instanz unter
  „Discovery Instanzen" statt „Splitter Instanzen" einsortiert wird.

Bekannte Lücken: SolaX-BAT-Detailregister (Einzelspannung/-strom) nicht abgedeckt, Solis nur
Hybrid-Serie, SMA ohne AC-/Netzmessung, Fronius ohne Batterie-Monitoring (siehe README).
