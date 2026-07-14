<?php
/**
 * Nav inferior compartido del módulo representante.
 * Incluir en cada página DESPUÉS del </main> y ANTES de </body>.
 *
 * Variable requerida (definir antes del include):
 *   $navActive — string: 'inicio' | 'venta' | 'stock' | 'ventas'
 */
$navActive = $navActive ?? '';
?>
<nav class="bottom-nav" aria-label="Navegacion representante">
    <div class="bottom-inner">
        <a class="nav-item <?= $navActive === 'inicio'  ? 'active' : '' ?>" href="<?= url('representante/index.php') ?>">
            <span>Inicio</span>
        </a>
        <a class="nav-item <?= $navActive === 'ventas'  ? 'active' : '' ?>" href="<?= url('representante/ventas.php') ?>">
            <span>Ventas</span>
        </a>
    </div>
</nav>
