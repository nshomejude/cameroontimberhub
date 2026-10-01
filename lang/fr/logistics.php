<?php

return [
    'errors' => [
        'vehicle_unavailable' => "Ce véhicule n'est pas disponible pour cette commande.",
        'driver_unavailable' => "Ce chauffeur n'est pas disponible pour cette commande.",
        'carrier_unavailable' => "Ce transporteur n'est pas disponible pour cette commande.",
        'shipment_supplier_only' => "Seul le fournisseur de cette commande peut modifier l'affectation de l'expédition.",
        'fleet_company_mismatch' => 'Le véhicule, le chauffeur et le transporteur doivent appartenir à la même entreprise.',
        'checkpoint_invalid' => "Ce point de contrôle n'a pas pu être enregistré. Vérifiez le formulaire et réessayez.",
        'checkpoint_login_required' => 'Veuillez vous connecter pour enregistrer des points de contrôle.',
        'checkpoint_forbidden' => 'Seuls le transporteur ou le fournisseur de cette expédition peuvent enregistrer des points de contrôle.',
        'order_not_shippable' => 'Impossible de créer une expédition pour une commande livrée, terminée ou annulée.',
        'fleet_in_use' => 'Cet élément est affecté à une expédition en cours et ne peut pas être supprimé.',
        'booking_not_pending' => 'Cette expédition n\'a aucune demande de réservation en attente.',
        'carrier_only' => 'Seul le transporteur de cette expédition peut répondre à sa demande de réservation.',
        'booking_not_active' => 'Acceptez la demande de réservation avant d\'enregistrer des points de contrôle.',
    ],
];
