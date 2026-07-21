# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

An diesen drei Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet:

- **InverterHub** (dieses Repo): Wechselrichter per Modbus TCP — https://github.com/DG65/InverterHub
- **MeterHub**: Energiezähler per Modbus TCP — https://github.com/DG65/MeterHub
  (lokale Arbeitskopie: `../MeterHub`)
- **Prognose** (Suite EnergiePrognose): PV- und Verbrauchsprognose — https://github.com/DG65/Prognose
  (lokale Arbeitskopie: `../Prognose`)

## Kopplung an die PV-Prognose (Prognose-Repo, Präfix `PVF`)

Der `InverterHubMonitor` berechnet Erwartungswerte (Einstrahlung × Generatorparameter) und
stellt sie dem gemessenen Ertrag gegenüber. Er konsumiert dafür:

| Verwendet | Zweck |
|---|---|
| `PVF_GetGenerators($id)` | liefert `pr` (Performance-Ratio) **und** je Generator kWp + Faktor |
| `PVF_GetModuleArea($id)` | Gesamt-Modulfläche (m²) für spez. Leistung / PR |
| Statusvariable `PVF_ModuleArea` | Fallback, wenn der Getter fehlt |
| Konfigurationsschlüssel `PVF_PR`, `PVGenerators` | Fallback für Prognose-Versionen **vor Build 41** (ohne Getter) |
| Modul-GUID `{257DD4E8-9705-462E-89FC-56D0A1038353}` | Instanz der PV-Prognose finden |

Verfügbar, aktuell ungenutzt: `PVF_GetModuleAreas($id)` (seit Build 39) liefert die Fläche
**je Generator** mit `name`, `modules`, `lengthMM`, `widthMM`, `areaPerModule`, `area` — die
Basis, falls spez. Leistung / PR einmal pro Generator statt gesamt ausgewertet werden soll.

**Vertrag (mit der Prognose-Sitzung abgestimmt):** Die `PVF_Get*`-Funktionen sind die
öffentliche Schnittstelle — Signatur- oder Strukturänderungen dort werden angekündigt und in
`InverterHubMonitor/module.php` nachgezogen. Interne Umbauten der Prognose sind frei, solange
die Rückgabestruktur stabil bleibt (so blieb der Vertrag z. B. unberührt, als die Modulfläche
ab Build 40 aus Länge × Breite berechnet statt als m² eingegeben wurde).

Der Zugriff auf `PVF_PR`/`PVGenerators` per `IPS_GetConfiguration` ist **ausschließlich
Fallback** und läuft nur, wenn der Getter fehlt oder nichts liefert (`PvfModel()`, Zweig ab
`count($rows) === 0`).

**Diesen Fallback nicht entfernen** — er ist derzeit für die *Mehrheit* der Installationen der
einzige Pfad (Stand von der Prognose-Sitzung bestätigt):

| Prognose-Kanal | Version / Build | `PVF_GetGenerators`, `PVF_GetModuleArea(s)`, Variable `PVF_ModuleArea` |
|---|---|---|
| Stable (`main`) | 0.19 / Build 32 | **nicht vorhanden** — nur `Rebuild`, `GetForecast`, `GetStatusText`, `GetSnapshot` |
| Beta | 0.20-beta / Build 43 | vorhanden (Getter ab Build 41, `GetModuleAreas` ab 39) |

Folge: Auf Stable funktionieren die **Erwartungswerte** über den Konfigurations-Fallback, die
**Modulfläche** (spez. Leistung / PR) dagegen gar nicht. Die Konfigurationsmaske weist mit
konkreter Versionsangabe darauf hin. Aufgeräumt wird erst, wenn 0.20 im Stable-Kanal ist und
die Prognose-Sitzung sich meldet — dann legen beide Seiten gemeinsam eine Mindestversion fest.

Achtung bei Hinweistexten: Die Modulfläche wird **seit Build 40 aus Länge × Breite (mm)**
berechnet, nicht mehr als m² je Modul eingegeben.

**Nichts eigenmächtig im Prognose-Repo ändern.** Wird ein neuer Getter gebraucht, in der
Prognose-Sitzung anfragen — sie bauen ihn dort. (Der Getter `PVF_GetGenerators` wurde
seinerzeit von hier aus unabgestimmt dort angelegt; das soll sich nicht wiederholen.)

Fehlt das Prognose-Modul, entfallen nur die Erwartungswerte (die Konfigurationsmaske weist
darauf hin) — es darf nichts brechen.

