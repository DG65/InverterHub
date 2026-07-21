# Hinweise für die Arbeit an diesem Repository

## Der Modul-Verbund

Dieses Repo gehört zu einer Gruppe eigenständiger IP-Symcon-Module, die zusammenwirken. An
ihnen wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet, die sich auf
gemeinsame Regeln und dokumentierte Schnittstellen geeinigt haben.

| Modul | Rolle | Repo / lokale Kopie | Vertrag zu uns |
|---|---|---|---|
| **InverterHub** (dieses Repo) | Wechselrichter messen, darstellen, steuern | `DG65/InverterHub` | — |
| **MeterHub** | Energiezähler (Modbus TCP) | `DG65/MeterHub` · `../MeterHub` | `MHUB_GetFunctions($id)` |
| **Prognose** (EnergiePrognose) | PV- und Verbrauchsprognose | `DG65/Prognose` · `../Prognose` | `PVF_GetGenerators`, `PVF_GetModuleArea(s)`, `PVF_GetForecast` |
| **HeishaMon** | Panasonic-Wärmepumpe | `DG65/HeishaMon` | `HEISHA_GetFunctions($id)` (ab v1.1.1) |
| **StromGedacht** | Netzampel (TransnetBW) | `DG65/StromGedachtWidget` | noch keiner; `SGW_GetState()` auf Zuruf zugesagt |
| **Tibber Grid Rewards** | Erlös-/Vermarktungssignale | `DG65/TibberGridRewards` | keiner; Statusvariablen `Delivering`, `GridRewardMode`, `GridRewardWallboxRequest` |
| **Tessie** | Tesla-Fahrzeuge (Wallbox-SOC) | `DG65/Tessie` | bewusst keiner — rein konfigurativ |
| **EMS** | Entscheidungslogik / Batteriefahrweise | EMS-Repo · `../EMS` | noch keiner (`EMS_GetStatus`, `EMS_SetECOWindow`, `EMS_PlanNightCharge`) |

### Grundregel: jedes Modul bleibt eigenständig — und das wird geprüft

Kein Modul darf ein anderes voraussetzen. Kopplungen liegen hinter `function_exists(...)`
bzw. `IPS_ModuleExists(...)`; fehlt der Partner, entfallen nur Zusatzfunktionen — es darf
nichts brechen.

**Das ist kein Stilthema.** Der Aufruf einer nicht vorhandenen Funktion ist in PHP ein
**Fatal Error**. Das oft vorangestellte `@` unterdrückt ihn **nicht** — es unterdrückt nur
Warnungen. Fehlt der Wächter und ist das Partnermodul nicht installiert, bricht die Instanz
hart ab, statt die Zusatzfunktion wegzulassen.

Damit die Zusage jederzeit belegbar ist statt nur behauptet:

```
php tools/check-standalone.php
```

Der Prüfer durchsucht alle PHP-Dateien nach Aufrufen fremder Modulpräfixe (`MHUB_`, `PVF_`,
`HEISHA_`, `SGW_`, `TIBBERGR_`, `TESSIE_`, `EMS_`, `GWET_`) und meldet jeden, der **in seiner
aufrufenden Funktion** keinen passenden `function_exists()`-Wächter hat. Kommentare und
Zeichenketten werden vorher entfernt, damit dokumentierte Beispielaufrufe keinen Fehlalarm
auslösen. Rückgabewert 0 = sauber, 1 = mindestens eine ungesicherte Stelle (für CI geeignet).

**Vor jedem Release ausführen**, und bei jeder neuen Kopplung. Kommt ein Partnermodul dazu,
dessen Präfix in `FOREIGN_PREFIXES` ergänzen — sonst prüft der Prüfer daran vorbei.

### Steuerhoheit: nur das EMS regelt die Batterie

Wichtigste Absprache im Verbund, weil sie sonst schwer auffindbare Fehler erzeugt:

1. **Das EMS ist die einzige Steuerhoheit auf der Batterie.** Es entscheidet.
2. **InverterHub ist reine Ausführungsschicht** — wir setzen um, wir entscheiden nicht.
3. **Signalmodule steuern nicht direkt durch.**

Hintergrund: StromGedacht und Tibber Grid Rewards besitzen beide generische
„Wenn→Dann"-Regel-Engines, mit denen sich **ohne eine Zeile Code** Regeln auf InverterHub- oder
GoodweET-Variablen legen ließen. Dann plant das EMS ein ECO-Fenster, während parallel eine
Regel eine Ladevorgabe schreibt — zwei Regler auf derselben Batterie, beide „korrekt". Beide
Signal-Sitzungen haben dieser Rollenverteilung zugestimmt.

