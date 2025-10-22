<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== TEST CONFIGURATION ===\n";
echo "STRIPE_KEY: " . (env('STRIPE_KEY') ? 'DÉFINI' : 'MANQUANT') . "\n";
echo "STRIPE_SECRET: " . (env('STRIPE_SECRET') ? 'DÉFINI (' . substr(env('STRIPE_SECRET'), 0, 10) . '...)' : 'MANQUANT') . "\n";

try {
    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
    echo "✅ Stripe configuré\n";
} catch (Exception $e) {
    echo "❌ Stripe: " . $e->getMessage() . "\n";
}

try {
    $service = new \App\Services\StripeDepositService();
    echo "✅ Service Stripe chargé\n";
} catch (Exception $e) {
    echo "❌ Service: " . $e->getMessage() . "\n";
}
echo "=== FIN TEST ===\n";
