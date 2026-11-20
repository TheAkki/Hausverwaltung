# Hausverwaltung
Die Software dient zur Verwaltung von Immobilien. Ziel soll es sein die Kosten und Einnahmen für die Immobilien zu erfassen und fehlende Einnahmen zu tracken.
Es soll eine Betriebskostenabrechnung erstellt werden können. Die soll über ein Template modifiziert werden können.
Außerdem soll eine Übersicht erstellt werden können um die Steuerliche Abrechnung zu vereinfachen.
In Übersichten soll die Auslastung und der Cashflow dargestellt werden. Zu allen Ein- und Ausgaben sollen Rechnungen hinterlegt werden können.
Außerdem sollen auch Wartungsverträge wie zum Beispiel für die Rauchwarnanlage (RWA) oder Belüftungen etc. hinterlegt werden.

## Berechtigungsverwaltung

### Benutzer
Es können Benutzer angelegt werden. Die Benutzer können unterschiedlichen Gruppen zugeordnet werden.
Den unterschiedlichen Benutzern können unterschiedliche Rollen zugeordnet werden (Administrator in der Gruppe: Rolle „admin“, sonst „user“).
Ein Administrator kann Benutzer der Gruppe hinzufügen, Benutzer aus der Gruppe entfernen und andere Benutzer zum Administrator machen.
Ein Nutzer kann sich über die Registrierung selbst anlegen. Er braucht eine gültige E-Mail-Adresse (Groß-/Kleinschreibung wird vereinheitlicht) und einen Namen.
Beim Anlegen eines Benutzers wird automatisch eine Benutzergruppe erstellt („Gruppe von [Name]“) und der Benutzer als Besitzer (admin) dieser Gruppe zugeordnet.
Ein Benutzer kann sich nicht selbst löschen. Ein Benutzer kann keine anderen Benutzer löschen.

### Benutzergruppen
Jede Gruppe hat einen Namen, eine optionale Beschreibung und einen Besitzer (owner_id). Nur der Besitzer kann die Gruppe löschen.
Wird der Besitzer gelöscht, werden die zugehörigen Gruppen durch cascade gelöscht (oder es könnte künftig ein anderer Besitzer zugewiesen werden).
Wenn ein Benutzer eingeloggt ist, kann er Gruppen anlegen und (als Besitzer) löschen.
Die Zuordnung Benutzer–Gruppe erfolgt über die Pivot-Tabelle group_user mit Rolle (admin/user).

## Verwaltungsstruktur

### Verwaltungsgruppe
Die oberste Ebene ist die Verwaltungsgruppe. Sie hat eine Beschreibung und optional ein Abrechnungsintervall-Beginn (billing_start), wenn dies nicht der 1. Januar ist.
Eine Verwaltungsgruppe ist genau einer Benutzergruppe zugeordnet (user_group_id).
Die Verwaltungsgruppe dient dazu, z. B. die Pflege einer gemeinsamen Grünfläche zuzuordnen.
Beim Erstellen einer Verwaltungsgruppe soll automatisch ein Abrechnungsintervall erzeugt werden. Diese ist entweder der 1. Januar eines Jahres oder der billing_start.
Eine Verwaltungsgruppe kann auch eine Beschreibung haben.

### Verwaltungseinheit
Jede Verwaltungseinheit ist einer Verwaltungsgruppe zugeordnet. Die Verwaltungseinheit kann einzeln postalisch adressiert werden (z. B. ein Gebäude) und hat eine Adresse sowie eine Beschreibung.
Die Adresse solle einzelnde Felder wie Straße, Postleitzahl und Ort haben.

### Mieteinheit
Eine Mieteinheit wird einer Verwaltungseinheit zugeordnet. Die Mieteinheit kann eine Größe haben. Außerdem hat sie einen Typ z. B. Wohnung, Garage, Stellplatz.
Es ist hinterlegt, ob die Mieteinheit für die Abrechnung relevant ist (is_relevant_for_billing). Garagen/Stellplätze sind in der Regel für die Nebenkostenabrechnung nicht relevant.
Einer Mieteinheit kann eine Etage zugeordnet werden.

### Mieter
Ein Mieter hat einen Namen, Vornamen und eine Anschrift bestehend aus Straße, PLZ und Ort. Optional kann eine IBAN und BIC hinterlegt werden.
Außerdem kann zu jedem Mieter eine Handynummer und eine Email-Adresse angegeben werden. Optional kann auch ein Firmenname gespeichert werden.
Zu jeden Mieter können mehrzeilige Notizen schrieben werden.

### Mietvertrag
Ein Mietvertrag ist einem Mieter und einer Mieteinheit zugeordnet. Er hat zwingend ein Startdatum; bei Kündigung ein Enddatum.
Die Art der Mietanpassung ist vermerkt: Fix (fixed), Staffelmiete (stepped) oder Indexmiete (indexed).
Zu einem Mietvertrag können Dateien (z. B. der Mietvertrag) hochgeladen werden (lease_documents). Mit anlegen eines neuen Mietvertrages wird automatisch ein neuer Mietintervall angelegt.
Das Startdatum des Mietintervalls ist der Beginn des Mietvertrages. Wird im Mietvertrag ein Enddatum gesetzt wird auch das Enddatum des Mietintervalls gesetzt.
Im Mietvertrag wird die vereinbarte Kaltmiete eingespeichert.
Mietverträge die einer Mieteinheit zugeordnet sind dürfen nicht überlappend sein.
In dem Mietvertrag wird hinterlegt wie hoch die Kation ist und wie sie hinterlegt ist.
Die Mindestlaufzeit ist auch hinterlegt und beträgt normalerweise 0 Monate.
Zu jedem Mietvertrag können Notizen eingegeben werden.
Ein Mietvertrag kann die Option "Vorvertrag" haben. In diesen Fall wird dieser für die Abrechnung nicht seperat mit harangezogen. Der Nachfolgende Vertrag wird somit um den Gültigkeitzeitraum verlängert und die Betriebskostenvorauszahlung angerechnet. 

### Mietintervall
Zu jedem Mietvertrag gehören Mietintervalle mit Start- und Enddatum. Bei Beginn eines Abrechnungsintervalls bei Staffel- oder Indexmietverträgen kann ein neues Mietintervall begonnen werden.
Im aktuellen Mietintervall ist die gültige Miete und die Anzahl der Personen in der Mieteinheit gespeichert.
Mietintervall müssen auch händisch angelegt werden können. Wenn kein vorheriger Mietintervall existiert wird das Startdatum des Mietvertrages genommen.
Wenn Mietintervalle bereits bestehen werden die Daten aus den letzten Mietintervall kopiert. Beim neuen Mietintervall muss ein Startdatum gesetzt werden. Beim Anlegen des Mietintervalls wird beim vorherigen Mietintervall das Enddatum auf einen Tag vor dem Startdatum des aktuellen Mietintervalls gesetzt. Jeder Mietintervall muss mindestens einen Tag lang sein.
Ein Mietintervall muss bearbeitbar sein.
In dem Mietintervall müssen auch die Betriebskostenvorrausszahlungen aufgelistet werden.
Für den Mietintervall kann eine Notiz eingegeben werden.
Mietzahlungen müssen mit einen Buchungsdatum versehen werden um zum einen die steuerlichen Anforderungen nachzukommen, als auch offene Buchungen zu erkennen.
Das letzte Mietintervall pro Mieteinheit kann gelöscht werden.

### Abrechnungsintervall
Ein Abrechnungsintervall ist einer Verwaltungsgruppe zugeordnet und hat ein Start- und ein Enddatum (billing_intervals).
Mit dem Anlegen einer neuen Verwaltungsgruppe wird ein Abrechnungsintervall automatisch angelegt. Man kann auch manuell Abrechnungsintervalle anlegen. Allerdings muss überprüft werden, dass zwischen den Abrechnungsintervallen keine Lücken entstehen.
Abrechnungsintervalle können eine Beschreibung haben. Ein Abrechnungsintervall kann maximal ein Jahr dauern. Das Enddatum des Abrechungsintervall wird mit dem 31.12. des Startjahres initialisiert.

### Rechnung
Rechnungen können abgelegt werden mit Rechnungsbetrag, Rechnungsdatum, Rechnungsnummer, Buchungsdatum und Zuordnung zu einem Rechnungssteller.
Optional: Beschreibung, Datei-Upload (file_path), wirksamer Rechnungsbetrag (effective_amount) – z. B. Skonto oder Mahnkosten. Für die Betriebskostenabrechnung ist nur der wirksame (ggf. niedrigere) Betrag maßgeblich.
Die Rechnungen können auch Sammelrechnungen für regelmäßige Abbuchungen sein. Diese Abbuchungen können sowohl im Betrag gleich wie auch unterschiedlich sein. Die Periodizität kann angegeben werden: einmalig (Standard), monatlich, quartalsweise, jährlich oder händisch. Bei monatlich, quartalsweise und jährlich werden Start und Ende der Wiederholung erfasst. Bei händisch können beliebig viele Termine mit jeweils zugehörigem Betrag angegeben werden; der Rechnungsbetrag ergibt sich aus der Summe dieser Beträge.
Der Defaultwert ist „einmalig“. Die Felder für Periodizität (Start/Ende bzw. Termine und Beträge) werden abhängig von der gewählten Periodizität ein- oder ausgeblendet.
Für jede Abbuchung müssen die entsprechenden Kostenpunkte angelegt werden. Für die Zuordnung des Abrechnungsintervalls gilt: Wenn ein Enddatum gesetzt ist, dann werden alle Abbuchungen dieser Rechnung dem ursprünglichen Abrechnungsintervall zugeordnet (dem, das beim Start der Wiederholung aktuell war) – auch wenn eine einzelne Abbuchung rein rechnerisch in ein späteres Kalenderjahr fällt, wird dafür kein neues Abrechnungsintervall angelegt. Wenn kein Enddatum gesetzt ist, wird für das Abrechnungsintervall stattdessen das Datum der jeweils geplanten Abbuchung herangezogen; ragt eine unbefristete Rechnung (z. B. ein Kredit) so in ein neues Kalenderjahr hinein, für das noch kein Abrechnungsintervall existiert, wird eines automatisch angefügt.

Der Rechnungsbetrag kann unterschiedlichen Schlüsseln zugeteilt werden. So besteht zum Beispiel eine Abbuchung für einen Kredit aus Zins und Tilgung.
Bei regelmäßigen Abbuchungen müssen die Buchungsdaten trotzdem eingetragen werden, um eine Zahlung als geschehen zu markieren.
Bei den unterschiedlichen Buchungen muss es möglich sein einen anderen Verteilungsschlüssel mit anzugeben, da zum Beispiel bei einen Annuitätendarlehen sich das Verhältniss zwischen Zins und Tilgung ändert.
Es muss auch möglich sein, dass bei einer regelmäßigen Buchungen der Betrag modifiziert werden muss. Da zum Beispiel eine Tilgung eines Kredits die Zinslast senkt.

Es muss möglich sein eine Rechnung in zu splitten. Das Splitten muss auch auf unterschliedliche Abrechnungsintervalle sein.

#### Rechnungen finden
Es gibt einen Suchdialog in dem man nach Rechnungen suchen kann. Suchkritieren sind dabei:
a) Rechnungssteller (und deren Accounts)
b) Rechnungsdatum über Zeitraum
c) Betrag/ -sbereich
d) Kategorie
e) Beschreibung
f) Buchungsdatum / Buchungsbereich
g) Rechnung vorhanden / Nicht vorhanden
h) Rechnungsnummer

Alle Suchkriterien können "ODER" bzw. "UND" verknüpft werden.



### Rechnungssteller
Der Rechnungssteller wird mit Name oder Firmennamen erstellt, optional mit Anrede ("Herr", "Frau"), Vornamen, Adresse (Straße, PLZ und Ort), Firmennamen, E-Mail, Telefnummer, Mobilfunk und Kommentar hinterlegt.
Optional sind außerdem IBAN und BIC.
Die Felder für die Straße, PLZ und den Ort sind seperat zu speichern.

