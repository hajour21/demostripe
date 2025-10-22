<!DOCTYPE html>
<html>
<head>
    <title>Checkout - {{ $booking->property_name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <div class="bg-white rounded-lg shadow p-6">
            <h1 class="text-2xl font-bold mb-6">Checkout - {{ $booking->property_name }}</h1>
            
            <div class="mb-6">
                <h2 class="text-lg font-semibold mb-2">Détails de la réservation</h2>
                <p><strong>Client:</strong> {{ $booking->guest_name }}</p>
                <p><strong>Dates:</strong> {{ $booking->check_in_at->format('d/m/Y') }} - {{ $booking->check_out_at->format('d/m/Y') }}</p>
                <p><strong>Caution:</strong> {{ number_format($booking->deposit_amount_cents / 100, 2, ',', ' ') }} €</p>
            </div>

            <form id="payment-form">
                @csrf
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="card-element">
                        Carte de crédit
                    </label>
                    <div id="card-element" class="p-3 border border-gray-300 rounded">
                        <!-- Stripe Elements will be inserted here -->
                    </div>
                    <div id="card-errors" class="text-red-500 text-sm mt-2"></div>
                </div>

                <button type="submit" id="submit-button" 
                        class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded">
                    Autoriser la caution de {{ number_format($booking->deposit_amount_cents / 100, 2, ',', ' ') }} €
                </button>
            </form>

            <div class="mt-6 text-center">
                <a href="{{ route('demo.bookings') }}" class="text-blue-500 hover:text-blue-700">
                    ← Retour aux réservations
                </a>
            </div>
        </div>
    </div>

    <script>
        const stripe = Stripe('{{ config('services.stripe.key') }}');
        const elements = stripe.elements();
        const cardElement = elements.create('card');
        cardElement.mount('#card-element');

        const form = document.getElementById('payment-form');
        const submitButton = document.getElementById('submit-button');
        const cardErrors = document.getElementById('card-errors');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            submitButton.disabled = true;
            submitButton.textContent = 'Traitement en cours...';

            const { paymentMethod, error } = await stripe.createPaymentMethod({
                type: 'card',
                card: cardElement,
            });

            if (error) {
                cardErrors.textContent = error.message;
                submitButton.disabled = false;
                submitButton.textContent = 'Autoriser la caution';
            } else {
                // Envoyer le paymentMethod.id à votre serveur
                try {
                    const response = await fetch('/api/deposits/authorize', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            booking_id: {{ $booking->id }},
                            payment_method_id: paymentMethod.id
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        alert('Caution autorisée avec succès !');
                        window.location.href = '{{ route('demo.bookings') }}';
                    } else {
                        cardErrors.textContent = result.error || 'Erreur lors de l\'autorisation';
                    }
                } catch (error) {
                    cardErrors.textContent = 'Erreur réseau: ' + error.message;
                }

                submitButton.disabled = false;
                submitButton.textContent = 'Autoriser la caution';
            }
        });
    </script>
</body>
</html>