<?php

use App\Models\Landlord\LandlordAdmin;

return [

    'defaults' => [
        'guard' => 'api',
        'passwords' => 'landlord_admins',
    ],

    'guards' => [
        'api' => [
            'driver' => 'jwt',
            'provider' => 'landlord_admins',
        ],
    ],

    'providers' => [
        'landlord_admins' => [
            'driver' => 'eloquent',
            'model' => LandlordAdmin::class,
        ],
    ],

    'passwords' => [
        'landlord_admins' => [
            'provider' => 'landlord_admins',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