Für jeden Rechnungssteller wird automatisch ein Kundenaccount festgelegt. In dem kann eine Beschreibung und die Kundennummer hinterlegt werden.
So ist es zum Beispiel möglich bei einen Versorger unterschiedliche Accounts für Strom, Wasser, Fernwäre oder sonstiges zu haben.
Unter einen Rechnungssteller können mehrere Accounts angelegt werden.

### Vermieter
Zu jeder Gruppe muss ein Vermieter angegeben werden. Dieser wird u.a. für die Betriebskostenabrechnung verwendet.
Eine Verwaltungsgruppe kann diese Vermieterinformationen für diese Verwaltungsgruppe überschreibem.
Diese Informationen müssen im Template zur Verfügung stehen. Wenn dem Account ein Vermieter zugeordnet ist, dann werden diese Informationen der persönlichen Gruppe zugewiesen.

### Kosten
Zu jeder Verwaltungsgruppe, Verwaltungseinheit oder Mieteinheit können für ein Abrechnungsintervall Kosten gebucht werden. Die Kosten haben Kategorien.
Die Kategorien sind initial angelegt (CostCategorySeeder): „Öffentliche Lasten“, „Wasserversorgung“, „Entwässerung“, „Heizkosten“, „Warmwasser“, „Wärmekosten“, „Personen- oder Lastaufzug“, „Straßenreinigung & Müllbeseitigung“, „Gebäudereinigung & Ungezieferbekämpfung“, „Gartenpflege“, „Beleuchtung“, „Schornsteinreinigung“, „Sach- und Haftpflichtversicherung“, „Hauswart“, „Gemeinschaftsantenne, Breitbandnetz, Glasfaser“, „Wäschepflege“, „Sonstige Betriebskosten“. Den Kategorien ist zugewiesen, ob sie für die Betriebskostenabrechnung relevant sind (is_relevant_for_billing).

Ein Kostenpunkt (costs) wird optional einer Rechnung zugeordnet; der Betrag wird gespeichert. Die Summe der Kostenbeträge pro Rechnung soll den (wirksamen) Rechnungsbetrag nicht übersteigen.

Pro Verwaltungsgruppe und Kategorie kann ein Abrechnungsschlüssel (allocation_key) definiert werden: z. B. person_count, area, individual. Dies erfolgt in der Tabelle management_group_cost_category. Innerhalb einer Verwaltungsgruppe ist pro Kategorie der Abrechnungsschlüssel einheitlich; verschiedene Verwaltungsgruppen können pro Kategorie unterschiedliche Schlüssel haben. Kategorien, die nicht abrechnungsrelevant sind brauchen keinen Abrechnungsschlüssel.
Zusätzlich gibt es den Abrechnungsschlüssel Mieteinheit. Damit werden die Ausgaben entsprechend der abrechnungsrelevanten Mieteinheiten dieser Verwaltungsebene aufgeteilt. Die Anzahl der abrechnungsrelevanten Mieteinheiten für die Verwaltungsgruppe und die Verwaltungseinheit müssen im Template verfügbar sein.

Zu den genannten Kategorien ist es möglich auch Unterkategorien anzulegen. Diese Subkategorie wird dann durch ein "::" von der Überkategorie getrennt. Die Schachteltiefe ist prinzipiell unbegrenzt. Ob eine Unterkategorie abrechnungsrelevant bzw. steuerrelevant ist bestimmt die Hauptkategorie.

### Kostenzuweisung
Wenn eine Katgorie die Abrechnungsart "Individuell" hat, dann auch einen einen Mietvertrag Kostenpunkte zugewiesen werden. Die Kostenzuweisungen gehen nicht in die Bilanzzierung ein, da diese nur extern ermittelt wurden.

## Rechnungsstruktur
### Kontensystem
In den Accounteinstellungen kann die Option "Kontensystem" aktiviert werden. Standardmäßig ist diese deaktiviert.
Wenn dieses Kontensystem deaktiviert ist soll an keiner anderen Stelle Anzeichen dieses Kontosystems erkennbar sein.

### Aktives Kontensystem
Alle Aussagen in diesen Kapitel beziehen sich auf ein aktives Kontosystems
Wenn das Kontosystems aktiv ist sollen unterschiedliche Konten angelegt werden können. Beim Anlegen kann ein buchungsneutraler Initalwert angegeben werden.
Ein Konto soll als das Defaultkonto markiert werden können. Wenn das erste Konto dieser Verwaltung erstellt wird soll die Option "Defaultkonto" angewählt sein.
Zwischen den Konten kann buchungsneutral Geld verschoben werden. Dieser Buchung kann ein Kommentar angefügt werden.

Eine Buchung aus mehreren Konten gespeist werden, wobei im allgemeinen nur ein Konto benutzt wird.
Dieses Konto kann in der Buchung ausgewählt werden.

#### Kontoübersicht
##### Grobübersicht
Es soll eine Übersicht geben, in der die Bilanzen pro Monat angezeigt werden. Dies soll wie etwa so aussehen (mit einer Umschaltung zwischen Jahr − 1, Jahr und Jahr + 1):

|                              | Konto 1 | Konto 2 | ... | Konto n | Summe |
| ---------------------------- | ------- | ------- | --- | ------- | ----- |
| Saldo Jahresanfang           | xxx €   | xxx €   | ... | xxx €   | xxx € |
| Januar                       | + xxx € | − xxx € | ... | + xxx € | + xxx € |
| ...                          |         |         |     |         |       |
| Dezember                     | − xxx € | − xxx € | ... | − xxx € | − xxx € |
| Saldo Jahresende / Ist-Saldo | xxx €   | xxx €   | ... | xxx €   | xxx € |

##### Feinübersicht

|                     | Betrag  | Konto 1 |
| ------------------- | ------- | ------- |
| Saldo (Startdatum)  |         | xxx €   |
| Buchung 1           | + xxx € | yyy €   |
| Buchung 2           | − xyz € | zzz €   |
| ...                 |         |         |
| Buchung N           | + zyx € | vvv €   |
| Saldo (Enddatum)    |         | xvz €   |

## Bedienung
### Allgemeines
- IBANs sollen der Lesbarkeit in 4er Blöcken angezeigt werden

### Dialog Erstellen von Mietverträge
Es muss ein Dialog existieren in dem man einen Mietvertrag erstellen kann. Wenn der Dialog im Kontext eines Mietobjektes aufgerufen wird sollen die entsprechenden Felder bereits initialisiert und auch schreibgeschützt sein.
Je weiter weg der Dialog im Kontext des Mietobjektes geöffnet wird desto mehr Parameter sind auswählbar.
Dabei wird automatisch ein erster Mietintervall ab Beginn des Mietvertrages mit angelegt.

### Übersicht Mietverträge
In der Übersicht Mietverträge sollen beim Klicken auf die Mieteinheit die entsprechenden Mietintervalle angezeigt werden.
Abgelaufene Mietverträge sind ausgeblendet und können eingeblendet werden. Diese sollen aber ausgegraut werden.
In einen Mietvertrag können mehrere Mieter verbunden sein.

### Detailbild Mietvertrag
In der Übersicht soll ein Klick auf ein Mietintervall den entsprechenden Intervall zu editieren öffnen.

### Detailbild Verwaltungseinheit
#### Belegungsplan
Für die Verwaltungsgruppe und für die Verwaltungseinheit soll eine Belegungstabelle für alle darunter liegenden Mieteinheiten erstellt werden.
Die Belegungstabelle soll wie die Belegungsmatrix gruppiert nach Jahr sein und ein- und ausblendbar sein. Der Defaultzustand soll ausgeblendet sein.
Mieteinheiten, die als Jahressumme 0 ergeben sollen ausgeblendet sein.

|               | Januar | Februar | ... | November | Dezember | Summe |
| --------      | ------ | ------- | --- | -------- | -------- | ----- |
| Mieteinheit 1 |  0     | 0       | ... | 1        | 2        | 3     |
| Mieteinheit 2 |  4     | 4       | ... | 4        | 4        | 48    |
| ...           |  ...   | ...     | ... | ...      | ...      | ...   |
| Mieteinheit n |  1     | 2       | ... | 11       | 12       | 68    |
| --------      | ------ | ------- | --- | -------- | -------- | ----- |
|               | 5      | 6       | ... | 16       | 18       | xxx   |

#### Mieteinnahmen
In der Übersicht Verwaltungsheitheit soll eine Übersicht zugänglich sein in der Mann die monatlichen Mieteinnahmen sieht.
Der angezeigte Betrag ist die Summe der Kaltmiete und der Betriebskostenvorauszahlung für den Mieter und dem Mietintervall.
In der letzten Zeile stehen dann die Monatssummen.

|          |              | Januar | Februar | ... | November | Dezember | Summe |
| -------- | ------------ | ------ | ------- | --- | -------- | -------- | ----- |
| Mieter 1 | Wohnung 1    | 30 €   | 45 €    | ... | 45 €     | 45 €     | xx €  |
|          | Stellplatz 1 | 12 €   | 12 €    | ... | 12 €     | 12 €     | xx €  |
| Mieter 2 | Wohnung 3    | 100 €  | 120 €   | ... | 120 €    | 120 €    | xx €  |
| Mieter 4 | Garage       | 20 €   | 20 €    | ... | 20 €     | 20 €     | xx €  |
|          |              | 162 €  | 197 €   | ... | 197 €    | 197 €    | xx €  |

Es sollen nur Mieter/Mietverträge in dem Jahr anzeigt werden, die da aktiv sind.
Es soll die Ansicht umgeschaltet werden können zwischen:

a) Gruppiert nach Mieter und Mieteinheit
b) Gruppiert nach Mieter
c) Gruppiert nach Mieter und Miete + Nebenkosten

#### Ausgaben
In der Übersicht Verwaltungsheitheit soll eine Übersicht zugänglich sein in der Mann die monatlichen Ausgaben gruppiert nach Kategorie aufgeliestet sind.

Die Details der Einnahmen und Ausgaben sollen auf Jahresbasis geschehen. Die Umschaltung des Jahres soll für beides gelten.

### Detailbild Verwaltungsgruppe
Im Detailbild einer Verwaltungsgruppe soll es möglich sein neue Verwaltungseinheiten anzulegen.
#### Übersicht Einnahmen
Im Detailbild einer Verwaltungsgruppe sollen alle Einnahmen gruppiert nach Typ der Mieteinheit aufgeliestet werden.
#### Übersicht Ausgaben
Im Detailbild einer Verwaltungsgruppe sollen alle Ausgaben gruppiert nach Kategorie aufgeliestet werden. Dabei sollen auch die Ausgaben der darunter liegenden Verwaltungseinheit und Mieteinheit berücksichtigt werden.

Die Details der Einnahmen und Ausgaben sollen auf Jahresbasis geschehen. Die Umschaltung des Jahres soll für beides gelten.

Wenn ich auf eine Ausgabenkategorie klicke sollen Details ein- und ausgeblendet werden. Das heißt alle Buchungen zu dieser Kategorie sollen dann für die Monate aufgelistet sein.

Unausgeklappt
|          | Januar | Februar | ... | November | Dezember | Summe |
| -------- | ------ | ------- | --- | -------- | -------- | ----- |
| Zinsen   | 30 €   | 45 €    | ... | 45 €     | 45 €     | xx €  |
| Tilgung  | 100 €  | 120 €   | ... | 120 €    | 120 €    | xx €  |
| Sonstiges| 20 €   | 20 €    | ... | 20 €     | 20 €     | xx €  |
|          | 162 €  | 197 €   | ... | 197 €    | 197 €    | xx €  |

Ausgeklappt
|                             | Januar | Februar | ... | November | Dezember | Summe |
| --------                    | ------ | ------- | --- | -------- | -------- | ----- |
| Zinsen                      | 30 €   | 45 €    | ... | 45 €     | 45 €     | xx €  |
|                   Vertrag 1 | xxx €  | yyy€    | ... | .....    | .....    | 333€  |
|                   Vertrag 2 |        |         | ... | 23€      |          | 23€   |
| Tilgung                     | 100 €  | 120 €   | ... | 120 €    | 120 €    | xx €  |
|                   Vertrag 3 |        | 10€     | ... | 13€      |          | 23€   |
| Sonstiges                   | 20 €   | 20 €    | ... | 20 €     | 20 €     | xx €  |
|                             | 162 €  | 197 €   | ... | 197 €    | 197 €    | xx €  |




