<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

use Stripe\Stripe;

// SOURCE ACCOUNT
Stripe::setApiKey(DESTINATION_KEY);

$productsRes = \Stripe\Product::all(['limit' => 100, 'type' => 'service']);
$products = $productsRes['data'];

$t = new \Symfony\Component\Console\Helper\Table(new Symfony\Component\Console\Output\ConsoleOutput());
$t->setHeaders(['id', 'plans']);

foreach ($products as $product) {
    $plans = \Stripe\Plan::all(['limit' => 100, 'product' => $product['id']]);

    $planIds = array_map(function ($a) {
        return $a['id'];
    }, $plans['data']);

    if (!count($planIds)) {
        $product->delete();

        echo "deleted {$product['id']}\n";
    }

    $t->addRow([$product['id'], implode(', ', $planIds)]);
}

$t->render();
