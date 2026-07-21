# Hinweise für die Arbeit an diesem Repository

## Schwester-Repository MeterHub

Dieses Projekt hat ein eng verwandtes Schwester-Repository:

- **InverterHub** (dieses Repo): Wechselrichter per Modbus TCP — https://github.com/DG65/InverterHub
- **MeterHub**: Energiezähler per Modbus TCP — https://github.com/DG65/MeterHub
  (lokale Arbeitskopie: `../MeterHub`)

Beide sind eigenständig lauffähig und koppeln nur optional aneinander. Die Berührungspunkte:

| Berührungspunkt | Wo im Code |
|---|---|
| Kombinierte Gerätesuche (findet WR **und** Zähler, legt Zähler als MeterHub-Instanz an) | `InverterHubDiscovery/module.php` |
| Verbraucher-Kreise der Stromflusskachel aus MeterHub-Funktionszuordnung | `InverterHubTile/module.php`, `CONSUMER_TYPES` in `InverterHubTile/module.html` |

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