### Konvention für neue `*_GetFunctions`-Verträge

Kommt ein neues Partnermodul dazu, ist `MHUB_GetFunctions` die **Referenz**. Die ausführliche
Fassung steht in der `CLAUDE.md` des MeterHub-Repos (Abschnitt „Konvention für
`*_GetFunctions`-Verträge"); in Kurzform:

- **Liste statt Einzelobjekt**, auch bei nur einem Eintrag — spätere Aufteilungen brechen die
  Signatur dann nicht.
- Empfohlene Felder: `function`, `label`, `powerID` (W), `energyImportID`/`energyExportID`
  (kumulative kWh), `measured` (bool).
- **Veröffentlichte Verträge werden nicht umbenannt.** Abweichende Feldnamen übersetzt die
  konsumierende Seite; Änderungen nur additiv und nach Ankündigung.
- **Genauigkeit braucht ein eigenes Flag** — nicht aus `energyID == 0` ableiten (siehe
  HeishaMon-Fall unten).
- **Energie nur aus kumulativen Zählern.** Fehlt einer, wird die Größe weggelassen statt aus
  der Leistung hochgerechnet.

### Zusammenarbeit der Sitzungen

Die Sitzungen **teilen kein Gedächtnis**. Was einer gesagt wird, wissen die anderen nicht — der
Abgleich funktioniert ausschließlich über ausdrückliche Nachrichten. Es gibt **keine Hierarchie**
zwischen ihnen; die Zuständigkeiten unten sind Absprache, nicht Rangordnung. Auftraggeber ist
der Repo-Eigentümer.

Bei Anliegen, die mehrere Module betreffen, wird die zuständige Sitzung angesprochen und
gebeten, es weiterzureichen — nicht im fremden Repo selbst gearbeitet.

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

| Prognose-Kanal | Version / Build | Getter `PVF_GetGenerators` / `GetModuleArea(s)` | Property `PVF_PR` | Modul-Spalten in `PVGenerators` |
|---|---|---|---|---|
| Stable (`main`) | 0.19 / Build 32 | **nein** (nur `Rebuild`, `GetForecast`, `GetStatusText`, `GetSnapshot`) | **ja** (Default 0.85) | **nein** |
| Beta | 0.20-beta / Build 44 | ja | ja | ja |

Build-Zuordnung (von der Prognose-Seite aus der Historie belegt): Modul-Spalten + `GetModuleArea`
ab **38**, `GetModuleAreas` ab **39**, Eingabe auf Länge × Breite (mm) umgestellt ab **40**,
`GetGenerators` ab **41**. Da wir Fläche *und* Getter brauchen, ist **41** die maßgebliche
Schwelle.

**Was auf Stable geht und was nicht — wichtig für Hinweistexte:**

- **Erwartungswerte: funktionieren auf Stable.** Sie brauchen nur kWp und das *konfigurierte*
  Performance-Ratio; `PVF_PR` ist dort als Property vorhanden und über den Fallback lesbar.
- **Spezifische Leistung (W/m²): geht auf Stable nicht** — nicht wegen eines fehlenden Getters,
  sondern weil `PVGenerators` dort **überhaupt keine Modul-Spalten** hat (weder Anzahl noch
  Fläche noch Länge/Breite). Ein Stable-Nutzer kann die Werte auch nicht eintragen.

Nicht behaupten, das *Performance-Ratio* brauche ein Update — das gilt nur für die aus
Einstrahlung × Fläche **gemessene** Größe, nicht für den konfigurierten Parameter. Diese
Mehrdeutigkeit hat schon einmal zu einem irreführenden Hinweistext geführt.

Aufgeräumt (Fallback entfernen) wird erst, wenn 0.20 im Stable-Kanal ist und die
Prognose-Sitzung sich meldet — dann legen beide Seiten gemeinsam eine Mindestversion fest.

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

## Kopplung an HeishaMon (Wärmepumpe, Präfix `HEISHA`)

Folgt demselben Muster wie MeterHub. Vertrag ab HeishaMon v1.1.1:

```php
HEISHA_GetFunctions(int $id): array
// [[ 'Type' => 'heatpump', 'Caption' => string,
//    'PowerID' => int,   // W, "Elektrische Leistung (gesamt)"
//    'EnergyID' => int,  // kumulative kWh des externen Zählers, 0 = keiner
//    'Measured' => bool  // Genauigkeit von PowerID
// ]]
```

Die abweichenden Feldnamen (`Type`/`Caption`/… statt `function`/`label`/…) werden **bei uns**
übersetzt — der Vertrag war bereits im Store veröffentlicht, als die Konvention entstand, und
veröffentlichte Verträge werden nicht umbenannt.

**Der HeishaMon-Fall — warum Genauigkeit ein eigenes Flag braucht:**
Ursprünglich war vorgeschlagen, „gemessen vs. geschätzt" aus `EnergyID == 0` abzuleiten. Das
trägt nicht: Im Panel „Externer Stromzähler" lassen sich Leistungs- **und/oder**
Energievariable zuweisen. Bei „nur Leistung" ist der Wert **gemessen**, `EnergyID` aber
trotzdem 0 — er wäre fälschlich als Schätzung eingestuft worden. Deshalb gibt es `Measured`.
Diese Ableitung also nirgends wieder einführen.

**Auswirkung auf die Darstellung:** Ohne externen Zähler schätzt HeishaMon die Leistung nur im
**~200-W-Raster**. `fmtKw()` in `module.html` rendert deshalb bei `measured === false` eine
Nachkommastelle mit vorangestelltem `≈` statt drei Stellen — „0,034 kW" wäre dort
Scheingenauigkeit. Das Modul setzt `measured` im Payload **immer** (bei manuellen Verbrauchern
und Zählern hart `true`), eine Prüfung auf „Feld fehlt" ist also nicht nötig; ein fehlendes
Feld gilt trotzdem als gemessen.

**Sankey:** Die Wärmepumpe wird nur berücksichtigt, wenn `EnergyID` gesetzt ist. Ist sie 0,
entfällt der Strang bewusst — aus der Leistung wird **keine** Energie hochgerechnet. HeishaMons
Variable „Stromverbrauch heute" ist als Quelle ungeeignet: Sie wird um Mitternacht auf 0
zurückgesetzt, was innerhalb einer Periode plausibel aussehende falsche Werte liefert und über
den Rücksetzpunkt hinweg den Knoten kommentarlos entfallen lässt.

## Parallele Sitzungen: Zuständigkeiten

An beiden Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet. Beide
committen auf denselben Branch `beta`. Vereinbarte Aufteilung:

- **MeterHub-Seite:** das MeterHub-Repo vollständig, plus die Integrationslogik in
  InverterHub — `InverterHubTile/module.php`, `form.json`, `CONSUMER_TYPES`, `MHUB_TYPE_MAP`
  und die Verbraucher-Icons; ebenso die Anbindung von `InverterHubEnergy` (Sankey) an die
  MeterHub-Zähler und die Anbindung weiterer Funktionsquellen (z. B. HeishaMon). Also alles zu
  Daten und Konfiguration.
- **Darstellungs-Seite:** die Darstellungsschicht in `InverterHubTile/module.html` —
  SVG-Geometrie, CSS, Farben, Filter/Verläufe, Browser-Kompatibilität. Dazu die
  Versionspflege in `library.json` **samt Changelog** (beides gehört zusammen; wer eine
  Erhöhung braucht, nennt Nummer und Text, statt die Dateien selbst zu bearbeiten).

### Wegweiser: welches Anliegen gehört wohin

| Anliegen | Zuständig |
|---|---|
| Wechselrichter-Treiber, Register, neue Hersteller, Gerätesuche | InverterHub (Kernmodule) |
| Aussehen der Kacheln — SVG, CSS, Farben, Browser-Probleme | InverterHub (Darstellung) |
| Versionsnummern und Changelog in diesem Repo | InverterHub (Darstellung) |
| Energiezähler, MeterHub-Repo | MeterHub |
| Verbraucher/Zähler in Kachel und Sankey (`module.php`, `form.json`) | MeterHub |
| PV- und Verbrauchsprognose, Erwartungswerte | Prognose |
| Wärmepumpe (Daten, Steuerbefehle) | HeishaMon |
| Netzampel | StromGedacht |
| Grid Rewards / Vermarktungssignale | Tibber Grid Rewards |
| Fahrzeuge, Wallbox-SOC | Tessie |
| Batteriesteuerung, Entscheidungslogik | EMS |

Bei Tester-Rückmeldungen nach **Symptom** zuordnen, nicht nach Modulname: „Werte falsch" →
das Modul, das sie liest; „sieht falsch aus" → Darstellung; „Verbraucher fehlt in der Kachel" →
MeterHub.

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
