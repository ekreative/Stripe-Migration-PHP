<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

$out = new Symfony\Component\Console\Output\ConsoleOutput();

do {
    \Stripe\Stripe::setApiKey(SOURCE_KEY);

    $criteria = ['limit' => 100];
    // Pagination
	if (isset($starting_after)) {
	    $criteria['starting_after'] = $starting_after;
    }

    $progress = new \Symfony\Component\Console\Helper\ProgressBar($out);
	$progress->start();

	$customers = \Stripe\Customer::all($criteria);
	$progress->advance();

    $t = new \Symfony\Component\Console\Helper\Table($out);
    $t->setHeaders(['customer_id', 'subscription_id', 'plan_id', 'billing_cycle_anchor', 'new_customer_id']);

	// Prepare the customer subscription data that we need for migration
    $subscribers = [];
	foreach ($customers['data'] as $c) {
		// Loop if they have multiple subscriptions
		foreach ($c['subscriptions']['data'] as $s) {
			// Only migrate active subscriptions (failed subscriptions going through retry settings, will be handled manually)
			if (in_array($s['status'], array('active'))) {
			    $subscriber = [
                    'customer_id' => $c['id'],
                    // Fields needed to create this customer
                    'new_customer' => [
                        'description' => $c['description'],
                        'email' => $c['email'],
                        'metadata' => [
                            'account_id' => $c['metadata']['account_id'],
                        ],
                    ],
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

    $progress->start(count($subscribers));

    foreach ($subscribers as $subscriber) {
        \Stripe\Stripe::setApiKey(DESTINATION_KEY);
        try {
            $customer = \Stripe\Customer::retrieve($subscriber['customer_id']);
        } catch (\Stripe\Error\InvalidRequest $exception) {
            $customer = ['id' => $subscriber['new_customer']['email']];
        }
        $progress->advance();

        $t->addRow([$subscriber['customer_id'], $subscriber['subscription_id'], $subscriber['plan_id'], $subscriber['billing_cycle_anchor'], $customer['id']]);
    }

    $progress->finish();
    $t->render();

//    foreach ($subscribers as $subscriber) {
//        // Setup NEW subscription on destination account to begin billing next cycle via billing_cycle_anchor
//        \Stripe\Stripe::setApiKey(DESTINATION_KEY);
//        \Stripe\Subscription::create([
//            'customer' => $subscriber['customer_id'],
//            'billing_cycle_anchor' => $subscriber['billing_cycle_anchor'],
//            'prorate' => false,
//            'items' => [
//                'plan' => $subscriber['plan_id'],
//            ],
//        ]);
//
//        echo "created subscription for customer {$subscriber['customer_id']} with plan {$subscriber['plan_id']}\n";
//
//        // Remove OLD subscription on source account at end of cycle
//        \Stripe\Stripe::setApiKey(SOURCE_KEY);
//        \Stripe\Subscription::retrieve($subscriber['subscription_id'])
//            ->cancel(['at_period_end' => true]);
//
//        echo "deleted subscription {$subscriber['subscription_id']} for customer {$subscriber['customer_id']}\n";
//    }

    $count = count($customers['data']);

    echo "Listed $count customers\n";
} while ($count == 100);