### Verwaltungsgruppe Bearbeiten
In den Benutzergruppen kann man alle Gruppen auswählen, in der sich der aktuelle Nutzer befindet.

### Detailbild Verwaltungseinheit
Im Detailbild einer Verwaltungseinheit soll es möglich sein neue Mieteinheiten anzulegen.

Außerdem soll eine Belegungsmatrix zu sehen sein. Diese kann man ein- und ausklappen. Im Normalzustand soll sie eingeklappt sein. Wenn das angezeigte Jahr geändert wird soll die Matrix aufgeklappt sein.
Wenn eine Mieteinheit belegt ist soll ein Tool-Tip möglich sein, die den Mieter anzeigt.

### Detailbild Mieteinheit
Im Detailbild Mieteinheit müssen Mietverträge angelegt werden können. Dazu sind entweder Mieter auswählbar oder ein zu referenzierender Mietvertrag. Ein referenzierter Mietvertrag muss abrechnungsrelevant sein, ein referenzierender Mietvertrag darf es nicht.
Der Dialog muss dazu fähig sein.
Der Referenzierende Mietvertrag endet spätestens mit dem referenzierten Mietvertrag.


### Erstellung einer Rechnung
Beim Erstellen oder Bearbeiten einer Rechnung können die Kosten direkt zugeordnet werden. Wenn eine Rechnung bearbeitet wird, bleiben die bereits zugeweisenen Kosten editierbar.
Zuerst erfolgt die Zuordnung der Verwaltungsgruppe, -einheit oder Mieteinheit. Danach wird das Abrechnungsintervall ausgewählt. Die angebotenen Abrechnungsintervalle werden zur gewählten Zuordnung passend gefiltert (nur Intervalle der zugehörigen Verwaltungsgruppe).

#### Übersicht aller Rechnungen
Es gibt eine Übersichtsseite über alle Rechnungen. Vollständig erledigte Rechnungen werden standardmäßig ausgeblendet. Es kann umgeschalten werden zwischen alle einblenden und erledigte ausblenden. (Wie bei den Mietverträgen)
Als erledigt zählt eine Rechnung, wenn alle Buchungen gebucht sind.

Folgende Spalten sollen enthalten sein:
- Rechnungsdatum
- Buchungsdatum oder Buchungsbeginn
- Buchungsende
- Rechnungssteller
- Beschreibung
- Betrag
- Periodizität
- Zugeordnet zu (Abrechnungsziel)


### Detailbild offene Zahlungen
Erstelle eine Übersichtsseite in dem alle offenen Buchungen bis zum jeweiligen Tag offen und somit ungebucht sind.

### Detailbild nicht zugeordnete Buchungen
Erstelle eine Übersichtsseite in der alle Buchungen bis zum jeweiligen Tag enthalten sind, bei der die Summe der Zuordnungen nicht der Rechnungssumme entspricht. (Es sei denn, dass die Option, dass die Buchungen nicht der Rechnungssumme entsprechen muss ist gesetzt.)
Verglichen wird je Termin: bei einer einmaligen Rechnung die Summe aller Kostenzeilen mit dem wirksamen Rechnungsbetrag, bei einer händischen der Betrag des Termins, bei einer wiederkehrenden der Betrag des Betragsintervalls, das den Termin abdeckt (sonst der wirksame Rechnungsbetrag). Dafür werden die Betragsintervalle an der Rechnung gespeichert.

### Sichtbarkeit
Man sieht nur Verwaltungsgruppen, Verwaltungseinheiten und Mieteinheiten die einer Gruppe zugeordnet sind in der man sich befindet.
Beim Erstellen einer Verwaltungsgruppe kann man nur Benutzergruppen auswählen in den man auch mitglied ist.

Mieter, Rechnungssteller (samt Kundenaccounts) und eigene Kategorien gehören zur selben Benutzergruppe wie die Verwaltungsgruppe, in der sie verwendet werden. Sonst sähen die Mitglieder einer gemeinsamen Gruppe über Mietverträge und Rechnungen Daten einer anderen Gruppe (z. B. der persönlichen). Systemkategorien (ohne Gruppe) passen überall. Weboberfläche, API und Seeder lehnen eine Mischung ab; bereits vorhandene Zuordnungen bleiben bearbeitbar, und ein Backup stellt den gesicherten Stand unverändert wieder her.
Wechselt eine Verwaltungsgruppe die Benutzergruppe, ziehen ihre Stammdaten mit um: Gibt es in der neuen Gruppe einen gleichen Eintrag, wird er verwendet; was nur diese Verwaltungsgruppe verwendet, wird verschoben; was auch eine andere verwendet, wird in die neue Gruppe kopiert.
Ein Rechnungssteller kann samt Kundenaccounts in eine andere eigene Gruppe kopiert werden.
Die Detailseiten von Mietern und Rechnungsstellern zeigen nur Mietverträge und Rechnungen, die der Betrachter sehen darf.
Verweise innerhalb einer Verwaltungsgruppe bleiben dort: Abrechnungsintervall und Konto einer Kostenzeile bzw. Mietzahlung gehören zur Verwaltungsgruppe ihrer Zuordnung, ein Kundenaccount zum Rechnungssteller der Rechnung. Kosten lassen sich nur auf Zuordnungen buchen, die man sehen darf.
Die Standardvorlage einer Verwaltungsgruppe ist eine eingebaute Vorlage oder gehört zu ihrer Benutzergruppe. Wechselt die Verwaltungsgruppe die Gruppe, wird die Vorlage in die neue Gruppe kopiert (oder eine gleichnamige dort verwendet). Eine Vorlage, die Standardvorlage einer Verwaltungsgruppe ist, wechselt die Gruppe nicht.
Kategorienamen sind innerhalb einer Gruppe (samt Systemkategorien) eindeutig; eine Überkategorie gehört zur selben Gruppe oder ist eine Systemkategorie. Haupt- und Unterkategorien beziehen sich nur auf Kategorien derselben Gruppe.
Hochgeladene Dateien (Belege, Mietvertragsdokumente, Kopfbilder) liegen nicht öffentlich und werden nur an Benutzer ausgeliefert, die den zugehörigen Datensatz sehen dürfen.

### Betriebskostenabrechnung
#### Übersicht aller Buchungen
Es soll eine Übersicht aller Buchungen in einen Abrechnungsintervall geben. Diese Auflistung soll nach Kategorie gruppiert werden. Alle verfügbaren Abrechnungsintervalle sollen anwählbar sein.
Diese Übersicht soll es sowohl für die Verwaltungsgruppe als auch für die Verwaltungseinheit geben.
Die angezeigten Kategorien sollen nur die Abrechnungsrelevanten sein.

#### Erstellung einer Betriebskostenabrechnung
Betriebskostenabrechnungen werden nur für Mieter erstellt, die eine abrechnungsrelevante Meiteinheit im Abrechnungszeitpunkt gemietet haben.
Innerhalb der Verwaltungseinheit sollen aller verfügbaren Abrechnungszeitpunkte enthalten sein. Diese ist auf- und zuklappbar.
Wenn es zugeklappt ist habe ich die Möglichkeit:
a) Vorschau aller anzeigen
b) PDF mit der Abrechnung aller relevanten Mieter
c) Download einer Zip Datei mit den PDF Dateien jedes einzelnen Mieters.
Wenn es aufgeklappt ist sind alle relevanten Mieter angezeigt und folgende Optionen sind für jeden Mieter möglich.
a) Vorschau für den einzelnen Mieter
b) PDF mit der Abrechnung des jeweiligen Mieters.

Der PDF-Dateiname hat dann folgendes Schema "{Abrechnungsjahr}__Betriebskostenabrechnung__{Mietername}.pdf". Leerzeichen werden mit "_" ersetzt.

#### Berechnung
Die Berechnung der Betriebskosten ist sehr komplex. Sie ist mehrstufig und für jede Kategorie durch die Abrechnungsart parametrierbar. Die Kosten sind an der Stelle nur für das entsprechende Abrechnungsintervall relevant.
Bei der Abrechnungsart "Individuell" werden alle Buchungen, die auf die Kategorie gebucht sind und einer Verwaltungseinheit, einer Verwaltungsgruppe oder einer Mieteinheit zugewiesen sind ignoriert und nur die Buchungen, die auf einen Mietvertrag gebucht sind verwendet.
Bei der Abrechnungsart "Fläche" werden die Summe der Buchungen dieser Kategorie anteilsmäßig der gemieteten Fläche relativ zu der Gesammtfläche der jeweiligen Verrechnungsebene in Betracht gezogen.
Die Abrechnungsart "Personen" verhält sich ähnlich, nur dass die jeweiligen Personenmonate (PM) des Mietzeitraumes in diesen Abrechnungszeitraum relativ zu den gesammten Personenmonaten in der Verwaltungsebene bezogen sind

Die Tabelle zeigt den Abrechnungsanteil an den geweiligen Gesammtkosten der entsprechenden Kathegorie

|               |             | Verwaltungsgruppe             | Verwaltungseinheit            | Mieteinheit   | Mietvertrag |
|-------------- | ----------- | ----------------------------- | ---------------------         | ------------- | ----------- |
| Abrechnungsart| Individual  | -                             | -                             | -             | 1           |
|               | Personen    | PM (Mieter) / PM (VG)         | PM (Mieter) / PM (VE)         | 1             | -           |
|               | Fläche      | Fläche (Mieter) / Fläche (VG) | Fläche (Mieter) / Fläche (VE) | 1             | -           |

Alle Kosten müssen mit dier Mietdauer skaliert werden. Also mit den Zeitfaktor Tage(Mietdauer im Abrechnungsintervall) / Tage(Dauer es Abrechnungsintervall). Nur die Kosten mit individueller Abrechnung auf einen Mieter werden nicht mit der Mietdauer skaliert. Diese werden 1:1 übernommen. (Erzeuge dazu einen Test)

Stufe 1:
Für jede Kategorie der Verwaltungsgruppe wird die Summe der Kategorie und dem Anteil an den Kosten entsprechend der Tabelle berechnet.
Aus dem Anteilen wird eine Summe gebildet.
Stufe 2:
Wie Stufe 1 nur diesmal auf Ebene der Verwaltungseinheit.
Stufe 3:
Wie Stufe 1 nur diesmal auf Ebene der Mieteinheit
Stufe 4
Wie Stufe 1 nur diesmal auf Ebene des Mietvertrages. (Individuelle Kosten).

Diese 4 Stufen werden Summiert.

Dies wird mit den ensprechenden Betriebskostenvorrauszahlungen verrechnet.

Die Variablen werden dann dem Template zur Verfügung gestellt.

Für abrechnungsrelevante, leerstehende Vermietungsobjekte müssen Leerstandsintervalle berechnet und angelegt werden, da auch Heizkosten und so weiter bei Leerstand anfallen. Zu diesen Leerstand müssen auch Forderungen, die z.B. von einer Abrechnungsgesellschaft kommen verbucht werden. Für diese Leerstände müssen dann auch PDFs erstellt werden.

##### Berechnung neue Vorauszahlung
Anhand der aktuellen Nebenkosten sollen die wirklichen Nebenkosten pro Monat pro Wohnfläche berechnet werden und im Template zur Verfügung gestellt werden.
Außerdem soll ein Vorschlag für eine neue Betriebskostenvorauszahlung ermittelt werden. Diese basiert auf folgenden Regeln.
a) Runde die neue Vorauszahlung auf volle zehntel pro qm auf
b) Wenn Mietdauer kleiner als 3 Monate: Behalte alte Vorauszahlung bei.
c) Reduziere nur, wenn die neue Vorrauszahlung weniger als 90% der alten Vorauszahlung beträgt.
d) Erhöhe wenn der neu Vorauszahlungsbetrag größer als 5% über den alten Betrag liegt.

