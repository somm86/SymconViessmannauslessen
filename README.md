# Viessmann auslessen für IP-Symcon

Eigenständiges PHP-Modul für Lesen und Schreiben über eine serielle Optolink-Anbindung mit KW-Protokoll. Keine Viessmann-Cloud und kein vcontrold erforderlich. Die Registervorgaben stammen aus den bereitgestellten Screenshots; ihre Bedeutung und Schreibbarkeit sind gerätespezifisch und noch nicht an einer Anlage geprüft.

## Installation

Dieses Repository als Modulbibliothek in IP-Symcon hinzufügen, eine Instanz **Viessmann auslessen** erstellen und den übergeordneten seriellen Port auf den Optolink-Adapter einstellen. Das Modul fordert 4800 Baud, 8 Datenbits, gerade Parität und 2 Stoppbits an. Eine reine PHP-CLI kann das Modul nur mit den Test-Doubles ausführen; im Betrieb wird IP-Symcon benötigt.

Die 18 Register werden bei neuen Instanzen aus `ViessmannOpenV/registers.json` vorbelegt. Änderungen erfolgen danach in der Instanzkonfiguration. Vorhandene Instanzkonfigurationen werden durch Änderungen dieser JSON-Datei nicht überschrieben.

## Lesen und Schreiben

Standardabfrage: alle zehn Sekunden. `VIO_SyncData($instanceID)` stellt eine neue Leserunde ein, sofern keine Aufträge offen sind. Einträge können mit `Typ` = `bool`, `int`, `float` oder `string` konfiguriert werden. Unterstützte Konverter: leer, `2`, `10`, `1000`, `3600` (Division beim Lesen), `X10`, `Temperature10`, `Temperature100`, `HexValue`. `signed` aktiviert vorzeichenbehaftete Zweierkomplementwerte; die Temperature-Konverter sind immer vorzeichenbehaftet. Vorzeichen sind in den Screenshot-Vorgaben nicht angegeben und daher standardmäßig deaktiviert. Insbesondere bei negativen Außentemperaturen die tatsächliche Registerkodierung prüfen und passend konfigurieren.

Vier Register sind entsprechend den Screenshots schreibbar:

| Ident | Adresse | Bytes | Eingabe |
|---|---|---|---|
| BetriebsartHeizung | B000 | 2 | Vier Hexzeichen in Übertragungsreihenfolge |
| BetriebsartLueftung | B005 | 2 | Numerischer Rohwert |
| raumsolltemp | 2000 | 2 | Numerischer Wert, Skalierung ×10 beim Schreiben |
| warmwassersolltemp | 6000 | 2 | Numerischer Wert, Skalierung ×10 beim Schreiben |

Beispiel für ein IP-Symcon-Skript:

```php
RequestAction(IPS_GetObjectIDByIdent('raumsolltemp', $instanceID), 21.5);
```

Auch direkt aufrufbar: `VIO_RequestAction($instanceID, 'raumsolltemp', 21.5);`.

Für `BetriebsartHeizung` wurde der String aus dem Screenshot ausdrücklich als `HexValue` konfiguriert: Die Zuordnung der Betriebsarten zu konkreten Bytes ist unbekannt. Daher gibt es keine vorgegebenen Betriebsartbuttons. HexValue gibt die Bytes direkt vor; numerische Werte werden Little Endian kodiert. Fachliche Wertebereiche wie zulässige Warmwassertemperaturen müssen anhand der konkreten Regelung bestimmt werden; aktuell wird der technische Registerbereich geprüft.

Schreibaufträge werden seriell abgearbeitet. Die Nutzlast muss exakt zur Registerlänge passen. Erst nach Bestätigung mit `00` wird ein Leseauftrag vorgezogen; der zurückgelesene Wert aktualisiert die Variable. Eine Annahme des Auftrags durch `RequestAction` ist noch keine Bestätigung der Heizung. Abgelehnte Schreibaufträge und Timeouts erzeugen Status 201 und Debugausgaben. Es gibt keine automatische Schreibwiederholung. `only_read` wird sowohl für Variablenaktionen als auch beim direkten Aufruf geprüft.

## Kommunikation und Grenzen

Jeder Auftrag beginnt nach einem einzelnen Bereitschaftsbyte `05` mit `01 F7/F4 Adresse Länge [Nutzlast]`. Nach einer vollständigen Antwort beendet `04` die Sitzung; der nächste Auftrag wartet auf eine neue Bereitschaftsmeldung. Antwortfragmente werden gesammelt. Überlange Antworten und ungültige Schreibbestätigungen verwerfen die Warteschlange. Das Antworttimeout gilt ab Telegrammversand. Ohne Bereitschaftsmeldung werden Aufträge nicht gesendet; Status 104 ist der anfängliche Wartezustand, Status 102 bedeutet eine verarbeitete Antwort und ist kein dauerhafter Verbindungsnachweis.

Die Sitzungssteuerung, Registerzuordnung und Endianness müssen mit dem tatsächlichen Regelungsmodell/Optolink geprüft werden. P300, BCD-Datum und Zeitprogramme sind in dieser ersten Version nicht implementiert. Kein Hardwaretest und keine Prüfung in einer echten IP-Symcon-Installation wurde durchgeführt.

## Entwicklung und Tests

Vorhandenen Checkout verwenden; für Cloud-Aufgaben ist kein zusätzlicher Git-Worktree erforderlich. Keine Composer-Abhängigkeiten. PHP 8.2 mit JSON und iconv verwenden.

```sh
php tests/run.php
php -l ViessmannOpenV/module.php
php -l ViessmannOpenV/Codec.php
```

Alternativ mit Docker aus dem Repositoryverzeichnis:

```sh
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli php tests/run.php
```

Die Tests simulieren IP-Symcon und die serielle Gegenstelle: Little-Endian-Telegramme, Skalierung, negative Werte, Wertebereich, Hexlängen, Boolean-Null, Schreibsperren, Bestätigung, fragmentiertes Rücklesen, ungültige Antworten, Timeout und Vermeidung doppelter Abfragerunden. Sie ersetzen keinen Integrationstest mit der Anlage.