## Fahrzeug-/Wallbox-Kopplung (z. B. Tessie) — bewusst nur konfigurativ

Die Stromflusskachel zeigt an Wallboxen den Ladestand des angesteckten Fahrzeugs. Die
Fahrzeug-Tabelle (`Vehicles`: Bezeichnung, Verbunden-Bedingung, `SocID`) ist
**herstellerneutral**; `AssignVehicles()` ordnet Fahrzeug und Wallbox über die zeitliche
Korrelation der beiden Verbinden-Meldungen zu, ohne dass eine Seite die andere kennen muss.

**Es gibt keine Code-Abhängigkeit zu [Tessie](https://github.com/DG65/Tessie)** — kein
`TESSIE_`-Aufruf, keine GUID. Tessie ist lediglich eine mögliche Quelle für die eingetragenen
Variablen (dort u. a. eine `Soc`-Variable). Das ist Absicht: Jede andere Wallbox-/Fahrzeug-
Quelle funktioniert genauso.

**Wer hier etwas ändert:** Diese Neutralität bitte erhalten. Eine direkte Anbindung an ein
bestimmtes Fahrzeugmodul wäre nur dann sinnvoll, wenn sie — wie bei MeterHub — rein additiv
ist und hinter einem `function_exists`-Guard liegt, sodass die manuelle Konfiguration
unverändert weiterfunktioniert.

## Schwester-Repository MeterHub

Beide sind eigenständig lauffähig und koppeln nur optional aneinander. Die Berührungspunkte:

| Berührungspunkt | Wo im Code |
|---|---|
| Kombinierte Gerätesuche (findet WR **und** Zähler, legt Zähler als MeterHub-Instanz an) | `InverterHubDiscovery/module.php` (`METERHUB_GUID`) |
| Verbraucher-Kreise der Stromflusskachel aus MeterHub-Funktionszuordnung | `InverterHubTile/module.php`: `CONSUMER_TYPES`, `MHUB_TYPE_MAP` |
| Icon-Zeichner der Verbraucher-Arten | `InverterHubTile/module.html`: `ICONS` |

`CONSUMER_TYPES` und `MHUB_TYPE_MAP` liegen **in `module.php`**, nicht in `module.html`. In
`module.html` liegen ausschließlich die Icon-Zeichner im `ICONS`-Objekt.

**`MHUB_GetFunctions($id)` ist der Vertrag zwischen den Repos.** Ändern sich dort Feldnamen
oder Struktur, muss `InverterHubTile/module.php` mitgezogen werden. Neue Einträge im
MeterHub-Vokabular `FUNCTIONS` brauchen einen Eintrag in `MHUB_TYPE_MAP`, sonst fallen sie in
der Kachel still heraus. Die Kernwerte (`grid`, `house`, `pv`, `battery`, `none`) sind dort
bewusst **nicht** gemappt.

**Grundregel:** Keines der Module darf das andere voraussetzen. Fehlt das jeweils andere
(`IPS_ModuleExists` prüfen), entfallen nur die Zusatzfunktionen — es darf nichts brechen.

### Invarianten der MeterHub-Kopplung

1. **Verbraucher-Arten nur in `CONSUMER_TYPES` pflegen.** Die Auswahlliste der Spalte „Art"
   wird zur Laufzeit von `injectConsumerTypeOptions()` in `GetConfigurationForm` erzeugt und
   überschreibt die statischen `options` der `form.json`. Wer eine Art nur in der `form.json`
   einträgt, erzeugt ein stilles Auseinanderlaufen.
2. **Vorzeichen des Netz-Kernwerts wird negiert.** MeterHub zählt `+` = Bezug, die Kachel
   `+` = Einspeisung. Ohne installiertes MeterHub greift ein `function_exists`-Guard und die
   Kachel verhält sich exakt wie zuvor.
3. **`form.json` nicht maschinell umformatieren.** Ein `json.dump`-Durchlauf hat dort schon
   einmal 929 Zeilen für eine 13-zeilige Ergänzung geändert und den Diff unlesbar gemacht.
   Die kompakte Originalformatierung (2 Leerzeichen, einzeilige Objekte) bitte beibehalten
   und rein additiv arbeiten.

## Parallele Sitzungen: Zuständigkeiten

An beiden Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet. Beide
committen auf denselben Branch `beta`. Vereinbarte Aufteilung:

- **MeterHub-Seite:** das MeterHub-Repo vollständig, plus die Integrationslogik in
  InverterHub — `InverterHubTile/module.php`, `form.json`, `CONSUMER_TYPES`, `MHUB_TYPE_MAP`
  und die Verbraucher-Icons; ebenso die Anbindung von `InverterHubEnergy` (Sankey) an die
  MeterHub-Zähler. Also alles zu Daten und Konfiguration.
- **Darstellungs-Seite:** die Darstellungsschicht in `InverterHubTile/module.html` —
  SVG-Geometrie, CSS, Farben, Filter/Verläufe, Browser-Kompatibilität. Dazu die
  Versionspflege in `library.json`.

**Die Grenze in `module.html` verläuft exakt am `ICONS`-Objekt.** Die MeterHub-Seite arbeitet
ausschließlich dort: je Verbraucher-Art eine Funktion `name(g)`, die im 32×32-Raster zentriert
auf (0,0) Kindelemente anhängt (`data-hollow` für offene Konturen). Alles außerhalb von `ICONS`
— Filter, Verläufe, Layout, viewBox — gehört der Darstellungs-Seite. Strichstärke, Farbgebung
und Relief lassen sich daher frei ändern, ohne die Icons anzufassen; sie erben das ohnehin.

Wer die **Struktur** von `ICONS` ändern will (Signatur, Rasterkonvention), stimmt das vorher
ab, damit die andere Seite ihre Icons nachziehen kann.

**Versionsnummern:** `library.json` pflegt die Darstellungs-Seite (sie bumpt häufiger). Wer
eine Erhöhung braucht, nennt die gewünschte Nummer, statt die Datei selbst zu bearbeiten —
so bleiben `library.json` und Changelog synchron.

## Regeln fürs Committen

Diese Regeln entstanden aus einem konkreten Vorfall: Ein `git add -A` hat die in Arbeit
befindlichen Änderungen der jeweils anderen Sitzung mit in einen fremden Commit gezogen, dessen
Botschaft sie nicht beschrieb.

- **Kein `git add -A`.** Nur die Dateien stagen, die man selbst geändert hat.
- **Vor dem Commit `git pull --rebase origin beta`.**
- **Vor dem Committen prüfen**, ob im Arbeitsbaum fremde Änderungen liegen (`git status`,
  `git diff`) — wenn ja, nicht mitcommitten.
- **Versionsbumps** in `library.json` und der Changelog-Eintrag gehören zusammen; wer bumpt,
  hält beide synchron (es ist schon vorgekommen, dass das Changelog eine Version nannte, die
  `library.json` noch nicht hatte).

## Browser-Eigenheiten der Kacheln (teuer erkauftes Wissen)

Gilt für `InverterHubTile/module.html` und sinngemäß für andere Kachel-HTML:

- **Safari rastert `filter: blur()` beim Skalieren grob.** Weiche Verläufe (Corona,
  Glanzlichter, Schatten) daher als radiale SVG-Verläufe umsetzen, nicht per Blur.
- **Safari rendert einen `objectBoundingBox`-Radialverlauf auf einer *elliptischen* Box hart
  statt weich.** Deshalb weiche Ellipsen als **Kreis** mit Verlauf zeichnen und per
  `transform: scale(1, …)` stauchen.
- **Verlaufs-Fill per Inline-Style setzen**, nicht als `fill`-Attribut: Eine Stylesheet-Regel
  wie `fill: none` schlägt sonst das Attribut, und Safari zeigt den Verlauf gar nicht.
- **`feSpecularLighting` wird in Safari körnig.** Höhenkarte großzügig glätten und den Glanz
  breit halten (kleiner `specularExponent`).
- **Die viewBox bleibt fest und quadratisch**; das Einpassen macht `preserveAspectRatio`
  („xMidYMid meet"). Die viewBox früher per JS ans Seitenverhältnis nachzuziehen führte beim
  laufenden Vergrößern zu einem Frame mit falschem Seitenverhältnis — der Inhalt sprang dabei
  klein in eine Ecke.
- **Das Maximieren-Symbol vergrößert die Kachel nicht.** Es öffnet die Objekt-Detailansicht
  des Hosts (Variablenliste der Instanz). Das gilt für alle HTML-SDK-Kacheln, auch für
  Symcons eigene. Wirkt die Ansicht leer, liegt das an fehlenden Variablen der Instanz — nicht
  am Kachel-Layout. Bitte nicht erneut „reparieren".