Stelle den Vorschlag (Summe und pro qm) im Template zur Verfügung

##### Leerstände
Abrechnungsrelevante, aber leerstehende Mieteinheiten müssen berücksichtigt werden.
Berechnet werden diese mit einen 0 Personenhaushalt. Ansonsten werden sie wie ein Mieter gehandhabt.
Das heißt zum Beispiel:
a) Aufführung in der Betriebskostenabrechnung
b) Forderungen können zugefügt werden


#### Template
Für jede Verwaltungseinheit soll eine Betriebskostenabrechnung anhand eines Templates (z.B. Ninja-Script, Blade oder was am besten passt) angezeigt und als PDF exportiert werden können.
Diese Templates müssen verwaltet und bearbeitet werden können. Jeder Verwaltungsgruppe kann ein Defaulttemplate zugeordnet werden.
In diesen Template soll ein Anschreiben mit dem entsprechenden Informationen über den Mieter und der entsprechenden Verwaltungsgruppe, Verwaltungseinheit und Mieteinheit zur Verfügung stehen.
Ebenfalls muss in den Template die der Abrechnungsintervall und die Mietdauer in dem Abrechnungsintervall zur Verfügung stehen.
Als Vorlage soll ein Geschäftsbrief nach DIN 5008 dienen. Innerhalb des Templates soll auch gerechnet werden können und Daten formatiert.
In der Vorlage soll auch ein Kopf- und Fußzeile definiert werden können. Da hinein soll dann auch ein Bild eingefügt werden können.
Template müssen kopiert werden können.
In dem Standard Template muss eine Übersicht über die monatlichen getätigten Nebenkosten sein.
Außerdem muss es über das Template möglich sein, alle relevanten Buchungen mit (Buchungsdatum, Betrag, Empfänger und Beschreibung) gruppiert nach Zuordnungsebene und danach nach Kategorie. Wenn eine Zuordnungsebene keine Einträge hat wird sie weggelassen. Ebenso, wenn eine Kategorie keine Einträge hat.
Diese Buchungen sollen als Anhang an die nächste Seite.
In jeden Template können jeweils eine Bilddatei für Header und Footer hochgeladen werden. Außerdem sollen auch andere Bild-Dateien hochgeladen werden können, die im Template eingefügt werden können.

z.B.
```
Verwaltungseinheit
Beleuchtung
Datum1  Stadtwerke  Strom Haus  123,45
Datum2  Stadtwerke  Strom Haus  123,45
Sach- und Haftpflichtversicherung
Datum3  Versicherungsgesellschaft    1234,56

Verwaltungseinheit
Datum4  Stadtwerke  Strom Haus  123,45
Datum5  Stadtwerke  Strom Haus  123,45
Sach- und Haftpflichtversicherung
Datum6  Versicherungsgesellschaft    1234,56
Gebäudereinigung & Ungezieferbekämpfung
Datum7  Reinigungsgesellschaft    1234,56
```


Templates müssen kopierbar sein, auch die internen. Selbsterstellte Templates sollen Gruppen zugewiesen sein. In der Templateübersicht soll die Gruppe erkenntlich sein.

Die Templates sollen in der HTML Vorschau als auch in der PDF funktionieren. Am wichtigsten ist die PDF-Funktionalität.
Daher müssen folgende Formatierungsḿöglichkeiten existieren.
a) Position eines Containers absolut auf einer PDF-Seite.
b) Header und Footer zum Beginn und Ende einer Seite. Der Content muss sich dann dazwischen befinden und sinnvoll umgebrochen sein.
c) Content der sich zwischen Header und Footer befindet. Für den Content kann man einen Absoluten Start für die erste Seite bezogen auf den Seitenanfang angeben.

Es gibt ein Link auf eine Infoseite, auf der ein bisschen Blade-Syntax erklärt ist (Wie iteriere ich über ein Array, Befehle mit führenden @). Außerdem sollen auf der Seite auch die Variablen, die das System anbietet aufgelistet und beschrieben sein. Der Aufbau aller Variablen und aller einzelnen Elemente muss sichtbar sein und erläutert. Sonst ist der Anwender nicht in der Lage ein Template zu erzeugen.
Auch soll erläutert werden, wie man z.B. im Template rechnen kann uns sich Werte merken. Es muss auch gezeigt werden, wie man die Größe eines Arrays ermitteln kann.
Außerdem soll auf der Seite ein Button zur Verfügung stehen um eine Vorschau mit Fake Daten zu generieren. Dabei soll das aktuelle Template der Vorlage dienen.

In den Template müssen unter anderen folgende Daten zur Verfügung stehen:
- Gesamtfläche Verwaltungseinheit
- Gesamtfläche Verwaltungsgruppe
- Summe der Personenmonate pro Verwaltungseinheit
- Summe der Personenmonate pro Verwaltungsgruppe
- Mietzahlungsinformationen
  - Aktuelle Kaltmiete
  - Aktuelle Betriebskosten
  - Mieten für andere nicht abrechnungsrelevante Mieteinheiten (z.B. Garage, Stellplatz)
- Länge des Abrechnungsintervalls in Tagen
- Länge des releavanten Abrechnungsintervall für diesen Mietvertrag
- monatlichte Betriebskostenvorrauszahlungen und deren Summe
- Summe aller Ausgaben die der Verwaltungsgruppe zugeordnet ist, gruppiert nach abrechnungsrelevater Kategorie
- Summe aller Ausgaben die der Verwaltungseinheit zugeordnet ist, gruppiert nach abrechnungsrelevater Kategorie
- Summe aller Ausgaben die der Mieteinheit zugeordnet ist, gruppiert nach abrechnungsrelevater Kategorie
- Summe aller Ausgaben die dem Mieter zugeordnet ist, gruppiert nach abrechnungsrelevater Kategorie


Es soll ein Button geben "Template überprüfen". Dabei soll die Syntax und die Verwendeten Variablen überprüft werden. Wenn ein Fehler enthalten ist soll dem Anwender ein Hinweis gegeben werden.

### Statistik


### Steuerübersicht
Für jede Verwaltungsgruppe soll eine Übersicht für die Steuererklärung erstellt werden. Relevant sind an der Stelle die Buchungen des jeweiligen Jahres.
#### Mieteinnahmen
Es soll eine Pivottabelle für die Mieteinnahmen, Betriebskostenvorauszahlung und Betriebskostenrück-/-nachzahlungen gruppiert nach Etage (0, 1, 2, 3, >3).

|                                                       | Mieteinnahmen   | Betriebskostenvorrauszahlung  | Betriebskostennachzahlung /-rückzahlung |
| Summe                                                 | XXX €           | XXX €                         | XXX €                                   |
| Erdgeschoss                                           | 100€            | 1000 €                        | -100 €                                  |
|     Mietvertrag 1 <Beschreibung Mietobject> <Zeitraum>| 100€            | 1000 €                        | -100 €                                  |
| 1. OG                                                 | 500 €           | 300 €                         | 1800 €                                  |
|     Mietvertrag 2 <Beschreibung Mietobject> <Zeitraum>| 200€            | 100 €                         | 1000 €                                  |
|     Mietvertrag 3 <Beschreibung Mietobject> <Zeitraum>| 300€            | 200 €                         | 800 €                                   |
| ...                                                   | ...             | ...                           | ...                                     |

#### Werbungskosten
Zu jeder Kategorie kann ein die Information gesetzt werden, ob diese Steuerliche Relevanz hat. Im Standardfall ist dies der Fall. Die Zuweisung kann dann über die HMI geschehen.
Es gibt eine Tabelle mit allen Werbekosten (nicht Miete oder Betriebskostenvorauszahlungen) und die Summe der Kategorie. 


## Technische Umsetzung (aktueller Stand)

