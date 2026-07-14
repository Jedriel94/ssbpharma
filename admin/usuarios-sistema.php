<?php
require_once '../includes/auth_admin.php';
require_once '../models/Administrador.php';
require_once '../models/Role.php';

$adminModel = new Administrador();
$roleModel = new Role();

// Obtener todos los roles
$roles = $roleModel->getAll();

// Obtener todos los administradores con roles
$usuarios = $adminModel->getAll();

// Obtener datos del admin actual
$admin_actual = $adminModel->getById($_SESSION['admin_id']);
$es_super_admin = ($admin_actual['rol_codigo'] ?? 'admin') === 'admin';

$totalUsuarios = count($usuarios);
$usuariosActivos = count(array_filter($usuarios, fn($u) => !empty($u['activo'])));
$usuariosInactivos = $totalUsuarios - $usuariosActivos;
$usuariosRepresentantes = count(array_filter($usuarios, fn($u) => ($u['rol_codigo'] ?? '') === 'representante' || !empty($u['representante_nombre'])));

// ── Árbol jerárquico ─────────────────────────────────────────
$byId = [];
$childrenOf = [];
foreach ($usuarios as $u) {
    $byId[$u['id']] = $u;
    $sid = !empty($u['superior_id']) ? (int)$u['superior_id'] : null;
    if ($sid) { $childrenOf[$sid][] = $u; }
}
$nivel0 = array_values(array_filter($usuarios, fn($u) => (int)($u['nivel_jerarquico'] ?? 99) === 0));
$nivel1 = array_values(array_filter($usuarios, fn($u) => (int)($u['nivel_jerarquico'] ?? 99) === 1));
$nivel2 = array_values(array_filter($usuarios, fn($u) => (int)($u['nivel_jerarquico'] ?? 99) === 2));
$nivel3 = array_values(array_filter($usuarios, fn($u) => (int)($u['nivel_jerarquico'] ?? 99) === 3));
$nivel4 = array_values(array_filter($usuarios, fn($u) => (int)($u['nivel_jerarquico'] ?? 99) === 4));
// Conteos para badges
$n4ForN3 = []; $n3ForN2 = []; $n4ForN2 = [];
foreach ($nivel4 as $r) { if (!empty($r['superior_id'])) $n4ForN3[(int)$r['superior_id']] = ($n4ForN3[(int)$r['superior_id']] ?? 0) + 1; }
foreach ($nivel3 as $g) {
    if (!empty($g['superior_id'])) {
        $n3ForN2[(int)$g['superior_id']] = ($n3ForN2[(int)$g['superior_id']] ?? 0) + 1;
        $n4ForN2[(int)$g['superior_id']] = ($n4ForN2[(int)$g['superior_id']] ?? 0) + ($n4ForN3[$g['id']] ?? 0);
    }
}

// ── Lista ordenada por jerarquía (DFS) para Vista Lista ──────
function _dfsUsuarios(array $childrenOf, array $byId, ?int $parentId, int $depth): array {
    $result = [];
    $children = $childrenOf[$parentId] ?? [];
    usort($children, fn($a, $b) => ($a['nivel_jerarquico'] ?? 99) <=> ($b['nivel_jerarquico'] ?? 99)
        ?: strcmp($a['nombre'], $b['nombre']));
    foreach ($children as $u) {
        $u['_depth'] = $depth;
        $result[] = $u;
        $result = array_merge($result, _dfsUsuarios($childrenOf, $byId, (int)$u['id'], $depth + 1));
    }
    return $result;
}
// Raíces: sin superior, o con superior que no existe en el árbol
$raices = array_values(array_filter($usuarios, fn($u) => empty($u['superior_id']) || !isset($byId[(int)$u['superior_id']])));
usort($raices, fn($a, $b) => ($a['nivel_jerarquico'] ?? 99) <=> ($b['nivel_jerarquico'] ?? 99)
    ?: strcmp($a['nombre'], $b['nombre']));
$usuariosOrdenados = [];
foreach ($raices as $r) {
    $r['_depth'] = 0;
    $usuariosOrdenados[] = $r;
    $usuariosOrdenados = array_merge($usuariosOrdenados, _dfsUsuarios($childrenOf, $byId, (int)$r['id'], 1));
}
?>

<?php include '../includes/header.php'; ?>

