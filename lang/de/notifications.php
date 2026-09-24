<?php

/* Machine-translated (first pass) — flagged for native speaker review before this ships as a claimed-accurate translation. Do not remove this notice until reviewed. */

return [

    'rfq_verification' => [
        'subject' => 'Bestätigen Sie Ihre Angebotsanfrage — :reference',
        'heading' => 'Bestätigen Sie Ihre Anfrage',
        'intro' => 'Vielen Dank für Ihre Anfrage :reference. Bitte bestätigen Sie Ihre E-Mail-Adresse, damit wir sie an verifizierte kamerunische Holzexporteure weiterleiten können.',
        'action' => 'Anfrage bestätigen',
        'disclaimer' => 'Das Senden einer Anfrage stellt keinen Vertrag dar. Käufer sollten vor jeder Transaktion eine eigene Sorgfaltsprüfung durchführen.',
        'salutation' => 'Vielen Dank,',
    ],

    'inquiry_verification' => [
        'subject' => 'Bestätigen Sie Ihre Nachricht — Cameroon Timber Hub',
        'heading' => 'Bestätigen Sie Ihre Nachricht',
        'intro' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse, damit Ihre Nachricht an den Exporteur weitergeleitet werden kann.',
        'action' => 'Nachricht bestätigen',
        'disclaimer' => 'Käufer sollten vor jeder Transaktion eine eigene Sorgfaltsprüfung durchführen.',
        'salutation' => 'Vielen Dank,',
    ],

    'quote_submitted' => [
        'subject' => 'Neues Angebot für :reference — :company',
        'heading' => 'Sie haben ein neues Angebot erhalten',
        'intro' => ':company hat auf Ihre Anfrage :reference geantwortet.',
        'quote_reference' => 'Angebotsreferenz:',
        'total' => 'Gesamt:',
        'lead_time' => 'Lieferzeit:',
        'lead_time_value' => ':days Tage',
        'valid_until' => 'Gültig bis:',
        'action' => 'Ihre Angebote ansehen',
        'personal_link' => 'Dieser Link ist persönlich mit Ihrer Anfrage verknüpft — bitte leiten Sie ihn nicht weiter.',
        'disclaimer' => 'Der Erhalt eines Angebots stellt keinen Vertrag dar. Käufer sollten vor jeder Transaktion eine eigene Sorgfaltsprüfung durchführen.',
        'salutation' => 'Vielen Dank,',
    ],

    'contact_message' => [
        'subject' => '[CTH Kontakt] :subject',
        'heading' => 'Neue Nachricht aus dem Kontaktformular',
        'name' => 'Name:',
        'company' => 'Unternehmen:',
        'email' => 'E-Mail:',
        'phone' => 'Telefon:',
        'subject_label' => 'Betreff:',
        'reply_hint' => 'Antworten Sie direkt auf diese E-Mail, um den Absender zu kontaktieren.',
    ],

    'error_digest' => [
        'subject' => '[:app] Fehlerübersicht — :count Fehler im letzten Zeitraum',
    ],

    'company_verified' => [
        'subject' => 'Ihr Unternehmen wurde verifiziert — :company',
        'line_1' => 'Herzlichen Glückwunsch! :company wurde vom Team von Cameroon Timber Hub verifiziert.',
        'line_2' => 'Ihr Verifizierungsabzeichen ist nun im öffentlichen Verzeichnis sichtbar.',
        'action' => 'Zu Ihrem Dashboard',
        'line_3' => 'Dokumente werden von Cameroon Timber Hub auf Grundlage der vom Unternehmen übermittelten Informationen geprüft. Käufer sollten vor jeder Transaktion eine eigene Sorgfaltsprüfung durchführen.',
    ],

    'document_expiring' => [
        'subject' => 'Ablauf eines Compliance-Dokuments — :company',
        'fallback_type' => 'Compliance-Dokument',
        'expired_line_1' => 'Ihr :type ist abgelaufen.',
        'expired_line_2' => 'Bitte laden Sie ein aktuelles Dokument hoch, um Ihren verifizierten Status aktiv zu halten.',
        'expiring_line_1' => 'Ihr :type läuft in :days Tagen (am :date) ab.',
        'expiring_line_2' => 'Bitte erneuern Sie es vor Ablauf, um Ihren verifizierten Status zu erhalten.',
        'action' => 'Dokumente verwalten',
    ],

    'rfq_routed_to_exporter' => [
        'subject' => 'Neue Käufergelegenheit — :reference',
        'line_1' => 'Eine Anfrage eines verifizierten Käufers wurde an Ihr Unternehmen weitergeleitet.',
        'action' => 'In Ihrem Dashboard ansehen',
        'line_2' => 'Referenz: :reference',
    ],

    'subscription_renewal' => [
        'subject' => 'Ihr Tarif :plan wird bald verlängert',
        'line_1' => 'Ihr Abonnement :plan muss am :date für :amount verlängert werden.',
        'line_2' => 'Mobile-Money-Zahlungen können nicht automatisch abgebucht werden: Bitte erneuern Sie über den untenstehenden Link vor Ablauf Ihres Zeitraums.',
        'action' => 'Jetzt verlängern',
        'line_3' => 'Ohne Ihr Zutun durchläuft Ihr Tarif nach dem Verlängerungsdatum eine Nachfrist von 7 Tagen und wechselt anschließend zum kostenlosen Tarif.',
    ],

    'subscription_past_due' => [
        'subject' => 'Zahlung überfällig — Tarif :plan',
        'line_1' => 'Wir haben die Zahlung von :amount für Ihr Abonnement :plan nicht erhalten.',
        'line_2' => 'Ihr Zugang bleibt bis zum :date bestehen. Erneuern Sie vor diesem Datum, um eine Unterbrechung zu vermeiden.',
        'action' => 'Jetzt verlängern',
        'line_3' => 'Nach diesem Datum wechselt Ihr Unternehmen zum kostenlosen Tarif, und kostenpflichtige Funktionen werden deaktiviert.',
    ],

    'subscription_lapsed' => [
        'subject' => 'Ihr Abonnement ist abgelaufen — Sie befinden sich jetzt im kostenlosen Tarif',
        'line_1' => 'Ihr Abonnement :plan wurde nicht verlängert; Ihr Unternehmen befindet sich jetzt im Tarif :free.',
        'line_2' => 'Ihre Daten bleiben erhalten. Sie können jederzeit erneut abonnieren, um kostenpflichtige Funktionen wiederherzustellen.',
        'action' => 'Tarife ansehen',
    ],

    'push' => [
        'quote_received' => [
            'title' => 'Neues Angebot erhalten',
            'body' => ':supplier hat ein Angebot für die Anfrage :rfq eingereicht.',
        ],
        'order_status_changed' => [
            'title' => 'Bestellstatus aktualisiert',
            'body' => 'Die Bestellung :order ist jetzt :status.',
        ],
        'message_received' => [
            'title' => 'Neue Nachricht von :sender',
        ],
        'dispute_reply' => [
            'title' => 'Neue Antwort auf Ihren Streitfall',
        ],
        'quote_accepted' => [
            'title' => 'Ihr Angebot wurde angenommen',
            'body' => 'Der Käufer hat Ihr Angebot für die Anfrage :rfq angenommen.',
        ],
        'quote_declined' => [
            'title' => 'Ihr Angebot wurde abgelehnt',
            'body' => 'Der Käufer hat Ihr Angebot :quote abgelehnt.',
        ],
        'counter_offer' => [
            'title' => 'Neues Gegenangebot',
            'body' => ':party hat ein Gegenangebot zum Angebot :quote gesendet.',
        ],
        'payment_requested' => [
            'title' => 'Zahlung angefordert',
            'body' => 'Der Lieferant hat die Zahlung für die Bestellung :order angefordert.',
        ],
        'payment_confirmed' => [
            'title' => 'Zahlung erfasst',
            'body' => 'Für die Bestellung :order wurde eine Zahlung erfasst.',
        ],
        'shipment_update' => [
            'title' => 'Versandaktualisierung',
            'body' => 'Die Versanddetails für die Bestellung :order wurden aktualisiert.',
        ],
        'document_uploaded' => [
            'title' => 'Neues Bestelldokument',
            'body' => 'Der Bestellung :order wurde ein neues Dokument hinzugefügt.',
        ],
        'dispute_opened' => [
            'title' => 'Ein Streitfall wurde eröffnet',
            'body' => 'Für die Bestellung :order wurde ein Streitfall eröffnet.',
        ],
        'rfq_routed' => [
            'title' => 'Neue Käuferanfrage',
            'body' => 'Eine Anfrage eines verifizierten Käufers wurde an Ihr Unternehmen weitergeleitet.',
        ],
    ],

    'trial_ended' => [
        'subject' => 'Ihre kostenlose Testphase für :plan ist beendet',
        'line_1' => 'Ihre kostenlose Testphase für :plan ist beendet, und es wurde keine Zahlung geleistet.',
        'line_2' => 'Ihr Unternehmen befindet sich nun im kostenlosen Tarif. Abonnieren Sie unten, um kostenpflichtige Funktionen zu behalten.',
        'action' => 'Abonnieren',
    ],

];