- **Framework:** Laravel (Blade), Datenbank: SQLite oder MariaDB.
- **Authentifizierung:** Laravel Breeze (Login, Registrierung, Logout, Passwort vergessen/zurücksetzen, Profil, E-Mail-Verifizierung optional). Alle App-Routen außer Startseite und Sprachumschaltung sind per auth-Middleware geschützt.
- **Startseite:** Gäste sehen eine Willkommensseite mit „Anmelden“ und „Registrieren“. Nach Login Redirect auf das Dashboard.
- **Mehrsprachigkeit:** Deutsch (Standard) und Englisch. Umschaltung über Route locale/{de|en} (Session), Middleware SetLocale. Übersetzungen in lang/de.json, lang/en.json sowie lang/de/auth.php, lang/de/validation.php. Da die App-Inhalte selbst überwiegend als deutsche Literal-Strings direkt in `__()` stehen, braucht `lang/de.json` dafür keinen eigenen Eintrag (der Literal-String ist bereits die deutsche Anzeige); `lang/en.json` muss dagegen für praktisch jeden dieser `__()`-Aufrufe eine Übersetzung enthalten, sonst zeigt die Oberfläche auch bei "Englisch" den deutschen Text. `tests/Feature/LocalizationTest.php` prüft das automatisiert: durchsucht app/resources/routes nach allen `__()`/`@lang()`-Aufrufen (Literal-Strings; dotted "group.item"-Referenzen wie "auth.password" ausgenommen, die laufen über die PHP-Array-Lang-Dateien statt über JSON) und schlägt mit Datei:Zeile fehl, sobald ein Aufruf keine Entsprechung in lang/en.json hat, sowie umgekehrt, sobald ein Eintrag in lang/de.json oder lang/en.json zu keinem tatsächlichen `__()`-Aufruf mehr passt (verwaister Eintrag – z. B. nach einer Umformulierung im Blade-Template).
- **E-Mail:** Speicherung und Abgleich von E-Mail-Adressen einheitlich in Kleinbuchstaben (User-Mutator + Normalisierung vor Validierung bei Login/Registrierung/Profil/Passwort-Reset).
- **Benutzer:** CRUD unter /users (nur eingeloggt). Beim Anlegen eines Benutzers (Registrierung oder Benutzerverwaltung) erstellt ein User-Observer automatisch eine Benutzergruppe und ordnet den Benutzer als Besitzer (admin) zu.
- **Ressourcen (CRUD):** Benutzergruppen, Benutzer, Verwaltungsgruppen, Verwaltungseinheiten, Mieteinheiten, Mieter, Mietverträge, Rechnungssteller, Rechnungen, Kostenkategorien, Kosten – jeweils mit Index, Erstellen, Bearbeiten, Anzeigen, Löschen wo fachlich vorgesehen.
- **Kostenkategorien – Unterkategorien:** Eine Kategorie kann per Name-Suffix (`CostCategory::SEPARATOR`, `"::"`) beliebig tief verschachtelt werden, z. B. "Hauswart::Winterdienst". Beim Anlegen wählt man dazu im Formular eine bereits existierende Kategorie (Haupt- oder Unterkategorie) als Überkategorie; `is_relevant_for_billing`/`is_tax_relevant` werden dann unabhängig vom übermittelten Formularwert immer von der Hauptkategorie (dem obersten Segment) übernommen – erzwungen serverseitig durch `CostCategoryObserver`, nicht nur im Formular. Ändert sich die Relevanz der Hauptkategorie, wird sie auf alle Unterkategorien (jeder Tiefe) kaskadiert. In der Kostenkategorien-Übersicht sind die beiden Haken bei Unterkategorien deshalb nur les-, nicht bearbeitbar.
- **Rechnungen:** Beim Anlegen und Bearbeiten einer Rechnung können Kostenzeilen (Kosten) mit Zuordnung (Verwaltungsgruppe, Verwaltungseinheit oder Mieteinheit), Abrechnungsintervall, Kategorie und Betrag gepflegt werden. Die zur gewählten Zuordnung passenden Abrechnungsintervalle werden serverseitig pro Zuordnung bereitgestellt (billingIntervalsByCostable) und im Formular per JavaScript dynamisch gefiltert.
- **Rechnungen – Intervall-\* (nur Testdaten-Seeder):** Eine wiederkehrende Rechnung (nicht "einmalig"/"händisch") kann in `seeddata.yaml` per "Intervall-\*" ihren Betrag und/oder ihre Zuordnung ab einem bestimmten Datum ändern, ohne die Wiederholung selbst zu unterbrechen (z. B. eine Lohnerhöhung innerhalb desselben Arbeitsvertrags) – `SeedsInvoicesAndCosts::resolveCostIntervals()`/`seedIntervalCostAllocations()`. Ein Betrag bzw. Anfang/Ende auf der Rechnung selbst ist dann nicht mehr erlaubt (ergibt sich aus dem ersten/letzten Intervall); die Intervalle müssen lückenlos aneinander anschließen; entweder hat jedes Intervall eine eigene Zuordnung oder keins (dann gilt die der Rechnung für alle) – alles serverseitig beim Seeden erzwungen. Eine optionale "Notiz" je Intervall landet auf `costs.note` der in diesem Zeitraum erzeugten Kostenzeilen (aktuell nicht in der Kosten-Tabelle/im Formular angezeigt).
- **Testdaten-Seeder – keine stillen Abbrüche:** Fehlt irgendwo im Seeder (Account, Sharegroup, Verwaltungsgruppe/Konto/Abrechnungsintervall, Verwaltungseinheit, Mieteinheit, Mietvertrag/Mietintervall, Mieter/Rechnungssteller/Kundenaccount/Vermieter, Template, Rechnung/Buchung/Forderung/Umbuchung) eine Pflichtangabe oder referenziert ein Eintrag etwas nicht Existierendes, bricht `TestDataSeeder` mit einer `\RuntimeException` ab, statt den betroffenen Eintrag (und alles darunter Verschachtelte) stillschweigend zu überspringen (Nutzerfeedback: "Niemals stillschweigend Daten überspringen" – zuvor verschwand z. B. eine Rechnung ganz ohne Meldung, wenn ihr "Rechnungsdatum" fehlte). Ausnahmen bleiben bewusste, gültige Konfigurationen bzw. Fälle, die nicht spurlos verschwinden: eine Rechnung ohne jede "Zuordnung" (weder rechnungsweit noch je Intervall – dann bewusst keine Kategorisierung); eine leere/fehlende `seeddata.yaml` selbst (Konzept.md "## Testdaten": "Wenn die Datei leer ist oder nicht existiert soll kein Fehler gebracht werden"); sowie eine "Zahlung", die in kein Mietintervall des Mietvertrags fällt (z. B. an einer Vorvertrag/Nachfolgevertrag-Grenze) – sie bleibt "offen" statt gebucht und ist über "Detailbild offene Zahlungen" auffindbar, statt den ganzen Seed-Lauf abzubrechen. Ein "Buchungen"-Wert, der kein YAML-Listenformat hat (z. B. "Buchungen: 3.1.2026" statt "Buchungen:\n  - 3.1.2026"), ist ebenfalls ein Fehler beim Seeden statt eines stillschweigend wie "kein Buchungen" behandelten Formatfehlers. Ebenso eine Rechnung ohne Intervall-\*, deren "Betrag" fehlt oder leer ist – das ergab bislang stillschweigend 0,00 € (samt jeder wiederkehrenden Periode, aber ohne jede Buchung).
- **Testdaten-Seeder – Rechnungs-Erkennung berücksichtigt die Beschreibung:** Der "schon vorhanden"-Abgleich beim Anlegen einer Rechnung (`seedInvoice()`) verglich bislang nur Rechnungssteller, Zuordnung (Verwaltungsgruppe/-einheit/Mieteinheit/Mietvertrag), Rechnungsdatum und Betrag – zwei rechnerisch identische, aber inhaltlich verschiedene Rechnungen (z. B. zwei gleich hohe Kontogebühren derselben Bank am selben Tag für unterschiedliche Personen) verschmolzen dadurch beim Seeden zu einer einzigen; die zweite Rechnung fand "sich selbst" (die erste) bereits vorhanden, ihre eigenen Kosten wurden nie erzeugt. Die Beschreibung ist jetzt Teil des Abgleichs.
- **Testdaten-Seeder – Account "From-File":** Ein `Account-*` kann statt seiner Felder direkt nur ein `From-File` mit dem Pfad einer eigenen YAML-Datei enthalten (analog zu Template und Buchungen) – `TestDataSeeder::resolveAccountFromFile()`, aufgelöst einmalig vor beiden Durchläufen über `$accountsByKey` (Account- und Sharegroup-Seeding), damit eine Sharegroup auch einen per `From-File` geladenen Account referenzieren kann. `From-File` muss der einzige Knoten unter `Account-*` sein (sonst Fehler beim Seeden); die referenzierte Datei kann die Account-Felder direkt enthalten oder in einem `Account`/`Account-*`-Wrapper.
- **Testdaten-Seeder – widersprüchliches "Abrechnungsintervall" über Jahresdateien hinweg:** "Abrechnungsintervall" auf einer Rechnung pinnt ALLE ihre Kostenzeilen auf ein einziges Abrechnungsintervall. Setzen zwei "Buchungen"-Jahresdateien für dieselbe Rechnung (per "Buchung-*"-Familie bereits schlüsselsicher zusammengeführt, siehe `mergeKeyFamily()`) je ein unterschiedliches "Abrechnungsintervall", bricht `mergeBuchungenData()` jetzt mit `\RuntimeException` ab, statt das der zuletzt gemergten Datei stillschweigend gewinnen zu lassen (was sonst auch die Buchungen der anderen Datei falsch mitgepinnt hätte).
- **Übersicht aller Rechnungen:** Analog zur "Übersicht Mietverträge" (`show_expired`) blendet `/invoices` erledigte Rechnungen standardmäßig aus, umschaltbar per `show_completed`-Query-Parameter. `Invoice::isFullyBooked()` definiert "erledigt": mindestens eine Cost-Zeile UND alle davon haben ein `booking_date`. Eine Rechnung ganz ohne Cost-Zeilen (z. B. eine künftige/manuelle Rechnung, deren erstes Vorkommen noch nicht erzeugt/gebucht wurde) gilt bewusst nicht als erledigt und bleibt sichtbar. Der Filter läuft in SQL (`whereDoesntHave`/`whereHas` auf `costs`), damit `paginate()` weiterhin über die tatsächliche Gesamtzahl arbeitet. Spalten wie spezifiziert (Rechnungsdatum, Buchungsdatum-oder-beginn, Buchungsende, Rechnungssteller, Beschreibung, Betrag, Periodizität, Zugeordnet zu); `Invoice::bookingRangeStart()`/`bookingRangeEnd()` liefern bei einer einmaligen Rechnung schlicht ihr eigenes `booking_date` (Ende dabei immer `null`, ein einzelnes Datum hat keinen Zeitraum), bei einer wiederkehrenden das früheste/späteste tatsächlich gebuchte (`booking_date !== null`) ihrer Cost-Zeilen – noch offene Vorkommen zählen nicht mit.
- **Tests:** Feature-Tests (Dashboard, Auth, Registrierung, Profil, Passwort-Reset/-Update/-Confirm, E-Mail-Verifizierung) und Unit-Tests (u. a. CostCategory, Invoice). CostCategorySeeder legt die genannten Kostenkategorien an.
- **Templates (Betriebskostenabrechnung):** Vorlagen für die Betriebskostenabrechnung können angelegt und bearbeitet werden (CRUD). Jeder Verwaltungsgruppe kann ein Standard-Template zugeordnet werden. Die Abrechnung pro Verwaltungseinheit/Abrechnungsintervall mit PDF-Export ist vorgesehen (Blade-basiert).
- **Wartungsverträge:** Wartungsverträge (z. B. Rauchwarnanlage, Belüftung) können pro Verwaltungsgruppe mit Name, Typ, Anbieter und Laufzeit erfasst werden (CRUD).
- **Template-Variable `$bookingsByLevel`:** `BillingStatementController::bookingsByAssignmentLevel()` sammelt alle bereits gebuchten (`booking_date` gesetzt), abrechnungsrelevanten Kostenzeilen aller vier Stufen (Verwaltungsgruppe, Verwaltungseinheit, jede Mieteinheit, jeder in diesem Abrechnungsintervall relevante Mietvertrag) und gruppiert sie Ebene → Kategorie → Buchungen (Datum/Empfänger/Beschreibung/Betrag), in fester Stufen-Reihenfolge; eine Ebene bzw. Kategorie ohne Buchung fehlt als Schlüssel, statt als leere Liste zu erscheinen. `Cost::assignmentLevelLabel()` liefert dafür den reinen Ebenennamen (ohne Instanzdetail, anders als `costableLabel()`). Auf der Einzelabrechnung eines Mietvertrags enthält die Ebene „Mietvertrag" bewusst nur dessen eigene individuelle Buchungen (Datenschutz: kein anderer Mieter). Im Standard-Template unter „Anlage: Buchungsübersicht" gerendert, auf einer eigenen Seite (`page-break-before`), wie gefordert; ebenso neu im Standard-Template: „Monatliche Nebenkosten" über die bereits vorhandene, bisher ungenutzte Variable `$costsByMonth`. Für „Template überprüfen"/die Testdaten-Vorschau liefert `FakeBillingStatementData` passende Beispieldaten.
- **Leerstand als 0-Personenhaushalt (Redesign):** Konzept.md "##### Leerstände": "Berechnet werden diese mit einem 0 Personenhaushalt. Ansonsten werden sie wie ein Mieter gehandhabt." Löst die frühere separate Leerstand-Übersicht mit eigener Ergänzungsrechnung ab (siehe Changelog): `BillingCostAllocationService::vacancyLeaseEntries()` baut pro Leerstands-Lücke (siehe `vacancyGaps()`) einer abrechnungsrelevanten Mieteinheit einen nie persistierten "Phantom-Mietvertrag" und lässt ihn durch dieselbe `allocateForLease()`-Pipeline laufen wie einen echten – eine nicht gespeicherte `Lease` liefert für jede darauf angewiesene Relation (`leaseIntervals`, `tenants`, `costs`) automatisch eine leere Collection/Query (Fremdschlüssel-Wert `null` → Eloquent baut daraus ein nie treffendes `whereNull`), wodurch Personenmonate = 0 und individuelle Kosten = 0 von selbst herauskommen, ohne jeden Sonderfall in `allocateForLease()`. Fläche- und Mieteinheit-Schlüssel bleiben dagegen real. Diese Einträge werden `BillingStatementController::show()`/`pdf()` (der KOMBINIERTEN Abrechnung, nicht der Einzelabrechnung/dem ZIP) mit `isVacancy: true` beigemischt und erscheinen im Standard-Template als eigener Abschnitt ohne Anschreiben ("Leerstand" statt Mietername), mit eigenem Kostenanteil je Stufe/Kategorie und – Konzept.md: "Zu diesem Leerstand müssen auch Forderungen ... verbucht werden" – ihren verbuchten `SettlementClaim`s (Forderung mit `rental_unit_id` statt `lease_id`, erzwungen in `SettlementClaimController::resolveOwner()`, nie beides/nie keines, nicht per DB-Constraint). Die Verwaltungseinheiten-Seite zeigt unter "Leerstand" nur noch die Zeiträume plus Forderungsverwaltung (Anlegen/Löschen); die eigentliche Kostenberechnung findet direkt in der echten Betriebskostenabrechnung statt, reconciled dadurch per Konstruktion exakt (keine separate Ergänzungsrechnung mehr nötig).
- **Berechnung neue Vorauszahlung – Glättung:** `BillingCostAllocationService::suggestedMonthlyUtilityAdvance()` rundet den rohen Vorschlag weiterhin auf volle 10 Cent/m² auf, prüft ihn danach aber gegen die bisherige Vorauszahlung (Konzept.md "##### Berechnung neue Vorauszahlung"): läuft der Mietvertrag noch keine 3 Monate (gemessen vom Vertragsbeginn bis zum Ende des Abrechnungsintervalls), bleibt die bisherige Vorauszahlung unverändert; sonst ändert sie sich nur bei einer Reduzierung unter 90 % oder einer Erhöhung über 105 % der bisherigen – innerhalb dieser Bandbreite bleibt sie ebenfalls unverändert, um triviale Anpassungen zu vermeiden.
- **Rechnungen finden:** `/invoices` hat einen aufklappbaren Suchdialog (Konzept.md "#### Rechnungen findem") mit den spezifizierten Kriterien: Rechnungssteller (und dessen Accounts, `invoice_issuer_id`/`invoice_issuer_account_id`), Rechnungsdatum-Zeitraum, Betragsbereich, Kategorie (`whereHas('costs', ...)`, da eine Rechnung über ihre Kostenzeilen mehrere Kategorien haben kann), Beschreibung (Teilstring), Buchungsdatum-Zeitraum (sowohl die Rechnung selbst als auch jede ihrer Kostenzeilen), ob ein Beleg (Datei) vorhanden ist, sowie die Rechnungsnummer (ebenfalls Teilstring). Per `AskUserQuestion` geklärt: statt eines vollen, verschachtelten UND/ODER-Ausdrucksbaums verknüpft ein globaler Schalter (`match=all|any`) alle ausgefüllten Kriterien einheitlich; `InvoiceController::applySearch()` baut dafür eine Liste von Query-Closures und kombiniert sie je nach Schalter mit `where()` oder `orWhere()`.
- **Individuelle Kosten nicht mit der Mietdauer skaliert:** Konzept.md "#### Berechnung": "Nur die Kosten mit individueller Abrechnung auf einen Mieter werden nicht mit der Mietdauer skaliert. Diese werden 1:1 übernommen." `BillingCostAllocationService::allocateForLease()`s Stufe 4 (Mietvertrags-Ebene, Abrechnungsschlüssel "individual") übergibt `allocateLevelCosts()` deshalb keinen `$timeFactor` mehr (Default 1.0), anders als die drei anderen Stufen – eine individuell zugewiesene Kostenzeile (z. B. ein von diesem Mieter verursachter Schaden) gehört ihm in voller Höhe, unabhängig von seinem Zeitanteil am Abrechnungsintervall.
- **"Wohnfläche (Verwaltungsgruppe)"/"Wohneinheiten (Verwaltungsgruppe)" nur bei tatsächlichen Stufe-1-Kosten:** Im Standard-Template stehen diese beiden Info-Tabellen-Zeilen sowie die vier Stufen-Kostentabellen ("Verwaltungsgruppe", "Objekt", "Mieteinheit", "Individuell") hinter `@if (abs($entry['allocatedCosts']['byStage'][...]['total']) > 0.001)` – ohne abrechnungsrelevante Buchungen auf der jeweiligen Ebene ist der Wert für diesen Mietvertrag irrelevant und wird nicht angezeigt. Bewusst `abs(...) > 0.001` statt eines bloßen `> 0`: eine Gutschrift/Rückerstattung auf dieser Ebene ergibt einen negativen Stufen-Anteil, der bei einem reinen `> 0`-Vergleich fälschlich als "keine Kosten" gegolten und die Zeile/Tabelle trotz echter, ungleich-null Buchungen ausgeblendet hätte; `0.001` statt eines exakten `!= 0` fängt zusätzlich Rundungsrauschen ab. "Wohnfläche (Verwaltungseinheit)"/"(Mietvertrag)" bleiben unbedingt sichtbar.
- **DIN-5008-Fensterumschlag + höhenrobuste Kopf-/Fußzeile:** `BillingTemplateRenderer::renderPdf()` setzt mPDFs `setAutoTopMargin`/`setAutoBottomMargin` auf `stretch` – eine Kopf-/Fußzeile, die höher ist als der im Template deklarierte `@page`-margin, schiebt den Content-Bereich automatisch weiter, statt ihn zu überlappen. Das Standard-Template positioniert Absenderzeile + Anschrift zusätzlich als eigenes `.address-window` absolut auf den DIN-5008-Koordinaten (20mm/27mm, 85mm x 45mm), unabhängig von einer variabel hohen Kopfzeile.
- **`BillingTemplate.css_content` (Formatierung getrennt vom Content):** Konzept.md "### Template": "Der Content soll von der Formatierung getrennt sein. Damit lassen sich Templates besser kopieren, wenn Anpassungen gemacht werden müssen." Neue optionale Spalte `css_content` (in der bestehenden `billing_templates`-Basismigration ergänzt, keine neue Migration). `BillingTemplateRenderer::render()`/`renderPdf()` führen Content und `css_content` beim Rendern zusammen (Konzept.md: "Beim Erstellen der Betriebskostenabrechnung muss dann der Content und die Formatierung zusammen gebracht werden.") – bei `render()` (HTML-Vorschau) wird die (Blade-gerenderte) CSS in den `<head>` eingefügt, bei `renderPdf()` mit einer eventuell im Content selbst noch vorhandenen `<style>` (Altbestand) zusammengeführt, bevor beides an mPDF geht. `BillingTemplateController` (store/update/preview/check/duplicate) sowie `SeedsBillingTemplates` (`[CSS-Content]` in der Testdaten-YAML, analog zu Header/Content/Footer) unterstützen das neue Feld durchgängig; das Standard-Template selbst nutzt es bereits (`BillingTemplateSeeder::defaultCss()`).

