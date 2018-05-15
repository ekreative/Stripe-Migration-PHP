<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

use Stripe\Stripe;

// SOURCE ACCOUNT
Stripe::setApiKey(DESTINATION_KEY);

$productsRes = \Stripe\Product::all(['limit' => 100, 'type' => 'good']);
$products = $productsRes['data'];

$t = new \Symfony\Component\Console\Helper\Table(new Symfony\Component\Console\Output\ConsoleOutput());
$t->setHeaders(['id', 'skus']);

foreach ($products as $product) {
    $skus = \Stripe\SKU::all(['limit' => 100, 'product' => $product['id']]);

    $skusIds = array_map(function ($a) {
        return $a['id'];
    }, $skus['data']);

    foreach ($skus['data'] as $sku) {
        $sku->delete();
        echo "deleted sku {$sku['id']}\n";
    }

    $product->delete();
    echo "deleted {$product['id']}\n";

    $t->addRow([$product['id'], implode(', ', $skusIds)]);
}

$t->render();
