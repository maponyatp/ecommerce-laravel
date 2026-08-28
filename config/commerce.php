<?php

return [
    'delivery_timezone' => 'Africa/Johannesburg',
    // Catalogue amounts are denominated in ZAR. Changing this is not FX conversion.
    'currency' => 'ZAR',
    // Stock holds expire locally; this does not cancel an external payment link.
    'reservation_minutes' => 30,
    // Add destinations only after confirming carrier coverage, rates and tax rules.
    'delivery_countries' => ['ZA' => 'South Africa'],
];