Geplant bzw. in Ausarbeitung: Auslastungs- und Cashflow-Übersichten ("### Statistik"). Belege gibt es inzwischen für Rechnungen, Forderungen und Umbuchungen; Mietzahlungen haben keinen eigenen Beleg.

Weitere Sprachen und erweiterte Berechtigungslogik (z. B. sichtbare Daten abhängig von Benutzergruppe) können auf dieser Basis ergänzt werden.

## UI
### Themes
Jeder Benutzer kann ein Theme auswählen. Der Default-Zustand soll ein ansprechendes, zeitgemäßes Design sein.
Themes sollen auch über eine Konfiguration (css) erstellbar sein.
Es soll ein optionales "Matrix"-Theme in NCurse-Design mit hell- und dunkelgrünen Vordergrund sowie schwarzen (hauptsächlich) und grauen(akzente) Hintergrund sein.
Außerdem soll ein Theme enthalten sein, der an Norton Commander erinnert.
Bau das Theme-Management so, dass jeder Nutzer sein eigenen Theme erstellen kann. In dem Dialog sollen die bereits vorhandenen CSS Elemente aufgeführt sein.
Außerdem soll ersichtlich sein, welche nicht berücksichtigt sind, weil z.B. nach der Theme Erstellung weiter CSS Elemente dazu gekommen sind.
Themes können auch kopiert werden. So kann man ein internes Theme adaptieren.

## Testdaten
Erstelle einen Seeder, der die Datei seeddata.yaml ausliest und die Datenbank mit den Daten füllt. Wenn die Datei leer ist oder nicht existiert soll kein Fehler gebracht werden.
Die yaml Datei hat folgenden Aufbau.
Attribute, die in eckigen Klammern sind sind optional. Attribute, die ein "*" im Namen haben können an der Stelle beliebig oft vorhanden sein. Beliebige Werte sind mit einen leeren String markiert.
Wenn nur bestimmte Werte möglich sind, werden diese durch einen senkrechten Strich "|" von einander getrennt. Beliebige Zahlenwerte werden durch eine 0 gekennzeichnet. Nicht vorhandene Werte durch "null".
Optional kann auch eine Rechnung angeben werden. Dies ist ein relativer Pfad zu einer Datei, die dann hochgeladen wird und der Rechnung zugeordnet wird.

```yaml
lang: "de"

[Account-*]:
  Name: ""
  Passwort: ""

  Vermieter-*:
    [Firma]: null | ""
    [Name]: ""
    Straße: ""
    PLZ: 01234
    Ort: ""
    [IBAN]: ""
    [BIC]: ""


  [Verwaltungsgruppe-*]:
    [Beschreibung]: ""
    [Kontosystem]: false | true

    [Vermieter-*]: # Aufbau wie eine Ebene höher

    [Intervall-*]:
      Start: Datum
      Ende: Datum
      [Beschreibung]: ""

    [Konto-*]:
      Name: "Defaultkonto"
      [Beschreibung]: ""
      [Initial]: 0.00
      [Default]: false | true

    [Verwaltungseinheit-*]:
      Straße: ""
      PLZ:  0
      Stadt: ""
      [Beschreibung]: ""

      [Mieteinheit-*]:
        Typ: "Wohnung" | "Garage" | "Stellplatz"
        Größe: null | 0
        [Beschreibung]: ""
        Abrechnungsrelevant: true | false
        Etage: null | 0

        [Mietvertrag-*]:
          [MieterId]: ""
          [MieterId-*]: ""
          [Mietvertrag]: ""
          Beginn: Datum
          [Ende]: null | Datum
          Mietpreistyp: "Fix" | "Staffel" | "Index"
          Kaltmiete: Betrag
          Nebenkosten: Betrag
          [Kaution]: Betrag
          [MinLaufzeit]: 0
          [Notiz]: ""
          Kundigungsfrist: ""
          [Vorvertrag]: false | true

          [Intervall-*]:
            [Beginn]: Datum
            Ende: null | Datum
            [Kaltmiete]: Betrag
            [Nebenkosten]: Betrag
            Personen: 0
            [Notiz]: ""

  [Mieter]:
    [Mieter-*]:
      [Firma]: ""
      Anrede: "" | "Herr" | "Frau"
      [Vorname]: ""
      Name: ""
      Straße: ""
      PLZ: 0
      Ort: ""
      [Email]: ""
      [Mobilfunk]: ""
      [IBAN]: ""
      [BIC]: ""

  [Rechnungssteller]:
    [Rechnungssteller-*]:
      [Firma]: ""
      [Anrede]: "" | "Herr" | "Frau"
      [Vorname]: ""
      [Name]: ""
      [Straße]: ""
      [PLZ]: 0
      [Ort]: ""
      [Email]: ""
      [Mobilfunk]: ""
      [IBAN]: ""
      [BIC]: ""
      [Notiz]: ""

      [Kundenaccount-*]:
        Kundenummer: 0
        [Beschreibung]: ""
        Lastschrift: false | true
        [Notizen]: ""

  [Rechnungen]:
    [Verwaltungsgruppe-*]:
      [Verwaltungseinheit-*]:
        [Mieteinheit-*]:
          [Mietvertrag-*]:
            [Rechnung-*]:
              Rechnungssteller: Rechnungssteller-*
              Account: Kundenaccount
              [Abrechnungsintervall]: Intervall-*
              [Rechnung]: Pfad zur Datei 
              Rechnungsdatum: Datum
              Buchungsdatum: null | Datum
              Rechnungsnummer: ""
              Betrag: 0,00€
              [Betragsüberprüfung]: true | false
              Beschreibung: ""
              [Anfang]: Datum
              [Ende]: Datum
              Wiederholung: "einmalig" | "jährlich" | "quartal" | "monatlich" | "2-wöchentlich" | "wöchentlich"
              [Zuordnung]:
                Betrag-1: 0,00
                Kategorie-1: ""
                Betrag-n: 0,00
                Kategorie-n: ""
              [Intervall-*]:
                Anfang: Datum
                [Ende]: Datum
                Betrag: 0,00€
                [Notiz]: ""
                [Zuordnung]:
                  Betrag-1: 0,00
                  Kategorie-1: ""
                  Betrag-n: 0,00
                  Kategorie-n: ""



              [Buchungen]: # Einfache Buchungen
                - Datum 1 
                - Datum 2
                - Datum n
              [Buchung-*]:  # Buchung mit einer Kategorie, aber ändernden Betrag
                Datum: Datum
                Betrag: 0,00€
              [Buchung-*]:  # Buchung, wenn sich die Aufteilung im zeitlichen Verlauf ändert.
                Datum: Datum
                Betrag-1: 0,00
                [Kategorie-1]: ""
                Betrag-n: 0,00
                [Kategorie-n]: ""
                  


      [Rechnung-*]:
        ... # Rechnung für die Verwaltungseinheit
    [Rechnung-*]:
      ... # Rechnung für die Verwaltungsgruppe

  [Buchungen]:
    from-File: ""

  [Kategorien]:
    [Kategorie-*]:
      Name: ""
      Abrechnungsrelevant: true | false
      [Abrechnungsschlüssel]: null | "Personen" | "Fläche" | "Individuell" | "Mieteinheit"
      [Steuer]: true | false


  [Group-*]:
    Name: ""
    [Admins]:
    [Users]:
      - Account-*
    Groups:
      - Verwaltungsgruppe-*

    [Mieter]:            # Aufbau wie auf Account-Ebene; gehören dieser Gruppe
    [Rechnungssteller]:  # Aufbau wie auf Account-Ebene; gehören dieser Gruppe
    [Kategorien]:        # Aufbau wie auf Account-Ebene; gelten für die Verwaltungsgruppen unter Groups

    [Templates]:
      [Template-*]:
        Name: ""
        [Standard]: false | true
        [Image]: LOCAL_PATH
        [Header]: ""
        [CSS-Content]: ""
        [Content]: ""
        [Footer]: ""
      [Template-*]:
        [Name]: ""
        From-File: ""
        [Standard]: false | true

[Account-*]:
  From-File: ""
```
### Allgmein
Das Attribut "lang" beschreibt die Sprache. Gültige werte sind "de". Dies definiert die Namen/Sprache der folgenden Attibute.
Dann kann eine Unterschiedliche Anzahl (>= 0) an Accounts kommen. Diese Accounts sind durch Name und Passwort definiert.

