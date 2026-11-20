"""Beispiel: Anna Beispiel importiert ihre Daten (alles frei erfunden).

    HV_API_TOKEN=<Annas Token> python3 daten_importieren.py beispiel/meine_daten.py --dry-run
    HV_API_TOKEN=<Annas Token> python3 daten_importieren.py beispiel/meine_daten.py

Den Token erzeugt Anna im Web unter Profil → API-Zugang. Das Script kann beliebig oft
laufen: Vorhandenes wird erkannt, nur Neues wird ergänzt. Pfade (Belege, Kopfbild)
sind relativ zu dieser Datei.

Anna vermietet das Haus gemeinsam mit Bert. Deshalb entsteht alles gleich in der
gemeinsamen Gruppe, die der Administrator angelegt hat (siehe admin_daten.py): Mieter,
Rechnungssteller und eigene Kategorien gehören zur Gruppe ihrer Verwaltungsgruppe.
Was nur Anna allein vermietet, käme in ``hv.personal_group()``.
"""


def importieren(hv):
    gruppe = hv.group("Erbengemeinschaft Beispiel")
    hv.group_landlord(gruppe, name="Erbengemeinschaft Beispiel", street="Lindenweg 2",
                      postal_code="01234", city="Musterstadt", iban="DE02120300000000202051")

    # Mieter
    meier = hv.tenant(gruppe, salutation="Frau", first_name="Maria", last_name="Meier",
                      street="Musterstraße 1", postal_code="01234", city="Musterstadt")
    schulz = hv.tenant(gruppe, salutation="Herr", first_name="Stefan", last_name="Schulz",
                       street="Musterstraße 1", postal_code="01234", city="Musterstadt")

    # Rechnungssteller mit Kundenaccounts
    stadtwerke = hv.invoice_issuer(gruppe, name="Stadtwerke Musterstadt", company_name="Stadtwerke Musterstadt GmbH",
                                   street="Werkstraße 5", postal_code="01234", city="Musterstadt")
    wasser = hv.issuer_account(stadtwerke, "W-4711", description="Wasser", direct_debit=True)
    bank = hv.invoice_issuer(gruppe, name="Musterbank", street="Bankplatz 1", postal_code="01234", city="Musterstadt")

    # Verwaltungsgruppe, Abrechnungsjahre und Haus
    haus = hv.management_group(gruppe, "Musterstraße 1")
    hv.billing_interval(haus, "2026-01-01", "2026-12-31")
    jahr_2025 = hv.billing_interval(haus, "2025-01-01", "2025-12-31", "Erstes Jahr")
    hv.allocation_key(haus, "Wasserversorgung", "person_count")
    hv.allocation_key(haus, "Entwässerung", "person_count")
    gebaeude = hv.management_unit(haus, "Musterstraße 1", "01234", "Musterstadt")
    eg = hv.rental_unit(gebaeude, "Wohnung", "EG links", size=62.5, floor=0, is_relevant_for_billing=True)
    og = hv.rental_unit(gebaeude, "Wohnung", "OG rechts", size=71.0, floor=1, is_relevant_for_billing=True)

    # Mietverträge mit Mietintervallen (Mieterhöhung ab 2026); rent_adjustment_type:
    # fixed = Festmiete, stepped = Staffelmiete, indexed = Indexmiete
    vertrag_eg = hv.lease(eg, "2025-01-01", tenants=[meier], rent_adjustment_type="fixed", cold_rent=500.0, intervals=[
        {"start_date": "2025-01-01", "end_date": "2025-12-31", "rent_amount": 500.0, "occupant_count": 2,
         "utility_advance_payment": 120.0, "notes": None},
        {"start_date": "2026-01-01", "end_date": None, "rent_amount": 520.0, "occupant_count": 2,
         "utility_advance_payment": 130.0, "notes": "Mieterhöhung"},
    ])
    vertrag_og = hv.lease(og, "2025-04-01", tenants=[schulz], rent_adjustment_type="fixed", cold_rent=610.0, intervals=[
        {"start_date": "2025-04-01", "end_date": None, "rent_amount": 610.0, "occupant_count": 1,
         "utility_advance_payment": 110.0, "notes": None},
    ])

    # Eingegangene Mieten laut Kontoauszug – den Monat bestimmt die Anwendung
    # (z. B. gilt der 30.12. als Miete für Januar).
    hv.rent_payments(vertrag_eg, ["2024-12-30", "2025-02-01", "2025-03-03"])
    hv.rent_payments(vertrag_og, ["2025-03-31", "2025-05-02"])

    # Wasser: monatlicher Abschlag, aufgeteilt auf zwei Kategorien, mit Rechnungs-PDF
    abschlag = hv.invoice(
        stadtwerke, haus, "2026-01-05", "monthly", amount=45.0, invoice_number="W-2026-01",
        description="Wasserabschlag 2026", recurrence_start="2026-01-05", recurrence_end="2026-12-05",
        allocations=[("Wasserversorgung", 30.0), ("Entwässerung", 15.0)],
        issuer_account=wasser, file="belege/stadtwerke-wasser-2026-01.pdf",
    )
    hv.bookings(abschlag, [
        ("2026-01-05", [("Wasserversorgung", 30.0), ("Entwässerung", 15.0)]),
        ("2026-02-04", [("Wasserversorgung", 32.0), ("Entwässerung", 16.0)]),
    ])

    # Kredit: Rate mit wechselndem Zins- und Tilgungsanteil, unbefristet
    kredit = hv.invoice(
        bank, haus, "2025-01-01", "monthly", amount=800.0, description="Darlehen 123456",
        recurrence_start="2025-01-30", allocations=[("Zinsen", None), ("Tilgung", None)],
        disable_amount_cap=True,
    )
    hv.bookings(kredit, [
        ("2025-01-30", [("Zinsen", 310.0), ("Tilgung", 490.0)]),
        ("2025-02-28", [("Zinsen", 308.5), ("Tilgung", 491.5)]),
    ])

    # Nachzahlung aus der Abrechnung 2025
    hv.settlement_claim(vertrag_eg, jahr_2025, "2026-03-15", 84.20, notes="Abrechnung 2025")

    # Vorlage der Gruppe als Standard der Verwaltungsgruppe. Sie liegt schon in der
    # Gruppe; move_management_group setzt hier nur die Standardvorlage.
    vorlage = hv.billing_template(gruppe, "Abrechnung Musterstraße", header_image="kopfbild.png",
                                  content="<h1>Betriebskostenabrechnung</h1>", css_content="h1 { color: #285aa0; }")
    hv.move_management_group(haus, gruppe, default_billing_template=vorlage)
