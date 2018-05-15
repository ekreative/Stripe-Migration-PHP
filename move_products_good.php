<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

use Stripe\Stripe;

// SOURCE ACCOUNT
Stripe::setApiKey(SOURCE_KEY);

$productsRes = \Stripe\Product::all(['limit' => 100, 'type' => 'good']);
$products = $productsRes['data'];

Stripe::setApiKey(DESTINATION_KEY);

foreach ($products as $product) {
    \Stripe\Product::create([
        'id' => $product['id'],
        'name' => $product['name'],
        'type' => $product['type'],
        'shippable' => $product['shippable'],
    ]);

    foreach ($product['skus']['data'] as $sku) {
        \Stripe\SKU::create([
            'id' => $sku['id'],
            'product' => $product['id'],
            'price' => $sku['price'],
            'currency' => $sku['currency'],
            'inventory' => [
                'type' => 'infinite',
            ],
        ]);
    }

    echo "created product {$product['id']}\n";
}