### Account
Innerhalb des Accounts können Gruppen (>=0) angelegt werden. Die Gruppen haben einen Namen.
Der Ersteller ist automatisch Admin und muss unter der Auflistung der Admins nicht genannt werden.
Es können beliebig viele Admins eingetragen werden. Benutzer mit der Berechtigung "User" werden unter Users eingetragen.
Admins können eigene Verwaltungsgruppen hinzufügen. Die eingefügten Verwaltungsgruppen werden unter Groups aufgelistet. Es muss mindestens eine Verwaltungsgruppe angegen werden.
Eine Verwaltungsgruppe steht unter "Groups" höchstens einer Gruppe; sie wird gleich in dieser Gruppe angelegt.
Mieter, Rechnungssteller und eigene Kategorien auf Account-Ebene gehören zur persönlichen Gruppe. Die einer Verwaltungsgruppe unter "Groups" stehen unter der Gruppe selbst (siehe "### Sichtbarkeit"). Die Nummern (z. B. Mieter-3) gelten im ganzen Account und dürfen nur einmal vorkommen. Ein Abrechnungsschlüssel einer Kategorie gilt nur für die Verwaltungsgruppen, in denen sie verwendet werden darf. Verwendet eine Verwaltungsgruppe Stammdaten einer anderen Gruppe, bricht der Seed mit einer Fehlermeldung ab.
Unter einer Gruppe sind nur die oben gezeigten Einträge erlaubt; Verwaltungsgruppen, Rechnungen und Buchungen stehen auf Account-Ebene. Ein anderer Eintrag bricht den Seed ab, statt still übergangen zu werden.
Die eingebenen Benutzer müssen einen Account haben.

Die Daten eines Accounts sollen auch über eine Datei-Referenz eingelesen werden können.
Dies soll es übersichtlicher machen unterschiedliche Accounts zu seeden. Dazu gibt es unter der Ebene "Account" noch eine Ebene "From-File". Diese hat als Eigenschaft den Dateinamen der importiert werden soll. (Vergleiche Template und Buchungen).
Es muss überprüft werden, dass, wenn "From-File" vorhanden ist, es der einzige Knoten unter "Account" ist.


### Verwaltungsgruppen
Innerhalb diesen Accounts kann es beliebig viele Verwaltungsgruppen (>=0) geben. Diese Verwaltungsgruppe kann eine Beschreibung haben.
Innerhalb dieser Verwaltungsgruppe können beliebig viele Konten abgebilet werden.
Die Eigenschaft "Kontosystem" schaltet das Kontosystem für diese Verwaltungsgruppe an bzw. aus (default).

### Abrechnungsintervalle
Innerhalb der Verwaltungsgruppe kann ein Abrechnungsintervall Intervall-* definiert werden. Dies hat ein Start- und Enddataum sowie eine optionale Beschreibung.

### Verwaltungseinheit
Diese Verwaltungseinheit hat eine Straße, PLZ und Stadt. Optional auch eine Beschreibung.
Innerhalb dieser Verwaltungseinheit kann es beliebig viele Mieteinheiten geben.

### Mieteinheit
Innerhalb einer Mieteinheit können beliebig viele Mietverträge (>= 0) angelegt werden.
Ein Mietvertrag verweist über MieterId (bzw. weitere MieterId-*) auf die Nummer(n) der zugehörigen Mieter-* Einträge dieses Accounts. Er hat zwingend ein Beginn-Datum, optional ein Ende-Datum.
Es gibt die Option "Vorvertrag". Diese kann nur Möglich sein, wenn es nicht der letzte Mietvertrag für diese Mieteinheit ist. Der Defaultwert beim seeden ist false.
Unterhalb eines Mietvertrages können unterschiedliche Intervalle angelegt werden.
Einer Mieteinheit kann eine Etage zugeordnet werden. Wenn sie nicht Null ist muss eine eine ganze Zahl sein.

### Mieter
Innerhalb dieses Accounts sind die Mieter (>= 0) gespeichert. Diese Mieter haben einen Vornamen, Namen, eine Adresse bestehend aus Straße, Ort, PLZ.
Optional haben sie eine IBAN und eine BIC.

### Mietvertrag
Ein Mietvertrag wird entweder durch eine (MieterId) oder mehrere MieterId (MieterId-*) oder durch eine Referenz auf einen Mietvertrag referenziert. Eine Mieteinheit, die abrechnungsrelevant ist darf nur durch MieterId referenziert werden. Wenn keines der beiden Sachen angegeben ist wird beim Seeden ein Fehler generiert.

Mit der unterlagerten Referenz auf auf einen anderen Mietvertrag darf nur eine nicht abrechnungsrelevante Mieteinheit auf eine Abrechnungsrelevante Mieteinheit gekoppelt werden.
Die Referenz des Mietvertrages ist eine Zeichenkette mit den Aufbau <Verwaltungsgruppe>.<Verwaltungseinheit>.<Mieteinheit>.<Mietvertrag>. Die durch Punkt getrennten Segmente können weg gelassen werden, so lange es eindeutig bleibt. Das heißt zum Beispiel: Wenn der zu referenzierende Mietvertrag in der selben Verwaltungseinheit ist, dann lautet die Referenzierung <Mieteinheit>.<Mietvertrag>.

Es gelten folgende Einschränkungen:
a) Es kann nicht auf eine andere Verwaltungseinheit referenziert werden.
b) Es kann nicht auf einen anderen Mietvertrag in der selben Mieteinheit referenziert werden.

Diese Bedingungen müssen beim Seeden überprüft werden.

Wenn ein Mietvertrag über eine Mietvertragsreferenz an einen anderen Mietvertrag gekoppelt ist, dann wird der gekoppelte Mietvertrag in den Referenzierten Mietvertrag mit eingerechnet. Das heißt zum Beipiel, dass ein Stellplatz in der Steuerübersicht nicht seperat aufgelistet wird, sondern die Mieteinnahmen und Nebenkosten werden dann summiert.
Eine Buchung ist dann für diesen Mietvertrag nicht nötig, sondern wird mit der Buchung im referenzierten Mietvertrag verbucht. 
Die Intension ist, dass der Mieter nur einmal Miete überweißt und so ist der Wiedererkennungswert der Buchungen besser.
Genierie bitte Fehlermeldungen, wenn Buchungen auf ein referenzierenden Mietvertrag gebucht werden.


### Rechnungssteller
Innerhalb dieses Accounts werden auch Rechnungssteller gespeichert. Auf diese werden in Rechnungen referenziwert.
Beim Rechnungsteller muss entweder Firma oder Name angegeben sein.

### Rechnungen
Außerdem werden alle Rechnungen aufgelistet. Unterhalb der Rechnungen existiert die Abrechnungsstruktur erneut. Je nach Ebene der Referenzierung erhält die Rechnung ihren Wirkungsrahmen. Also wird diese für die ganze Verwaltungsgruppe oder nur für die Verwaltungseinheit bzw. Mieteinheit oder Mietvertrag (nur für individuelle Abrechnungsschlüssel) angerechnet.
Unterhalb der Rechnungen kann eine Auflistung der Buchungen existieren, wenn es eine regelmäßige Buchung ist. Die einfache Form ist ein Array von Daten, an dem die Buchung statt fand. Gebucht wird dann der Rechnungsbetrag. Wenn eine Zuordnung existiert wird diese auch mit gebucht. Der Knoten "Buchungen" ist ungültig, wenn es eine einmalige Rechnung ist.
Ist nur eine Zuordnung vorgesehen können Buchungen über den Knoten "Buchung-*" eingegeben werden. Zwingend an dieser Stelle ist das Datum und der Betrag.
Wenn mehrere Kategorien verwendet werden, werden in den Knoten "Buchung-*" zusätzlich zum Datum auch jeweils der Betrag und die Kategorie mit angegeben. Betrag-n und Kategorie-n gehören zusammen. Sind im Knoten "Zuordnung" die Kategorien angegeben, dann bezieht sich Buchung-*.Betrag-1 auf Zuordnung.Kategorie-1.
Eine Rechnung kann die Eigenschaft Abrechnungsintervall habe. Ist dieses nicht angegeben, dann wird immer das letzte genommen. Ansonsten kann auf die Abrechnungsintervalle die in der Verwaltungsgruppe definiert sind verwiesen werdem.

Die referenzierten Ebenen müssen auch oben existieren.
Der Aufbau einer Rechnung ist unabhängig der Ebene.
[CSS-Content:]
Wenn bei der Zuordnung der Kosten kein Betrag genannt ist sollen trotzdem schon die Kategorien angelegt werden.

Der Eintrag "Betragsüberprüfung" handelt den die Eigenschaft "disable_amount_cap". Wenn die Eigenschaft nicht existiert ist der Defaultwert true und die Eigenschaft "disable_amount_cap" false. Es ist also negiert.
Wenn der Eintrag "Betragsüberprüfung" true ist, dann wird überprüft ob die Summe der Kategorien den wirksamen Rechnungsbetrag ergibt. Wenn nicht gibt es beim Seeden ein Fehler.

Die Eigenschaft Anfang und Ende sind nur bei periodischen Intervallen notwendig. Diese bestimmen den Gültigkeitsbereich dieser Periodizität an.

Ähnlich wie bei einen Mietvertrag können auch Intervalle definiert werden. Als Beispiel wäre hier ein Arbeitsvertrag zu nennen. So kann bei dem selben Arbeitsvertrag eine Lohnerhöhung gezahlt werden.
Das Intervall ist optional. Wenn ein Intervall angegeben ist, darf kein Betrag innerhalb der Rechnung angegen sein.
Zwingend muss der Betrag und das Anfangsdatum angegeben werden. Die Intervalle dürfen nicht lückend sein. Mit einen neuen Intervall kann sich die Zuordnung ändern. Wenn in der Rechnung eine Zuordnung angegeben ist darf im Intervall keine Zuordnung erfolgen und umgekehrt.
Wenn Intervalle verwendet werden, dann entfallen auch die Elemente Anfang und Ende. Diese Ergeben sich aus den Intervallen.


### Kategorie
Unterhalb des Elements gibt es Kategorien. Die haben einen Namen und sind entweder abrechnungsrelevant oder nicht.
Wenn sie Abrechnungsrelevant sind müssen sie einen Abrechnungsschlüssel zugewiesen haben.
Wenn der Name schon existiert wid der Abrechnungsschlüssel überschrieben.
Desweiteren hat eine Kategorie die Information, ob diese steuerliche Relevanz hat.

### Template
Templates werden innerhalb einer Gruppe definiert. Da Templates schnell sehr groß werden können, können diese auch in separate Dateien definiert werden.
Die Datei wird dann Definiert durch "From-File". Der Name in der Datei kann überschrieben werden, wenn die Option "Name" angegeben ist. Auch die Option "Standard" kann überschrieben werden.
Für die Fälle, dass das Template nicht in einer seperaten Datei abliegt können Header, Footer, Content und Bild angegebn werden. Der Content soll von der Formatierung getrennt sein. Damit lassen sich Templates besser kopieren, wenn anpassungen gemacht werden müssen. Beim Erstellen der Betriebskostenabrechnung muss dann der Content und die Formatierung zusammen gebracht werden.        
Die optionale Eigenschaft "CSS-Content" beinhaltet Formatierungsanweisungen.

