<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Cautions - Démo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="//unpkg.com/alpinejs" defer></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">Gestion des Cautions - Réservations</h1>

        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Réservation
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Dates
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Caution
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Statut
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($bookings as $booking)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ $booking->guest_name }}</div>
                                <div class="text-sm text-gray-500">{{ $booking->property_name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    {{ $booking->check_in_at->format('d/m/Y') }} - {{ $booking->check_out_at->format('d/m/Y') }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    Check-out: {{ $booking->check_out_at->diffForHumans() }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ number_format($booking->deposit_amount_cents / 100, 2) }}€
                                @if($booking->deposit && $booking->deposit->captured_amount_cents > 0)
                                    <br>
                                    <span class="text-xs text-orange-600">
                                        Capturé: {{ number_format($booking->deposit->captured_amount_cents / 100, 2) }}€
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($booking->deposit)
                                    @php
                                        $statusColors = [
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'authorized' => 'bg-blue-100 text-blue-800',
                                            'released' => 'bg-green-100 text-green-800',
                                            'captured' => 'bg-purple-100 text-purple-800',
                                            'failed' => 'bg-red-100 text-red-800',
                                            'expired' => 'bg-gray-100 text-gray-800',
                                        ];
                                    @endphp
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusColors[$booking->deposit->status] }}">
                                        {{ $booking->deposit->status }}
                                    </span>
                                    @if($booking->deposit->last_error)
                                        <div class="text-xs text-red-600 mt-1">
                                            Erreur: {{ Str::limit($booking->deposit->last_error, 50) }}
                                        </div>
                                    @endif
                                @else
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                        Aucune caution
                                    </span>
                                @endif
                            </td>
                            <!-- Dans le tableau, colonne Actions -->
<td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
    <div class="flex flex-col space-y-2">
        @if(!$booking->deposit)
            <a href="{{ route('demo.checkout', $booking) }}" 
               class="bg-blue-500 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm text-center">
                Autoriser
            </a>
        @else
            <!-- Informations sur le dépôt -->
            <div class="text-xs text-gray-500 mb-2">
                @if($booking->deposit->captured_amount_cents > 0)
                    <div>Déjà capturé: {{ number_format($booking->deposit->captured_amount, 2) }}€</div>
                @endif
                @if($booking->deposit->status === 'authorized')
                    <div>Disponible: {{ number_format($booking->deposit->remaining_amount, 2) }}€</div>
                @endif
            </div>

            <div class="flex space-x-2">
                @if($booking->deposit->canBeReleased())
                    <form action="{{ route('demo.release', $booking) }}" method="POST" class="flex-1">
                        @csrf
                        @method('POST')
                        <button type="submit" 
                                class="w-full bg-green-500 hover:bg-green-700 text-white px-2 py-1 rounded text-sm"
                                onclick="return confirm('Êtes-vous sûr de vouloir relâcher la caution?')">
                            Relâcher
                        </button>
                    </form>
                @endif

                @if($booking->deposit->canBeCaptured())
                    <div x-data="{ open: false, amount: '' }" class="flex-1">
                        <button @click="open = true; amount = '{{ number_format($booking->deposit->remaining_amount, 2) }}'" 
                                class="w-full bg-orange-500 hover:bg-orange-700 text-white px-2 py-1 rounded text-sm">
                            Capturer
                        </button>
                        
                        <!-- Modal de capture -->
                        <div x-show="open" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                                <div class="mt-3">
                                    <h3 class="text-lg font-medium text-gray-900 mb-4">Capture de caution</h3>
                                    
                                    <div class="mb-4 p-3 bg-blue-50 rounded">
                                        <p class="text-sm text-blue-800">
                                            <strong>Autorisé:</strong> {{ number_format($booking->deposit->authorized_amount, 2) }}€
                                        </p>
                                        <p class="text-sm text-blue-800">
                                            <strong>Déjà capturé:</strong> {{ number_format($booking->deposit->captured_amount, 2) }}€
                                        </p>
                                        <p class="text-sm text-green-800 font-semibold">
                                            <strong>Disponible:</strong> {{ number_format($booking->deposit->remaining_amount, 2) }}€
                                        </p>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Montant à capturer (€)
                                        </label>
                                        <input type="number" 
                                               step="0.01" 
                                               min="0.01" 
                                               max="{{ $booking->deposit->remaining_amount }}"
                                               x-model="amount"
                                               placeholder="Montant en €"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md">
                                        <p class="text-xs text-gray-500 mt-1">
                                            Maximum: {{ number_format($booking->deposit->remaining_amount, 2) }}€
                                        </p>
                                    </div>
                                    
                                    <div class="flex justify-end space-x-3">
                                        <button @click="open = false" 
                                                type="button"
                                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded">
                                            Annuler
                                        </button>
                                        <form action="{{ route('demo.capture', $booking) }}" method="POST">
                                            @csrf
                                            @method('POST')
                                            <input type="hidden" name="amount" :value="amount">
                                            <button type="submit" 
                                                    :disabled="!amount || parseFloat(amount) > {{ $booking->deposit->remaining_amount }} || parseFloat(amount) <= 0"
                                                    class="px-4 py-2 bg-orange-500 hover:bg-orange-700 disabled:bg-gray-400 text-white rounded"
                                                    onclick="return confirm('Confirmer la capture de ' + amount + '€?')">
                                                Confirmer la capture
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-8 bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-bold mb-4">Cartes de test Stripe</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="border rounded p-4">
                    <h3 class="font-semibold text-green-600">Carte OK</h3>
                    <code class="text-sm">4242 4242 4242 4242</code>
                    <p class="text-xs text-gray-600 mt-1">Autorisation réussie</p>
                </div>
                <div class="border rounded p-4">
                    <h3 class="font-semibold text-red-600">Fonds insuffisants</h3>
                    <code class="text-sm">4000 0000 0000 9995</code>
                    <p class="text-xs text-gray-600 mt-1">Échec de l'autorisation</p>
                </div>
                <div class="border rounded p-4">
                    <h3 class="font-semibold text-blue-600">3DS Authentication</h3>
                    <code class="text-sm">4000 0027 6000 3184</code>
                    <p class="text-xs text-gray-600 mt-1">Nécessite une authentification</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>