<div class="container mx-auto px-4 py-8">
    <!-- Header con Estadísticas -->
    <div class="mb-8">
        <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-800 mb-2">👥 Gestión de Usuarios</h1>
                <p class="text-slate-600">Administra usuarios del sistema, roles jerárquicos y perfiles de representantes.</p>
            </div>

            <?php if ($es_super_admin): ?>
            <button onclick="toggleModal('modalNuevo', true)" class="btn-primary text-white px-6 py-3 rounded-xl font-semibold shadow-lg hover:shadow-xl transition flex items-center justify-center gap-2">
                <span>+</span> Nuevo Usuario
            </button>
            <?php endif; ?>
        </div>

        <!-- Estadísticas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Total Usuarios</div>
                <div class="text-2xl font-bold text-slate-800"><?= $totalUsuarios ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Activos</div>
                <div class="text-2xl font-bold text-green-600"><?= $usuariosActivos ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Inactivos</div>
                <div class="text-2xl font-bold text-red-600"><?= $usuariosInactivos ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Representantes</div>
                <div class="text-2xl font-bold text-blue-600"><?= $usuariosRepresentantes ?></div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card rounded-xl shadow p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Filtrar por Rol</label>
                <select id="filtroRol" class="input-field w-full rounded-xl px-4 py-2">
                    <option value="">Todos los roles</option>
                    <?php foreach ($roles as $rol): ?>
                        <option value="<?= $rol['id'] ?>"><?= htmlspecialchars($rol['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Estado</label>
                <select id="filtroEstado" class="input-field w-full rounded-xl px-4 py-2">
                    <option value="">Todos</option>
                    <option value="1">Activos</option>
                    <option value="0">Inactivos</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Buscar</label>
                <input type="text" id="buscarUsuario" placeholder="Nombre o usuario..." 
                       class="input-field w-full rounded-xl px-4 py-2">
            </div>
        </div>
    </div>

    <!-- ── Vista Toggle ─────────────────────────────────────────── -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="flex bg-slate-100 rounded-2xl p-1 gap-1">
            <button id="btnVistaLista" onclick="setVista('lista')"
                    class="vista-tab active-tab px-5 py-2 rounded-xl text-sm font-semibold transition-all">
                📋 Lista
            </button>
            <button id="btnVistaJerarquia" onclick="setVista('jerarquia')"
                    class="vista-tab px-5 py-2 rounded-xl text-sm font-semibold transition-all text-slate-500">
                🌳 Jerarquía
            </button>
        </div>
        <div id="controles-jerarquia" class="hidden flex items-center gap-2">
            <button onclick="orgExpandAll(true)"
                    class="text-xs text-slate-500 hover:text-slate-800 px-3 py-1.5 rounded-xl border border-slate-200 hover:border-slate-300 bg-white transition">
                Expandir todo
            </button>
            <button onclick="orgExpandAll(false)"
                    class="text-xs text-slate-500 hover:text-slate-800 px-3 py-1.5 rounded-xl border border-slate-200 hover:border-slate-300 bg-white transition">
                Colapsar todo
            </button>
        </div>
    </div>

    <!-- ── Vista Jerarquía ───────────────────────────────────────── -->
    <div id="vistaJerarquia" class="hidden">

        <?php if (!empty($nivel0) || !empty($nivel1)): ?>
        <!-- Ápex: Admins + Director General -->
        <div class="flex flex-wrap gap-3 mb-6">
            <?php foreach (array_merge($nivel0, $nivel1) as $top):
                $isN0 = (int)($top['nivel_jerarquico'] ?? 0) === 0;
            ?>
            <div class="org-apex <?= $isN0 ? 'org-apex-n0' : 'org-apex-n1' ?> <?= empty($top['activo']) ? 'opacity-50' : '' ?>">
                <span class="org-apex-icon"><?= $isN0 ? '⚙️' : '★' ?></span>
                <div>
                    <div class="org-apex-name"><?= htmlspecialchars($top['nombre']) ?></div>
                    <div class="org-apex-role"><?= htmlspecialchars($top['rol_nombre'] ?? 'Administrador') ?></div>
                </div>
                <span class="org-dot <?= !empty($top['activo']) ? 'org-dot-on' : 'org-dot-off' ?>"></span>
                <?php if ($es_super_admin): ?>
                <button class="org-apex-edit" onclick="editarUsuario(<?= $top['id'] ?>)" title="Editar">✏️</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Árbol N2 → N3 → N4 -->
        <div id="orgTree">
        <?php if (empty($nivel2)): ?>
            <div class="text-center py-16 text-slate-400 text-sm">No hay gerentes nacionales registrados.</div>
        <?php endif; ?>

        <?php foreach ($nivel2 as $du):
            $gerentesHijos = $childrenOf[$du['id']] ?? [];
            $totalGer = $n3ForN2[$du['id']] ?? 0;
            $totalRep = $n4ForN2[$du['id']] ?? 0;
            $duActivo = !empty($du['activo']);
        ?>
        <!-- ▶ N2: Director de Unidad -->
        <div class="org-n2-wrap" data-rol="<?= $du['rol_id'] ?>" data-estado="<?= $du['activo'] ?>">
            <div class="org-n2-hdr" role="button" tabindex="0" onclick="orgToggle('n2-<?= $du['id'] ?>', this)" aria-expanded="false">
                <span class="org-lv-pill org-lv-n2">N2</span>
                <span class="org-hdr-icon">🏢</span>
                <span class="org-hdr-name"><?= htmlspecialchars($du['nombre']) ?></span>
                <span class="org-hdr-sub">
                    Director de Unidad<?= !empty($du['ruta']) ? ' &middot; ' . htmlspecialchars($du['ruta']) : '' ?>
                </span>
                <span class="org-hdr-counts">
                    <span class="org-cnt org-cnt-n3"><?= $totalGer ?> <i>ger.</i></span>
                    <span class="org-cnt org-cnt-n4"><?= $totalRep ?> <i>rep.</i></span>
                </span>
                <span class="org-status <?= $duActivo ? 'org-on' : 'org-off' ?>"><?= $duActivo ? 'Activo' : 'Inactivo' ?></span>
                <?php if ($es_super_admin): ?>
                <button class="org-edit-btn" onclick="event.stopPropagation(); editarUsuario(<?= $du['id'] ?>)" title="Editar">✏️</button>
                <?php endif; ?>
                <svg class="org-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="org-body" id="n2-<?= $du['id'] ?>">
            <div class="org-body-inner">
                <?php if (empty($gerentesHijos)): ?>
                <p class="org-empty">Sin gerentes de distrito asignados</p>
                <?php endif; ?>

                <?php foreach ($gerentesHijos as $ger):
                    if ((int)($ger['nivel_jerarquico'] ?? 99) !== 3) continue;
                    $repsHijos = $childrenOf[$ger['id']] ?? [];
                    $totalRepGer = count($repsHijos);
                    $gerActivo = !empty($ger['activo']);
                ?>
                <!-- ▶ N3: Gerente -->
                <div class="org-n3-wrap" data-rol="<?= $ger['rol_id'] ?>" data-estado="<?= $ger['activo'] ?>" data-gerente-id="<?= $ger['id'] ?>">
                    <div class="org-n3-hdr" role="button" tabindex="0" onclick="orgToggle('n3-<?= $ger['id'] ?>', this)" aria-expanded="false">
                        <span class="org-lv-pill org-lv-n3">N3</span>
                        <span class="org-hdr-icon org-av-n3">👤</span>
                        <span class="org-hdr-name"><?= htmlspecialchars($ger['nombre']) ?></span>
                        <span class="org-hdr-sub">
                            Gerente de Distrito<?= !empty($ger['ruta']) ? ' &middot; ' . htmlspecialchars($ger['ruta']) : '' ?>
                            <?= !empty($ger['celular']) ? ' &middot; 📱' . htmlspecialchars($ger['celular']) : '' ?>
                        </span>
                        <span class="org-hdr-counts">
                            <span class="org-cnt org-cnt-n4"><?= $totalRepGer ?> <i>rep.</i></span>
                        </span>
                        <span class="org-status <?= $gerActivo ? 'org-on' : 'org-off' ?>"><?= $gerActivo ? 'Activo' : 'Inactivo' ?></span>
                        <?php if ($es_super_admin): ?>
                        <button class="org-edit-btn" onclick="event.stopPropagation(); editarUsuario(<?= $ger['id'] ?>)" title="Editar">✏️</button>
                        <?php endif; ?>
                        <svg class="org-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </div>
                    <div class="org-body org-body-n3" id="n3-<?= $ger['id'] ?>">
                    <div class="org-body-inner org-drop-zone" data-gerente-id="<?= $ger['id'] ?>">
                        <div class="org-drop-hint">Suelta aquí para reasignar</div>
                        <?php if (empty($repsHijos)): ?>
                        <p class="org-empty org-empty-placeholder">Sin representantes asignados</p>
                        <?php else: ?>
                        <div class="org-rep-grid">
                            <?php foreach ($repsHijos as $rep):
                                if ((int)($rep['nivel_jerarquico'] ?? 99) !== 4) continue;
                                $repActivo = !empty($rep['activo']);
                            ?>
                            <div class="org-rep-card <?= $repActivo ? '' : 'org-rep-dim' ?>"
                                 data-rol="<?= $rep['rol_id'] ?>" data-estado="<?= $rep['activo'] ?>"
                                 data-rep-id="<?= $rep['id'] ?>" data-rep-nombre="<?= htmlspecialchars($rep['nombre'], ENT_QUOTES) ?>"
                                 <?php if ($es_super_admin): ?>draggable="true"<?php endif; ?>>
                                <div class="org-rep-top">
                                    <?php if (!empty($rep['representante_codigo'])): ?>
                                    <span class="org-rep-code"><?= htmlspecialchars($rep['representante_codigo']) ?></span>
                                    <?php endif; ?>
                                    <span class="org-dot <?= $repActivo ? 'org-dot-on' : 'org-dot-off' ?>" title="<?= $repActivo ? 'Activo' : 'Inactivo' ?>"></span>
                                </div>
                                <div class="org-rep-name"><?= htmlspecialchars($rep['nombre']) ?></div>
                                <div class="org-rep-user">@<?= htmlspecialchars($rep['usuario']) ?></div>
                                <div class="org-rep-meta">
                                    <?php if (!empty($rep['ruta'])): ?><span>🗺 <?= htmlspecialchars($rep['ruta']) ?></span><?php endif; ?>
                                    <?php if (!empty($rep['celular'])): ?><span>📱 <?= htmlspecialchars($rep['celular']) ?></span><?php endif; ?>
                                </div>
                                <?php if ($es_super_admin): ?>
                                <div class="org-rep-actions">
                                    <?php if (!empty($rep['representante_codigo'])): ?>
                                    <a href="representantes-qr.php?id=<?= (int)$rep['id'] ?>"
                                       class="org-rep-btn org-rep-qr" onclick="event.stopPropagation()">QR</a>
                                    <?php endif; ?>
                                    <button class="org-rep-btn org-rep-edit" onclick="editarUsuario(<?= $rep['id'] ?>)">Editar</button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div><!-- /N3 -->
                <?php endforeach; ?>
            </div>
            </div>
        </div><!-- /N2 -->
        <?php endforeach; ?>

        <?php
        // Usuarios sin posición en el árbol
        $huerfanosN3 = array_filter($nivel3, fn($g) => empty($g['superior_id']) || !isset($byId[(int)$g['superior_id']]) || (int)($byId[(int)$g['superior_id']]['nivel_jerarquico'] ?? 0) !== 2);
        $huerfanosN4 = array_filter($nivel4, fn($r) => empty($r['superior_id']) || !isset($byId[(int)$r['superior_id']]) || (int)($byId[(int)$r['superior_id']]['nivel_jerarquico'] ?? 0) !== 3);
        $huerfanos = array_values(array_merge($huerfanosN3, $huerfanosN4));
        if (!empty($huerfanos)): ?>
        <div class="mt-8" id="orphan-section">
            <div class="flex items-center gap-3 mb-4">
                <span class="text-xs font-bold text-amber-600 uppercase tracking-widest bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">⚠️ Sin asignar en árbol</span>
                <span class="text-xs text-slate-400" id="orphan-count"><?= count($huerfanos) ?> usuario(s)</span>
            </div>
            <div class="org-body-inner" id="orphan-zone">
            <div class="org-rep-grid">
                <?php foreach ($huerfanos as $h):
                    $hActivo = !empty($h['activo']);
                    $hEsRep  = ($h['rol_codigo'] ?? '') === 'representante';
                ?>
                <div class="org-rep-card org-rep-orphan <?= $hActivo ? '' : 'org-rep-dim' ?>"
                     data-rol="<?= $h['rol_id'] ?>" data-estado="<?= $h['activo'] ?>"
                     data-rep-id="<?= $h['id'] ?>" data-rep-nombre="<?= htmlspecialchars($h['nombre'], ENT_QUOTES) ?>"
                     <?php if ($es_super_admin && $hEsRep): ?>draggable="true"<?php endif; ?>>
                    <div class="org-rep-top">
                        <span class="org-rep-code" style="background:#FEF3C7;color:#92400E;"><?= htmlspecialchars($h['rol_nombre'] ?? '?') ?></span>
                        <span class="org-dot <?= $hActivo ? 'org-dot-on' : 'org-dot-off' ?>"></span>
                    </div>
                    <div class="org-rep-name"><?= htmlspecialchars($h['nombre']) ?></div>
                    <div class="org-rep-user">@<?= htmlspecialchars($h['usuario']) ?></div>
                    <div class="org-rep-meta">
                        <span>⚠️ <?= $h['superior_nombre'] ? htmlspecialchars($h['superior_nombre']) : 'Sin superior' ?></span>
                    </div>
                    <?php if ($es_super_admin): ?>
                    <div class="org-rep-actions">
                        <button class="org-rep-btn org-rep-edit" onclick="editarUsuario(<?= $h['id'] ?>)">Editar</button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            </div><!-- /org-body-inner orphan -->
        </div>
        <?php endif; ?>
        </div><!-- /orgTree -->

    </div><!-- /vistaJerarquia -->

    <!-- ── Vista Lista (tabla original) ─────────────────────────── -->
    <div id="vistaLista">
    <div class="card rounded-xl shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead style="background:linear-gradient(to right,var(--tw-neu-800),var(--tw-neu-900));color:#fff;">
                    <tr>
                        <th class="px-4 py-3 text-left">Usuario</th>
                        <th class="px-4 py-3 text-left">Nombre</th>
                        <th class="px-4 py-3 text-left">Rol</th>
                        <th class="px-4 py-3 text-left">Reporta a</th>
                        <th class="px-4 py-3 text-left">Representante</th>
                        <th class="px-4 py-3 text-left">Ruta / Celular</th>
                        <th class="px-4 py-3 text-left">Estado</th>
                        <?php if ($es_super_admin): ?>
                        <th class="px-4 py-3 text-center">Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tablaUsuarios" class="divide-y divide-slate-200">
                    <?php
                    $tieneHijos = [];
                    foreach ($usuariosOrdenados as $u) {
                        if (!empty($u['superior_id'])) $tieneHijos[(int)$u['superior_id']] = true;
                    }
                    ?>
                    <?php foreach ($usuariosOrdenados as $user):
                        $depth = $user['_depth'] ?? 0;
                        $indent = $depth * 20; // px de sangría
                        $connector = $depth > 0 ? str_repeat('&nbsp;', $depth * 2) . '<span class="text-slate-300 mr-1">' . ($depth === 1 ? '├─' : '└─') . '</span>' : '';
                    ?>
                        <tr class="hover:bg-slate-50 transition"
                            data-uid="<?= $user['id'] ?>"
                            data-parent-uid="<?= $user['superior_id'] ?? '' ?>"
                            data-rol="<?= $user['rol_id'] ?? '' ?>"
                            data-estado="<?= $user['activo'] ?>">
                            <td class="px-4 py-3" style="padding-left: <?= 16 + $indent ?>px">
                                <?php if (isset($tieneHijos[$user['id']])): ?>
                                    <button type="button" class="tree-toggle mr-1 text-slate-400 hover:text-slate-600 text-xs w-4 inline-block text-center leading-none" data-uid="<?= $user['id'] ?>" data-collapsed="0" onclick="toggleColapsar(this)" title="Colapsar / Expandir">▼</button>
                                <?php else: ?>
                                    <span class="mr-1 inline-block w-4"></span>
                                <?php endif; ?>
                                <?= $connector ?><span class="font-mono font-bold text-blue-600"><?= htmlspecialchars($user['usuario']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-slate-700 text-sm">
                                <?= htmlspecialchars($user['nombre']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                    <?= htmlspecialchars($user['rol_nombre'] ?? 'Administrador') ?>
                                </span>
                                <?php if (isset($user['nivel_jerarquico'])): ?>
                                    <span class="text-xs text-slate-500 ml-2">Nivel <?= $user['nivel_jerarquico'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 text-sm">
                                <?= $user['superior_nombre'] ? htmlspecialchars($user['superior_nombre']) : '—' ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 text-sm">
                                <?php if ($user['representante_nombre']): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-medium bg-purple-50 text-purple-700">
                                        <?= htmlspecialchars($user['representante_codigo'] ?? $user['representante_nombre']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?php if (!empty($user['ruta'])): ?>
                                    <span class="inline-block bg-teal-50 text-teal-800 text-xs font-semibold px-2 py-0.5 rounded mb-1"><?= htmlspecialchars($user['ruta']) ?></span>
                                    <?php if (!empty($user['desc_ruta'])): ?>
                                        <div class="text-xs text-slate-500 truncate max-w-[140px]" title="<?= htmlspecialchars($user['desc_ruta']) ?>"><?= htmlspecialchars($user['desc_ruta']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                                <?php if (!empty($user['celular'])): ?>
                                    <div class="text-xs text-slate-600 mt-0.5">📱 <?= htmlspecialchars($user['celular']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($user['activo']): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">Activo</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($es_super_admin): ?>
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <?php if (!empty($user['representante_codigo'])): ?>
                                    <a href="representantes-qr.php?id=<?= (int)$user['id'] ?>"
                                       class="px-3 py-1 rounded-lg bg-purple-50 hover:bg-purple-100 text-purple-600 text-sm font-medium">QR</a>
                                    <?php endif; ?>
                                    <button onclick="editarUsuario(<?= $user['id'] ?>)"
                                            class="px-3 py-1 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-600 text-sm font-medium">Editar</button>
                                    <button onclick="toggleActivo(<?= $user['id'] ?>)"
                                            class="px-3 py-1 rounded-lg bg-orange-50 hover:bg-orange-100 text-orange-600 text-sm font-medium">
                                        <?= $user['activo'] ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="<?= $es_super_admin ? 8 : 7 ?>" class="px-6 py-12 text-center">
                                <p class="text-slate-600 text-lg font-semibold">No hay usuarios registrados</p>
                                <p class="text-slate-500 text-sm mt-2">Crea un nuevo usuario para comenzar a gestionar accesos.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Paginador -->
        <div class="pag-bar" id="us-pag-bar">
            <div class="pag-left">
                <span class="pag-info" id="us-pag-info"></span>
                <select class="pag-size" id="us-pag-size">
                    <option value="10" selected>10 / pág</option>
                    <option value="25">25 / pág</option>
                    <option value="50">50 / pág</option>
                    <option value="100">100 / pág</option>
                </select>
            </div>
            <div class="pag-controls" id="us-pag-ctrl"></div>
        </div>
    </div>
    </div><!-- /vistaLista -->

</div><!-- /container -->

<!-- Modal Nuevo Usuario -->
<div id="modalNuevo" class="fixed inset-0 hidden flex items-center justify-center z-50 p-4"
     onclick="if(event.target===this) toggleModal('modalNuevo', false)">
  <div id="modalNuevoInner" onclick="event.stopPropagation()">
    <div class="me-left">
      <div class="me-avatar">+</div>
      <div class="me-user-name">Nuevo usuario</div>
      <div class="me-user-role">Alta en sistema</div>
      <div class="me-divider"></div>
      <div class="me-meta-row">
        <div class="me-meta-icon">@</div>
        <div>
          <div class="me-meta-label">Acceso</div>
          <div class="me-meta-val">Usuario + email</div>
        </div>
      </div>
      <div class="me-meta-row">
        <div class="me-meta-icon">R</div>
        <div>
          <div class="me-meta-label">Jerarquía</div>
          <div class="me-meta-val">Rol y superior</div>
        </div>
      </div>
      <div class="me-badge">Creación</div>
    </div>

    <div class="me-right">
      <div class="me-right-header">
        <div class="me-right-title">Crear usuario</div>
        <button class="me-close-btn" onclick="toggleModal('modalNuevo', false)" title="Cerrar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="me-form-body">
        <form id="formNuevo" onsubmit="guardarUsuario(event)">
          <div class="me-section">
            <div class="me-section-head">
              <span class="me-section-pill">Identidad</span>
              <div class="me-section-line"></div>
            </div>
            <div class="me-grid-2" style="margin-bottom:14px;">
              <div class="me-field">
                <label class="me-label">Nombre completo</label>
                <input type="text" name="nombre" required class="me-input" placeholder="Ej. María García">
              </div>
              <div class="me-field">
                <label class="me-label">Usuario <span>(login)</span></label>
                <input type="text" name="usuario" required class="me-input" placeholder="usuario_sistema">
              </div>
            </div>
            <div class="me-field">
              <label class="me-label">Correo electrónico <span>· se usa para iniciar sesión</span></label>
              <input type="email" name="email" required class="me-input" placeholder="usuario@correo.com">
            </div>
          </div>

          <div class="me-section">
            <div class="me-section-head">
              <span class="me-section-pill">Credenciales</span>
              <div class="me-section-line"></div>
            </div>
            <div class="me-field">
              <label class="me-label">Contraseña</label>
              <input type="password" name="password" required autocomplete="new-password" class="me-input" placeholder="••••••••">
            </div>
          </div>

          <div class="me-section">
            <div class="me-section-head">
              <span class="me-section-pill">Rol & Jerarquía</span>
              <div class="me-section-line"></div>
            </div>
            <div class="me-grid-2">
              <div class="me-field">
                <label class="me-label">Rol</label>
                <select name="rol_id" id="rolSelect" required class="me-input" onchange="toggleRepresentanteField()">
                  <?php foreach ($roles as $rol): ?>
                    <option value="<?= $rol['id'] ?>" data-codigo="<?= $rol['codigo'] ?>" data-nivel="<?= $rol['nivel_jerarquico'] ?>">
                      <?= htmlspecialchars($rol['nombre']) ?> — Nivel <?= $rol['nivel_jerarquico'] ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="me-field">
                <label class="me-label">Reporta a</label>
                <select name="superior_id" class="me-input">
                  <option value="">Sin superior directo</option>
                  <?php foreach ($usuarios as $sup): ?>
                    <?php if ($sup['activo']): ?>
                      <option value="<?= $sup['id'] ?>">
                        <?= htmlspecialchars($sup['nombre']) ?> · <?= htmlspecialchars($sup['rol_nombre'] ?? 'Admin') ?>
                      </option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div id="rutaField" class="me-ruta-box hidden">
            <div class="me-ruta-head">
              <span class="me-ruta-label">Datos de Ruta</span>
              <span class="me-ruta-badge">N3 · N4</span>
            </div>
            <div class="me-grid-3">
              <div class="me-field">
                <label class="me-label">Ruta <span>máx. 20 car.</span></label>
                <input type="text" name="ruta" maxlength="20" class="me-input" placeholder="ZN-SUR-01">
              </div>
              <div class="me-field">
                <label class="me-label">Celular</label>
                <input type="tel" name="celular" maxlength="15" class="me-input" placeholder="10 dígitos">
              </div>
              <div class="me-field">
                <label class="me-label">Descripción de Ruta</label>
                <input type="text" name="desc_ruta" maxlength="250" class="me-input" placeholder="Zona sur: CDMX, Morelos">
              </div>
            </div>
          </div>

          <div id="representanteField" class="me-rep-box hidden">
            <div class="me-rep-head">
              <span class="me-rep-label">Perfil de Representante</span>
              <span class="me-rep-badge">Nuevo flujo</span>
            </div>
            <div class="me-grid-2">
              <div class="me-field">
                <label class="me-label">Código</label>
                <input type="text" name="representante_codigo" id="representanteCodigo" class="me-input" placeholder="REP002">
              </div>
              <div class="me-field">
                <label class="me-label">Teléfono</label>
                <input type="tel" name="representante_telefono" class="me-input" placeholder="10 dígitos">
              </div>
              <div class="me-field" style="grid-column:1/-1;">
                <label class="me-label">Tags permitidos</label>
                <input type="text" name="representante_tags_permitidos" class="me-input" placeholder="medico, clinica">
              </div>
            </div>
            <div class="me-rep-subdiv">
              <div class="me-rep-subdiv-label">Dirección de inventario</div>
              <div class="me-grid-2">
                <div class="me-field" style="grid-column:1/-1;">
                  <label class="me-label">Calle</label>
                  <input type="text" name="dir_calle" class="me-input" placeholder="Av. Insurgentes Sur">
                </div>
                <div class="me-field">
                  <label class="me-label">Número</label>
                  <input type="text" name="dir_numero" class="me-input" placeholder="123 Int. 4">
                </div>
                <div class="me-field">
                  <label class="me-label">Código postal</label>
                  <input type="text" name="dir_cp" class="me-input" placeholder="06600">
                </div>
                <div class="me-field">
                  <label class="me-label">Colonia</label>
                  <input type="text" name="dir_colonia" class="me-input" placeholder="Roma Norte">
                </div>
                <div class="me-field">
                  <label class="me-label">Ciudad</label>
                  <input type="text" name="dir_ciudad" class="me-input" placeholder="Ciudad de México">
                </div>
                <div class="me-field" style="grid-column:1/-1;">
                  <label class="me-label">Estado</label>
                  <input type="text" name="dir_estado" class="me-input" placeholder="CDMX">
                </div>
              </div>
            </div>
          </div>

          <div style="height:8px;"></div>
        </form>
      </div>

      <div class="me-form-footer">
        <button type="button" class="me-btn-cancel" onclick="toggleModal('modalNuevo', false)">
          Cancelar
        </button>
        <button type="submit" form="formNuevo" id="btnGuardarNuevo" class="me-btn-save">
          Guardar usuario
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Editar Usuario — diseño tableta/escritorio -->
<style>


/* ══════════════════════════════════════════════════════════
   VISTA TOGGLE
══════════════════════════════════════════════════════════ */
.vista-tab {
    background: transparent; border: none; cursor: pointer;
    color: var(--text-secondary);
    font-family: var(--font-base);
    transition: background .15s, color .15s;
}
.vista-tab.active-tab {
    background: var(--bg-card);
    color: var(--text-primary);
    box-shadow: 0 1px 4px rgba(15,23,42,.10);
}

/* ══════════════════════════════════════════════════════════
   ORG APEX (N0 + N1 cards at top)
══════════════════════════════════════════════════════════ */
.org-apex {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-radius: 14px;
    border: 1.5px solid;
    font-family: var(--font-base);
    position: relative;
}
.org-apex-n0 { background: #F8FAFC; border-color: #CBD5E1; }
.org-apex-n1 { background: #FFFBEB; border-color: #FDE68A; }
.org-apex-icon { font-size: 18px; }
.org-apex-name { font-size: 14px; font-weight: 700; color: var(--text-primary); }
.org-apex-role { font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .05em; }
.org-apex-edit {
    background: none; border: none; cursor: pointer; font-size: 13px;
    opacity: .5; padding: 0 2px; transition: opacity .15s;
}
.org-apex-edit:hover { opacity: 1; }

/* Status dot */
.org-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    display: inline-block;
}
.org-dot-on  { background: #22C55E; box-shadow: 0 0 0 2px #DCFCE7; }
.org-dot-off { background: #94A3B8; box-shadow: 0 0 0 2px #F1F5F9; }

/* Status badge */
.org-status {
    font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; flex-shrink: 0;
}
.org-on  { background: #DCFCE7; color: #166534; }
.org-off { background: #F1F5F9; color: #64748B; }
.org-status-sm { font-size: 9px; padding: 2px 6px; }

/* ══════════════════════════════════════════════════════════
   ORG TREE ROOT
══════════════════════════════════════════════════════════ */
#orgTree { display: flex; flex-direction: column; gap: 10px; }

/* ── Level pills ── */
.org-lv-pill {
    font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 6px;
    text-transform: uppercase; letter-spacing: .06em; flex-shrink: 0;
    font-family: var(--font-mono);
}
.org-lv-n2 { background: #DBEAFE; color: #1D4ED8; }
.org-lv-n3 { background: #EDE9FE; color: #6D28D9; }

/* ── N2 accordion ── */
.org-n2-wrap {
    border-radius: 16px;
    border: 1.5px solid #BFDBFE;
    background: #F0F7FF;
    overflow: hidden;
    transition: box-shadow .2s;
}
.org-n2-wrap:focus-within { box-shadow: 0 0 0 3px rgba(59,130,246,.15); }

.org-n2-hdr {
    width: 100%; display: flex; align-items: center; gap: 10px;
    padding: 14px 18px; background: none; cursor: pointer;
    font-family: var(--font-base);
    transition: background .15s;
    min-width: 0; user-select: none;
}
.org-n2-hdr:hover { background: rgba(59,130,246,.06); }
.org-n2-hdr[aria-expanded="true"] { background: rgba(59,130,246,.08); }

/* ── N3 accordion ── */
.org-n3-wrap {
    border-radius: 12px;
    border: 1.5px solid #DDD6FE;
    background: #FAF8FF;
    overflow: hidden;
    margin: 0 14px 10px;
    transition: box-shadow .2s;
}
.org-n3-wrap:focus-within { box-shadow: 0 0 0 3px rgba(139,92,246,.15); }

.org-n3-hdr {
    width: 100%; display: flex; align-items: center; gap: 10px;
    padding: 11px 16px; background: none; cursor: pointer;
    font-family: var(--font-base);
    transition: background .15s;
    min-width: 0; user-select: none;
}
.org-n3-hdr:hover { background: rgba(139,92,246,.05); }
.org-n3-hdr[aria-expanded="true"] { background: rgba(139,92,246,.07); }

/* ── Shared header elements ── */
.org-hdr-icon { font-size: 18px; flex-shrink: 0; }
.org-hdr-name {
    font-size: 14px; font-weight: 700; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    min-width: 0; flex: 1;
}
.org-hdr-sub {
    font-size: 11.5px; color: var(--text-secondary); white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis; flex-shrink: 1; min-width: 0;
    max-width: 280px;
}
.org-hdr-counts { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }
.org-cnt {
    font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 20px;
    font-family: var(--font-base);
}
.org-cnt i { font-style: normal; font-weight: 500; opacity: .7; }
.org-cnt-n3 { background: #DBEAFE; color: #1D4ED8; }
.org-cnt-n4 { background: #D1FAE5; color: #065F46; }

.org-edit-btn {
    background: none; border: none; cursor: pointer; font-size: 14px;
    padding: 2px 4px; opacity: .45; transition: opacity .15s; flex-shrink: 0;
    border-radius: 6px; line-height: 1;
}
.org-edit-btn:hover { opacity: 1; background: rgba(0,0,0,.05); }

.org-chev {
    width: 18px; height: 18px; flex-shrink: 0;
    color: var(--text-secondary); transition: transform .25s ease;
}
.org-n2-hdr[aria-expanded="true"] .org-chev,
.org-n3-hdr[aria-expanded="true"] .org-chev { transform: rotate(180deg); }

/* ── Accordion body (CSS grid trick — smooth height) ── */
.org-body {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows .32s cubic-bezier(.4,0,.2,1);
}
.org-body.open { grid-template-rows: 1fr; }
.org-body-inner {
    overflow: hidden;
    min-height: 0;
    padding: 0;
    transition: padding .32s;
}
.org-body.open .org-body-inner { padding: 6px 0 14px; }
.org-body-n3.open .org-body-inner { padding: 6px 14px 14px; }

/* ── Empty state ── */
.org-empty {
    font-size: 12px; color: var(--text-muted);
    text-align: center; padding: 18px; font-style: italic;
}

/* ══════════════════════════════════════════════════════════
   REP CARDS GRID (N4)
══════════════════════════════════════════════════════════ */
.org-rep-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 10px;
}

.org-rep-card {
    background: var(--bg-card);
    border: 1.5px solid #A7F3D0;
    border-radius: 12px;
    padding: 12px 14px;
    display: flex; flex-direction: column; gap: 4px;
    transition: box-shadow .15s, transform .15s;
    position: relative;
}
.org-rep-card:hover {
    box-shadow: 0 4px 16px rgba(16,185,129,.12);
    transform: translateY(-2px);
}
.org-rep-dim {
    border-color: #E2E8F0;
    opacity: .65;
}
.org-rep-orphan {
    border-color: #FDE68A;
    background: #FFFBEB;
}

.org-rep-top {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 2px;
}
.org-rep-code {
    font-family: var(--font-mono);
    font-size: 10px; font-weight: 500;
    padding: 2px 7px; border-radius: 6px;
    background: #D1FAE5; color: #065F46;
}
.org-rep-name {
    font-size: 13px; font-weight: 700; color: var(--text-primary);
    line-height: 1.3;
}
.org-rep-user {
    font-family: var(--font-mono);
    font-size: 10.5px; color: var(--text-secondary);
    margin-bottom: 4px;
}
.org-rep-meta {
    display: flex; flex-wrap: wrap; gap: 4px;
    font-size: 10.5px; color: var(--text-secondary);
}
.org-rep-meta span { display: flex; align-items: center; gap: 3px; }

.org-rep-actions {
    display: flex; gap: 6px; margin-top: 8px; padding-top: 8px;
    border-top: 1px solid rgba(0,0,0,.05);
}
.org-rep-btn {
    padding: 3px 10px; border-radius: 7px;
    font-size: 11px; font-weight: 600;
    border: 1.5px solid; cursor: pointer; text-decoration: none;
    font-family: var(--font-base); transition: background .12s;
    display: inline-flex; align-items: center;
}
.org-rep-edit { background: #EFF6FF; color: #2563EB; border-color: #BFDBFE; }
.org-rep-edit:hover { background: #DBEAFE; }
.org-rep-qr   { background: #F5F3FF; color: #7C3AED; border-color: #DDD6FE; }
.org-rep-qr:hover { background: #EDE9FE; }

#modalNuevo,
#modalEditar {
  backdrop-filter: blur(6px);
  background: rgba(15,23,42,.45);
}
#modalNuevoInner,
#modalEditarInner {
  font-family: var(--font-base);
  display: grid;
  grid-template-columns: 190px 1fr;
  max-width: 860px;
  width: 96vw;
  height: min(92vh, 820px);
  max-height: 92vh;
  border-radius: 14px;
  overflow: hidden;
  background: var(--bg-card);
  border: 1px solid var(--border-card);
  box-shadow: 0 24px 70px rgba(15,23,42,.28);
  animation: meIn .22s cubic-bezier(.34,1.3,.64,1);
}
@keyframes meIn {
  from { opacity:0; transform:translateY(14px) scale(.97); }
  to   { opacity:1; transform:none; }
}

/* Left sidebar */
#meLeft,
.me-left {
  background: var(--bg-card-hover);
  border-right: 1px solid var(--border-card);
  padding: 26px 20px 22px;
  display: flex;
  flex-direction: column;
  gap: 0;
  position: relative;
  overflow: hidden;
}
#meLeft > *,
.me-left > * {
  position: relative;
  z-index: 1;
}
#meLeft::before,
.me-left::before {
  content: none;
}
#meLeft::after,
.me-left::after {
  content: none;
}
.me-avatar {
  width: 44px; height: 44px;
  border-radius: 10px;
  background: var(--accent);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 19px; margin-bottom: 16px;
  box-shadow: none;
  flex-shrink: 0;
}
.me-user-name {
  font-size: 15px; font-weight: 700; color: var(--text-primary);
  line-height: 1.3; word-break: break-word;
  min-height: 20px; margin-bottom: 4px;
}
.me-user-role {
  font-size: 11px; font-weight: 600;
  color: var(--text-secondary); text-transform: uppercase; letter-spacing: .07em;
  margin-bottom: 24px;
}
.me-divider {
  height: 1px; background: var(--border-card); margin-bottom: 18px;
}
.me-meta-row {
  display: flex; align-items: flex-start; gap: 8px;
  margin-bottom: 14px;
}
.me-meta-icon {
  width: 24px; height: 24px; border-radius: 7px;
  background: var(--bg-card);
  color: var(--text-secondary);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; flex-shrink: 0;
  border: 1px solid var(--border-card);
}
.me-meta-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
.me-meta-val   { font-family: var(--font-mono); font-size: 12px; color: var(--text-secondary); margin-top: 1px; word-break: break-all; }

.me-badge {
  display: inline-flex; align-items: center;
  padding: 5px 9px; border-radius: 7px;
  font-size: 10px; font-weight: 700; letter-spacing: .04em;
  background: var(--bg-card);
  color: var(--text-secondary);
  border: 1px solid var(--border-card);
  margin-top: auto;
}

/* Right panel */
#meRight,
.me-right {
  background: var(--bg-card);
  min-height: 0;
  overflow: hidden;
  overflow-x: hidden;
  display: flex;
  flex-direction: column;
}
#meRight::-webkit-scrollbar, .me-right::-webkit-scrollbar { width: 5px; }
#meRight::-webkit-scrollbar-track, .me-right::-webkit-scrollbar-track { background: var(--bg-card-hover); }
#meRight::-webkit-scrollbar-thumb, .me-right::-webkit-scrollbar-thumb { background: var(--border-input); border-radius: 4px; }

.me-right-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 22px 28px 18px;
  border-bottom: 1px solid var(--border-card);
  position: sticky; top: 0; background: var(--bg-card); z-index: 2;
}
.me-right-title {
  font-size: 17px; font-weight: 800; color: var(--text-primary); letter-spacing: 0;
}
.me-close-btn {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--bg-card-hover); border: 1px solid var(--border-card); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: var(--text-secondary); transition: background .12s, color .12s;
}
.me-close-btn:hover { background: var(--bg-menu-item); color: var(--text-primary); }

.me-form-body {
  padding: 22px 28px 0;
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
}
.me-form-body::-webkit-scrollbar { width: 6px; }
.me-form-body::-webkit-scrollbar-track { background: var(--bg-card-hover); }
.me-form-body::-webkit-scrollbar-thumb { background: var(--border-input); border-radius: 999px; }
.me-form-footer {
  padding: 16px 28px 22px;
  display: flex; justify-content: flex-end; gap: 10px;
  border-top: 1px solid var(--border-card);
  flex: 0 0 auto;
  background: var(--bg-card); z-index: 2;
}

/* Section headers */
.me-section {
  margin-bottom: 20px;
}
.me-section-head {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 14px;
}
.me-section-pill {
  font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em;
  padding: 3px 10px; border-radius: 20px;
  background: var(--bg-card-hover); color: var(--text-secondary); border: 1px solid var(--border-card);
}
.me-section-line {
  flex: 1; height: 1px; background: var(--border-card);
}

/* Fields */
.me-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.me-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
.me-field { display: flex; flex-direction: column; gap: 5px; }
.me-label {
  font-size: 11px; font-weight: 700; color: var(--text-secondary);
  text-transform: uppercase; letter-spacing: .06em;
}
.me-label span { font-weight: 500; text-transform: none; letter-spacing: 0; color: var(--text-muted); }
.me-input {
  background: var(--bg-input);
  border: 1.5px solid var(--border-input);
  border-radius: 10px;
  padding: 9px 12px;
  font-family: var(--font-base);
  font-size: 13.5px; font-weight: 500;
  color: var(--text-primary);
  transition: border-color .15s, box-shadow .15s;
  width: 100%;
}
.me-input:focus {
  outline: none;
  border-color: var(--border-focus);
  box-shadow: 0 0 0 3px var(--focus-ring);
  background: var(--bg-input);
}
.me-input::placeholder { color: var(--text-muted); }
.me-input[type="password"] { font-family: var(--font-mono); font-size: 15px; letter-spacing: .08em; }

.me-note { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Ruta section */
.me-ruta-box {
  background: var(--bg-card-hover);
  border: 1.5px solid var(--border-card);
  border-left: 3px solid var(--accent);
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 20px;
}
.me-ruta-head {
  display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
}
.me-ruta-label {
  font-size: 11px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .07em; color: var(--text-primary);
}
.me-ruta-badge {
  font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 6px;
  background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border-card);
}
.me-ruta-box .me-input {
  background: var(--bg-input);
  border-color: var(--border-input);
}
.me-ruta-box .me-input:focus {
  border-color: var(--border-focus);
}

/* Rep section */
.me-rep-box {
  background: var(--bg-card-hover);
  border: 1.5px solid var(--border-card);
  border-left: 3px solid var(--accent);
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 20px;
}
.me-rep-head {
  display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px;
}
.me-rep-label {
  font-size: 11px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .07em; color: var(--text-primary);
}
.me-rep-badge {
  font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 6px;
  background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border-card);
}
.me-rep-box .me-input {
  background: var(--bg-input);
  border-color: var(--border-input);
}
.me-rep-box .me-input:focus {
  border-color: var(--border-focus);
  box-shadow: 0 0 0 3px var(--focus-ring);
}
.me-rep-subdiv {
  margin-top: 14px; padding-top: 14px;
  border-top: 1px dashed var(--border-card);
}
.me-rep-subdiv-label {
  font-size: 10px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .07em; color: var(--text-secondary); margin-bottom: 12px;
}

/* Buttons */
.me-btn-cancel {
  padding: 9px 22px; border-radius: 10px;
  background: var(--bg-card-hover); color: var(--text-secondary);
  font-family: var(--font-base);
  font-size: 13.5px; font-weight: 600;
  border: 1.5px solid var(--border-card); cursor: pointer;
  transition: background .12s;
}
.me-btn-cancel:hover { background: var(--bg-menu-item); color: var(--text-primary); }
.me-btn-save {
  padding: 9px 26px; border-radius: 10px;
  background: var(--accent); color: #fff;
  font-family: var(--font-base);
  font-size: 13.5px; font-weight: 700;
  border: none; cursor: pointer;
  transition: background .12s, box-shadow .12s;
  box-shadow: none;
}
.me-btn-save:hover { background: var(--accent-hover); }
.me-btn-save:disabled { opacity: .6; cursor: not-allowed; }

@media (max-width: 680px) {
  #modalNuevoInner, #modalEditarInner { grid-template-columns: 1fr; }
  #meLeft, .me-left { display: none; }
  .me-grid-2, .me-grid-3 { grid-template-columns: 1fr; }
}

/* ══════════════════════════════════════════════════════════
   DRAG & DROP
══════════════════════════════════════════════════════════ */

/* Card mientras se arrastra */
.org-rep-card[draggable="true"] { cursor: grab; }
.org-rep-card[draggable="true"]:active { cursor: grabbing; }
.org-rep-card.dnd-dragging {
    opacity: .4;
    transform: scale(.97);
    box-shadow: none !important;
    pointer-events: none;
}

/* Ghost flotante que sigue el cursor */
#dnd-ghost {
    position: fixed;
    pointer-events: none;
    z-index: 9999;
    background: var(--bg-card);
    border: 2px solid #6D28D9;
    border-radius: 12px;
    padding: 10px 14px;
    box-shadow: 0 8px 32px rgba(109,40,217,.22);
    font-family: var(--font-base);
    font-size: 13px; font-weight: 700;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
    max-width: 220px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    animation: ghostPop .12s cubic-bezier(.34,1.5,.64,1);
    transform-origin: top left;
}
@keyframes ghostPop {
    from { opacity:0; transform: scale(.85); }
    to   { opacity:1; transform: scale(1); }
}
#dnd-ghost .dnd-ghost-icon { font-size: 16px; flex-shrink:0; }
#dnd-ghost .dnd-ghost-sub  { font-size: 10px; font-weight:500; color:#7C3AED; margin-left:2px; }

/* Hint de drop (texto dentro de cada N3) */
.org-drop-hint {
    display: none;
    text-align: center;
    font-size: 11.5px; font-weight: 600;
    color: #6D28D9;
    padding: 8px;
    border: 2px dashed #C4B5FD;
    border-radius: 10px;
    margin-bottom: 8px;
    letter-spacing: .02em;
    transition: background .15s;
}

/* Drop zone activa — cuando se arrastra sobre ella */
.org-body-inner.dnd-over {
    outline: 3px solid #7C3AED;
    outline-offset: -3px;
    border-radius: 10px;
    background: rgba(109,40,217,.05);
}
.org-body-inner.dnd-over .org-drop-hint {
    display: block;
    background: rgba(109,40,217,.06);
}

/* Zona origen (de donde viene el rep) */
.org-body-inner.dnd-source {
    outline: 2px dashed #CBD5E1;
    outline-offset: -2px;
    border-radius: 10px;
    background: rgba(100,116,139,.03);
}

/* N3 wrap highlight cuando su body-inner es drop target */
.org-n3-wrap.dnd-target-active {
    box-shadow: 0 0 0 3px rgba(109,40,217,.25);
}
</style>

<div id="modalEditar" class="fixed inset-0 hidden flex items-center justify-center z-50 p-4"
     onclick="if(event.target===this) toggleModal('modalEditar', false)">
  <div id="modalEditarInner" onclick="event.stopPropagation()">

    <!-- Left sidebar: user identity -->
    <div id="meLeft">
      <div class="me-avatar">👤</div>
      <div class="me-user-name" id="meSidebarName">—</div>
      <div class="me-user-role" id="meSidebarRole">—</div>
      <div class="me-divider"></div>
      <div class="me-meta-row">
        <div class="me-meta-icon">@</div>
        <div>
          <div class="me-meta-label">Usuario</div>
          <div class="me-meta-val" id="meSidebarUsuario">—</div>
        </div>
      </div>
      <div class="me-meta-row">
        <div class="me-meta-icon">✉</div>
        <div>
          <div class="me-meta-label">Email</div>
          <div class="me-meta-val" id="meSidebarEmail">—</div>
        </div>
      </div>
      <div class="me-badge" id="meSidebarBadge">Editando</div>
    </div>

    <!-- Right panel: form -->
    <div id="meRight">
      <div class="me-right-header">
        <div class="me-right-title">Editar usuario</div>
        <button class="me-close-btn" onclick="toggleModal('modalEditar', false)" title="Cerrar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="me-form-body">
        <form id="formEditar" onsubmit="actualizarUsuario(event)">
          <input type="hidden" name="id" id="editId">

          <!-- Identidad -->
          <div class="me-section">
            <div class="me-section-head">
              <span class="me-section-pill">Identidad</span>
              <div class="me-section-line"></div>
            </div>
            <div class="me-grid-2" style="margin-bottom:14px;">
              <div class="me-field">
                <label class="me-label">Nombre completo</label>
                <input type="text" name="nombre" id="editNombre" required class="me-input"
                       placeholder="Ej. María García"
                       oninput="document.getElementById('meSidebarName').textContent=this.value||'—'">
              </div>
              <div class="me-field">
                <label class="me-label">Usuario <span>(login)</span></label>
                <input type="text" name="usuario" id="editUsuario" required class="me-input"
                       placeholder="usuario_sistema"
                       oninput="document.getElementById('meSidebarUsuario').textContent=this.value||'—'">
              </div>
            </div>
            <div class="me-field" style="margin-bottom:0;">
              <label class="me-label">Correo electrónico <span>· se usa para iniciar sesión</span></label>
              <input type="email" name="email" id="editEmail" required class="me-input"
                     placeholder="usuario@correo.com"
                     oninput="document.getElementById('meSidebarEmail').textContent=this.value||'—'">
            </div>
          </div>

          <!-- Credenciales -->
          <div class="me-section">
            <div class="me-section-head">
              <span class="me-section-pill">Contraseña</span>
              <div class="me-section-line"></div>
            </div>
            <div class="me-field">
              <label class="me-label">Nueva contraseña <span>· dejar vacío para no cambiar</span></label>
              <input type="password" name="password" id="editPassword" autocomplete="new-password"
                     class="me-input" placeholder="••••••••">
            </div>
          </div>

          <!-- Rol & Jerarquía -->
          <div class="me-section">
            <div class="me-section-head">
              <span class="me-section-pill">Rol & Jerarquía</span>
              <div class="me-section-line"></div>
            </div>
            <div class="me-grid-2">
              <div class="me-field">
                <label class="me-label">Rol</label>
                <select name="rol_id" id="editRolSelect" required class="me-input"
                        onchange="toggleEditRepresentanteField(); const o=this.options[this.selectedIndex]; document.getElementById('meSidebarRole').textContent=o?o.textContent.trim():'—';">
                  <?php foreach ($roles as $rol): ?>
                    <option value="<?= $rol['id'] ?>" data-codigo="<?= $rol['codigo'] ?>" data-nivel="<?= $rol['nivel_jerarquico'] ?>">
                      <?= htmlspecialchars($rol['nombre']) ?> — Nivel <?= $rol['nivel_jerarquico'] ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="me-field">
                <label class="me-label">Reporta a</label>
                <select name="superior_id" id="editSuperiorId" class="me-input">
                  <option value="">Sin superior directo</option>
                  <?php foreach ($usuarios as $sup): ?>
                    <?php if ($sup['activo']): ?>
                      <option value="<?= $sup['id'] ?>">
                        <?= htmlspecialchars($sup['nombre']) ?> · <?= htmlspecialchars($sup['rol_nombre'] ?? 'Admin') ?>
                      </option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- Datos de Ruta -->
          <div id="editRutaField" class="me-ruta-box hidden">
            <div class="me-ruta-head">
              <span class="me-ruta-label">Datos de Ruta</span>
              <span class="me-ruta-badge">N3 · N4</span>
            </div>
            <div class="me-grid-3">
              <div class="me-field">
                <label class="me-label">En Ruta</label>
                <input type="text" name="ruta" id="editRuta" maxlength="20"
                       class="me-input" placeholder="ZN-SUR-01">
              </div>
              <div class="me-field">
                <label class="me-label">Celular</label>
                <input type="tel" name="celular" id="editCelular" maxlength="15"
                       class="me-input" placeholder="10 dígitos">
              </div>
              <div class="me-field">
                <label class="me-label">Descripción de Ruta</label>
                <input type="text" name="desc_ruta" id="editDescRuta" maxlength="250"
                       class="me-input" placeholder="Zona sur: CDMX, Morelos…">
              </div>
            </div>
          </div>

          <!-- Perfil comercial representante -->
          <div id="editRepresentanteField" class="me-rep-box hidden">
            <div class="me-rep-head">
              <span class="me-rep-label">Perfil de Representante</span>
              <span class="me-rep-badge">Nuevo flujo</span>
            </div>
            <div class="me-grid-2">
              <div class="me-field">
                <label class="me-label">Código</label>
                <input type="text" name="representante_codigo" id="editRepresentanteCodigo"
                       class="me-input" placeholder="REP002">
              </div>
              <div class="me-field">
                <label class="me-label">Teléfono</label>
                <input type="tel" name="representante_telefono" id="editRepresentanteTelefono"
                       class="me-input" placeholder="10 dígitos">
              </div>
              <div class="me-field" style="grid-column:1/-1;">
                <label class="me-label">Tags permitidos</label>
                <input type="text" name="representante_tags_permitidos" id="editRepresentanteTags"
                       class="me-input" placeholder="medico, clinica">
              </div>
            </div>
            <div class="me-rep-subdiv">
              <div class="me-rep-subdiv-label">Dirección de inventario</div>
              <div class="me-grid-2">
                <div class="me-field" style="grid-column:1/-1;">
                  <label class="me-label">Calle</label>
                  <input type="text" name="dir_calle" id="editDirCalle"
                         class="me-input" placeholder="Av. Insurgentes Sur">
                </div>
                <div class="me-field">
                  <label class="me-label">Número</label>
                  <input type="text" name="dir_numero" id="editDirNumero"
                         class="me-input" placeholder="123 Int. 4">
                </div>
                <div class="me-field">
                  <label class="me-label">Código postal</label>
                  <input type="text" name="dir_cp" id="editDirCp"
                         class="me-input" placeholder="06600">
                </div>
                <div class="me-field">
                  <label class="me-label">Colonia</label>
                  <input type="text" name="dir_colonia" id="editDirColonia"
                         class="me-input" placeholder="Roma Norte">
                </div>
                <div class="me-field">
                  <label class="me-label">Ciudad</label>
                  <input type="text" name="dir_ciudad" id="editDirCiudad"
                         class="me-input" placeholder="Ciudad de México">
                </div>
                <div class="me-field" style="grid-column:1/-1;">
                  <label class="me-label">Estado</label>
                  <input type="text" name="dir_estado" id="editDirEstado"
                         class="me-input" placeholder="CDMX">
                </div>
              </div>
            </div>
          </div>

          <!-- spacer so last section breathes above sticky footer -->
          <div style="height:8px;"></div>
        </form>
      </div><!-- /me-form-body -->

      <div class="me-form-footer">
        <button type="button" class="me-btn-cancel" onclick="toggleModal('modalEditar', false)">
          Cancelar
        </button>
        <button type="submit" form="formEditar" id="btnGuardarEditar" class="me-btn-save">
          Guardar cambios
        </button>
      </div>
    </div><!-- /meRight -->

  </div><!-- /modalEditarInner -->
</div>

<script src="<?= asset('js/paginator.js') ?>"></script>
<script>
function showToast(mensaje, tipo = 'success') {
    if (typeof mostrarAlerta === 'function') {
        mostrarAlerta(mensaje, tipo);
        return;
    }

    const bg = tipo === 'error' ? '#EF4444' : tipo === 'warning' ? '#F59E0B' : '#0e7c7b';
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;top:1rem;left:50%;transform:translateX(-50%);background:' + bg + ';color:#fff;padding:.85rem 1.5rem;border-radius:.75rem;font-size:.875rem;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.18);z-index:9999;';
    toast.textContent = mensaje;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Toggle modal
function toggleModal(id, show) {
    document.getElementById(id).classList.toggle('hidden', !show);
    if (show && id === 'modalNuevo') {
        // Reset form y campos al abrir
        document.getElementById('formNuevo').reset();
        const btnNuevo = document.getElementById('btnGuardarNuevo');
        if (btnNuevo) {
            btnNuevo.disabled = false;
            btnNuevo.textContent = 'Guardar usuario';
        }
        toggleRepresentanteField();
    }
}

// Mostrar/ocultar campos según el rol — modal NUEVO
function toggleRepresentanteField() {
    _applyRoleVisibility('rolSelect', 'representanteField', 'representanteCodigo', 'rutaField');
}

// Mostrar/ocultar campos según el rol — modal EDITAR
function toggleEditRepresentanteField() {
    _applyRoleVisibility('editRolSelect', 'editRepresentanteField', 'editRepresentanteCodigo', 'editRutaField');
}

function _applyRoleVisibility(selectId, repFieldId, repCodigoId, rutaFieldId) {
    const rolSelect = document.getElementById(selectId);
    const selectedOption = rolSelect.options[rolSelect.selectedIndex];
    const rolCodigo = selectedOption.getAttribute('data-codigo');
    const nivel = parseInt(selectedOption.getAttribute('data-nivel') || '0');
    const representanteField = document.getElementById(repFieldId);
    const rutaField = document.getElementById(rutaFieldId);
    const codigoInput = document.getElementById(repCodigoId);

    if (rolCodigo === 'representante') {
        representanteField.classList.remove('hidden');
        codigoInput.required = true;
    } else {
        representanteField.classList.add('hidden');
        codigoInput.required = false;
    }

    if (nivel >= 3) {
        rutaField.classList.remove('hidden');
    } else {
        rutaField.classList.add('hidden');
    }
}

// Cargar datos y abrir modal de edición
async function editarUsuario(id) {
    try {
        const response = await fetch(window.BASE_PATH + 'api/usuarios.php?action=obtener&id=' + id);
        const result = await response.json();

        if (!result.success) {
            showToast(result.message || 'No se pudo cargar el usuario', 'error');
            return;
        }

        const d = result.data;

        // Campos base
        document.getElementById('editId').value          = d.id;
        document.getElementById('editUsuario').value     = d.usuario      || '';
        document.getElementById('editNombre').value      = d.nombre       || '';
        document.getElementById('editEmail').value       = d.email        || '';
        document.getElementById('editPassword').value    = '';

        // Rol
        const rolSelect = document.getElementById('editRolSelect');
        rolSelect.value = d.rol_id || '';

        // Superior
        const superiorSelect = document.getElementById('editSuperiorId');
        superiorSelect.value = d.superior_id || '';

        // Ruta
        document.getElementById('editRuta').value     = d.ruta      || '';
        document.getElementById('editCelular').value  = d.celular   || '';
        document.getElementById('editDescRuta').value = d.desc_ruta || '';

        // Perfil representante
        document.getElementById('editRepresentanteCodigo').value   = d.representante_codigo          || '';
        document.getElementById('editRepresentanteTelefono').value = d.representante_telefono        || '';
        document.getElementById('editRepresentanteTags').value     = d.representante_tags_permitidos || '';
        document.getElementById('editDirCalle').value    = d.dir_calle    || '';
        document.getElementById('editDirNumero').value   = d.dir_numero   || '';
        document.getElementById('editDirCp').value       = d.dir_cp       || '';
        document.getElementById('editDirColonia').value  = d.dir_colonia  || '';
        document.getElementById('editDirCiudad').value   = d.dir_ciudad   || '';
        document.getElementById('editDirEstado').value   = d.dir_estado   || '';

        // Sidebar de identidad
        document.getElementById('meSidebarName').textContent    = d.nombre  || '—';
        document.getElementById('meSidebarUsuario').textContent = d.usuario || '—';
        document.getElementById('meSidebarEmail').textContent   = d.email   || '—';
        const rolOpt = document.querySelector(`#editRolSelect option[value="${d.rol_id}"]`);
        document.getElementById('meSidebarRole').textContent    = rolOpt ? rolOpt.textContent.trim() : '—';

        // Actualizar visibilidad de secciones
        toggleEditRepresentanteField();

        toggleModal('modalEditar', true);
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

// Guardar edición
async function actualizarUsuario(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarEditar');
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    const formData = new FormData(e.target);
    formData.append('action', 'actualizar');

    try {
        const response = await fetch(window.BASE_PATH + 'api/usuarios.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            showToast('Usuario actualizado exitosamente', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(result.message || 'No se pudo actualizar el usuario', 'error');
            btn.disabled = false;
            btn.textContent = 'Guardar Cambios';
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
        btn.disabled = false;
        btn.textContent = 'Guardar Cambios';
    }
}

// Filtrado
document.getElementById('filtroRol').addEventListener('change', filtrar);
document.getElementById('filtroEstado').addEventListener('change', filtrar);
document.getElementById('buscarUsuario').addEventListener('input', filtrar);

const _usPag = new Paginator({
    rows:   () => document.querySelectorAll('#tablaUsuarios tr'),
    bar:    '#us-pag-bar',
    info:   '#us-pag-info',
    ctrl:   '#us-pag-ctrl',
    sizeEl: '#us-pag-size',
    unit:   'usuario', units: 'usuarios',
});

function filtrar() {
    const rol      = document.getElementById('filtroRol').value;
    const estado   = document.getElementById('filtroEstado').value;
    const busqueda = document.getElementById('buscarUsuario').value.toLowerCase();

    _usPag.filter(fila => {
        if (rol      && fila.dataset.rol    !== rol)      return false;
        if (estado   && fila.dataset.estado !== estado)   return false;
        if (busqueda && !fila.textContent.toLowerCase().includes(busqueda)) return false;
        return true;
    });

    // Filtrar vista jerarquía
    _filtrarJerarquia(rol, estado, busqueda);
}

document.addEventListener('DOMContentLoaded', () =>
    _usPag.apply(Array.from(document.querySelectorAll('#tablaUsuarios tr')))
);

// ── Colapsar / Expandir árbol jerárquico ────────────────────
function toggleColapsar(btn) {
    const uid = btn.dataset.uid;
    const isCollapsed = btn.dataset.collapsed === '1';
    if (isCollapsed) {
        btn.dataset.collapsed = '0';
        btn.textContent = '▼';
        _expandirHijos(uid);
    } else {
        btn.dataset.collapsed = '1';
        btn.textContent = '▶';
        _colapsarHijos(uid);
    }
}
function _colapsarHijos(parentUid) {
    document.querySelectorAll(`#tablaUsuarios tr[data-parent-uid="${parentUid}"]`).forEach(row => {
        row.style.display = 'none';
        _colapsarHijos(row.dataset.uid);
    });
}
function _expandirHijos(parentUid) {
    document.querySelectorAll(`#tablaUsuarios tr[data-parent-uid="${parentUid}"]`).forEach(row => {
        row.style.display = '';
        const childToggle = row.querySelector('.tree-toggle');
        if (!childToggle || childToggle.dataset.collapsed !== '1') {
            _expandirHijos(row.dataset.uid);
        }
    });
}

// Guardar usuario
async function guardarUsuario(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarNuevo');
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    const formData = new FormData(e.target);
    formData.append('action', 'crear');
    
    try {
        const response = await fetch(window.BASE_PATH + 'api/usuarios.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast('Usuario creado exitosamente', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(result.message || 'No se pudo crear el usuario', 'error');
            btn.disabled = false;
            btn.textContent = 'Guardar usuario';
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
        btn.disabled = false;
        btn.textContent = 'Guardar usuario';
    }
}

// Toggle activo
async function toggleActivo(id) {
    if (!confirm('¿Cambiar estado del usuario?')) return;
    
    try {
        const response = await fetch(window.BASE_PATH + 'api/usuarios.php?action=toggle&id=' + id);
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            showToast(result.message, 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

// ── Vista Toggle ─────────────────────────────────────────────
function setVista(v) {
    const isJer = v === 'jerarquia';
    document.getElementById('vistaJerarquia').classList.toggle('hidden', !isJer);
    document.getElementById('vistaLista').classList.toggle('hidden', isJer);
    document.getElementById('controles-jerarquia').classList.toggle('hidden', !isJer);
    document.getElementById('btnVistaJerarquia').classList.toggle('active-tab', isJer);
    document.getElementById('btnVistaLista').classList.toggle('active-tab', !isJer);
    document.getElementById('btnVistaJerarquia').classList.toggle('text-slate-500', !isJer);
    document.getElementById('btnVistaLista').classList.toggle('text-slate-500', isJer);
}

// ── Accordion org ─────────────────────────────────────────────
function orgToggle(bodyId, btn) {
    const body = document.getElementById(bodyId);
    if (!body) return;
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    btn.setAttribute('aria-expanded', String(!isOpen));
}

function orgExpandAll(expand) {
    document.querySelectorAll('.org-body').forEach(b => {
        b.classList.toggle('open', expand);
    });
    document.querySelectorAll('.org-n2-hdr, .org-n3-hdr').forEach(btn => {
        btn.setAttribute('aria-expanded', String(expand));
    });
}

// ── Filtrado jerarquía ────────────────────────────────────────
function _filtrarJerarquia(rol, estado, busqueda) {
    document.querySelectorAll('#orgTree [data-rol]').forEach(el => {
        let ok = true;
        if (rol && el.dataset.rol !== rol) ok = false;
        if (estado && el.dataset.estado !== estado) ok = false;
        if (busqueda && !el.textContent.toLowerCase().includes(busqueda)) ok = false;
        el.style.display = ok ? '' : 'none';
    });
    // Auto-abrir acordeones que contengan resultados
    if (busqueda || rol || estado) {
        document.querySelectorAll('.org-n3-wrap').forEach(n3 => {
            const anyVisible = [...n3.querySelectorAll('.org-rep-card')].some(c => c.style.display !== 'none');
            if (anyVisible) {
                const body = n3.querySelector('.org-body');
                const hdr  = n3.querySelector('.org-n3-hdr');
                if (body) body.classList.add('open');
                if (hdr)  hdr.setAttribute('aria-expanded', 'true');
            }
        });
        document.querySelectorAll('.org-n2-wrap').forEach(n2 => {
            const anyVisible = [...n2.querySelectorAll('.org-n3-wrap, .org-rep-card')]
                .some(c => c.style.display !== 'none');
            if (anyVisible) {
                const body = n2.querySelector('.org-body');
                const hdr  = n2.querySelector('.org-n2-hdr');
                if (body) body.classList.add('open');
                if (hdr)  hdr.setAttribute('aria-expanded', 'true');
            }
        });
    }
}

// ══════════════════════════════════════════════════════════════
// DRAG & DROP — reasignación de representantes
// ══════════════════════════════════════════════════════════════
(function () {
    'use strict';

    let draggedCard   = null;   // elemento .org-rep-card
    let draggedRepId  = null;
    let draggedName   = '';
    let sourceZone    = null;   // .org-body-inner de origen
    let ghost         = null;

    // ── Crear ghost element ──────────────────────────────────
    function createGhost(name) {
        const g = document.createElement('div');
        g.id = 'dnd-ghost';
        g.innerHTML = `<span class="dnd-ghost-icon">👤</span>
            <span>${name}</span>
            <span class="dnd-ghost-sub">arrastrando…</span>`;
        document.body.appendChild(g);
        return g;
    }

    function moveGhost(e) {
        if (!ghost) return;
        ghost.style.left = (e.clientX + 14) + 'px';
        ghost.style.top  = (e.clientY - 20) + 'px';
    }

    function removeGhost() {
        if (ghost) { ghost.remove(); ghost = null; }
    }

    // ── Event delegation en #orgTree ────────────────────────
    const tree = document.getElementById('orgTree');
    if (!tree) return;

    // dragstart: en rep cards
    tree.addEventListener('dragstart', function (e) {
        const card = e.target.closest('.org-rep-card[draggable="true"]');
        if (!card) return;
        draggedCard  = card;
        draggedRepId = card.dataset.repId;
        draggedName  = card.dataset.repNombre || 'Representante';
        sourceZone   = card.closest('.org-body-inner');

        // Ocultar imagen nativa del browser
        const blank = document.createElement('canvas');
        e.dataTransfer.setDragImage(blank, 0, 0);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', draggedRepId);

        // Ghost + animación salida
        ghost = createGhost(draggedName);
        moveGhost(e);
        requestAnimationFrame(() => card.classList.add('dnd-dragging'));
        if (sourceZone) sourceZone.classList.add('dnd-source');

        // Abrir todos los acordeones N3 para que sean visible como targets
        document.querySelectorAll('.org-n3-hdr').forEach(btn => {
            const bodyId = btn.getAttribute('onclick').match(/'(n3-\d+)'/)?.[1];
            if (bodyId) {
                const body = document.getElementById(bodyId);
                if (body && !body.classList.contains('open')) {
                    body.classList.add('open', 'dnd-auto-opened');
                    btn.setAttribute('aria-expanded', 'true');
                }
            }
        });
    });

    // drag: mover ghost
    document.addEventListener('drag', moveGhost);

    // dragover: sobre drop zones
    // Resolver zona de drop: la inner org-drop-zone, o la del N3-wrap si el hover
    // cayó en el header/título del gerente en lugar del área interna.
    function resolveDropZone(target) {
        let zone = target.closest('.org-drop-zone');
        if (!zone) {
            const n3wrap = target.closest('.org-n3-wrap');
            if (n3wrap) zone = n3wrap.querySelector('.org-drop-zone');
        }
        return zone || null;
    }

    tree.addEventListener('dragover', function (e) {
        const zone = resolveDropZone(e.target);
        if (!zone || zone === sourceZone) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (!zone.classList.contains('dnd-over')) {
            clearDndOver();
            zone.classList.add('dnd-over');
            zone.closest('.org-n3-wrap')?.classList.add('dnd-target-active');
        }
    });

    // dragleave
    tree.addEventListener('dragleave', function (e) {
        const zone = resolveDropZone(e.target);
        if (!zone) return;
        // Solo limpiar si realmente salimos del wrap completo (no de un hijo)
        const n3wrap = zone.closest('.org-n3-wrap');
        const container = n3wrap || zone;
        const rel = e.relatedTarget;
        if (!container.contains(rel)) {
            zone.classList.remove('dnd-over');
            n3wrap?.classList.remove('dnd-target-active');
        }
    });

    // drop
    tree.addEventListener('drop', async function (e) {
        e.preventDefault();
        const zone = resolveDropZone(e.target);
        if (!zone || zone === sourceZone || !draggedRepId) return;

        const nuevoGerenteId = zone.dataset.gerenteId;
        if (!nuevoGerenteId) return;

        await reasignarRepresentante(draggedRepId, nuevoGerenteId, draggedName, draggedCard, sourceZone, zone);
    });

    // dragend: limpiar siempre
    document.addEventListener('dragend', function () {
        removeGhost();
        draggedCard?.classList.remove('dnd-dragging');
        if (sourceZone) { sourceZone.classList.remove('dnd-source'); }
        clearDndOver();
        // Cerrar los que se abrieron automáticamente
        document.querySelectorAll('.org-body.dnd-auto-opened').forEach(body => {
            body.classList.remove('open', 'dnd-auto-opened');
            const n3wrap = body.closest('.org-n3-wrap');
            n3wrap?.querySelector('.org-n3-hdr')?.setAttribute('aria-expanded', 'false');
        });
        draggedCard  = null;
        draggedRepId = null;
        sourceZone   = null;
    });

    function clearDndOver() {
        document.querySelectorAll('.dnd-over').forEach(z => z.classList.remove('dnd-over'));
        document.querySelectorAll('.dnd-target-active').forEach(z => z.classList.remove('dnd-target-active'));
    }

    // ── Llamada API ──────────────────────────────────────────
    async function reasignarRepresentante(repId, nuevoGerenteId, nombre, card, fromZone, toZone) {
        // Feedback inmediato: mover la card visualmente
        let fromGrid = fromZone?.querySelector('.org-rep-grid');
        let toGrid   = toZone?.querySelector('.org-rep-grid');

        if (!toGrid) {
            // Drop zone vacía — eliminar "sin representantes" y crear grid
            const empty = toZone?.querySelector('.org-empty-placeholder');
            if (empty) empty.remove();
            toGrid = document.createElement('div');
            toGrid.className = 'org-rep-grid';
            toZone.appendChild(toGrid);
        }

        // Animar card hacia nueva posición
        card.classList.remove('dnd-dragging');
        card.style.transition = 'opacity .25s, transform .25s';
        card.style.opacity = '0';
        card.style.transform = 'scale(.9)';

        await new Promise(r => setTimeout(r, 200));
        toGrid.appendChild(card);
        card.style.opacity = '1';
        card.style.transform = '';
        setTimeout(() => { card.style.transition = ''; }, 300);

        // Si la zona origen quedó vacía
        if (fromGrid && fromGrid.children.length === 0) {
            fromGrid.remove();
            const placeholder = document.createElement('p');
            placeholder.className = 'org-empty org-empty-placeholder';
            placeholder.textContent = 'Sin representantes asignados';
            fromZone?.appendChild(placeholder);
        }

        // Actualizar badges de conteo
        updateN3Badge(fromZone);
        updateN3Badge(toZone);

        // Llamada al servidor
        try {
            const fd = new FormData();
            fd.append('action', 'reasignar_superior');
            fd.append('rep_id', repId);
            fd.append('nuevo_superior_id', nuevoGerenteId);
            const res  = await fetch(window.BASE_PATH + 'api/usuarios.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showToast(`${nombre} reasignado/a a ${data.sup_nombre}`, 'success');
            } else {
                showToast(data.message || 'Error al reasignar', 'error');
                // Revertir: volver a poner la card en fromZone
                if (fromGrid && fromZone) { fromGrid.appendChild(card); updateN3Badge(fromZone); updateN3Badge(toZone); }
            }
        } catch (err) {
            showToast('Error de conexión al reasignar', 'error');
        }
    }

    // Actualiza el badge "N rep." del header N3 (o el contador de huérfanos)
    function updateN3Badge(zone) {
        if (!zone) return;
        // Zona huérfana
        if (zone.id === 'orphan-zone') {
            const count = zone.querySelectorAll('.org-rep-card').length;
            const lbl = document.getElementById('orphan-count');
            if (lbl) lbl.textContent = count + ' usuario(s)';
            const section = document.getElementById('orphan-section');
            if (section) section.style.display = count === 0 ? 'none' : '';
            return;
        }
        const n3wrap = zone.closest('.org-n3-wrap');
        if (!n3wrap) return;
        const count = zone.querySelectorAll('.org-rep-card').length;
        const badge = n3wrap.querySelector('.org-cnt-n4');
        if (badge) badge.innerHTML = `${count} <i>rep.</i>`;
    }
})();
</script>

<?php include '../includes/footer.php'; ?>