### Buchungen
Optional gibt es die Möglichkeit auch Buchungen in eine externe Datei auszulagern um die Seed Datei zu verringern. Die Syntax ist folgend aufgebaut.
Unterhalb des Elements "Buchungen" sind die unterschiedlichen Ebenen angesetzt zu der eine Buchung gehört. Es wird dann zwischen Rechnungen (Forderungen dritter == Ausgaben) und Zahlungen (Mietzahlungen oder Betriebskostenvorauszahlungen bzw. Betriebskostenrückzahlungen/-nachzahlungen) unterschieden.
Buchungen können sowohl in der Hauptdatei als auch in der importierten Datei notiert sein.

```yaml
Buchungen:
  [Verwaltungsgruppe-*]:
    [Verwaltungseinheit-*]:
      [Mieteinheit-*]:
        [Rechnung-*]:
            # Zuordnung zu einer Rechnung (einer Mieteinheit) gemäß dem standard Seeder
        [Mietvertrag-*]:
          [Rechnung-*]:
            # Zuordnung zu einer Rechnung (eines Mietvertrages) gemäß dem standard Seeder
          [Zahlungen]: # Zahlungen eines Seeders
          [Forderung-*]:
            Abrechnungsintervall: Intervall-* # Pflichtangabe, damit sich die Nebenkosten dieses Intervalls später nachvollziehen lassen
            Datum: Datum
            Betrag: 0,00€ # positiv = Nachzahlung, negativ = Rückzahlung
            [Buchungsdatum]: null | Datum
            [Notiz]: ""

    [Rechnung-*]:
      # Zuordnung zu einer Rechnung (einer Verwaltungsgruppe) gemäß dem standard Seeder
    [Umbuchung-*]:
      Von: Konto-n
      Nach: Konto-m
      Datum: Datum
      Betrag: x,xx €
      [Notiz]: ""

```

Eine "Forderung" bildet eine Betriebskostennachzahlung bzw. -rückzahlung eines Mietvertrages ab (positiver Betrag = Nachzahlung, negativer Betrag = Rückzahlung) und wird als eigener, buchbarer Vorgang gespeichert – nicht nur als Momentaufnahme des aktuell berechneten Abrechnungssaldos. Das Abrechnungsintervall ist dabei eine Pflichtangabe (Referenz auf ein in der Verwaltungsgruppe definiertes Intervall-*), damit sich die zugrunde liegenden Nebenkosten dieses konkreten Abrechnungszeitraums auch später noch nachvollziehen und überprüfen lassen. Ist kein Buchungsdatum angegeben, gilt die Forderung als noch offen.

Eine "Umbuchung" bildet eine buchungsneutrale Verschiebung von Geld zwischen zwei Konten derselben Verwaltungsgruppe ab (siehe "Kontosystem"). "Von" und "Nach" referenzieren je ein in der Verwaltungsgruppe definiertes Konto-*; beide müssen zu dieser Verwaltungsgruppe gehören und dürfen nicht dasselbe Konto sein. Datum und Betrag sind Pflichtangaben.


## Administation
### Admin-Account
Lege automatisch einen Admin Account mit einen leeren Passwort an. Nach dem ersten Einloggen muss das Passwort entsprechend den Passwort-Policies geändert werden.


## Backup-Konzept
Es soll möglich sein, dass jeder Benutzer seine Daten exportieren und importieren. Die kann zum Beispiel eine Zip-Datei sein.
Das beinhaltet
- Profileinstellungen
- Alle Gruppen-Einstellungen
- Alle von ihm verwaltete Verwaltungsgruppen (und die Struktur darunter). Das heißt, alle Gruppen von den der Benutzer Admin ist.
- Alle angehängten Dateien
  - Rechnungen
  - Mietverträge
  - ...

Der Administrator kann alle alle Einstellungen exportieren. Beim Importieren müssen die Berechtigungen wieder hergestellt werden.

## Dokumentation
### Bedienungsanleitung
Es soll automatisch eine HTML Dokumentation für den Benutzer erzeugt werden. In dieser sollen auch Screenshots enthalten sein und Handhabung der Software in einzelnen Schritten beschrieben.
Die Screenshots erzeugt `php artisan docs:screenshots` aus frei erfundenen Beispieldaten (eigene Datenbank im Speicher, Chromium im Headless-Modus); sie liegen unter `resources/docs/screenshots`.
#### Erstellung der testdata.yaml
Es soll erläutert werden, wie der Anwender die Datei zum Seeden der Datenbank erstellen kann.
#### Erstellung eines Themes
Es soll erläutert werden, wie der Anwender ein Theme erstellen kann? Welche css Einträge bedeuten was? Woher weiß der Anwender an welcher Stelle welches CSS Element verwendet worden ist?s

### Quellcode Dokumentation
Der Quellcode soll automatisch für Doxygen kommentiert werden.

## Build-System
### Docker
Zum Bauen eines stabilen Systems soll auch eine Docker-Compose erstellt werden.

## Datenvalidierung
### IBAN
Bei der Validierung der IBAN soll überprüft werden ob die IBAN gültig ist. Das heißt es soll überprüft werden ob die Checksumme in der IBAN passt.

## Software-Test
Erzeuge Automatiktests mit einer Codeabdeckung von mindestens 95%. Beachte dabei die Grenzfälle.
### Testfälle
Es müssen für die folgenden Punkte Tests erstellt werden.
#### Rechnungen
- Innerhalb einer Rechnung dürfen nicht unterschiedliche Buchungsschemas gemixt werden können.

## API
Erstelle eine REST-API für die oben genannten Anforderungen, mindestens aber
- Ein- und Ausloggen
- Berichte erstellen
- Mieter erstellen, anzeigen, bearbeiten und löschen
- Mietverträge erstellen, anzeigen, bearbeiten und löschen
- Mieteinheiten erstellen, anzeigen, bearbeiten und löschen
- Verwaltungegruppen erstellen, anzeigen, bearbeiten und löschen
- Verwaltungseinheiten erstellen, anzeigen, bearbeiten und löschen
- Einnahmen und Ausgaben erstellen, anzeigen, bearbeiten und löschen
- Rechnungen an Einnahmen und Ausnahmen anhängen oder löschen (Datei)
- Nach Rechnungen suchen: Name, Datum bzw. Datumsbereich, Rechnungsnummer
- Anzeigen von offenen Buchungen
- Backup erstellen und einspielen
  - Für Administrator: Alle Daten sichern und einspielen
  - Für Benutzer: Alle sichtbaren Daten sichern und alle Schreibbaren Daten wieder einspielen
- Benutzer Eigenschaften auslesen
  - Name der persönlichen Gruppe
- API-Token
  - Für Adminstrator: Kann ein Token für jeden User erstellen
  - Für Benutzer: Token erstellen, Token löschen (nicht den aktiven)
- Gruppen
  - Benutzer kann Gruppen erstellen und wird Besitzer
  - Besitzer kann Gruppenmitglieder verwalten und Gruppen ohne Member löschen
  - Administrator kann Gruppen löschen

Erstelle für die API außerdem eine Swagger Dokumentation.

---

## Umsetzungsprotokoll

Das fortlaufende Umsetzungsprotokoll (was seit "Technische Umsetzung (aktueller Stand)"
zusätzlich umgesetzt wurde, gefundene Bugs/Datenfehler, wichtige Fallstricke für künftige
Änderungen) wird in einem separaten Entwicklungsprotokoll geführt, das nicht Teil des
Repositorys ist. Die Änderungen je Version stehen in [CHANGELOG.md](CHANGELOG.md).

---

## Verbesserungen

Ideen für künftige Weiterentwicklungen, die bisher nicht im Konzept angefragt wurden, sich
aber aus der bisherigen Umsetzung anbieten. Keine dieser Punkte ist umgesetzt – sie dienen
als Vorschlagsliste für eine spätere Priorisierung durch den Nutzer.

### Finanzen & Bankanbindung
- **Bankabgleich (MT940/CAMT.053-Import):** importierte Kontoauszugszeilen automatisch mit
  offenen `Cost`-/`RentPayment`-Zeilen abgleichen (Betrag + Datum + Verwendungszweck), damit
  das Buchungsdatum nicht mehr für jede Zeile einzeln von Hand gesetzt werden muss.
- **Mahnwesen:** überfällige, noch unbezahlte Rechnungen (Rechnungsdatum + X Tage ohne
  Buchungsdatum) automatisch markieren und bündeln – Ergänzung zur bereits vorhandenen
  Übersicht "offene Zahlungen".
- **DATEV-/CSV-Export** der Kosten- und Einnahmenbuchungen für die Übergabe an einen
  Steuerberater.

### Betriebskostenabrechnung
- **E-Mail-Versand** der fertigen PDF-Abrechnung direkt an die im Mietvertrag/Mieter
  hinterlegte E-Mail-Adresse (statt nur Download), inkl. Versandprotokoll (wann an wen
  verschickt).
- **Warnhinweis bei fehlendem Vermieter:** Konzept.md fordert ihn als Pflichtangabe ("Zu
  jeder Gruppe muss ein Vermieter angegeben werden"), technisch ist er aber aktuell rein
  optional und wird beim Fehlen still als `null` durchgereicht. Ein sichtbarer Hinweis beim
  Erzeugen einer Abrechnung ohne hinterlegten Vermieter würde diese Lücke auffangen, ohne
  das Anlegen einer Verwaltungsgruppe zwingend zu blockieren.
- **Mehrjahresvergleich:** Betriebskosten je Kategorie über mehrere Abrechnungsintervalle
  hinweg als Trend darstellen, damit Kostensteigerungen leichter einzuordnen sind.

### Datensicherheit & Nachvollziehbarkeit
- **Audit-Log** für finanzrelevante Änderungen (wer hat wann welchen Kosten-/Buchungsbetrag
  geändert) – bei geteilten Verwaltungsgruppen mit mehreren Admins aktuell nicht
  nachvollziehbar.
- **Soft-Delete** statt Hard-Delete für Mietverträge/Rechnungen/Kosten, damit ein
  versehentliches Löschen ohne vollständiges DB-Backup rückgängig gemacht werden kann.
- **Zwei-Faktor-Authentifizierung** fürs Login, da die Anwendung sensible Finanz- und
  Personendaten (IBAN, Mieterdaten) verwaltet.

### Bedienung
- **Volltextsuche** über Rechnungen/Belege (Rechnungsnummer, Rechnungssteller,
  Beschreibung) – die Anzahl an Rechnungen pro Verwaltungsgruppe wächst über die Jahre
  schnell an.
- **Duplikatserkennung** beim Anlegen einer Rechnung (gleiche Rechnungsnummer +
  Rechnungssteller bereits vorhanden) als Hinweis, nicht als Blockade.
- **Erinnerung an auslaufende Wartungsverträge** (z. B. RWA-Wartung) X Tage vor
  Vertragsende, analog zu den bereits vorhandenen "offene Buchungen"-Übersichten.

### Technisch
- Der mehrstufige Berechnungsalgorithmus (`BillingCostAllocationService`) lädt aktuell pro
  Mietvertrag die kompletten `managementUnits`/`rentalUnits`-Collections der
  Verwaltungsgruppe neu; bei sehr großen Verwaltungsgruppen (> 50 Verwaltungseinheiten)
  lohnt sich ein Caching dieser gruppen-/einheitenweiten Summen pro Abrechnungslauf statt
  pro Mietvertrag.
- **DSGVO-Löschkonzept:** Mieterdaten nach Vertragsende und Ablauf gesetzlicher
  Aufbewahrungsfristen automatisiert anonymisieren/löschen können – aktuell bleiben
  beendete Mietverträge dauerhaft mit vollständigen Personendaten erhalten.
