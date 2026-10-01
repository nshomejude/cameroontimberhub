<?php

return [
    'errors' => [
        'vehicle_unavailable' => "Ce véhicule n'est pas disponible pour cette commande.",
        'driver_unavailable' => "Ce chauffeur n'est pas disponible pour cette commande.",
        'carrier_unavailable' => "Ce transporteur n'est pas disponible pour cette commande.",
        'fleet_company_mismatch' => 'Le véhicule, le chauffeur et le transporteur doivent appartenir à la même entreprise.',
        'checkpoint_invalid' => "Ce point de contrôle n'a pas pu être enregistré. Vérifiez le formulaire et réessayez.",
        'checkpoint_login_required' => 'Veuillez vous connecter pour enregistrer des points de contrôle.',
        'checkpoint_forbidden' => 'Seuls le transporteur ou le fournisseur de cette expédition peuvent enregistrer des points de contrôle.',
        'order_not_shippable' => "Impossible de créer une expédition pour une commande livrée, terminée ou annulée.",
        'fleet_in_use' => "Cet élément est affecté à une expédition en cours et ne peut pas être supprimé.",
    ],
];
