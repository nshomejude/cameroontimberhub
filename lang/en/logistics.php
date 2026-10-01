<?php

return [
    'errors' => [
        'vehicle_unavailable' => 'That vehicle is not available for this order.',
        'driver_unavailable' => 'That driver is not available for this order.',
        'carrier_unavailable' => 'That carrier is not available for this order.',
        'fleet_company_mismatch' => 'The vehicle, driver and carrier must all belong to the same company.',
        'checkpoint_invalid' => 'This checkpoint could not be saved. Please check the form and try again.',
        'checkpoint_login_required' => 'Please log in to record checkpoints.',
        'checkpoint_forbidden' => 'Only the carrier or the supplier on this shipment can record checkpoints.',
        'order_not_shippable' => 'Shipments cannot be created for a delivered, completed or cancelled order.',
        'fleet_in_use' => 'This record is assigned to a shipment that is still in transit and cannot be deleted.',
    ],
];
