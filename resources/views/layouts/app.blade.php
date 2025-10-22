
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Stripe Caution Demo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
  <nav class="bg-white shadow mb-4">
    <div class="container mx-auto px-6 py-3 flex justify-between items-center">
      <h1 class="text-lg font-semibold">Stripe Caution Demo</h1>
      <a href="/demo/bookings" class="text-blue-600 hover:underline">Réservations</a>
    </div>
  </nav>

  <main class="container mx-auto">
    @yield('content')
  </main>
</body>
</html>


