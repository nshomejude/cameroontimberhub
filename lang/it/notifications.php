<?php

/* Machine-translated (first pass) — flagged for native speaker review before this ships as a claimed-accurate translation. Do not remove this notice until reviewed. */

// Locale: le notifiche inviate a un utente vengono attualmente visualizzate in
// config('app.locale') poiché non esiste ancora una colonna users.locale.
// TODO: locale per destinatario una volta disponibile users.locale.

return [

    'rfq_verification' => [
        'subject' => 'Conferma la tua richiesta di preventivo — :reference',
        'heading' => 'Conferma la tua richiesta',
        'intro' => 'Grazie per la tua richiesta :reference. Conferma il tuo indirizzo email affinché possiamo inoltrarla a esportatori di legname camerunensi verificati.',
        'action' => 'Conferma la richiesta',
        'disclaimer' => "L'invio di una richiesta non costituisce un contratto. Gli acquirenti devono effettuare la propria due diligence prima di qualsiasi transazione.",
        'salutation' => 'Grazie,',
    ],

    'inquiry_verification' => [
        'subject' => 'Conferma il tuo messaggio — Cameroon Timber Hub',
        'heading' => 'Conferma il tuo messaggio',
        'intro' => "Conferma il tuo indirizzo email affinché il tuo messaggio possa essere inoltrato all'esportatore.",
        'action' => 'Conferma il messaggio',
        'disclaimer' => 'Gli acquirenti devono effettuare la propria due diligence prima di qualsiasi transazione.',
        'salutation' => 'Grazie,',
    ],

    'quote_submitted' => [
        'subject' => 'Nuovo preventivo per :reference — :company',
        'heading' => 'Hai ricevuto un nuovo preventivo',
        'intro' => ':company ha risposto alla tua richiesta :reference.',
        'quote_reference' => 'Riferimento preventivo:',
        'total' => 'Totale:',
        'lead_time' => 'Tempo di consegna:',
        'lead_time_value' => ':days giorni',
        'valid_until' => 'Valido fino al:',
        'action' => 'Visualizza i tuoi preventivi',
        'personal_link' => 'Questo link è personale per la tua richiesta — ti preghiamo di non inoltrarlo.',
        'disclaimer' => 'La ricezione di un preventivo non costituisce un contratto. Gli acquirenti devono effettuare la propria due diligence prima di qualsiasi transazione.',
        'salutation' => 'Grazie,',
    ],

    'contact_message' => [
        'subject' => '[CTH Contatto] :subject',
        'heading' => 'Nuovo messaggio dal modulo di contatto',
        'name' => 'Nome:',
        'company' => 'Azienda:',
        'email' => 'Email:',
        'phone' => 'Telefono:',
        'subject_label' => 'Oggetto:',
        'reply_hint' => 'Rispondi direttamente a questa email per contattare il mittente.',
    ],

    'error_digest' => [
        'subject' => '[:app] Riepilogo errori — :count errore/i nell\'ultimo periodo',
    ],

    'company_verified' => [
        'subject' => 'La tua azienda è stata verificata — :company',
        'line_1' => 'Congratulazioni! :company è stata verificata dal team di Cameroon Timber Hub.',
        'line_2' => 'Il tuo badge "verificato" è ora visibile nella directory pubblica.',
        'action' => 'Vai alla tua dashboard',
        'line_3' => "Documenti esaminati da Cameroon Timber Hub sulla base delle informazioni fornite dall'azienda. Gli acquirenti devono effettuare la propria due diligence prima di qualsiasi transazione.",
    ],

    'document_expiring' => [
        'subject' => 'Scadenza di un documento di conformità — :company',
        'fallback_type' => 'documento di conformità',
        'expired_line_1' => 'Il tuo :type è scaduto.',
        'expired_line_2' => 'Carica un documento aggiornato per mantenere attivo il tuo profilo verificato.',
        'expiring_line_1' => 'Il tuo :type scade tra :days giorni (il :date).',
        'expiring_line_2' => 'Rinnovalo prima della scadenza per mantenere il tuo profilo verificato.',
        'action' => 'Gestisci i documenti',
    ],

    'rfq_routed_to_exporter' => [
        'subject' => 'Nuovo contatto commerciale — :reference',
        'line_1' => 'Una richiesta di un acquirente verificato è stata inoltrata alla tua azienda.',
        'action' => 'Visualizza nella tua dashboard',
        'line_2' => 'Riferimento: :reference',
    ],

    'subscription_renewal' => [
        'subject' => 'Il tuo piano :plan si rinnoverà a breve',
        'line_1' => 'Il tuo abbonamento :plan deve essere rinnovato il :date per :amount.',
        'line_2' => 'I pagamenti tramite mobile money non possono essere addebitati automaticamente: rinnova tramite il link qui sotto prima della fine del tuo periodo.',
        'action' => 'Rinnova ora',
        'line_3' => 'Senza alcuna azione da parte tua, il tuo piano passerà a un periodo di tolleranza di 7 giorni dopo la data di rinnovo, per poi tornare al piano gratuito.',
    ],

    'subscription_past_due' => [
        'subject' => 'Pagamento in ritardo — piano :plan',
        'line_1' => 'Non abbiamo ricevuto il pagamento di :amount per il tuo abbonamento :plan.',
        'line_2' => 'Il tuo accesso rimane attivo fino al :date. Rinnova prima di questa data per evitare interruzioni.',
        'action' => 'Rinnova ora',
        'line_3' => 'Dopo questa data, la tua azienda passerà al piano gratuito e le funzionalità a pagamento verranno disattivate.',
    ],

    'subscription_lapsed' => [
        'subject' => 'Il tuo abbonamento è scaduto — ora sei sul piano gratuito',
        'line_1' => 'Il tuo abbonamento :plan non è stato rinnovato; la tua azienda è ora sul piano :free.',
        'line_2' => 'I tuoi dati sono conservati. Puoi riabbonarti in qualsiasi momento per ripristinare le funzionalità a pagamento.',
        'action' => 'Vedi i piani',
    ],

    'push' => [
        'quote_received' => [
            'title' => 'Nuovo preventivo ricevuto',
            'body' => ':supplier ha inviato un preventivo per la richiesta :rfq.',
        ],
        'order_status_changed' => [
            'title' => "Stato dell'ordine aggiornato",
            'body' => "L'ordine :order è ora :status.",
        ],
        'message_received' => [
            'title' => 'Nuovo messaggio da :sender',
        ],
        'dispute_reply' => [
            'title' => 'Nuova risposta alla tua controversia',
        ],
        'quote_accepted' => [
            'title' => 'Il tuo preventivo è stato accettato',
            'body' => "L'acquirente ha accettato il tuo preventivo per la richiesta :rfq.",
        ],
        'quote_declined' => [
            'title' => 'Il tuo preventivo è stato rifiutato',
            'body' => "L'acquirente ha rifiutato il tuo preventivo :quote.",
        ],
        'counter_offer' => [
            'title' => 'Nuova controfferta',
            'body' => ':party ha inviato una controfferta sul preventivo :quote.',
        ],
        'payment_requested' => [
            'title' => 'Pagamento richiesto',
            'body' => "Il fornitore ha richiesto il pagamento dell'ordine :order.",
        ],
        'payment_confirmed' => [
            'title' => 'Pagamento registrato',
            'body' => "Un pagamento è stato registrato sull'ordine :order.",
        ],
        'shipment_update' => [
            'title' => 'Aggiornamento sulla spedizione',
            'body' => "I dettagli di spedizione sono stati aggiornati per l'ordine :order.",
        ],
        'document_uploaded' => [
            'title' => "Nuovo documento dell'ordine",
            'body' => "Un nuovo documento è stato aggiunto all'ordine :order.",
        ],
        'dispute_opened' => [
            'title' => 'È stata aperta una controversia',
            'body' => "È stata aperta una controversia sull'ordine :order.",
        ],
        'rfq_routed' => [
            'title' => 'Nuova richiesta acquirente',
            'body' => 'Una richiesta di un acquirente verificato è stata inoltrata alla tua azienda.',
        ],
    ],

    'trial_ended' => [
        'subject' => 'La tua prova gratuita :plan è terminata',
        'line_1' => 'La tua prova gratuita di :plan è terminata e non è stato effettuato alcun pagamento.',
        'line_2' => 'La tua azienda è ora sul piano gratuito. Abbonati qui sotto per mantenere le funzionalità a pagamento.',
        'action' => 'Abbonati',
    ],

];
