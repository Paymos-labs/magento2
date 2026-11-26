# Paymos für Magento 2

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles Paymos-Zahlungsmodul für Magento Open Source und Adobe Commerce. Es ergänzt den
Checkout um genau eine Zahlungsart: Die Kundschaft gibt die Bestellung auf, gelangt zur gehosteten
Paymos-Zahlungsseite, wählt einen Token samt Netzwerk und sendet die Transaktion aus der eigenen
Wallet. Magento erstellt die Rechnung erst dann, wenn ein signierter Webhook die
On-Chain-Bestätigung meldet — nicht, wenn die Kundschaft in den Shop zurückkehrt.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- Magento Open Source oder Adobe Commerce 2.4 — das Modul erfordert `magento/framework` 103.0 oder neuer
- PHP 8.3, 8.4 oder 8.5 (Adobe Commerce 2.4.9 hat PHP 8.1/8.2 entfernt)
- Shell-Zugriff für `bin/magento`
- Eine über HTTPS ausgelieferte Storefront; der Verbindungsablauf lehnt eine unverschlüsselte HTTP-Basis-URL ab
- Ein Paymos-Konto, in dessen Dashboard das gewünschte Projekt geöffnet ist

Diese Versionen sind in `app/code/Paymos/Payment/composer.json` hinterlegt. Das Release-Archiv
enthält das Paymos PHP SDK direkt im modul-eigenen Verzeichnis `vendor/`, sodass für die manuelle
Installation kein Composer-Schritt nötig ist.

## Installation

