<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Grid</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <h1 class="text-2xl font-bold mb-4">Test Grid Responsive</h1>
    
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 mb-8">
        <div class="bg-blue-500 text-white p-4 rounded">Card 1</div>
        <div class="bg-blue-500 text-white p-4 rounded">Card 2</div>
        <div class="bg-blue-500 text-white p-4 rounded">Card 3</div>
    </div>

    <div class="mt-8 p-4 bg-white rounded">
        <h2 class="font-bold mb-2">Debug Info:</h2>
        <p>Ancho de ventana: <span id="width"></span>px</p>
        <p>Breakpoints Tailwind:</p>
        <ul class="text-sm">
            <li>sm: 640px</li>
            <li>md: 768px</li>
            <li>lg: 1024px</li>
            <li>xl: 1280px</li>
        </ul>
    </div>

    <script>
        function updateWidth() {
            document.getElementById('width').textContent = window.innerWidth;
        }
        updateWidth();
        window.addEventListener('resize', updateWidth);
    </script>
</body>
</html>
