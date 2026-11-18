<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Hilfe: Templates schreiben') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[922px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold">{{ __('Blade-Syntax (Kurzübersicht)') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('Kopfzeile, Inhalt und Fußzeile eines Templates werden mit Laravels Blade-Syntax geschrieben. Die wichtigsten Befehle:') }}
                </p>
                <p class="text-sm text-gray-600">
                    {{ __('Das optionale "CSS-Content"-Feld hält die Formatierung getrennt vom Inhalt – so lässt sich ein kopiertes Template leichter anpassen, ohne Content und Formatierung auseinanderpflücken zu müssen. Es wird beim Rendern automatisch in den Inhalt eingefügt.') }}
                </p>
                <table class="w-full text-sm text-left">
                    <tbody class="divide-y">
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#123;&#123; $variable &#125;&#125;</td>
                            <td class="py-2 text-gray-600">{{ __('Gibt einen Wert aus (automatisch HTML-escaped).') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#123;&#123; $objekt-&gt;eigenschaft &#125;&#125;</td>
                            <td class="py-2 text-gray-600">{{ __('Greift auf eine Eigenschaft eines Objekts zu (z. B. ein Datenbank-Datensatz).') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#64;if (...) ... &#64;else ... &#64;endif</td>
                            <td class="py-2 text-gray-600">{{ __('Bedingung: Der Inhalt erscheint nur, wenn die Bedingung wahr ist.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#64;foreach ($liste as $eintrag) ... &#64;endforeach</td>
                            <td class="py-2 text-gray-600">{{ __('Wiederholt den Inhalt für jeden Eintrag einer Liste.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#64;foreach ($liste as $schlüssel =&gt; $wert) ... &#64;endforeach</td>
                            <td class="py-2 text-gray-600">{{ __('Wie oben, zusätzlich mit dem Schlüssel jedes Eintrags (z. B. bei $costsByCategory der Kategoriename).') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#123;&#123;-- Kommentar --&#125;&#125;</td>
                            <td class="py-2 text-gray-600">{{ __('Kommentar, erscheint nicht im Ergebnis.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">euro($betrag)</td>
                            <td class="py-2 text-gray-600">{{ __('Formatiert einen Betrag im deutschen Zahlenformat (Punkt als Tausendertrennzeichen, Komma für Nachkommastellen, 2 Nachkommastellen) – z. B. 1.234,56.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#64;php($summe = 0)</td>
                            <td class="py-2 text-gray-600">{{ __('Legt eine eigene Variable an bzw. verändert sie – so kann man sich z. B. während einer Schleife eine Zwischensumme merken (rechnen).') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&#64;php ... &#64;endphp</td>
                            <td class="py-2 text-gray-600">{{ __('Wie oben, aber für mehrere Zeilen PHP-Code auf einmal.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">count($liste)</td>
                            <td class="py-2 text-gray-600">{{ __('Anzahl der Einträge einer Liste/eines Arrays – z. B.') }} <code>&#123;&#123; count($leases) &#125;&#125;</code> {{ __('für die Anzahl der Mietverträge, oder in einer Bedingung') }} <code>&#64;if (count($entry['otherRents']) &gt; 0)</code>.</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap align-top">&lt;pagebreak /&gt;</td>
                            <td class="py-2 text-gray-600">{{ __('Erzwingt einen Seitenumbruch an dieser Stelle. Besser geeignet als ein umschließendes <div style="page-break-after: always;">, weil ein solches Div CSS-Regeln wie "position: absolute" (z. B. für ein Anschriftfenster) innerhalb des Divs unwirksam macht – position:absolute funktioniert nur auf Elementen, die direkte Kinder von <body> sind.') }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-xs text-gray-600 bg-gray-50 border border-gray-200 rounded p-2">
                    {{ __('Beispiel für eine Zwischensumme über eine Schleife (genau so macht es das Standard-Template):') }}
                    <code class="block mt-1 whitespace-pre-wrap">&#64;php($summe = 0)
&#64;foreach ($costsByCategory as $kategorie =&gt; $betrag)
    &#64;php($summe += $betrag)
    &#123;&#123; $kategorie &#125;&#125;: &#123;&#123; euro($betrag) &#125;&#125;
&#64;endforeach
Gesamtsumme: &#123;&#123; euro($summe) &#125;&#125;</code>
                </p>
                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
                    {{ __('Achtung bei verschachtelten Schleifen (z. B. über') }} <code>$leases</code> {{ __('und darin über') }} <code>['otherRents']</code>{{ __('): zwei &#64;endforeach direkt hintereinander OHNE Leerzeichen oder Zeilenumbruch dazwischen werden nicht korrekt erkannt. Immer mindestens ein Leerzeichen oder einen Zeilenumbruch zwischen aufeinanderfolgende Blade-Befehle setzen.') }}
                </p>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold">{{ __('PDF-Layout') }}</h3>
                <p class="text-sm text-gray-600">{{ __('Maßgeblich ist die PDF-Ausgabe; die HTML-Vorschau bildet sie nur näherungsweise nach. Für das Seitenlayout gibt es drei Möglichkeiten:') }}</p>
                <table class="w-full text-sm">
                    <tbody class="divide-y">
                        <tr>
                            <td class="py-2 pr-4 font-semibold align-top whitespace-nowrap">{{ __('Absolut positionierter Container') }}</td>
                            <td class="py-2 text-gray-600">
                                {{ __('Ein Element mit "position: absolute" und Angaben in mm sitzt unabhängig von Kopfzeile und Textfluss genau an dieser Stelle der Seite – z. B. das Anschriftfeld eines Fensterumschlags nach DIN 5008. Es muss direkt im <body> stehen, nicht in einem umschließenden Div.') }}
                                <code class="block mt-1 whitespace-pre-wrap">.address-window { position: absolute; top: 27mm; left: 20mm; width: 85mm; height: 45mm; }</code>
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-semibold align-top whitespace-nowrap">{{ __('Kopf- und Fußzeile') }}</td>
                            <td class="py-2 text-gray-600">
                                {{ __('Kopf- und Fußzeile (eigene Felder der Vorlage) stehen auf jeder Seite oben bzw. unten; der Inhalt fließt dazwischen und bricht automatisch auf die nächste Seite um. Den Platz dafür legt der Seitenrand fest. Ist eine Kopf- oder Fußzeile höher als ihr Rand, rückt der Inhalt automatisch nach, statt überdeckt zu werden.') }}
                                <code class="block mt-1 whitespace-pre-wrap">@page { margin: 50mm 20mm 20mm 25mm; }   /* oben rechts unten links */</code>
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-semibold align-top whitespace-nowrap">{{ __('Beginn des Inhalts auf der ersten Seite') }}</td>
                            <td class="py-2 text-gray-600">
                                {{ __('An den Anfang eines Briefs gesetzt, lässt der Befehl den folgenden Inhalt genau so weit unter der Blattoberkante beginnen – unabhängig von der Höhe der Kopfzeile und für jeden Brief der Gesamt-PDF. Folgeseiten beginnen wie gewohnt unter der Kopfzeile. Ein Außenabstand (margin) des ersten Elements kommt hinzu. Erlaubt sind Millimeter.') }}
                                <code class="block mt-1 whitespace-pre-wrap">&#64;foreach ($leases as $entry)
    &#64;if (! $loop-&gt;first) &lt;pagebreak /&gt; &#64;endif
    &lt;div class="address-window"&gt;…&lt;/div&gt;
    &#64;contentStart('75mm')
    &lt;div class="subject"&gt;Betriebskostenabrechnung …&lt;/div&gt;
&#64;endforeach</code>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="text-sm text-gray-600 space-y-2" data-print-hint>
                    <p><span class="font-semibold">{{ __('Drucken in Originalgröße:') }}</span> {{ __('Die PDF setzt alle Angaben in mm exakt. Drucke sie mit „Tatsächliche Größe“ bzw. Skalierung 100 % – mit „An Seite anpassen“ verkleinert der Drucker die Seite (typisch auf etwa 93 %), dann rutscht z. B. das Anschriftfeld aus dem Fenster. Die PDF gibt „Tatsächliche Größe“ als Druckvorgabe mit; nicht jeder Betrachter übernimmt sie.') }}</p>
                    <p><span class="font-semibold">{{ __('Rahmen zählt zur Breite hinzu:') }}</span> {{ __('Wie in CSS üblich gelten width und height ohne Rahmen. Ein Rahmen von 2px (0,53 mm) macht ein 85 mm breites Feld außen 86,06 mm breit. Soll die Außenkante genau 85 × 45 mm messen, ziehe den Rahmen ab: width: 83.94mm; height: 43.94mm.') }}</p>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold">{{ __('Verfügbare Variablen') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('Diese Variablen stehen in Kopfzeile, Inhalt und Fußzeile zur Verfügung:') }}
                </p>
                <dl class="text-sm space-y-4">
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementGroup</dt>
                        <dd class="text-gray-600">
                            {{ __('Die Verwaltungsgruppe. Verfügbare Eigenschaften:') }}
                            <ul class="list-disc list-inside mt-1 space-y-0.5">
                                <li><code>-&gt;description</code> – {{ __('Bezeichnung') }}</li>
                                <li><code>-&gt;billing_start</code> – {{ __('Datum, ab dem für diese Gruppe abgerechnet wird, oder null') }}</li>
                                <li><code>-&gt;kontosystem_enabled</code> – {{ __('ob das Kontosystem aktiviert ist (true/false)') }}</li>
                                <li><code>-&gt;user_group_id</code> – {{ __('interne ID der zugehörigen Benutzergruppe') }}</li>
                                <li><code>-&gt;default_billing_template_id</code> – {{ __('interne ID des Standard-Templates dieser Gruppe, oder null') }}</li>
                            </ul>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementUnit</dt>
                        <dd class="text-gray-600">
                            {{ __('Die Verwaltungseinheit. Verfügbare Eigenschaften:') }}
                            <ul class="list-disc list-inside mt-1 space-y-0.5">
                                <li><code>-&gt;street</code> – {{ __('Straße') }}</li>
                                <li><code>-&gt;postal_code</code> – {{ __('PLZ') }}</li>
                                <li><code>-&gt;city</code> – {{ __('Stadt') }}</li>
                                <li><code>-&gt;description</code> – {{ __('Beschreibung') }}</li>
                                <li><code>-&gt;management_group_id</code> – {{ __('interne ID der übergeordneten Verwaltungsgruppe') }}</li>
                            </ul>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$billingInterval</dt>
                        <dd class="text-gray-600">
                            {{ __('Das Abrechnungsintervall. Verfügbare Eigenschaften:') }}
                            <ul class="list-disc list-inside mt-1 space-y-0.5">
                                <li><code>-&gt;start_date</code>, <code>-&gt;end_date</code> – {{ __('Datum, z. B. mit') }} <code>-&gt;format('d.m.Y')</code> {{ __('formatierbar') }}</li>
                                <li><code>-&gt;description</code> – {{ __('Beschreibung') }}</li>
                                <li><code>-&gt;management_group_id</code> – {{ __('interne ID der übergeordneten Verwaltungsgruppe') }}</li>
                            </ul>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$landlord</dt>
                        <dd class="text-gray-600">
            {{ __('Der Vermieter (für die Verwaltungsgruppe hinterlegt, ggf. überschrieben). Kann') }} <code>null</code> {{ __('sein, falls keiner hinterlegt ist – daher am besten mit') }} <code>&#64;if ($landlord)</code> {{ __('prüfen bzw. per') }} <code>$landlord?-&gt;…</code> {{ __('zugreifen. Verfügbare Eigenschaften:') }}
                            <ul class="list-disc list-inside mt-1 space-y-0.5">
                                <li><code>-&gt;salutation</code> – {{ __('Anrede ("Herr", "Frau" oder null)') }}</li>
                                <li><code>-&gt;first_name</code>, <code>-&gt;name</code> – {{ __('Vor- und Nachname (bei einer Firma steht der Firmenname in') }} <code>-&gt;name</code>)</li>
                                <li><code>-&gt;company_name</code> – {{ __('Firmenname, oder null') }}</li>
                                <li><code>-&gt;street</code>, <code>-&gt;postal_code</code>, <code>-&gt;city</code> – {{ __('Anschrift (jeweils optional)') }}</li>
                                <li><code>-&gt;email</code>, <code>-&gt;phone</code>, <code>-&gt;mobile_phone</code> – {{ __('Kontaktdaten (jeweils optional)') }}</li>
                                <li><code>-&gt;iban</code>, <code>-&gt;bic</code> – {{ __('Bankverbindung (jeweils optional)') }}</li>
                                <li><code>-&gt;notes</code> – {{ __('Notiz, oder null') }}</li>
                                <li><code>-&gt;fullName()</code> – {{ __('vollständiger Name als Methode') }}</li>
                            </ul>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$leases</dt>
                        <dd class="text-gray-600">
                            {{ __('Liste der im Abrechnungszeitraum aktiven Mietverträge. Jeder Eintrag ist ein Array mit:') }}
                            <ul class="list-disc list-inside mt-1 space-y-0.5">
                                <li>
                                    <code>['lease']</code> – {{ __('der Mietvertrag selbst, mit') }}
                                    <ul class="list-disc list-inside ml-4">
                                        <li><code>-&gt;start_date</code>, <code>-&gt;end_date</code> – {{ __('Beginn/Ende des Mietvertrags (Ende kann null sein)') }}</li>
                                        <li><code>-&gt;rent_adjustment_type</code> – {{ __('Mietpreistyp ("fixed", "staffel" oder "index")') }}</li>
                                        <li><code>-&gt;cold_rent</code> – {{ __('Kaltmiete bei Vertragsabschluss') }}</li>
                                        <li><code>-&gt;deposit_amount</code> – {{ __('Kaution, oder null') }}</li>
                                        <li><code>-&gt;min_term_months</code> – {{ __('Mindestlaufzeit in Monaten, oder null') }}</li>
                                        <li><code>-&gt;notice_period</code> – {{ __('Kündigungsfrist') }}</li>
                                        <li><code>-&gt;notes</code> – {{ __('Notiz, oder null') }}</li>
                                        <li><code>-&gt;rental_unit_id</code> – {{ __('interne ID der Mieteinheit') }}</li>
                                    </ul>
                                </li>
                                <li>
                                    <code>['tenants']</code> – {{ __('Liste der Mieter dieses Vertrags, jeder mit') }}
                                    <ul class="list-disc list-inside ml-4">
                                        <li><code>-&gt;salutation</code> – {{ __('Anrede ("Herr", "Frau" oder null)') }}</li>
                                        <li><code>-&gt;first_name</code>, <code>-&gt;last_name</code> – {{ __('Vor- und Nachname (bei Firmenmietern ggf. leer)') }}</li>
                                        <li><code>-&gt;company_name</code> – {{ __('Firmenname, oder null') }}</li>
                                        <li><code>-&gt;street</code>, <code>-&gt;postal_code</code>, <code>-&gt;city</code> – {{ __('Anschrift') }}</li>
                                        <li><code>-&gt;email</code>, <code>-&gt;mobile_phone</code> – {{ __('Kontaktdaten, jeweils oder null') }}</li>
                                        <li><code>-&gt;iban</code>, <code>-&gt;bic</code> – {{ __('Bankverbindung, jeweils oder null') }}</li>
                                        <li><code>-&gt;notes</code> – {{ __('Notiz, oder null') }}</li>
                                        <li><code>-&gt;fullName()</code> – {{ __('vollständiger Name als Methode (kombiniert Vor-/Nachname bzw. Firmenname)') }}</li>
                                    </ul>
                                </li>
                                <li><code>['tenantNames']</code> – {{ __('alle Mieternamen als ein Text, kommagetrennt') }}</li>
                                <li>
                                    <code>['rentalUnit']</code> – {{ __('die Mieteinheit, mit') }}
                                    <ul class="list-disc list-inside ml-4">
                                        <li><code>-&gt;type</code> – {{ __('Typ ("Wohnung", "Garage" oder "Stellplatz")') }}</li>
                                        <li><code>-&gt;size</code> – {{ __('Größe in m², oder null') }}</li>
                                        <li><code>-&gt;description</code> – {{ __('Beschreibung') }}</li>
                                        <li><code>-&gt;floor</code> – {{ __('Etage, oder null') }}</li>
                                        <li><code>-&gt;is_relevant_for_billing</code> – {{ __('ob abrechnungsrelevant (true/false)') }}</li>
                                        <li><code>-&gt;management_unit_id</code> – {{ __('interne ID der Verwaltungseinheit') }}</li>
                                        <li><code>-&gt;label()</code> – {{ __('Anzeigename als Methode (Typ, ggf. mit Beschreibung in Klammern)') }}</li>
                                    </ul>
                                </li>
                                <li><code>['periodStart']</code>, <code>['periodEnd']</code> – {{ __('Zeitraum, in dem Mietvertrag und Abrechnungsintervall sich überschneiden') }}</li>
                                <li><code>['days']</code> – {{ __('Anzahl Tage dieses Zeitraums (die Länge des für diesen Mietvertrag relevanten Abrechnungsintervalls)') }}</li>
                                <li><code>['currentColdRent']</code>, <code>['currentUtilityAdvance']</code> – {{ __('die aktuell (heute) gültige Kaltmiete bzw. Betriebskostenvorauszahlung dieses Mietvertrags') }}</li>
                                <li><code>['monthlyUtilityAdvances']</code> – {{ __('monatliche Betriebskostenvorauszahlungen innerhalb des Zeitraums. Schlüssel = Jahr-Monat (z. B. 2026-01), Wert ist ein Array mit') }} <code>['label']</code> {{ __('und') }} <code>['amount']</code></li>
                                <li><code>['totalUtilityAdvance']</code> – {{ __('Summe aller monatlichen Betriebskostenvorauszahlungen aus') }} <code>['monthlyUtilityAdvances']</code></li>
                                <li>
                                    <code>['otherRents']</code> – {{ __('Liste weiterer, aktuell vom selben Mieter gemieteter, nicht abrechnungsrelevanter Mieteinheiten (z. B. Garage, Stellplatz) in derselben Verwaltungseinheit. Jeder Eintrag hat') }}
                                    <ul class="list-disc list-inside ml-4">
                                        <li><code>['rentalUnit']</code> – {{ __('die Mieteinheit (gleicher Aufbau wie oben)') }}</li>
                                        <li><code>['coldRent']</code> – {{ __('deren aktuelle Kaltmiete') }}</li>
                                        <li><code>['utilityAdvance']</code> – {{ __('deren aktuelle Betriebskostenvorauszahlung') }}</li>
                                    </ul>
                                </li>
                                <li>
                                    <code>['allocatedCosts']</code> – {{ __('der auf diesen Mietvertrag entfallende Anteil an den Betriebskosten (mehrstufige Berechnung, siehe unten). Ein Array mit genau diesen drei Schlüsseln:') }}
                                    <ul class="list-disc list-inside ml-4">
                                        <li>
                                            <code>['byCategory']</code> – {{ __('Anteil je Kostenkategorie, über alle 4 Stufen hinweg summiert. Selbst wieder ein Array:') }}
                                            <ul class="list-disc list-inside ml-4">
                                                <li>{{ __('Schlüssel = Kategoriename (Text, z. B. "Heizkosten")') }}</li>
                                                <li>{{ __('Wert = Betrag (Zahl) – nur abrechnungsrelevante Kategorien mit einem Anteil &gt; 0 tauchen auf; am besten mit') }} <code>&#64;foreach ($entry['allocatedCosts']['byCategory'] as $kategorie =&gt; $betrag)</code> {{ __('durchlaufen') }}</li>
                                            </ul>
                                        </li>
                                        <li>
                                            <code>['byStage']</code> – {{ __('Anteil je Berechnungsstufe. Ein Array mit genau diesen vier Schlüsseln:') }}
                                            <ul class="list-disc list-inside ml-4">
                                                <li><code>['managementGroup']</code> – {{ __('Anteil aus auf Verwaltungsgruppen-Ebene gebuchten Kosten (Stufe 1)') }}</li>
                                                <li><code>['managementUnit']</code> – {{ __('Anteil aus auf Verwaltungseinheiten-Ebene gebuchten Kosten (Stufe 2)') }}</li>
                                                <li><code>['rentalUnit']</code> – {{ __('Anteil aus auf Mieteinheiten-Ebene gebuchten Kosten (Stufe 3)') }}</li>
                                                <li><code>['lease']</code> – {{ __('Anteil aus direkt auf diesen Mietvertrag gebuchten (individuellen) Kosten (Stufe 4)') }}</li>
                                            </ul>
                                            {{ __('Jede dieser vier Stufen ist selbst wieder ein Array mit denselben Schlüsseln wie eine Kategorie-Aufschlüsselung:') }}
                                            <ul class="list-disc list-inside ml-4">
                                                <li><code>['byCategory']</code> – {{ __('Aufschlüsselung des Stufen-Anteils je Kategorie (Schlüssel = Kategoriename, Wert = Betrag), z. B.') }} <code>&#64;foreach ($entry['allocatedCosts']['byStage']['managementGroup']['byCategory'] as $kategorie =&gt; $betrag)</code></li>
                                                <li>
                                                    <code>['categoryDetails']</code> – {{ __('wie') }} <code>['byCategory']</code>, {{ __('aber je Kategorie mit drei Werten statt nur dem Betrag (Schlüssel = Kategoriename):') }}
                                                    <ul class="list-disc list-inside ml-4">
                                                        <li><code>['sum']</code> – {{ __('die ungekürzte Kosten-Summe dieser Kategorie auf dieser Ebene, vor Anwendung des Abrechnungsschlüssels') }}</li>
                                                        <li><code>['key']</code> – {{ __('Name des Abrechnungsschlüssels dieser Kategorie als Text ("Fläche", "Personen", "Mieteinheit" oder "Individuell")') }}</li>
                                                        <li><code>['share']</code> – {{ __('derselbe, bereits anteilig berechnete Betrag wie in') }} <code>['byCategory']</code> {{ __('für diese Kategorie') }}</li>
                                                    </ul>
                                                    {{ __('z. B.') }} <code>&#64;foreach ($entry['allocatedCosts']['byStage']['managementGroup']['categoryDetails'] as $kategorie =&gt; $detail) &#123;&#123; $kategorie &#125;&#125;: &#123;&#123; euro($detail['sum']) &#125;&#125; &euro; (&#123;&#123; $detail['key'] &#125;&#125;) =&gt; &#123;&#123; euro($detail['share']) &#125;&#125; &euro; &#64;endforeach</code>
                                                </li>
                                                <li><code>['total']</code> – {{ __('Betrag (Zahl): Summe dieser Stufe, z. B.') }} <code>&#123;&#123; euro($entry['allocatedCosts']['byStage']['managementGroup']['total']) &#125;&#125;</code></li>
                                            </ul>
                                        </li>
                                        <li><code>['total']</code> – {{ __('Betrag (Zahl): Summe aller 4 Stufen – der gesamte Betriebskostenanteil dieses Mietvertrags. Entspricht der Summe der') }} <code>['byStage'][...]['total']</code> {{ __('bzw. der Summe über') }} <code>['byCategory']</code>.</li>
                                    </ul>
                                </li>
                                <li><code>['balance']</code> – {{ __('Differenz aus') }} <code>['allocatedCosts']['total']</code> {{ __('und') }} <code>['totalUtilityAdvance']</code>{{ __(': positiv = Nachzahlung des Mieters, negativ = Rückerstattung an den Mieter.') }}</li>
                                <li>
                                    <code>['suggestedUtilityAdvance']</code> – {{ __('Vorschlag für eine neue monatliche Betriebskostenvorauszahlung: der tatsächliche Betriebskostenanteil dieses Mietvertrags (') }}<code>['allocatedCosts']['total']</code>{{ __(') geteilt durch') }} <code>$billingIntervalMonths</code> {{ __('und durch die Fläche der Mieteinheit ergibt einen Betrag pro m² und Monat, der auf volle 10 Cent aufgerundet und wieder mit der Fläche multipliziert wird – ein "glatter" €/m²-Satz statt eines krummen Betrags.') }}
                                    {{ __('Dieser rohe Wert wird dann noch geglättet: läuft der Mietvertrag noch keine 3 Monate, bleibt') }} <code>['currentUtilityAdvance']</code> {{ __('unverändert; ansonsten ändert sich der Vorschlag nur, wenn er unter 90 % oder über 105 % der bisherigen Vorauszahlung liegt – innerhalb dieser Bandbreite bleibt ebenfalls die bisherige Vorauszahlung bestehen.') }}
                                </li>
                                <li>
                                    <code>['isVacancy']</code> – {{ __('true bei einer leerstehenden, abrechnungsrelevanten Mieteinheit statt eines echten Mietvertrags (Konzept.md "Leerstände"): kein echter Mieter, sondern ein 0-Personenhaushalt, der wie ein Mietvertrag durch dieselbe Berechnung läuft. Ein Template sollte in diesem Fall kein Anschreiben an') }} <code>['tenantNames']</code> {{ __('(dann fest "Leerstand") adressieren.') }}
                                </li>
                                <li>
                                    <code>['claims']</code> – {{ __('nur bei') }} <code>['isVacancy']</code>: {{ __('die Forderungen (z. B. einer Abrechnungsgesellschaft) für den Leerstand dieser Mieteinheit in diesem Abrechnungsintervall. Jeder Eintrag ist eine') }} <code>SettlementClaim</code> {{ __('mit') }} <code>-&gt;date</code>, <code>-&gt;amount</code>, <code>-&gt;notes</code> {{ __('und') }} <code>-&gt;isOpen()</code>.
                                </li>
                            </ul>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$costsByCategory</dt>
                        <dd class="text-gray-600">{{ __('Summe der abrechnungsrelevanten Kosten je Kategorie im Abrechnungszeitraum. Schlüssel = Kategoriename, Wert = Betrag.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$costsByMonth</dt>
                        <dd class="text-gray-600">
                            {{ __('Summe der Kosten je Monat. Schlüssel = Jahr-Monat (z. B. 2026-01), Wert ist ein Array mit') }}
                            <code>['label']</code> ({{ __('z. B. Januar 2026') }}) {{ __('und') }} <code>['amount']</code>.
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$bookingsByLevel</dt>
                        <dd class="text-gray-600">
                            {{ __('Alle bereits gebuchten, abrechnungsrelevanten Kostenzeilen dieses Abrechnungsintervalls, gruppiert nach Zuordnungsebene und darin nach Kategorie. Eine Zuordnungsebene bzw. Kategorie ohne Buchungen fehlt einfach als Schlüssel, statt als leere Liste aufzutauchen.') }}
                            <code>&#64;foreach ($bookingsByLevel as $ebene =&gt; $kategorien) &#64;foreach ($kategorien as $kategorie =&gt; $buchungen) &#64;foreach ($buchungen as $buchung) ... &#64;endforeach &#64;endforeach &#64;endforeach</code>
                            <ul class="list-disc list-inside ml-4">
                                <li>{{ __('Schlüssel der äußeren Liste = Zuordnungsebene als Text ("Verwaltungsgruppe", "Verwaltungseinheit", "Mieteinheit" oder "Mietvertrag")') }}</li>
                                <li>{{ __('Schlüssel der mittleren Liste = Kategoriename') }}</li>
                                <li>
                                    {{ __('jede Buchung ist ein Array mit') }}
                                    <code>['date']</code> ({{ __('Buchungsdatum, formatierbar mit') }} <code>-&gt;format('d.m.Y')</code>), <code>['issuer']</code> ({{ __('Empfänger/Rechnungssteller') }}), <code>['description']</code> {{ __('und') }} <code>['amount']</code> ({{ __('Betrag') }})
                                </li>
                            </ul>
                            {{ __('Auf der Einzelabrechnung eines Mietvertrags (') }}<code>$leases</code> {{ __('enthält nur diesen einen Mietvertrag) enthält die Ebene "Mietvertrag" ausschließlich dessen eigene individuelle Buchungen, nie die eines anderen Mietvertrags.') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$headerImage</dt>
                        <dd class="text-gray-600">{{ __('Das hochgeladene Kopf-Bild, einbindbar mit') }} <code>&lt;img src="@{{ $headerImage }}"&gt;</code>, {{ __('oder') }} <code>null</code>, {{ __('wenn keins hochgeladen wurde.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementUnitTotalArea</dt>
                        <dd class="text-gray-600">{{ __('Gesamtfläche aller Mieteinheiten der Verwaltungseinheit (in m²), unabhängig von deren Abrechnungsrelevanz.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementGroupTotalArea</dt>
                        <dd class="text-gray-600">{{ __('Gesamtfläche aller Mieteinheiten der gesamten Verwaltungsgruppe (in m²).') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementUnitRelevantUnits</dt>
                        <dd class="text-gray-600">{{ __('Anzahl der abrechnungsrelevanten Mieteinheiten der Verwaltungseinheit (Nenner beim Abrechnungsschlüssel "Mieteinheit").') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementGroupRelevantUnits</dt>
                        <dd class="text-gray-600">{{ __('Anzahl der abrechnungsrelevanten Mieteinheiten der gesamten Verwaltungsgruppe.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementUnitPersonMonths</dt>
                        <dd class="text-gray-600">{{ __('Summe der Personenmonate aller Mieteinheiten der Verwaltungseinheit über das Abrechnungsintervall (Personenzahl je Monat aufsummiert).') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$managementGroupPersonMonths</dt>
                        <dd class="text-gray-600">{{ __('Wie oben, aber für die gesamte Verwaltungsgruppe.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$billingIntervalDays</dt>
                        <dd class="text-gray-600">{{ __('Länge des gesamten Abrechnungsintervalls in Tagen (unabhängig von einzelnen Mietverträgen).') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-xs font-semibold">$billingIntervalMonths</dt>
                        <dd class="text-gray-600">{{ __('Länge des gesamten Abrechnungsintervalls in Monaten (üblicherweise 12) – Nenner beim Vorschlag für eine neue Betriebskostenvorauszahlung, siehe') }} <code>['suggestedUtilityAdvance']</code>.</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex justify-between items-center">
                    <h3 class="font-semibold">{{ __('Beispiel') }}</h3>
                    <a href="{{ route('billing-templates.help.preview') }}" target="_blank" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                        {{ __('Vorschau mit Testdaten generieren') }}
                    </a>
                </div>
                <p class="text-sm text-gray-600">
                    {{ __('Dieses Beispiel nutzt alle oben genannten Variablen. Der Button rendert es mit erfundenen Testdaten, ohne dass dafür eine echte Verwaltungsgruppe/-einheit oder Abrechnung angelegt sein muss.') }}
                </p>
                <pre class="text-xs bg-gray-50 p-4 rounded overflow-x-auto">{{ $exampleContent }}</pre>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold">{{ __('Berechnung der Betriebskosten') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('Der Anteil eines Mietvertrags an den Betriebskosten (') }}<code>['allocatedCosts']</code>{{ __(') ergibt sich aus vier Stufen, je nachdem, auf welcher Ebene eine Kostenbuchung erfolgt ist und welche Abrechnungsart die jeweilige Kategorie hat:') }}
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left border-collapse">
                        <thead class="text-gray-500">
                            <tr>
                                <th class="py-1 pr-3"></th>
                                <th class="py-1 pr-3">{{ __('Verwaltungsgruppe') }}</th>
                                <th class="py-1 pr-3">{{ __('Verwaltungseinheit') }}</th>
                                <th class="py-1 pr-3">{{ __('Mieteinheit') }}</th>
                                <th class="py-1 pr-3">{{ __('Mietvertrag') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y text-gray-600">
                            <tr>
                                <td class="py-1 pr-3 font-medium">{{ __('Individuell') }}</td>
                                <td class="py-1 pr-3">{{ __('ignoriert') }}</td>
                                <td class="py-1 pr-3">{{ __('ignoriert') }}</td>
                                <td class="py-1 pr-3">{{ __('ignoriert') }}</td>
                                <td class="py-1 pr-3">100&nbsp;%</td>
                            </tr>
                            <tr>
                                <td class="py-1 pr-3 font-medium">{{ __('Personen') }}</td>
                                <td class="py-1 pr-3">PM({{ __('Mieter') }}) / PM(VG)</td>
                                <td class="py-1 pr-3">PM({{ __('Mieter') }}) / PM(VE)</td>
                                <td class="py-1 pr-3">100&nbsp;%</td>
                                <td class="py-1 pr-3">{{ __('ignoriert') }}</td>
                            </tr>
                            <tr>
                                <td class="py-1 pr-3 font-medium">{{ __('Fläche') }}</td>
                                <td class="py-1 pr-3">{{ __('Fläche') }}({{ __('Mieter') }}) / {{ __('Fläche') }}(VG)</td>
                                <td class="py-1 pr-3">{{ __('Fläche') }}({{ __('Mieter') }}) / {{ __('Fläche') }}(VE)</td>
                                <td class="py-1 pr-3">100&nbsp;%</td>
                                <td class="py-1 pr-3">{{ __('ignoriert') }}</td>
                            </tr>
                            <tr>
                                <td class="py-1 pr-3 font-medium">{{ __('Mieteinheit') }}</td>
                                <td class="py-1 pr-3">1 / {{ __('Anzahl relevante ME') }}(VG)</td>
                                <td class="py-1 pr-3">1 / {{ __('Anzahl relevante ME') }}(VE)</td>
                                <td class="py-1 pr-3">100&nbsp;%</td>
                                <td class="py-1 pr-3">{{ __('ignoriert') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500">
                    {{ __('PM = Personenmonate. "PM(Mieter)"/"Fläche(Mieter)" beziehen sich auf den Mietvertrag selbst, "PM(VG)"/"Fläche(VG)" bzw. "PM(VE)"/"Fläche(VE)" auf die gesamte Verwaltungsgruppe bzw. -einheit im Abrechnungsintervall.') }}
                    {{ __('Kosten der Abrechnungsart "Individuell" zählen nur, wenn sie direkt einem Mietvertrag zugewiesen wurden (siehe "Kostenzuweisung"); auf den anderen drei Ebenen werden sie ignoriert. Die vier Stufen werden pro Kategorie berechnet und am Ende zusammen mit den Betriebskostenvorauszahlungen zur Differenz (') }}<code>$entry['balance']</code>{{ __(') verrechnet.') }}
                    {{ __('Bei der Abrechnungsart "Mieteinheit" werden die Kosten zu gleichen Teilen auf die abrechnungsrelevanten Mieteinheiten der jeweiligen Ebene verteilt (Anteil = 1 / $managementGroupRelevantUnits bzw. 1 / $managementUnitRelevantUnits).') }}
                </p>
                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
                    {{ __('Grenze der Berechnung: Auf Mieteinheiten-Ebene wird eine Kostenbuchung immer zu 100 % dem jeweiligen Mietvertrag zugerechnet. Teilen sich innerhalb desselben Abrechnungsintervalls mehrere aufeinanderfolgende Mietverträge dieselbe Mieteinheit (z. B. bei einem Mieterwechsel), erhält jeder davon 100 % der auf dieser Ebene gebuchten Kosten – eine Aufteilung nach Mietdauer erfolgt hier nicht.') }}
                </p>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold">{{ __('Template überprüfen') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('Im Bearbeiten-Formular eines Templates prüft der Button "Template überprüfen" den aktuellen (auch ungespeicherten) Inhalt auf drei Arten von Fehlern:') }}
                </p>
                <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                    <li>{{ __('Blade-Syntaxfehler (z. B. ein fehlendes @endforeach).') }}</li>
                    <li>{{ __('Unbekannte Variablen (z. B. $mangementUnit statt $managementUnit) – auch dann, wenn die Stelle in einem') }} &#64;if {{ __('steht, das mit den Testdaten nie zutrifft.') }}</li>
                    <li>{{ __('Unbekannte Eigenschaften direkt auf $managementGroup, $managementUnit, $billingInterval sowie auf einer Mieteinheit/einem Mietvertrag/dem ersten Mieter aus einer Liste (z. B. $managementGroup->descriptio statt $managementGroup->description).') }}</li>
                    <li>{{ __('Fehlerhaftes HTML, z. B. ein falsch geschriebener schließender Tag wie </htm> statt </html>.') }}</li>
                </ul>
                <p class="text-xs text-gray-500">
                    {{ __('Grenzen der Prüfung: Eigenschaften werden nur auf diesen konkreten Variablen geprüft, nicht auf einem über') }} &#64;foreach {{ __('durchlaufenen Mieter oder auf Beziehungen/Methodenaufrufen (z. B. ->format(...)) – dort kann ein Tippfehler weiterhin unbemerkt bleiben. Auch ein unbekannter Array-Schlüssel (z. B. $entry["tenantName"] statt $entry["tenantNames"]) wird nur erkannt, wenn die betreffende Stelle beim Testrendern tatsächlich erreicht wird.') }}
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