Laden Sie `paymos-magento2-<version>.zip` unter
[Releases](https://github.com/Paymos-labs/magento2/releases/latest) oder über das Panel **CMS
integration** im Paymos Dashboard herunter und entpacken Sie das Archiv im Magento-Wurzelverzeichnis.
Die Struktur liegt bereits unter `app/code/Paymos/Payment/…` vor, sodass nichts verschoben werden
muss.

```bash
bin/magento module:enable Paymos_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

`setup:upgrade` legt zwei Tabellen an: `paymos_payment_invoice` (ein Snapshot pro Bestellung) und
`paymos_payment_event` (das Deduplizierungsprotokoll für Webhooks).

Pro Release wird genau ein Archiv veröffentlicht, und alle Händler laden dieselbe Datei herunter.
Sie enthält weder API-Schlüssel noch API Secret, Projekt-ID, Webhook-Geheimnis, OAuth-Token oder
Gerätecode — die bloße Installation stellt noch keine Verbindung her.

## Verbinden

1. Öffnen Sie im Paymos Dashboard das Projekt, über das dieser Shop abrechnen soll. Genau dieses
   Projekt wird übernommen — das Modul besitzt keine eigene Projektauswahl.
2. Öffnen Sie in Magento **Stores → Configuration → Sales → Payment Methods → Paymos** und klicken
   Sie auf **Connect Paymos**.
3. Bestätigen Sie Shop-URL und Projekt im geöffneten Paymos-Tab. Blockiert der Browser den Tab,
   zeigt die Zeile stattdessen den Bestätigungslink und den Benutzercode an.
4. Warten Sie, bis **Connection status** „connected“ meldet, und stellen Sie **Enabled** auf Yes.

Eine einzige Bestätigung richtet Sandbox und Live gemeinsam ein. Ihr einziger aktiver
Payment-Schlüssel wird weiterverwendet — oder angelegt, falls keiner existiert — und ein
Invoice-Webhook wird unter folgender Adresse registriert:

```text
https://your-store.example/paymos/payment/callback
```

Ein bestehender Webhook wird nur wiederverwendet, wenn Callback-URL, Kategorie und Projekt exakt
übereinstimmen; ein abweichender Webhook unter derselben Adresse wird nie überschrieben. Die
Geräteautorisierung dient ausschließlich als einmaliger Übertragungsweg — das kurzlebige Token
wird verworfen, und jeder weitere Aufruf der Merchant API erfolgt HMAC-signiert.

Die Zugangsdaten landen über Magentos `EncryptorInterface` verschlüsselt in `core_config_data` und
werden niemals zurück in die Admin-Seite gerendert. Es gibt kein Eingabefeld für Geheimnisse;
erneutes Verbinden ist die einzige Möglichkeit, sie auszutauschen.

## Konfigurationsbereich

Die Felder des Moduls sind im Bereich **Default Config** und auf **website**-Ebene verfügbar.
Store Views erben Einstellungen über Magentos regulären Fallback, und der für eine Store View
aufgelöste Wert gilt für deren Checkout und Bestellungen.

| Einstellung | Standard | Zweck |
|---|---|---|
| Enabled | No | Ob die Zahlungsart überhaupt angeboten wird |
| Title | `Pay with crypto (Paymos)` | Bezeichnung für die Kundschaft |
| Mode | `sandbox` | Welchen Zugangsdatensatz die Website verwendet |
| New order status | `pending_payment` | Status während die Zahlung läuft |
| Paid order status | `processing` | Status nach Zahlungsbestätigung |
| Debug logging | No | Diagnosemeldungen in `var/log/paymos.log` |
| Sort order | `1` | Position unter den Zahlungsarten |

Die verbundenen Zugangsdaten gelten einmal pro Installation statt pro Website, sodass eine zweite
Website keine eigene Bestätigung braucht. Wechseln Sie eine Website ausschließlich über **Mode**
zwischen den Umgebungen. Neu verbinden müssen Sie nur nach einer Änderung der Basis-URL, dem
Widerruf eines Payment-Schlüssels oder der Rotation eines Webhook-Geheimnisses.

## Ablauf für Bestellungen

| Paymos-Ereignis | Ergebnis in Magento |
|---|---|
| Rechnung erstellt, Kundschaft weitergeleitet | Konfigurierter **New order status** plus Verlaufskommentar mit Rechnungs-ID |
| `invoice.confirming` | Status unverändert; Kommentar vermerkt die laufende Bestätigung |
| `invoice.underpaid_waiting` | Status unverändert; Bestellung wartet auf den Restbetrag |
| `invoice.awaiting_payment` | Status unverändert; eine bereits gezählte Zahlung ging durch ein Chain-Reorg verloren |
| `invoice.paid`, `invoice.paid_over` | Eine Offline-Rechnung wird erzeugt, die Bestellung wechselt in den Zustand Processing und den konfigurierten **Paid order status**, und die Rechnungs-E-Mail geht raus |
| `invoice.underpaid` | Bestellung storniert |
| `invoice.expired`, `invoice.cancelled` | Bestellung storniert |

Das Rechnungsdokument speichert den On-Chain-Transaktions-Hash der letzten bestätigten Überweisung
als Transaction ID und fällt auf die Paymos-Rechnungs-ID zurück, wenn die Nutzlast keine enthält.

Zwei Schutzmechanismen sitzen vor dieser Tabelle. Ändern sich Gesamtsumme oder Währung einer
Bestellung nach Erstellung der Rechnung, wird sie niemals automatisch abgeschlossen — sie erhält
einen Kommentar zur manuellen Prüfung, während die Zustellung dennoch quittiert wird, damit die
Wiederholungsreihe endet. Und ein verspätetes `confirming`, `underpaid`, `expired` oder
`cancelled` nach bereits erfolgter Zahlung wird protokolliert und verworfen, niemals angewendet.

## Vor dem Livegang testen

1. Stellen Sie **Mode** auf Sandbox und geben Sie eine Bestellung in der Storefront auf.
2. Öffnen Sie diese Rechnung im Paymos Dashboard, während das Dashboard auf Sandbox steht.
   **Pay Full**, **Pay 50%**, **Pay 150%** und **Cancel** lösen exakt dieselben Lebenszyklus-Ereignisse
   aus wie eine echte Zahlung. Es wird keine Kryptowährung bewegt.
3. Beobachten Sie die Magento-Bestellung — Status, Verlaufskommentare und die erzeugte Rechnung.
4. Stellen Sie **Mode** auf Live. Die Live-Zugangsdaten kamen mit derselben Bestätigung, es ist
   weder eine neue Verbindung noch eine zweite Einrichtung nötig.

Sandbox-Nutzlasten enthalten keine Transaktionen; daher speichert eine Sandbox-Bestellung dort die
Rechnungs-ID, wo eine Live-Bestellung einen Transaktions-Hash einträgt.

## Webhooks

Zustellungen treffen als `POST` unter `/paymos/payment/callback` ein. Die Route ist von Magentos
Form-Key ausgenommen, weil der Header `X-Webhook-Signature` die Vertrauensgrenze bildet; ein
unsignierter oder fehlerhaft signierter Body erhält `401` und erreicht keine Bestellung.
`X-Webhook-Id` bleibt über Wiederholungen hinweg stabil und dient als Deduplizierungsschlüssel in
`paymos_payment_event`, sodass Wiederholungszustellungen `200` zurückgeben und nichts ändern.

Endgültige Ereignisse werden rückgeprüft. Bevor eine Bestellung abgeschlossen oder storniert wird,
liest das Modul die Rechnung erneut über die Merchant API aus und vergleicht sie mit dem
gespeicherten Snapshot; bei Abweichung wird die Nutzlast abgewiesen.

Ein Zustellzyklus umfasst 11 Versuche über rund 16 Stunden, sodass auch ein kurzzeitig nicht
erreichbarer Shop das Ereignis erhält. Dahinter ruft der Cronjob `paymos_reconcile_invoices` alle
15 Minuten aktuelle offene Rechnungen über die API ab, während `paymos_reconcile_refunds` jede
Minute ausstehende Erstattungen prüft; beide setzen voraus, dass der Magento-Cron läuft.

## Erstattungen

Das Modul unterstützt native Erstattungen über die Paymos Merchant API (`/v1/refunds`) in zwei Modi:

- `full` — erstattet den vollen bezahlten Rechnungsbetrag;
- `overpayment` — erstattet ausschließlich den Überzahlungsbetrag einer `invoice.paid_over`-Bestellung.

Die Mittel fließen ausschließlich an die verifizierte Wallet-Adresse `first_sender` zurück, die
Paymos bei Bezahlung der Rechnung erfasst hat. Eine Empfängeradresse wird nicht manuell eingegeben,
und partielle Artikel- oder Anpassungserstattungen werden abgewiesen.

- **Vollständige Erstattung (`full`):** Öffnen Sie im Magento Admin die Rechnung der bezahlten
  Paymos-Bestellung und reichen Sie eine native **Credit Memo** ein, bei der alle berechneten
  Positionen vollständig erstattet werden und weder Versand- noch Betragsanpassungen abweichen.
  `Paymos\Payment\Plugin\RefundEntry` fängt die Übermittlung ab, registriert die `full`-Erstattung
  bei Paymos ohne vorzeitige lokale Gutschrift und leitet zum Panel **Paymos refunds** weiter.
- **Überzahlungserstattung (`overpayment`) und Statusprüfung:** Öffnen Sie **Stores → Configuration
  → Sales → Payment Methods → Paymos**, geben Sie die Bestell-ID im Bereich **Paymos refunds** ein,
  klicken Sie auf **Load order** und nutzen Sie **Refund overpayment** oder **Check full refund** /
  **Check overpayment refund**.

Magento erzeugt die Credit Memo und den Bestellkommentar erst, nachdem Paymos `completed` über
`/paymos/payment/callback`, eine manuelle Statusprüfung oder den einminütigen Cronjob
`paymos_reconcile_refunds` bestätigt hat. Im Sandbox-Modus lassen sich Endzustände (`completed`,
`failed`, `cancelled`) simulieren, ohne Krypto-Mittel zu bewegen.

## Fehlerbehebung

**Paymos wird im Checkout nicht angeboten.** Die Zahlungsart ist nach der Installation deaktiviert.
Setzen Sie **Enabled** auf Yes für die Website der Storefront, prüfen Sie, ob **Connection status**
„connected“ anzeigt, und leeren Sie den Konfigurations-Cache — Magento cached `payment/paymos/*`
wie jeden anderen Konfigurationspfad.

**Bestellungen bleiben im Status „Warten auf Zahlung“.** Der Webhook kommt nicht an. Der Callback
muss aus dem öffentlichen Internet exakt unter der bestätigten Basis-URL erreichbar sein:
Loopback-, private und Link-Local-Ziele sind gesperrt und Weiterleitungen werden nicht verfolgt.
Hat der Shop den Host gewechselt oder eine Weiterleitung erhalten, verbinden Sie ihn neu. Mit
`bin/magento cron:run` lässt sich prüfen, ob der Abgleichjob die Lücke zwischenzeitlich schließt.

**Verbinden scheitert mit "Magento base URL must use HTTPS".** Der Geräteablauf liest die
Basis-URL der Standard-Store-View aus; diese URL erscheint auf der Bestätigungsseite und wird für
den Webhook registriert. Stellen Sie sie auf HTTPS um und starten Sie die Verbindung erneut.

## Links

- Dokumentation: [paymos.io/de/docs/cms-magento2](https://paymos.io/de/docs/cms-magento2)
- Quellcode und Releases: [Paymos-labs/magento2](https://github.com/Paymos-labs/magento2)
- Support: [support@paymos.io](mailto:support@paymos.io)
