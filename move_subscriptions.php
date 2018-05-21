<?php

require_once('./vendor/autoload.php');
require_once('./stripe_keys.php');

$out = new Symfony\Component\Console\Output\ConsoleOutput();

$replacedCustomers = [];
$migratedSubscriptions = [];
$failedSubscriptions = [];
$migratedCustomers = [];

if (file_exists('replaced_customers.json')) {
    echo "loaded replaced customers\n";
    $dataR = file_get_contents('replaced_customers.json');
    $replacedCustomers = json_decode($dataR, true);
}

if (file_exists('migrated_subscriptions.json')) {
    echo "loaded migrated subscriptions\n";
    $dataM = file_get_contents('migrated_subscriptions.json');
    $migratedSubscriptions = json_decode($dataM, true);
}

if (file_exists('failed_subscriptions.json')) {
    echo "loaded failed subscriptions\n";
    $dataF = file_get_contents('failed_subscriptions.json');
    $failedSubscriptions = json_decode($dataF, true);
}

if (file_exists('migrated_customers.json')) {
    echo "loaded migrated customers\n";
    $dataC = file_get_contents('migrated_customers.json');
    $migratedCustomers = json_decode($dataC, true);
}

if (count($migratedCustomers)) {
    $starting_after = $migratedCustomers[count($migratedCustomers) - 1];
}

$running = true;

pcntl_signal(SIGINT, function($sig) {
    global $running;
    echo "Stopping at end of block\n";
    $running = false;
});

$totalCount = count($migratedSubscriptions);

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

    // Prepare the customer subscription data that we need for migration
    $subscribers = [];
    foreach ($customers['data'] as $c) {
        // Loop if they have multiple subscriptions
        foreach ($c['subscriptions']['data'] as $s) {
            // Only migrate active subscriptions (failed subscriptions going through retry settings, will be handled manually)
            if ($s['status'] === 'active' && $s['cancel_at_period_end'] === false) {
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
                    'metadata' => [
                        'account_id' => isset($s['metadata']['account_id']) ? $s['metadata']['account_id'] : null,
                    ]
                ];

                $subscribers[] = $subscriber;
            }
        }

        // Pagination
        $starting_after = $c['id'];
    }

    $progress->start(count($subscribers));

    foreach ($subscribers as $subscriber) {
        echo "\n";
        if (in_array($subscriber['subscription_id'], $migratedSubscriptions)) {
            echo "[$totalCount]: skipping migrated subscription {$subscriber['subscription_id']}\n";
            continue;
        }

        // Setup NEW subscription on destination account to begin billing next cycle via billing_cycle_anchor
        \Stripe\Stripe::setApiKey(DESTINATION_KEY);

        if (isset($replacedCustomers[$subscriber['customer_id']])) {
            $newCustomerId = $replacedCustomers[$subscriber['customer_id']];
            echo "[$totalCount]: using already created new customer {$newCustomerId} for {$subscriber['customer_id']}\n";
        }

        try {
            \Stripe\Customer::retrieve($subscriber['customer_id']);
            $newCustomerId = $subscriber['customer_id'];
        } catch (\Stripe\Error\InvalidRequest $exception) {
            try {
                $customer = \Stripe\Customer::create($subscriber['new_customer']);
                $newCustomerId = $customer['id'];
                $replacedCustomers[$subscriber['customer_id']] = $newCustomerId;

                echo "[$totalCount]: created new customer {$newCustomerId} for {$subscriber['customer_id']}\n";
            } catch (\Stripe\Error\InvalidRequest $exception) {
                echo "[$totalCount]: error creating customer for {$subscriber['customer_id']}\n";
                echo "[$totalCount]: error: {$exception->getMessage()}\n";
                $failedSubscriptions[] = $subscriber['subscription_id'];

                continue;
            }
        }

        try {
            $subscription = \Stripe\Subscription::create([
                'customer' => $newCustomerId,
                'billing_cycle_anchor' => $subscriber['billing_cycle_anchor'],
                'prorate' => false,
                'items' => [
                    [
                        'plan' => $subscriber['plan_id'],
                    ]
                ],
                'metadata' => $subscriber['metadata'],
            ]);
            echo "[$totalCount]: created subscription {$subscription['id']} for customer {$newCustomerId} with plan {$subscriber['plan_id']} from {$subscriber['subscription_id']}\n";


        } catch (\Stripe\Error\InvalidRequest $exception) {
            echo "[$totalCount]: error creating subscription for customer {$newCustomerId} with plan {$subscriber['plan_id']}\n";
            echo "[$totalCount]: old customer {$subscriber['customer_id']}, old subscription {$subscriber['subscription_id']}\n";
            echo "[$totalCount]: error: {$exception->getMessage()}\n";
            $failedSubscriptions[] = $subscriber['subscription_id'];

            continue;
        }

        // Remove OLD subscription on source account at end of cycle
        \Stripe\Stripe::setApiKey(SOURCE_KEY);
        try {
            \Stripe\Subscription::retrieve($subscriber['subscription_id'])
                ->cancel(['at_period_end' => true]);
            echo "[$totalCount]: deleted subscription {$subscriber['subscription_id']} for customer {$subscriber['customer_id']}\n";
        } catch (\Stripe\Error\InvalidRequest $exception) {
            echo "[$totalCount]: error canceling subscription for customer {$subscriber['customer_id']} with plan {$subscriber['plan_id']}\n";
            echo "[$totalCount]: new customer {$newCustomerId}, subscription {$subscriber['subscription_id']}\n";
            echo "[$totalCount]: error: {$exception->getMessage()}\n";
            $failedSubscriptions[] = $subscriber['subscription_id'];

            continue;
        }

        $progress->advance();
        $migratedSubscriptions[] = $subscriber['subscription_id'];
        $migratedCustomers[] = $subscriber['customer_id'];

        save_data();
        $totalCount++;

        pcntl_signal_dispatch();
        if (!$running) {
            break;
        }
    }

    $progress->finish();
    echo "\n";

    echo "saving state\n";
    save_data();

    $count = count($customers['data']);
    echo "Listed $count customers\n";
    pcntl_signal_dispatch();
} while ($count == 100 && $running);

echo "saving state\n";
save_data();

function save_data() {
    global $replacedCustomers, $migratedSubscriptions, $failedSubscriptions, $migratedCustomers;

    file_put_contents('replaced_customers.json', json_encode($replacedCustomers));
    file_put_contents('migrated_subscriptions.json', json_encode($migratedSubscriptions));
    file_put_contents('failed_subscriptions.json', json_encode($failedSubscriptions));
    file_put_contents('migrated_customers.json', json_encode($migratedCustomers));
}
