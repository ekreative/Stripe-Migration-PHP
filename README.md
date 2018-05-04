Stripe-Migration-PHP
====================

These are a collection of scripts that will help you migrate your Stripe data to another Stripe account. Stripe will only migrate customers and cards - nothing else. As a result, I've built these scripts to move other elements: 

- Plans - `move_plans.php`
- Subscriptions - `move_subscriptions.php`

The code provided is intended to be used as a starting point and to be modified to fit your own specific needs. Therefore, I am assuming that you have enough PHP knowledge to go through the code before running it on your Stripe account.

## Instructions

1. Add your Stripe API keys into `stripe_keys.php`, but be careful! Test it on your test API keys first!

3. Run the respective file directly in on the cli, e.g. `php list_products.php`
  * **Warning!** When running `move_subscriptions.php`, the script will automatically cancel existing subscriptions at period end from the source account before creating the new one on the destination account.

4. Consider modifying the scripts to migrate only a few subscriptions/plans first as a secondary test.

5. Check your destination account to make sure that everything went through as expected. 
