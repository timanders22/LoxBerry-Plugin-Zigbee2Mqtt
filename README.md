# Zigbee2MqttNG – LoxBerry-Plugin

Zigbee2MqttNG bringt [Zigbee2MQTT](https://www.zigbee2mqtt.io/) als Plugin auf den LoxBerry und ist auf das
Zusammenspiel mit anderen Plugins ausgelegt – insbesondere mit
[Matter2Lox (Matter to Loxone)](https://github.com/timanders22/LoxBerry-Plugin-Matter2Lox).

Zigbee2MqttNG ist ein Fork des Plugins [Zigbee2Mqtt](https://github.com/romanlum/LoxBerry-Plugin-Zigbee2Mqtt) (Apache-2.0, siehe `LICENSE`).
Es ist ein **eigenständiges Plugin** (Name/Ordner `zigbee2mqttng`, Dienst `zigbee2mqttng`, Installation in `/opt/zigbee2mqttng`)
und kollidiert deshalb nicht mit dem Original. Bis Version 4.0.0 hieß das Plugin **Zigbee2Lox**.

## Umstieg vom Original-Plugin oder von Zigbee2Lox

Bei der Erstinstallation übernimmt Zigbee2MqttNG automatisch das Zigbee-Netz des Vorgängers – zuerst von
Zigbee2Lox, sonst vom Original-Plugin Zigbee2Mqtt (`configuration.yaml`, Datenbank, Netzwerkschlüssel,
`devices.yaml`, `groups.yaml` sowie die MQTT- und Dienst-Einstellungen). Kein Gerät muss neu angelernt werden,
das MQTT-Topic bleibt gleich. Der Dienst des Vorgängers (`zigbee2lox` bzw. `zigbee2mqtt`) wird dabei gestoppt
und deaktiviert.

Zigbee2Lox 4.0.0 holt seine Updates aus diesem Repository. Sein automatisches Update installiert deshalb
Zigbee2MqttNG als **neues** Plugin daneben, das dann wie oben das Netz übernimmt. Die Haus-Themen gehen dabei auf
Zigbee2MqttNG über, und die automatischen Updates von Zigbee2Lox werden abgeschaltet, damit es Zigbee2MqttNG
nicht jede Nacht erneut installiert.

**Danach bitte den Vorgänger deinstallieren.** Ein Update des Vorgängers würde seinen Dienst sonst wieder
starten, und zwei Dienste können nicht denselben Zigbee-Adapter benutzen. Die Einstellungsseite warnt, solange
ein Vorgänger noch installiert ist.

## Was Zigbee2MqttNG zusätzlich kann

### USB-Adapter mit festem Pfad
Die Einstellungsseite listet alle Adapter unter `/dev/serial/by-id/` und warnt bei Namen wie `/dev/ttyACM0`.
Diese können sich bei jedem Neustart vertauschen, sobald ein zweiter Stick steckt – etwa ein Thread-Stick für den
Border-Router von Matter2Lox. Ein Klick übernimmt den festen Pfad.

### Zigbee-Kanal und Thread-Kanal
Zigbee und Thread funken im selben 2,4-GHz-Band mit denselben Kanalnummern (11–26). Der Zigbee-Kanal lässt sich
einstellen; daneben zeigt das Plugin den Thread-Kanal aus dem Dataset von Matter2Lox (oder eines
OpenThread-Border-Routers auf Port 8081) und warnt, wenn beide gleich oder direkt benachbart sind.
Achtung: Nach einem Kanalwechsel müssen alle Zigbee-Geräte neu angelernt werden.

### Weniger Last am MQTT Gateway
Standardmäßig registriert Zigbee2MqttNG beim MQTT Gateway nur noch die Zustands-Themen der Geräte und Gruppen
(plus `bridge/state`) statt `<topic>/#`. Die großen `bridge/*`-Nachrichten (Geräteliste, Logging) erreichen den
Miniserver nicht mehr. Die Liste wird automatisch nachgeführt, wenn Geräte dazukommen, gehen oder umbenannt werden.
Wer das alte Verhalten braucht, stellt „An den Miniserver weiterleiten“ auf „alles“.

### Erreichbarkeit
Zigbee2MQTT meldet unter `<topic>/<gerät>/availability`, ob ein Gerät erreichbar ist. Das Plugin lässt das
Gateway `online`/`offline` in `1`/`0` umwandeln – so sieht Loxone ausgefallene Geräte wie bei Matter2Lox.

### Haus-Themen für Türen und Schlösser (optional)
Tür-/Fensterkontakte und Schlösser werden zusätzlich gemeldet unter

| Thema | Wert | |
|---|---|---|
| `haus/tuer/<name>/offen` | 1 offen, 0 zu | retained |
| `haus/tuer/<name>/verriegelt` | 1 verriegelt, 0 nicht | retained |

Das ist dieselbe Hausvereinbarung wie in Matter2Lox (gleiche Namensregel: klein, Umlaute ausgeschrieben, alles
außer `a-z 0-9 _ -` wird `_`). Logik in Loxone funktioniert damit gleich, egal ob das Gerät Zigbee oder Matter
spricht. Gerätenamen nicht doppelt in beiden Plugins vergeben. Beim Abschalten, Umbenennen, Entfernen eines
Geräts und bei der Deinstallation werden die Themen wieder gelöscht.

### Loxone-Vorlagen
Die Seite „Loxone“ erzeugt aus der Geräteliste von Zigbee2MQTT Vorlagen für Loxone Config:

* **Virtueller UDP-Eingang** – Befehlserkennung `<topic>/<gerät>/<wert>=\v` für das UDP-Format des Gateways
* **Virtueller Ausgang** – Befehle `publish <topic>/<gerät>/set/<wert> …` an den UDP-Eingang des Gateways
* Tabelle mit den Namen der virtuellen HTTP-Eingänge, falls das Gateway per HTTP sendet

Namensschema `ZIGBEE_<GERÄT>_<WERT>` – passend zu `MATTER_<N>_<E>_<THEMA>` aus Matter2Lox.

### Port der Zigbee2MQTT-Oberfläche
Der Port der Weboberfläche (Standard 8881) ist einstellbar.

## Technik

Die Verbindung zwischen Zigbee2MQTT und dem Plugin übernimmt eine externe Erweiterung
(`bin/zigbee2mqttng_extension.mjs`, wird nach `data/external_extensions/zigbee2mqttng.mjs` kopiert).
Sie schreibt die Geräteliste für die Weboberfläche, pflegt die Abos des MQTT Gateways und sendet die Haus-Themen.
Gesteuert wird sie über `data/zigbee2mqttng.json`, das `bin/update-config.php` bei jedem Speichern neu schreibt.
