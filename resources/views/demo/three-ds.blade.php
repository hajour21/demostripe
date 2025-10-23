<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentification 3DS Requise</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-md">
        <div class="bg-white rounded-lg shadow-lg p-6 text-center">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">Authentification Requise</h1>
            
            <div class="mb-6">
                <p class="text-gray-600 mb-4">
                    Votre banque requiert une authentification supplémentaire pour sécuriser le paiement.
                </p>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-sm text-yellow-800">
                        <strong>Réservation:</strong> {{ $booking->property_name }}<br>
                        <strong>Client:</strong> {{ $booking->guest_name }}<br>
                        <strong>Montant:</strong> {{ number_format($booking->deposit_amount_cents / 100, 2) }}€
                    </p>
                </div>
            </div>

            <div id="payment-element">
                <!-- Stripe injectera le formulaire 3DS ici -->
            </div>

            <button id="submit-button" 
                    class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded disabled:bg-gray-400 mt-4">
                Vérifier l'authentification
            </button>

            <div id="payment-message" class="hidden mt-4 p-3 rounded text-sm"></div>
        </div>
    </div>

    <script>
        const stripe = Stripe('{{ config('services.stripe.key') }}');
        const clientSecret = '{{ $clientSecret }}';

        const elements = stripe.elements();
        const paymentElement = elements.create('payment', {
            clientSecret: clientSecret,
            layout: 'tabs'
        });

        paymentElement.mount('#payment-element');

        const form = document.getElementById('payment-form');
        const submitButton = document.getElementById('submit-button');
        const paymentMessage = document.getElementById('payment-message');

        submitButton.addEventListener('click', async (e) => {
            e.preventDefault();
            submitButton.disabled = true;

            const { error } = await stripe.confirmPayment({
                elements,
                confirmParams: {
                    return_url: '{{ $returnUrl }}',
                },
            });

            if (error) {
                paymentMessage.textContent = error.message;
                paymentMessage.classList.remove('hidden');
                paymentMessage.classList.add('bg-red-100', 'border', 'border-red-400', 'text-red-700');
                submitButton.disabled = false;
            }
        });
    </script>
</body>
</html>