<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First System Admin
    |--------------------------------------------------------------------------
    |
    | Lets hr:create-admin run without prompting. Remove the value from .env
    | after its one-time use.
    |
    */

    'admin_password' => env('HR_ADMIN_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | First mail server
    |--------------------------------------------------------------------------
    |
    | Lets hr:configure-smtp save the mail server before anyone can sign in,
    | because signing in needs an emailed code. Remove the values from .env
    | after their one-time use.
    |
    */

    'smtp' => [
        'host' => env('HR_SMTP_HOST'),
        'port' => env('HR_SMTP_PORT', 587),
        'security' => env('HR_SMTP_SECURITY', 'starttls'),
        'username' => env('HR_SMTP_USERNAME'),
        'password' => env('HR_SMTP_PASSWORD'),
        'from_address' => env('HR_SMTP_FROM_ADDRESS'),
        'from_name' => env('HR_SMTP_FROM_NAME', 'HR System'),
    ],

];
