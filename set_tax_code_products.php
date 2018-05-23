<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

use Stripe\Stripe;

// SOURCE ACCOUNT
Stripe::setApiKey(DESTINATION_KEY);

$productsRes = \Stripe\Product::all(['limit' => 100]);
$products = $productsRes['data'];

$t = new \Symfony\Component\Console\Helper\Table(new Symfony\Component\Console\Output\ConsoleOutput());
$t->setHeaders(['id', 'name']);

foreach ($products as $product) {
    $product['metadata']['tax_code'] = '30070';
    $product->save();

    $t->addRow([$product['id'], $product['name']]);
}

$t->render();
