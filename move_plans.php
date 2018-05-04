<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

use Stripe\Stripe;

// SOURCE ACCOUNT
Stripe::setApiKey(SOURCE_KEY);

// Get existing plans from source account
$plans = \Stripe\Plan::all(array('limit' => 100));
$plans = (array) $plans['data'];

Stripe::setApiKey(DESTINATION_KEY);

foreach ($plans as $p) {
    \Stripe\Plan::create([
        "id" => $p['id'],
        "product" => [
            "name" => $p['name'],
            "statement_description" => $p["statement_description"],
        ],
        "amount" => $p['amount'],
        "currency" => $p['currency'],
        "interval" => $p['interval'],
        "interval_count" => $p['interval_count'],
        "trial_period_days" => $p['trial_period_days'],
    ]);

    echo "created plan {$p['id']}\n";
}
