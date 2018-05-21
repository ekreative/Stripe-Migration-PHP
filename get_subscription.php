<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

\Stripe\Stripe::setApiKey(SOURCE_KEY);
$sub = \Stripe\Subscription::retrieve("sub_");
echo $sub['id'];
