<?php

return [
    'enabled' => (bool) env('FRAMEKEEP_TEST_PAYMENTS', false),
    'stripe' => [
        'key' => env('STRIPE_TEST_SECRET_KEY'),
        'price' => env('STRIPE_TEST_YEARLY_PRICE_ID'),
        'webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    ],
    'razorpay' => [
        'key' => env('RAZORPAY_TEST_KEY_ID'),
        'secret' => env('RAZORPAY_TEST_KEY_SECRET'),
        'price' => env('RAZORPAY_TEST_YEARLY_PLAN_ID'),
        'webhook_secret' => env('RAZORPAY_TEST_WEBHOOK_SECRET'),
    ],
];
