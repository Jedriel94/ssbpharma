<?php

function report_representantes_visibles(PDO $pdo, array $adminActual)
{
    $rolCodigo = $adminActual['rol_codigo'] ?? '';
    if (in_array($rolCodigo, ['admin', 'director_general', 'viewer'], true)) {
        return null;
    }

    $pendientes = [(int)($adminActual['id'] ?? 0)];
    $visitados = [];
    $representantes = [];

    while (!empty($pendientes)) {
        $actualId = array_shift($pendientes);
        if ($actualId <= 0 || isset($visitados[$actualId])) {
            continue;
        }
        $visitados[$actualId] = true;

        $stmt = $pdo->prepare("
            SELECT a.id, r.codigo AS rol_codigo
            FROM administradores a
            INNER JOIN roles r ON r.id = a.rol_id
            WHERE a.activo = 1
              AND (a.id = ? OR a.superior_id = ?)
            ORDER BY a.nombre ASC
        ");
        $stmt->execute([$actualId, $actualId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['id'];
            if (($row['rol_codigo'] ?? '') === 'representante') {
                $representantes[] = $id;
            } elseif (!isset($visitados[$id])) {
                $pendientes[] = $id;
            }
        }
    }

    return array_values(array_unique(array_map('intval', $representantes)));
}

function report_scope_sql($representantesVisibles, $column)
{
    if ($representantesVisibles === null) {
        return ['sql' => '', 'params' => []];
    }

    if (empty($representantesVisibles)) {
        return ['sql' => ' AND 1=0', 'params' => []];
    }

    return [
        'sql' => ' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($representantesVisibles), '?')) . ')',
        'params' => $representantesVisibles,
    ];
}

function report_rep_permitido($representantesVisibles, $representanteId)
{
    return $representanteId <= 0
        || $representantesVisibles === null
        || in_array($representanteId, $representantesVisibles, true);
}
