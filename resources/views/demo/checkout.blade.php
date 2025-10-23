<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout Caution - {{ $booking->property_name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-md">
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Autorisation de caution</h1>
            
            <div class="mb-6">
                <h2 class="text-lg font-semibold">{{ $booking->property_name }}</h2>
                <p class="text-gray-600">Client: {{ $booking->guest_name }}</p>
                <p class="text-gray-600">Dates: {{ $booking->check_in_at->format('d/m/Y') }} - {{ $booking->check_out_at->format('d/m/Y') }}</p>
                <div class="mt-2 p-3 bg-blue-50 rounded">
                    <p class="font-semibold text-blue-800">Montant de la caution: {{ number_format($booking->deposit_amount_cents / 100, 2) }}€</p>
                    <p class="text-sm text-blue-600">Pré-autorisation uniquement - aucun débit immédiat</p>
                </div>
            </div>

            <form id="payment-form">
                @csrf
                
                <div class="mb-4">
                    <label for="card-element" class="block text-sm font-medium text-gray-700 mb-2">
                        Carte de crédit
                    </label>
                    <div id="card-element" class="p-3 border border-gray-300 rounded-md">
                        <!-- Stripe Elements will be inserted here -->
                    </div>
                    <div id="card-errors" class="text-red-500 text-sm mt-2" role="alert"></div>
                </div>

                <button id="submit-button" 
                        type="submit" 
                        class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded disabled:bg-gray-400">
                    Autoriser la caution
                </button>
            </form>

            <!-- Message de statut -->
            <div id="payment-status" class="hidden mt-4 p-3 rounded text-sm"></div>

            <div class="mt-6 p-4 bg-gray-50 rounded">
                <h3 class="font-semibold mb-2">Cartes de test recommandées:</h3>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li>• <strong>4242 4242 4242 4242</strong> - Succès immédiat</li>
                    <li>• <strong>4000 0000 0000 9995</strong> - Fonds insuffisants</li>
                    <li>• <strong>4000 0027 6000 3184</strong> - Authentification 3DS requise</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        const stripe = Stripe('{{ $stripeKey }}');
        const elements = stripe.elements();
        
        const cardElement = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#424770',
                    '::placeholder': {
                        color: '#aab7c4',
                    },
                },
            },
        });

        cardElement.mount('#card-element');

        const form = document.getElementById('payment-form');
        const submitButton = document.getElementById('submit-button');
        const cardErrors = document.getElementById('card-errors');
        const paymentStatus = document.getElementById('payment-status');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            
            submitButton.disabled = true;
            submitButton.textContent = 'Traitement en cours...';

            try {
                // Créer le PaymentMethod
                const { paymentMethod, error } = await stripe.createPaymentMethod({
                    type: 'card',
                    card: cardElement,
                });

                if (error) {
                    cardErrors.textContent = error.message;
                    submitButton.disabled = false;
                    submitButton.textContent = 'Autoriser la caution';
                    return;
                }

                // Afficher le statut
                paymentStatus.className = 'mt-4 p-3 bg-blue-100 border border-blue-400 text-blue-700 rounded text-sm';
                paymentStatus.textContent = 'Création de l\'autorisation en cours...';
                paymentStatus.classList.remove('hidden');

                // Envoyer la requête au serveur
                const response = await fetch('{{ route("demo.process-checkout", $booking) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        payment_method_id: paymentMethod.id
                    })
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.error || result.message || 'Erreur lors du traitement');
                }

                if (result.success) {
                    // Succès - redirection vers la page des réservations
                    paymentStatus.className = 'mt-4 p-3 bg-green-100 border border-green-400 text-green-700 rounded text-sm';
                    paymentStatus.textContent = 'Autorisation créée avec succès! Redirection...';
                    
                    setTimeout(() => {
                        window.location.href = '{{ route("demo.bookings") }}';
                    }, 1500);
                } else {
                    // Gérer les cas nécessitant une action 3DS
                    if (result.payment_intent && result.payment_intent.requires_action) {
                        paymentStatus.className = 'mt-4 p-3 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded text-sm';
                        paymentStatus.textContent = 'Authentification 3DS requise...';
                        
                        // Confirmer le paiement avec Stripe
                        const { error: confirmError } = await stripe.confirmCardPayment(
                            result.payment_intent.client_secret
                        );

                        if (confirmError) {
                            throw new Error(confirmError.message);
                        }

                        // Si on arrive ici, la 3DS a réussi
                        paymentStatus.className = 'mt-4 p-3 bg-green-100 border border-green-400 text-green-700 rounded text-sm';
                        paymentStatus.textContent = 'Authentification 3DS réussie! Redirection...';
                        
                        setTimeout(() => {
                            window.location.href = '{{ route("demo.bookings") }}';
                        }, 1500);
                    } else {
                        throw new Error(result.error || 'Erreur inconnue');
                    }
                }

            } catch (error) {
                console.error('Error:', error);
                cardErrors.textContent = 'Erreur: ' + error.message;
                paymentStatus.className = 'mt-4 p-3 bg-red-100 border border-red-400 text-red-700 rounded text-sm';
                paymentStatus.textContent = 'Erreur: ' + error.message;
                paymentStatus.classList.remove('hidden');
                
                submitButton.disabled = false;
                submitButton.textContent = 'Autoriser la caution';
            }
        });

        cardElement.on('change', ({ error }) => {
            if (error) {
                cardErrors.textContent = error.message;
            } else {
                cardErrors.textContent = '';
            }
        });
    </script>
</body>
</html>