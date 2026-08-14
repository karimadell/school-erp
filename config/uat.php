<?php

return [
    'enabled' => env('UAT_SEED_ENABLED', false),

    'passwords' => [
        'admin' => env('UAT_ADMIN_PASSWORD'),
        'accountant' => env('UAT_ACCOUNTANT_PASSWORD'),
        'cashier' => env('UAT_CASHIER_PASSWORD'),
        'reception' => env('UAT_RECEPTION_PASSWORD'),
    ],
];
