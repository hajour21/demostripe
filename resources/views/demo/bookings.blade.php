<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réservations & Cautions</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold mb-8">Réservations & cautions</h1>
        
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                {{ session('error') }}
            </div>
        @endif

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Logement</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Caution</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($bookings as $booking)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">{{ $booking->id }}</td>
                        <td class="px-6 py-4 whitespace-nowrap">{{ $booking->guest_name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap">{{ $booking->property_name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            {{ $booking->check_in_at->format('Y-m-d') }} → {{ $booking->check_out_at->format('Y-m-d') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">{{ number_format($booking->deposit_amount_cents / 100, 2, ',', ' ') }} €</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($booking->deposit)
                                @php
                                    $statusColors = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'authorized' => 'bg-green-100 text-green-800',
                                        'released' => 'bg-blue-100 text-blue-800',
                                        'captured' => 'bg-red-100 text-red-800',
                                        'failed' => 'bg-gray-100 text-gray-800',
                                        'expired' => 'bg-gray-100 text-gray-800'
                                    ];
                                    $color = $statusColors[$booking->deposit->status] ?? 'bg-gray-100 text-gray-800';
                                @endphp
                                <span class="px-2 py-1 text-xs rounded-full {{ $color }}">
                                    {{ $booking->deposit->status }}
                                </span>
                                @if($booking->deposit->captured_amount_cents > 0)
                                    <div class="text-xs text-gray-500 mt-1">
                                        Capturé: {{ number_format($booking->deposit->captured_amount_cents / 100, 2, ',', ' ') }} €
                                    </div>
                                @endif
                            @else
                                <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap space-x-2">
                            <a href="{{ route('demo.checkout', $booking) }}" 
                               class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                                Checkout invité
                            </a>
                            
                            @if($booking->deposit && $booking->deposit->status === 'authorized')
                                <button onclick="releaseDeposit({{ $booking->id }})" 
                                        class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm">
                                    Relâcher
                                </button>
                                <button onclick="showCaptureModal({{ $booking->id }}, {{ $booking->deposit->authorized_amount_cents }})" 
                                        class="bg-orange-500 hover:bg-orange-700 text-white font-bold py-2 px-4 rounded text-sm">
                                    Capturer
                                </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Modal pour capture partielle -->
        <div id="captureModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center">
            <div class="bg-white p-6 rounded-lg shadow-xl w-96">
                <h3 class="text-lg font-bold mb-4">Capture partielle</h3>
                <form id="captureForm">
                    @csrf
                    <input type="hidden" id="captureBookingId" name="booking_id">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Montant à capturer (€)</label>
                        <input type="number" id="captureAmount" name="amount_cents" 
                               class="w-full p-2 border border-gray-300 rounded" 
                               min="1" step="0.01" required>
                        <div class="text-sm text-gray-500 mt-1">
                            Montant autorisé: <span id="authorizedAmount">0</span> €
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="hideCaptureModal()" 
                                class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="bg-orange-500 hover:bg-orange-700 text-white font-bold py-2 px-4 rounded">
                            Capturer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function releaseDeposit(bookingId) {
        if (!confirm('Êtes-vous sûr de vouloir relâcher cette caution ?')) {
            return;
        }

        fetch('/api/deposits/release', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                booking_id: bookingId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ Caution relâchée avec succès !');
                location.reload();
            } else {
                alert('❌ Erreur: ' + data.error);
            }
        })
        .catch(error => {
            alert('❌ Erreur réseau: ' + error.message);
        });
    }

    function showCaptureModal(bookingId, authorizedAmountCents) {
        document.getElementById('captureBookingId').value = bookingId;
        document.getElementById('captureAmount').value = (authorizedAmountCents / 100).toFixed(2);
        document.getElementById('captureAmount').max = authorizedAmountCents / 100;
        document.getElementById('authorizedAmount').textContent = (authorizedAmountCents / 100).toFixed(2);
        document.getElementById('captureModal').classList.remove('hidden');
    }

    function hideCaptureModal() {
        document.getElementById('captureModal').classList.add('hidden');
    }

    document.getElementById('captureForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const bookingId = document.getElementById('captureBookingId').value;
        const amount = document.getElementById('captureAmount').value;
        const amountCents = Math.round(amount * 100);

        fetch('/api/deposits/capture', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                booking_id: parseInt(bookingId),
                amount_cents: amountCents
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ Caution capturée avec succès !');
                hideCaptureModal();
                location.reload();
            } else {
                alert('❌ Erreur: ' + data.error);
            }
        })
        .catch(error => {
            alert('❌ Erreur réseau: ' + error.message);
        });
    });

    // Fermer la modal en cliquant à l'extérieur
    document.getElementById('captureModal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideCaptureModal();
        }
    });
    </script>
</body>
</html>