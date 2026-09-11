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

    'trial_ended' => [
        'subject' => 'Votre essai gratuit :plan est terminé',
        'line_1' => 'Votre essai gratuit de :plan est terminé et aucun paiement n\'a été effectué.',
        'line_2' => 'Votre société est maintenant sur l\'offre gratuite. Abonnez-vous ci-dessous pour conserver les fonctionnalités payantes.',
        'action' => 'S\'abonner',
    ],

];
