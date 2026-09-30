<?php

return [
    'app_name' => 'XHTTP SaaS',
    'version' => '0.1.0',
    'database' => __DIR__ . '/data/database.sqlite',

    'telegram' => [
        'token' => '8873266818:AAFwMYpHJpX-aPKZZ5iUVpynBVS5ojqDuKU',
        'admin_id' => '',
    ],

    'security' => [
        'encryption_key' => '',
    ],
    'mercadopago' => [
        'access_token_env' => 'MP_ACCESS_TOKEN',
    ],
];
