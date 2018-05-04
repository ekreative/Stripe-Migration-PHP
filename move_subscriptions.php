<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

// Get existing customers from source account to display and process in HTML table below
$count = 100;
while ($count == 100) {
    \Stripe\Stripe::setApiKey(SOURCE_KEY);

    $criteria = ['limit' => 100];
    // Pagination
	if (isset($starting_after)) {
	    $criteria['starting_after'] = $starting_after;
    }

	$customers = \Stripe\Customer::all($criteria);

	// Prepare the customer subscription data that we need for migration
    $subscribers = [];
	foreach ($customers['data'] as $c) {
		// Loop if they have multiple subscriptions
		foreach ($c['subscriptions']['data'] as $s) {
			// Only migrate active subscriptions (failed subscriptions going through retry settings, will be handled manually)
			if (in_array($s['status'], array('active'))) {

			    $subscriber = [
                    'customer_id' => $c['id'],
                    'subscription_id' => $s['id'],
                    'plan_id' => $s['plan']['id'],
                    'billing_cycle_anchor' => $s['current_period_end'],
                ];

			    $subscribers[] = $subscriber;
			}
		}

		// Pagination
		$starting_after = $c['id'];
    }

    foreach ($subscribers as $subscriber) {
        // Setup NEW subscription on destination account to begin billing next cycle via billing_cycle_anchor
        \Stripe\Stripe::setApiKey(DESTINATION_KEY);
        \Stripe\Subscription::create([
            'customer' => $subscriber['customer_id'],
            'billing_cycle_anchor' => $subscriber['billing_cycle_anchor'],
            'prorate' => false,
            'items' => [
                'plan' => $subscriber['plan_id'],
            ],
        ]);

        echo "created subscription for customer {$subscriber['customer_id']} with plan {$subscriber['plan_id']}\n";

        // Remove OLD subscription on source account at end of cycle
        \Stripe\Stripe::setApiKey(SOURCE_KEY);
        \Stripe\Subscription::retrieve($subscriber['subscription_id'])
            ->cancel(['at_period_end' => true]);

        echo "deleted subscription {$subscriber['subscription_id']} for customer {$subscriber['customer_id']}\n";
    }

    $count = count($customers['data']);
}
