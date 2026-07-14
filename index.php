<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Configuracion.php';

// Obtener nombre del representante fresco desde DB si hay cookie de referencia
$_nombreRepActual = null;
$_pdo = Database::getInstance()->getConnection();
$_repAdminId = isset($_COOKIE['botikit_rep_admin']) ? (int)$_COOKIE['botikit_rep_admin'] : null;

if ($_repAdminId) {
    $_stmtRep = $_pdo->prepare("
        SELECT a.nombre
        FROM representante_perfiles rp
        INNER JOIN administradores a ON a.id = rp.admin_id
        WHERE rp.admin_id = ?
          AND rp.activo = 1
          AND a.activo = 1
        LIMIT 1
    ");
    $_stmtRep->execute([$_repAdminId]);
    $_nombreRepActual = $_stmtRep->fetchColumn() ?: null;
}
?>
<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= asset('css/cliente-mobile.css') ?>">
<script>document.body.classList.add('cliente-app');</script>
    
    <!-- Header -->
    <header class="cliente-hero pt-6 pb-1 px-4">
        <div class="max-w-md mx-auto text-center">
            <img src="<?= asset('assets/images/logo.png') ?>" alt="<?= htmlspecialchars(Configuracion::get('nombre_tienda', 'Tienda')) ?>" class="w-28 h-28 mx-auto">
        </div>
    </header>

    <!-- Main Content -->
    <main class="cliente-shell cliente-screen px-4 py-8">
        <div class="max-w-md mx-auto space-y-6">
            
            <?php
            // ========================================
            // DETECCIÓN DE COOKIE DE REPRESENTANTE
            // ========================================
            
            // Mensaje de bienvenida si viene por enlace de representante
            if (isset($_GET['ref']) && $_GET['ref'] == '1' && $_nombreRepActual) {
                $nombreRep = htmlspecialchars($_nombreRepActual);
                echo "
                <div class='bg-white border border-slate-200 rounded-2xl p-5 shadow-sm'>
                    <div class='flex items-start gap-3'>
                        <div class='flex-1'>
                            <h3 class='font-bold text-slate-900 mb-1'>¡Bienvenido!</h3>
                            <p class='text-sm text-slate-700'>
                                Fuiste referido por <strong>{$nombreRep}</strong>
                            </p>
                            <p class='text-xs text-slate-500 mt-2'>
                                Todas tus compras serán acreditadas a tu representante
                            </p>
                        </div>
                    </div>
                </div>
                ";
            }
            
            // Mensaje de error si el código fue inválido
            if (isset($_GET['ref_error']) && $_GET['ref_error'] == '1') {
                echo "
                <div class='bg-red-50 border-2 border-red-300 rounded-2xl p-5 shadow-lg'>
                    <div class='flex items-start gap-3'>
                        <div class='flex-1'>
                            <h3 class='font-bold text-red-900 mb-1'>Código Inválido</h3>
                            <p class='text-sm text-red-800'>
                                El código de representante no es válido o está inactivo.
                            </p>
                            <p class='text-xs text-red-600 mt-2'>
                                Puedes continuar con tu pedido normalmente.
                            </p>
                        </div>
                    </div>
                </div>
                ";
            }
            
            // Indicador sutil si ya tiene cookie de representante (visitas posteriores)
            if (!isset($_GET['ref']) && $_nombreRepActual) {
                $nombreRep = htmlspecialchars($_nombreRepActual);
                echo "
                <div class='bg-slate-50 border border-slate-200 rounded-xl p-3 text-center'>
                    <p class='text-xs text-slate-600'>
                        Representante: <strong>{$nombreRep}</strong>
                    </p>
                </div>
                ";
            }
            ?>
            
            
            <!-- Card Principal -->
            <div class="card cliente-home-card rounded-3xl shadow-lg p-8">
                
                <!-- Input Teléfono -->
                <div class="mb-8">
                    <label for="telefono" class="block text-sm font-medium text-slate-700 mb-3">
                        Número de Teléfono
                    </label>
                    <input 
                        type="tel" 
                        id="telefono" 
                        name="telefono"
                        placeholder="Ingresa tu teléfono (10 dígitos)"
                        class="input-field w-full px-5 py-4 rounded-2xl text-lg focus:outline-none"
                        pattern="[0-9]{10}"
                        inputmode="numeric"
                        maxlength="10"
                        required
                    >
                    <p class="text-xs text-slate-500 mt-2 ml-1">
                        Ingresa tu número de 10 dígitos para continuar
                    </p>
                </div>

                <!-- Botones de Acción -->
                <div class="space-y-4">
                    <!-- Botón Crear Pedido -->
                    <button 
                        onclick="crearPedido()"
                        class="btn-primary w-full text-white font-semibold py-5 px-6 rounded-2xl shadow-lg flex items-center justify-center gap-3"
                    >
                        <span class="text-lg">Crear Nuevo Pedido</span>
                    </button>

                    <!-- Botón Seguimiento -->
                    <button 
                        onclick="seguimientoPedidos()"
                        class="btn-secondary w-full text-white font-semibold py-5 px-6 rounded-2xl shadow-lg flex items-center justify-center gap-3"
                    >
                        <span class="text-lg">Seguimiento de Pedidos</span>
                    </button>
                </div>

            </div>

            <!-- Info Card -->
            <div class="card rounded-2xl shadow-md p-6 text-center">
                <p class="text-slate-600 text-sm">
                    <span class="font-medium">Tip:</span> Ingresa tu número de teléfono para comenzar
                </p>
            </div>

        </div>
    </main>

    <script>
        function validarTelefono() {
            const telefono = document.getElementById('telefono').value.trim();
            
            if (!telefono) {
                mostrarAlerta('Por favor ingresa tu número de teléfono');
                return false;
            }
            
            if (telefono.length !== 10) {
                mostrarAlerta('El número de teléfono debe tener exactamente 10 dígitos');
                return false;
            }
            
            return telefono;
        }

        function crearPedido() {
            const telefono = validarTelefono();
            if (telefono) {
                window.location.href = `crear-pedido.php?telefono=${encodeURIComponent(telefono)}`;
            }
        }

        function seguimientoPedidos() {
            const telefono = validarTelefono();
            if (telefono) {
                window.location.href = `seguimiento.php?telefono=${encodeURIComponent(telefono)}`;
            }
        }

        // Auto-formatear teléfono (solo números)
        document.getElementById('telefono').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    </script>

</body>
</html>
