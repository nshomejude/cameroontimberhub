<?php

// User-facing copy for transactional emails (app/Mail/*) and notifications
// (app/Notifications/*). Extracted in Batch D4 of the production-readiness plan.
//
// Locale: notifications sent to a User currently render in config('app.locale')
// because there is no users.locale column yet.
// TODO: per-recipient locale once users.locale exists.

return [

    // ----- app/Mail/RfqVerificationMail + emails/rfq-verification.blade.php -----
    'rfq_verification' => [
        'subject' => 'Confirm your quote request — :reference',
        'heading' => 'Confirm your request',
        'intro' => 'Thanks for your request :reference. Please confirm your email so we can route it to verified Cameroonian timber exporters.',
        'action' => 'Confirm request',
        'disclaimer' => 'Submitting a request does not constitute a contract. Buyers should conduct final due diligence before any transaction.',
        'salutation' => 'Thanks,',
    ],

    // ----- app/Mail/InquiryVerificationMail + emails/inquiry-verification.blade.php -----
    'inquiry_verification' => [
        'subject' => 'Confirm your inquiry — Cameroon Timber Hub',
        'heading' => 'Confirm your inquiry',
        'intro' => 'Please confirm your email so your message can be delivered to the exporter.',
        'action' => 'Confirm inquiry',
        'disclaimer' => 'Buyers should conduct final due diligence before any transaction.',
        'salutation' => 'Thanks,',
    ],

    // ----- app/Mail/QuoteSubmittedMail + emails/quote-submitted.blade.php -----
    'quote_submitted' => [
        'subject' => 'New quote for :reference — :company',
        'heading' => 'You have a new quote',
        'intro' => ':company has responded to your request :reference.',
        'quote_reference' => 'Quote reference:',
        'total' => 'Total:',
        'lead_time' => 'Lead time:',
        'lead_time_value' => ':days days',
        'valid_until' => 'Valid until:',
        'action' => 'Review your quotes',
        'personal_link' => 'This link is personal to your request — please do not forward it.',
        'disclaimer' => 'Receiving a quote does not constitute a contract. Buyers should conduct final due diligence before any transaction.',
        'salutation' => 'Thanks,',
    ],

    // ----- app/Mail/ContactMessageMail + emails/contact-message.blade.php -----
    // Internal team mailbox message.
    'contact_message' => [
        'subject' => '[CTH Contact] :subject',
        'heading' => 'New contact form message',
        'name' => 'Name:',
        'company' => 'Company:',
        'email' => 'Email:',
        'phone' => 'Phone:',
        'subject_label' => 'Subject:',
        'reply_hint' => 'Reply directly to this email to answer the sender.',
    ],

    // ----- app/Mail/ErrorDigestMail — internal ops digest -----
    // The digest body is rendered server-side in English (ops-only) and is
    // intentionally not translated.
    'error_digest' => [
        'subject' => '[:app] Error digest — :count error(s) in the last window',
    ],

    // ----- app/Notifications/CompanyVerifiedNotification -----
    'company_verified' => [
        'subject' => 'Your company has been verified — :company',
        'line_1' => 'Congratulations! :company has been verified by the Cameroon Timber Hub team.',
        'line_2' => 'Your verified badge is now live on the public directory.',
        'action' => 'View your dashboard',
        'line_3' => 'Documents reviewed by Cameroon Timber Hub based on information submitted by the company. Buyers should conduct final due diligence before any transaction.',
    ],

    // ----- app/Notifications/DocumentExpiring -----
    'document_expiring' => [
        'subject' => 'Compliance document expiry — :company',
        'fallback_type' => 'compliance document',
        'expired_line_1' => 'Your :type has expired.',
        'expired_line_2' => 'Please upload a current document to keep your verified listing active.',
        'expiring_line_1' => 'Your :type expires in :days days (on :date).',
        'expiring_line_2' => 'Please renew it before it lapses to keep your verified listing.',
        'action' => 'Manage documents',
    ],

    // ----- app/Notifications/RfqRoutedToExporter -----
    'rfq_routed_to_exporter' => [
        'subject' => 'New buyer lead — :reference',
        'line_1' => 'A verified buyer request has been routed to your company.',
        'action' => 'View in your dashboard',
        'line_2' => 'Reference: :reference',
    ],

    // ----- app/Notifications/RfqWithdrawnNotification -----
    'rfq_withdrawn' => [
        'subject' => 'Buyer request withdrawn — :reference',
        'line_1' => 'The buyer has withdrawn request :reference. No quote is needed any more.',
        'line_2' => 'Any draft quote you prepared for it can be discarded.',
        'title' => 'Request withdrawn',
        'body' => 'The buyer withdrew request :reference.',
    ],

    // ----- app/Notifications/DisputeDecisionNotification -----
    'dispute_decision' => [
        'resolved' => [
            'subject' => 'Dispute decided — order :order',
            'title' => 'Dispute decided',
            'line' => 'Our team has decided the dispute on order :order.',
        ],
        'appeal_decided' => [
            'subject' => 'Appeal decided — order :order',
            'title' => 'Appeal decided',
            'line' => 'Our team has re-reviewed the appealed dispute on order :order and issued a new decision.',
        ],
        'closed' => [
            'subject' => 'Dispute closed — order :order',
            'title' => 'Dispute closed',
            'line' => 'The dispute on order :order is now closed.',
        ],
        'notes' => 'Decision: :notes',
        'action' => 'View dispute',
    ],

    // ----- app/Notifications/DisputeOpenedStaffNotification -----
    'dispute_opened_staff' => [
        'subject' => 'New dispute on order :order',
        'title' => 'New dispute to review',
        'line' => 'A :category dispute was opened on order :order and needs staff attention.',
        'action' => 'Open disputes',
    ],

    'dispute_appealed_staff' => [
        'subject' => 'Dispute appealed on order :order',
        'title' => 'Dispute appeal to review',
        'line' => 'A party appealed the decision on the :category dispute for order :order. Move it back to review and re-decide it.',
        'action' => 'Open disputes',
    ],

    // ----- app/Notifications/MessageReceivedNotification (mail, coalesced) -----
    'message_received_mail' => [
        'subject' => 'New message from :sender',
        'someone' => 'Someone',
        'line_1' => ':sender sent you a message on Cameroon Timber Hub:',
        'action' => 'Open the conversation',
        'line_2' => 'To avoid flooding your inbox we send at most one email per conversation every :minutes minutes — open the conversation to see everything.',
    ],

    // ----- app/Notifications/LeadReceivedNotification -----
    'lead_received' => [
        'subject' => 'New buyer inquiry from :name',
        'line_1' => ':name has sent your company a confirmed inquiry.',
        'action' => 'View the lead',
        'line_2' => 'Reply quickly — buyers usually contact several suppliers.',
    ],

    // ----- app/Notifications/ShipmentAssignedNotification -----
    'shipment_assigned' => [
        'subject' => 'You were assigned as carrier for shipment :waybill',
        'line_1' => ':supplier assigned your company as carrier for shipment :waybill (:origin → :destination).',
        'action' => 'View the shipment',
        'line_2' => 'If your company did not agree to move this shipment, contact the supplier or Cameroon Timber Hub support.',
    ],

    'shipment_booking_requested' => [
        'subject' => 'Booking request for shipment :waybill',
        'line_1' => ':supplier asked your company to carry shipment :waybill (:origin → :destination).',
        'action' => 'Accept or decline',
        'line_2' => 'Please accept or decline this booking so the supplier can plan the shipment.',
    ],

    'shipment_booking_accepted' => [
        'subject' => ':carrier accepted the booking for shipment :waybill',
        'line_1' => ':carrier accepted your booking request for shipment :waybill (order :order).',
        'action' => 'View the shipment',
    ],

    'shipment_booking_declined' => [
        'subject' => ':carrier declined the booking for shipment :waybill',
        'line_1' => ':carrier declined your booking request for shipment :waybill (order :order).',
        'reason' => 'Reason: :reason',
        'action' => 'Choose another carrier',
    ],

    // ----- app/Notifications/ShipmentDeliveredNotification -----
    'shipment_delivered' => [
        'supplier' => [
            'subject' => 'Shipment :waybill delivered (order :order)',
            'line_1' => 'The carrier recorded shipment :waybill for order :order as delivered.',
            'action' => 'View the shipment',
            'line_2' => 'The order status has not changed — mark the order delivered once you have confirmed it.',
        ],
        'buyer' => [
            'subject' => 'Goods delivered — please confirm (order :order)',
            'line_1' => 'Shipment :waybill for your order :order was recorded as delivered.',
            'action' => 'Confirm receipt',
            'line_2' => 'Please check the goods and confirm receipt on your order.',
        ],
    ],

    // ----- app/Mail/BuyerRfqRoutedMail + BuyerRfqRejectedMail -----
    'buyer_rfq_routed' => [
        'subject' => 'Your request :reference was sent to suppliers',
        'heading' => 'Your request is with suppliers',
        'intro' => 'Good news — your request :reference has been reviewed and sent to :count verified supplier(s).',
        'next' => 'Suppliers reply with quotes by email. You can follow responses using the button below.',
        'action' => 'View responses',
        'salutation' => 'Thanks,',
    ],
    'buyer_rfq_rejected' => [
        'subject' => 'Update on your request :reference',
        'heading' => 'We could not route your request',
        'intro' => 'Thank you for your request :reference. After review, we are unable to send it to suppliers at this time.',
        'reason' => 'Reason: :reason',
        'next' => 'If you believe this is a mistake or can add more detail, simply reply to this email or submit a new request.',
        'salutation' => 'Kind regards,',
    ],

    // ----- app/Notifications/SubscriptionRenewalReminder (billing engine M6) -----
    'subscription_renewal' => [
        'subject' => 'Your :plan plan renews soon',
        'line_1' => 'Your :plan subscription is due to renew on :date for :amount.',
        'line_2' => 'Mobile-money payments cannot be charged automatically, so please renew from the link below before your term ends.',
        'action' => 'Renew now',
        'line_3' => 'If you do nothing, your plan enters a 7-day grace period after the renewal date, then moves to the Free plan.',
    ],

    // ----- app/Notifications/SubscriptionPastDue (billing engine M6) -----
    'subscription_past_due' => [
        'subject' => 'Payment past due — :plan plan',
        'line_1' => 'We have not received payment of :amount for your :plan subscription.',
        'line_2' => 'Your access continues until :date. Renew before then to avoid any interruption.',
        'action' => 'Renew now',
        'line_3' => 'After that date your company moves to the Free plan and paid features stop.',
    ],

    // ----- app/Notifications/SubscriptionLapsedToFree (billing engine M6) -----
    'subscription_lapsed' => [
        'subject' => 'Your subscription has lapsed — you are now on the Free plan',
        'line_1' => 'Your :plan subscription was not renewed, so your company is now on the :free plan.',
        'line_2' => 'Your data is safe. You can re-subscribe at any time to restore paid features.',
        'action' => 'View plans',
    ],

    // ----- app/Notifications/TrialEndedUnpaid (billing engine M6) -----
    'trial_ended' => [
        'subject' => 'Your :plan free trial has ended',
        'line_1' => 'Your free trial of :plan has ended and no payment was taken.',
        'line_2' => 'Your company is now on the Free plan. Subscribe below to keep the paid features.',
        'action' => 'Subscribe',
    ],

    // ----- mobile push/database notification centre (App\Notifications\*) -----
    // `title`/`body` here are baked into the notification's stored `data` at
    // CREATION time (see each class's docblock) using whatever locale is
    // active when `app()->getLocale()` resolves for that request — there is
    // no `users.locale` column yet, so a per-recipient locale is not
    // possible; the current request's resolved locale is used as the
    // documented fallback (same limitation `lang/en/notifications.php`'s
    // header already notes for mail).
    'push' => [
        'quote_received' => [
            'title' => 'New quote received',
            'body' => ':supplier submitted a quote for RFQ :rfq.',
        ],
        'order_status_changed' => [
            'title' => 'Order status updated',
            'body' => 'Order :order is now :status.',
        ],
        'message_received' => [
            'title' => 'New message from :sender',
        ],
        'dispute_reply' => [
            'title' => 'New reply on your dispute',
        ],
        'support_ticket_opened' => [
            'title' => 'New support ticket',
        ],
        'support_ticket_user_reply' => [
            'title' => 'New reply on a support ticket',
        ],
        'support_ticket_staff_reply' => [
            'title' => 'Support replied to your ticket',
        ],
        'quote_accepted' => [
            'title' => 'Your quote was accepted',
            'body' => 'The buyer accepted your quote for RFQ :rfq.',
        ],
        'transformation_request_created' => [
            'title' => 'New transformation request',
            'body' => ':requester sent you transformation request :request.',
        ],
        'transformation_request_quoted' => [
            'title' => 'Transformation request quoted',
            'body' => ':provider sent a quote for transformation request :request.',
        ],
        'transformation_request_accepted' => [
            'title' => 'Transformation request accepted',
            'body' => ':requester accepted transformation request :request.',
        ],
        'transformation_request_completed' => [
            'title' => 'Transformation request completed',
            'body' => ':provider completed transformation request :request.',
        ],
        'quote_declined' => [
            'title' => 'Your quote was declined',
            'body' => 'The buyer declined your quote :quote.',
        ],
        'counter_offer' => [
            'title' => 'New counter-offer',
            'body' => ':party sent a counter-offer on quotation :quote.',
        ],
        'payment_requested' => [
            'title' => 'Payment requested',
            'body' => 'The supplier requested payment for order :order.',
        ],
        'referral_signed_up' => [
            'title' => 'New referral signed up',
            'body' => ':name joined Cameroon Timber Hub with your referral code.',
        ],
        'referral_commission_earned' => [
            'title' => 'Referral commission earned',
            'body' => 'You earned :amount from a referral\'s first subscription payment.',
        ],
        'payment_confirmed' => [
            'title' => 'Payment recorded',
            'body' => 'A payment was recorded on order :order.',
        ],
        'shipment_update' => [
            'title' => 'Shipment update',
            'body' => 'Shipment details were updated for order :order.',
        ],
        'document_uploaded' => [
            'title' => 'New order document',
            'body' => 'A new document was attached to order :order.',
        ],
        'dispute_opened' => [
            'title' => 'A dispute was opened',
            'body' => 'A dispute was opened on order :order.',
        ],
        'rfq_routed' => [
            'title' => 'New buyer lead',
            'body' => 'A verified buyer request was routed to your company.',
        ],
    ],

];
