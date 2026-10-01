<?php

// Traduction française de lang/en/notifications.php — registre e-mail
// transactionnel, professionnel et naturel. Batch D4.
//
// Locale : les notifications envoyées à un utilisateur s'affichent pour
// l'instant dans config('app.locale') faute de colonne users.locale.
// TODO: locale par destinataire une fois users.locale disponible.

return [

    'rfq_verification' => [
        'subject' => 'Confirmez votre demande de devis — :reference',
        'heading' => 'Confirmez votre demande',
        'intro' => 'Merci pour votre demande :reference. Veuillez confirmer votre adresse e-mail afin que nous puissions la transmettre à des exportateurs de bois camerounais vérifiés.',
        'action' => 'Confirmer la demande',
        'disclaimer' => "L'envoi d'une demande ne constitue pas un contrat. Les acheteurs doivent effectuer leur propre vérification préalable avant toute transaction.",
        'salutation' => 'Merci,',
    ],

    'inquiry_verification' => [
        'subject' => 'Confirmez votre message — Cameroon Timber Hub',
        'heading' => 'Confirmez votre message',
        'intro' => 'Veuillez confirmer votre adresse e-mail afin que votre message puisse être transmis à l\'exportateur.',
        'action' => 'Confirmer le message',
        'disclaimer' => 'Les acheteurs doivent effectuer leur propre vérification préalable avant toute transaction.',
        'salutation' => 'Merci,',
    ],

    'quote_submitted' => [
        'subject' => 'Nouveau devis pour :reference — :company',
        'heading' => 'Vous avez reçu un nouveau devis',
        'intro' => ':company a répondu à votre demande :reference.',
        'quote_reference' => 'Référence du devis :',
        'total' => 'Total :',
        'lead_time' => 'Délai :',
        'lead_time_value' => ':days jours',
        'valid_until' => "Valable jusqu'au :",
        'action' => 'Consulter vos devis',
        'personal_link' => 'Ce lien est personnel à votre demande — merci de ne pas le transférer.',
        'disclaimer' => "La réception d'un devis ne constitue pas un contrat. Les acheteurs doivent effectuer leur propre vérification préalable avant toute transaction.",
        'salutation' => 'Merci,',
    ],

    'contact_message' => [
        'subject' => '[CTH Contact] :subject',
        'heading' => 'Nouveau message du formulaire de contact',
        'name' => 'Nom :',
        'company' => 'Société :',
        'email' => 'E-mail :',
        'phone' => 'Téléphone :',
        'subject_label' => 'Objet :',
        'reply_hint' => 'Répondez directement à cet e-mail pour contacter l\'expéditeur.',
    ],

    'error_digest' => [
        'subject' => '[:app] Récapitulatif des erreurs — :count erreur(s) sur la dernière période',
    ],

    'company_verified' => [
        'subject' => 'Votre société a été vérifiée — :company',
        'line_1' => 'Félicitations ! :company a été vérifiée par l\'équipe de Cameroon Timber Hub.',
        'line_2' => 'Votre badge « vérifié » est désormais visible dans l\'annuaire public.',
        'action' => 'Accéder à votre tableau de bord',
        'line_3' => 'Documents examinés par Cameroon Timber Hub sur la base des informations communiquées par la société. Les acheteurs doivent effectuer leur propre vérification préalable avant toute transaction.',
    ],

    'document_expiring' => [
        'subject' => 'Expiration d\'un document de conformité — :company',
        'fallback_type' => 'document de conformité',
        'expired_line_1' => 'Votre :type a expiré.',
        'expired_line_2' => 'Veuillez téléverser un document à jour pour conserver votre fiche vérifiée active.',
        'expiring_line_1' => 'Votre :type expire dans :days jours (le :date).',
        'expiring_line_2' => 'Veuillez le renouveler avant son expiration pour conserver votre fiche vérifiée.',
        'action' => 'Gérer les documents',
    ],

    'rfq_routed_to_exporter' => [
        'subject' => 'Nouvelle piste acheteur — :reference',
        'line_1' => 'Une demande d\'acheteur vérifié a été transmise à votre société.',
        'action' => 'Consulter dans votre tableau de bord',
        'line_2' => 'Référence : :reference',
    ],

    'rfq_withdrawn' => [
        'subject' => 'Demande acheteur retirée — :reference',
        'line_1' => 'L\'acheteur a retiré la demande :reference. Aucun devis n\'est plus nécessaire.',
        'line_2' => 'Vous pouvez abandonner tout brouillon de devis préparé pour cette demande.',
        'title' => 'Demande retirée',
        'body' => 'L\'acheteur a retiré la demande :reference.',
    ],

    'message_received_mail' => [
        'subject' => 'Nouveau message de :sender',
        'someone' => 'Quelqu\'un',
        'line_1' => ':sender vous a envoyé un message sur Cameroon Timber Hub :',
        'action' => 'Ouvrir la conversation',
        'line_2' => 'Pour ne pas encombrer votre boîte, nous envoyons au plus un e-mail par conversation toutes les :minutes minutes — ouvrez la conversation pour tout voir.',
    ],

    'lead_received' => [
        'subject' => 'Nouvelle demande acheteur de :name',
        'line_1' => ':name a envoyé une demande confirmée à votre société.',
        'action' => 'Voir la piste',
        'line_2' => 'Répondez rapidement — les acheteurs contactent souvent plusieurs fournisseurs.',
    ],

    'shipment_assigned' => [
        'subject' => 'Vous avez été désigné transporteur de l\'expédition :waybill',
        'line_1' => ':supplier a désigné votre société comme transporteur de l\'expédition :waybill (:origin → :destination).',
        'action' => 'Voir l\'expédition',
        'line_2' => 'Si votre société n\'a pas accepté ce transport, contactez le fournisseur ou le support Cameroon Timber Hub.',
    ],

    'shipment_delivered' => [
        'supplier' => [
            'subject' => 'Expédition :waybill livrée (commande :order)',
            'line_1' => 'Le transporteur a enregistré l\'expédition :waybill de la commande :order comme livrée.',
            'action' => 'Voir l\'expédition',
            'line_2' => 'Le statut de la commande n\'a pas changé — marquez-la livrée une fois la livraison confirmée.',
        ],
        'buyer' => [
            'subject' => 'Marchandises livrées — merci de confirmer (commande :order)',
            'line_1' => 'L\'expédition :waybill de votre commande :order a été enregistrée comme livrée.',
            'action' => 'Confirmer la réception',
            'line_2' => 'Vérifiez les marchandises et confirmez la réception sur votre commande.',
        ],
    ],

    'buyer_rfq_routed' => [
        'subject' => 'Votre demande :reference a été transmise aux fournisseurs',
        'heading' => 'Votre demande est chez les fournisseurs',
        'intro' => 'Bonne nouvelle — votre demande :reference a été examinée et transmise à :count fournisseur(s) vérifié(s).',
        'next' => 'Les fournisseurs répondent par e-mail avec leurs offres. Suivez les réponses avec le bouton ci-dessous.',
        'action' => 'Voir les réponses',
        'salutation' => 'Merci,',
    ],
    'buyer_rfq_rejected' => [
        'subject' => 'Mise à jour de votre demande :reference',
        'heading' => 'Nous ne pouvons pas transmettre votre demande',
        'intro' => 'Merci pour votre demande :reference. Après examen, nous ne pouvons pas la transmettre aux fournisseurs pour le moment.',
        'reason' => 'Motif : :reason',
        'next' => 'Si vous pensez qu\'il s\'agit d\'une erreur ou pouvez apporter plus de détails, répondez simplement à cet e-mail ou soumettez une nouvelle demande.',
        'salutation' => 'Cordialement,',
    ],

    'subscription_renewal' => [
        'subject' => 'Votre offre :plan se renouvelle bientôt',
        'line_1' => 'Votre abonnement :plan doit être renouvelé le :date pour :amount.',
        'line_2' => 'Les paiements par mobile money ne peuvent pas être prélevés automatiquement : veuillez renouveler via le lien ci-dessous avant la fin de votre période.',
        'action' => 'Renouveler maintenant',
        'line_3' => 'Sans action de votre part, votre offre passe par une période de grâce de 7 jours après la date de renouvellement, puis bascule vers l\'offre gratuite.',
    ],

    'subscription_past_due' => [
        'subject' => 'Paiement en retard — offre :plan',
        'line_1' => 'Nous n\'avons pas reçu le paiement de :amount pour votre abonnement :plan.',
        'line_2' => 'Votre accès est maintenu jusqu\'au :date. Renouvelez avant cette date pour éviter toute interruption.',
        'action' => 'Renouveler maintenant',
        'line_3' => 'Après cette date, votre société bascule vers l\'offre gratuite et les fonctionnalités payantes sont désactivées.',
    ],

    'subscription_lapsed' => [
        'subject' => 'Votre abonnement a expiré — vous êtes maintenant sur l\'offre gratuite',
        'line_1' => 'Votre abonnement :plan n\'a pas été renouvelé ; votre société est désormais sur l\'offre :free.',
        'line_2' => 'Vos données sont conservées. Vous pouvez vous réabonner à tout moment pour rétablir les fonctionnalités payantes.',
        'action' => 'Voir les offres',
    ],

    'push' => [
        'quote_received' => [
            'title' => 'Nouveau devis reçu',
            'body' => ':supplier a soumis un devis pour la demande :rfq.',
        ],
        'order_status_changed' => [
            'title' => 'Statut de la commande mis à jour',
            'body' => 'La commande :order est maintenant :status.',
        ],
        'message_received' => [
            'title' => 'Nouveau message de :sender',
        ],
        'dispute_reply' => [
            'title' => 'Nouvelle réponse à votre litige',
        ],
        'quote_accepted' => [
            'title' => 'Votre devis a été accepté',
            'body' => 'L\'acheteur a accepté votre devis pour la demande :rfq.',
        ],
        'transformation_request_created' => [
            'title' => 'Nouvelle demande de transformation',
            'body' => ':requester vous a envoyé la demande de transformation :request.',
        ],
        'transformation_request_quoted' => [
            'title' => 'Devis reçu pour la demande de transformation',
            'body' => ':provider a envoyé un devis pour la demande de transformation :request.',
        ],
        'transformation_request_accepted' => [
            'title' => 'Demande de transformation acceptée',
            'body' => ':requester a accepté la demande de transformation :request.',
        ],
        'transformation_request_completed' => [
            'title' => 'Demande de transformation terminée',
            'body' => ':provider a terminé la demande de transformation :request.',
        ],
        'quote_declined' => [
            'title' => 'Votre devis a été refusé',
            'body' => 'L\'acheteur a refusé votre devis :quote.',
        ],
        'counter_offer' => [
            'title' => 'Nouvelle contre-offre',
            'body' => ':party a envoyé une contre-offre sur le devis :quote.',
        ],
        'payment_requested' => [
            'title' => 'Paiement demandé',
            'body' => 'Le fournisseur a demandé le paiement de la commande :order.',
        ],
        'payment_confirmed' => [
            'title' => 'Paiement enregistré',
            'body' => 'Un paiement a été enregistré sur la commande :order.',
        ],
        'shipment_update' => [
            'title' => 'Mise à jour de l\'expédition',
            'body' => 'Les détails d\'expédition ont été mis à jour pour la commande :order.',
        ],
        'document_uploaded' => [
            'title' => 'Nouveau document de commande',
            'body' => 'Un nouveau document a été ajouté à la commande :order.',
        ],
        'dispute_opened' => [
            'title' => 'Un litige a été ouvert',
            'body' => 'Un litige a été ouvert sur la commande :order.',
        ],
        'rfq_routed' => [
            'title' => 'Nouvelle demande acheteur',
            'body' => 'Une demande d\'acheteur vérifiée a été transmise à votre société.',
        ],
    ],

    'trial_ended' => [
        'subject' => 'Votre essai gratuit :plan est terminé',
        'line_1' => 'Votre essai gratuit de :plan est terminé et aucun paiement n\'a été effectué.',
        'line_2' => 'Votre société est maintenant sur l\'offre gratuite. Abonnez-vous ci-dessous pour conserver les fonctionnalités payantes.',
        'action' => 'S\'abonner',
    ],

];
