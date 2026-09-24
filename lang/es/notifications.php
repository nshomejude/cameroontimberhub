<?php

/* Machine-translated (first pass) — flagged for native speaker review before this ships as a claimed-accurate translation. Do not remove this notice until reviewed. */

return [

    'rfq_verification' => [
        'subject' => 'Confirme su solicitud de cotización — :reference',
        'heading' => 'Confirme su solicitud',
        'intro' => 'Gracias por su solicitud :reference. Confirme su correo electrónico para que podamos transmitirla a exportadores de madera camerunesa verificados.',
        'action' => 'Confirmar la solicitud',
        'disclaimer' => 'Enviar una solicitud no constituye un contrato. Los compradores deben realizar su propia verificación previa antes de cualquier transacción.',
        'salutation' => 'Gracias,',
    ],

    'inquiry_verification' => [
        'subject' => 'Confirme su mensaje — Cameroon Timber Hub',
        'heading' => 'Confirme su mensaje',
        'intro' => 'Confirme su correo electrónico para que su mensaje pueda transmitirse al exportador.',
        'action' => 'Confirmar el mensaje',
        'disclaimer' => 'Los compradores deben realizar su propia verificación previa antes de cualquier transacción.',
        'salutation' => 'Gracias,',
    ],

    'quote_submitted' => [
        'subject' => 'Nueva cotización para :reference — :company',
        'heading' => 'Ha recibido una nueva cotización',
        'intro' => ':company ha respondido a su solicitud :reference.',
        'quote_reference' => 'Referencia de la cotización:',
        'total' => 'Total:',
        'lead_time' => 'Plazo:',
        'lead_time_value' => ':days días',
        'valid_until' => 'Válida hasta:',
        'action' => 'Ver sus cotizaciones',
        'personal_link' => 'Este enlace es personal para su solicitud — le rogamos no reenviarlo.',
        'disclaimer' => 'Recibir una cotización no constituye un contrato. Los compradores deben realizar su propia verificación previa antes de cualquier transacción.',
        'salutation' => 'Gracias,',
    ],

    'contact_message' => [
        'subject' => '[CTH Contacto] :subject',
        'heading' => 'Nuevo mensaje del formulario de contacto',
        'name' => 'Nombre:',
        'company' => 'Empresa:',
        'email' => 'Correo electrónico:',
        'phone' => 'Teléfono:',
        'subject_label' => 'Asunto:',
        'reply_hint' => 'Responda directamente a este correo electrónico para contactar al remitente.',
    ],

    'error_digest' => [
        'subject' => '[:app] Resumen de errores — :count error(es) en el último período',
    ],

    'company_verified' => [
        'subject' => 'Su empresa ha sido verificada — :company',
        'line_1' => '¡Felicidades! :company ha sido verificada por el equipo de Cameroon Timber Hub.',
        'line_2' => 'Su insignia de «verificado» ya es visible en el directorio público.',
        'action' => 'Ir a su panel',
        'line_3' => 'Documentos revisados por Cameroon Timber Hub sobre la base de la información proporcionada por la empresa. Los compradores deben realizar su propia verificación previa antes de cualquier transacción.',
    ],

    'document_expiring' => [
        'subject' => 'Vencimiento de un documento de cumplimiento — :company',
        'fallback_type' => 'documento de cumplimiento',
        'expired_line_1' => 'Su :type ha vencido.',
        'expired_line_2' => 'Suba un documento actualizado para mantener activa su ficha verificada.',
        'expiring_line_1' => 'Su :type vence en :days días (el :date).',
        'expiring_line_2' => 'Renuévelo antes de su vencimiento para mantener activa su ficha verificada.',
        'action' => 'Gestionar documentos',
    ],

    'rfq_routed_to_exporter' => [
        'subject' => 'Nueva oportunidad de comprador — :reference',
        'line_1' => 'Se ha transmitido una solicitud de comprador verificado a su empresa.',
        'action' => 'Ver en su panel',
        'line_2' => 'Referencia: :reference',
    ],

    'subscription_renewal' => [
        'subject' => 'Su plan :plan se renueva pronto',
        'line_1' => 'Su suscripción :plan debe renovarse el :date por :amount.',
        'line_2' => 'Los pagos por mobile money no pueden cobrarse automáticamente: renueve mediante el enlace a continuación antes de que finalice su período.',
        'action' => 'Renovar ahora',
        'line_3' => 'Sin ninguna acción de su parte, su plan pasa por un período de gracia de 7 días después de la fecha de renovación, y luego pasa al plan gratuito.',
    ],

    'subscription_past_due' => [
        'subject' => 'Pago atrasado — plan :plan',
        'line_1' => 'No hemos recibido el pago de :amount para su suscripción :plan.',
        'line_2' => 'Su acceso se mantiene hasta el :date. Renueve antes de esa fecha para evitar cualquier interrupción.',
        'action' => 'Renovar ahora',
        'line_3' => 'Después de esa fecha, su empresa pasa al plan gratuito y las funciones de pago se desactivan.',
    ],

    'subscription_lapsed' => [
        'subject' => 'Su suscripción ha vencido — ahora está en el plan gratuito',
        'line_1' => 'Su suscripción :plan no se renovó; su empresa ahora está en el plan :free.',
        'line_2' => 'Sus datos se conservan. Puede volver a suscribirse en cualquier momento para restablecer las funciones de pago.',
        'action' => 'Ver planes',
    ],

    'push' => [
        'quote_received' => [
            'title' => 'Nueva cotización recibida',
            'body' => ':supplier ha enviado una cotización para la solicitud :rfq.',
        ],
        'order_status_changed' => [
            'title' => 'Estado del pedido actualizado',
            'body' => 'El pedido :order ahora está :status.',
        ],
        'message_received' => [
            'title' => 'Nuevo mensaje de :sender',
        ],
        'dispute_reply' => [
            'title' => 'Nueva respuesta a su disputa',
        ],
        'quote_accepted' => [
            'title' => 'Su cotización ha sido aceptada',
            'body' => 'El comprador aceptó su cotización para la solicitud :rfq.',
        ],
        'quote_declined' => [
            'title' => 'Su cotización ha sido rechazada',
            'body' => 'El comprador rechazó su cotización :quote.',
        ],
        'counter_offer' => [
            'title' => 'Nueva contraoferta',
            'body' => ':party envió una contraoferta sobre la cotización :quote.',
        ],
        'payment_requested' => [
            'title' => 'Pago solicitado',
            'body' => 'El proveedor ha solicitado el pago del pedido :order.',
        ],
        'payment_confirmed' => [
            'title' => 'Pago registrado',
            'body' => 'Se ha registrado un pago en el pedido :order.',
        ],
        'shipment_update' => [
            'title' => 'Actualización de envío',
            'body' => 'Los detalles de envío se actualizaron para el pedido :order.',
        ],
        'document_uploaded' => [
            'title' => 'Nuevo documento de pedido',
            'body' => 'Se ha agregado un nuevo documento al pedido :order.',
        ],
        'dispute_opened' => [
            'title' => 'Se ha abierto una disputa',
            'body' => 'Se ha abierto una disputa sobre el pedido :order.',
        ],
        'rfq_routed' => [
            'title' => 'Nueva solicitud de comprador',
            'body' => 'Se ha transmitido una solicitud de comprador verificado a su empresa.',
        ],
    ],

    'trial_ended' => [
        'subject' => 'Su prueba gratuita de :plan ha terminado',
        'line_1' => 'Su prueba gratuita de :plan ha terminado y no se realizó ningún pago.',
        'line_2' => 'Su empresa ahora está en el plan gratuito. Suscríbase a continuación para conservar las funciones de pago.',
        'action' => 'Suscribirse',
    ],

];

