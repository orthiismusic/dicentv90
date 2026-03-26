<?php
/* ============================================================
   contratos.php  —  Gestión de Contratos
   Diseño unificado con dashboard.php / clientes.php
   ============================================================ */
require_once 'config.php';
verificarSesion();

$mensaje      = '';
$tipo_mensaje = '';

// ─── Procesar acciones POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    try {
        $conn->beginTransaction();

        switch ($action) {

            case 'crear':
                $stmt = $conn->prepare("
                    INSERT INTO contratos
                        (numero_contrato, cliente_id, plan_id, vendedor_id,
                         fecha_inicio, fecha_fin, monto_mensual, monto_total,
                         dia_cobro, estado, notas)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    strtoupper(trim($_POST['numero_contrato'])),
                    intval($_POST['cliente_id']),
                    intval($_POST['plan_id']),
                    intval($_POST['vendedor_id']),
                    $_POST['fecha_inicio'],
                    $_POST['fecha_fin'],
                    floatval($_POST['monto_mensual']),
                    floatval($_POST['monto_total'] ?? 0),
                    intval($_POST['dia_cobro']),
                    'activo',
                    trim($_POST['notas'] ?? ''),
                ]);
                $contrato_id = $conn->lastInsertId();

                if (!empty($_POST['beneficiarios']) && is_array($_POST['beneficiarios'])) {
                    $stmtBen = $conn->prepare("
                        INSERT INTO beneficiarios
                            (contrato_id, nombre, apellidos, parentesco, porcentaje, fecha_nacimiento)
                        VALUES (?,?,?,?,?,?)
                    ");
                    foreach ($_POST['beneficiarios'] as $ben) {
                        if (!empty(trim($ben['nombre'] ?? ''))) {
                            $stmtBen->execute([
                                $contrato_id,
                                trim($ben['nombre']),
                                trim($ben['apellidos'] ?? ''),
                                trim($ben['parentesco'] ?? ''),
                                floatval($ben['porcentaje'] ?? 0),
                                $ben['fecha_nacimiento'] ?? null,
                            ]);
                        }
                    }
                }

                $mensaje      = 'Contrato creado exitosamente.';
                $tipo_mensaje = 'success';
                break;

            case 'editar':
                $id = intval($_POST['id']);
                $conn->prepare("
                    UPDATE contratos SET
                        plan_id       = ?,
                        vendedor_id   = ?,
                        fecha_inicio  = ?,
                        fecha_fin     = ?,
                        monto_mensual = ?,
                        monto_total   = ?,
                        dia_cobro     = ?,
                        estado        = ?,
                        notas         = ?
                    WHERE id = ?
                ")->execute([
                    intval($_POST['plan_id']),
                    intval($_POST['vendedor_id']),
                    $_POST['fecha_inicio'],
                    $_POST['fecha_fin'],
                    floatval($_POST['monto_mensual']),
                    floatval($_POST['monto_total'] ?? 0),
                    intval($_POST['dia_cobro']),
                    trim($_POST['estado']),
                    trim($_POST['notas'] ?? ''),
                    $id,
                ]);

                if (isset($_POST['beneficiarios'])) {
                    $conn->prepare("DELETE FROM beneficiarios WHERE contrato_id = ?")->execute([$id]);
                    if (is_array($_POST['beneficiarios'])) {
                        $stmtBen = $conn->prepare("
                            INSERT INTO beneficiarios
                                (contrato_id, nombre, apellidos, parentesco, porcentaje, fecha_nacimiento)
                            VALUES (?,?,?,?,?,?)
                        ");
                        foreach ($_POST['beneficiarios'] as $ben) {
                            if (!empty(trim($ben['nombre'] ?? ''))) {
                                $stmtBen->execute([
                                    $id,
                                    trim($ben['nombre']),
                                    trim($ben['apellidos'] ?? ''),
                                    trim($ben['parentesco'] ?? ''),
                                    floatval($ben['porcentaje'] ?? 0),
                                    $ben['fecha_nacimiento'] ?? null,
                                ]);
                            }
                        }
                    }
                }

                $mensaje      = 'Contrato actualizado exitosamente.';
                $tipo_mensaje = 'success';
                break;

            case 'eliminar':
                $conn->prepare("UPDATE contratos SET estado='cancelado' WHERE id=?")
                     ->execute([intval($_POST['id'])]);
                $mensaje      = 'Contrato cancelado exitosamente.';
                $tipo_mensaje = 'success';
                break;
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $mensaje      = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ─── Datos para selectores ────────────────────────────────────────────────────
$planes = $conn->query("
    SELECT id, codigo, nombre, precio_base FROM planes WHERE estado='activo' ORDER BY nombre
")->fetchAll();

$vendedores = $conn->query("
    SELECT id, codigo, nombre_completo FROM vendedores WHERE estado='activo' ORDER BY nombre_completo
")->fetchAll();

// ─── Filtros y paginación ─────────────────────────────────────────────────────
$registros_por_pagina = isset($_COOKIE['contratos_por_pagina']) ? (int)$_COOKIE['contratos_por_pagina'] : 15;
$pagina_actual        = max(1, intval($_GET['pagina'] ?? 1));
$filtro_estado        = trim($_GET['estado'] ?? '');
$filtro_vendedor      = trim($_GET['vendedor'] ?? '');
$buscar               = trim($_GET['buscar'] ?? '');
$offset               = ($pagina_actual - 1) * $registros_por_pagina;

$where  = '1=1';
$params = [];

if ($filtro_estado && $filtro_estado !== 'all') {
    $where   .= ' AND c.estado = ?';
    $params[] = $filtro_estado;
}
if ($filtro_vendedor && $filtro_vendedor !== 'all') {
    $where   .= ' AND c.vendedor_id = ?';
    $params[] = intval($filtro_vendedor);
}
if ($buscar !== '') {
    $t        = "%$buscar%";
    $where   .= " AND (c.numero_contrato LIKE ? OR cl.codigo LIKE ?
                    OR cl.nombre LIKE ? OR cl.apellidos LIKE ?
                    OR cl.cedula LIKE ? OR p.nombre LIKE ?)";
    $params   = array_merge($params, [$t, $t, $t, $t, $t, $t]);
}

// ─── Estadísticas globales ────────────────────────────────────────────────────
$stats = $conn->query("
    SELECT
        COUNT(*)                                            AS total,
        SUM(estado = 'activo')                              AS activos,
        SUM(estado = 'suspendido')                          AS suspendidos,
        SUM(estado = 'cancelado')                           AS cancelados,
        COALESCE(SUM(CASE WHEN estado='activo' THEN monto_mensual END),0) AS monto_activo
    FROM contratos
")->fetch();

// ─── Total filtrado ───────────────────────────────────────────────────────────
$stmtCount = $conn->prepare("
    SELECT COUNT(*)
    FROM contratos c
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN planes   p  ON c.plan_id    = p.id
    WHERE $where
");
$stmtCount->execute($params);
$total_registros = $stmtCount->fetchColumn();
$total_paginas   = max(1, ceil($total_registros / $registros_por_pagina));
$pagina_actual   = min($pagina_actual, $total_paginas);
$offset          = ($pagina_actual - 1) * $registros_por_pagina;

// ─── Query principal ──────────────────────────────────────────────────────────
$sql = "
    SELECT c.*,
           cl.codigo          AS cliente_codigo,
           cl.nombre          AS cliente_nombre,
           cl.apellidos       AS cliente_apellidos,
           cl.cedula          AS cliente_cedula,
           p.nombre           AS plan_nombre,
           v.nombre_completo  AS vendedor_nombre,
           (SELECT COUNT(*) FROM dependientes d
            WHERE d.contrato_id = c.id AND d.estado = 'activo') AS total_dependientes
    FROM contratos c
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN planes   p  ON c.plan_id    = p.id
    LEFT JOIN vendedores v ON c.vendedor_id = v.id
    WHERE $where
    ORDER BY c.id DESC
    LIMIT ? OFFSET ?
";
$paramsAll = array_merge($params, [$registros_por_pagina, $offset]);
$stmt = $conn->prepare($sql);
foreach ($paramsAll as $k => $val) {
    $stmt->bindValue($k + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$contratos = $stmt->fetchAll();

// ─── Función URL paginador ────────────────────────────────────────────────────
function buildContratoUrl(int $pag, string $buscar, string $estado, string $vendedor): string {
    $p = ['pagina' => $pag];
    if ($buscar  !== '')                       $p['buscar']   = $buscar;
    if ($estado  !== '' && $estado  !== 'all') $p['estado']   = $estado;
    if ($vendedor !== '' && $vendedor !== 'all') $p['vendedor'] = $vendedor;
    return 'contratos.php?' . http_build_query($p);
}

require_once 'header.php';
?>

<!-- ============================================================
     ESTILOS ESPECÍFICOS DE CONTRATOS
     ============================================================ -->
<style>
/* ── KPI CARDS CON COLOR (estilo dashboard) ── */
.kpi-contratos {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 24px;
}

.kpi-contratos .kpi-card {
    border-radius: var(--radius);
    padding: 22px 22px 18px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: var(--transition);
    text-decoration: none;
    color: white;
}
.kpi-contratos .kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}
.kpi-contratos .kpi-card::before {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 80px; height: 80px;
    border-radius: 0 var(--radius) 0 100%;
    opacity: 0.15;
    background: white;
}

.kpi-contratos .kpi-card.blue  { background: linear-gradient(135deg, #1565C0, #1976D2); }
.kpi-contratos .kpi-card.green { background: linear-gradient(135deg, #1B5E20, #2E7D32); }
.kpi-contratos .kpi-card.amber { background: linear-gradient(135deg, #E65100, #F57F17); }
.kpi-contratos .kpi-card.red   { background: linear-gradient(135deg, #B71C1C, #C62828); }

.kpi-contratos .kpi-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 6px;
}
.kpi-contratos .kpi-icon {
    width: 48px; height: 48px;
    background: rgba(255,255,255,0.18);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: white;
    flex-shrink: 0;
}
.kpi-contratos .kpi-label {
    font-size: 11px; font-weight: 600;
    color: rgba(255,255,255,0.8);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 10px;
}
.kpi-contratos .kpi-value {
    font-size: 30px; font-weight: 800;
    color: white; line-height: 1;
    margin-bottom: 4px;
}
.kpi-contratos .kpi-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.70);
    font-weight: 500;
}
.kpi-contratos .kpi-footer {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.kpi-contratos .kpi-change {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700;
    padding: 3px 8px; border-radius: 20px;
    background: rgba(255,255,255,0.18);
    color: rgba(255,255,255,0.95);
}

@media (max-width: 900px)  { .kpi-contratos { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px)  { .kpi-contratos { grid-template-columns: 1fr; } }

/* ── FILTROS BAR ── */
.filtros-contratos {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: nowrap;
}
@media (max-width: 768px) {
    .filtros-contratos {
        flex-wrap: wrap;
    }
    .filtros-contratos .search-wrap {
        width: 100%;
        max-width: 100%;
    }
    .filtros-contratos .filter-select {
        flex: 1;
        min-width: 0;
    }
}

.search-wrap {
    position: relative;
    flex: 1;
    min-width: 220px;
    max-width: 380px;
}
.search-wrap i {
    position: absolute;
    left: 12px; top: 50%;
    transform: translateY(-50%);
    color: var(--gray-400);
    font-size: 13px;
    pointer-events: none;
}
.search-wrap input {
    width: 100%;
    padding: 9px 12px 9px 34px;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    font-size: 13px;
    color: var(--gray-700);
    background: var(--white);
    outline: none;
    font-family: var(--font);
    transition: var(--transition);
}
.search-wrap input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(33,150,243,.10);
}

.filter-select {
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 9px 12px;
    font-size: 13px;
    color: var(--gray-700);
    background: var(--white);
    outline: none;
    font-family: var(--font);
    cursor: pointer;
    transition: var(--transition);
    height: 38px;
}
.filter-select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(33,150,243,.10); }

/* ── Tabla ── */
.contrato-cell   { display: flex; align-items: center; gap: 10px; }
.contrato-avatar {
    width: 34px; height: 34px; border-radius: 10px;
    background: linear-gradient(135deg, var(--accent), #0D47A1);
    color: white; font-size: 11px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.contrato-num  { font-family: monospace; font-size: 12.5px; font-weight: 700; color: var(--accent); }
.client-name   { font-weight: 600; color: var(--gray-800); font-size: 13px; line-height: 1.3; }
.client-code   { font-size: 11px; color: var(--gray-400); font-family: monospace; }
.plan-name     { font-weight: 600; color: var(--gray-700); font-size: 13px; }
.td-muted      { color: var(--gray-400); font-size: 12px; }
.td-amount     { font-weight: 700; color: var(--gray-800); font-size: 13px; }

.dep-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1.5px solid var(--gray-200);
    background: var(--white);
    color: var(--gray-600);
    font-size: 11px; font-weight: 700;
    white-space: nowrap;
}
.dep-badge .dep-num {
    background: var(--accent); color: white;
    font-size: 10px; font-weight: 800;
    min-width: 17px; height: 17px;
    border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    padding: 0 3px;
}
.dep-badge .dep-num.zero { background: var(--gray-300); }

/* ── Badges de estado ── */
.badge {
    display: inline-flex; align-items: center;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px; font-weight: 700;
    white-space: nowrap;
}
.badge-activo     { background: #DCFCE7; color: #15803D; }
.badge-suspendido { background: #FEF3C7; color: #B45309; }
.badge-cancelado  { background: #FEE2E2; color: #DC2626; }
.badge-secondary  { background: var(--gray-100); color: var(--gray-500); }

/* ── Paginador ── */
.paginador-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-top: 1px solid var(--gray-100);
    background: var(--gray-50);
    border-radius: 0 0 var(--radius) var(--radius);
    flex-wrap: wrap;
    gap: 10px;
}
.paginador-info { font-size: 12.5px; color: var(--gray-500); }
.paginador-info strong { color: var(--gray-700); }

.paginador-pages { display: flex; align-items: center; gap: 4px; }
.pag-btn {
    width: 34px; height: 34px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--radius-sm);
    border: 1px solid var(--gray-200);
    background: var(--white);
    color: var(--gray-600);
    font-size: 13px; font-weight: 600;
    text-decoration: none;
    transition: var(--transition);
    cursor: pointer;
    font-family: var(--font);
}
.pag-btn:hover { border-color: var(--accent); color: var(--accent); background: #EFF6FF; text-decoration: none; }
.pag-btn.active { background: var(--accent); border-color: var(--accent); color: white; }
.pag-btn.disabled { opacity: .4; pointer-events: none; cursor: default; }
.pag-ellipsis { color: var(--gray-400); font-size: 13px; padding: 0 4px; }

.paginador-rpp {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    color: var(--gray-500);
}
.paginador-rpp select {
    padding: 5px 8px;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-family: var(--font);
    outline: none;
    cursor: pointer;
}

/* ── Modales ── */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.55);
    display: flex; align-items: center; justify-content: center;
    z-index: 1050;
    opacity: 0; visibility: hidden;
    transition: opacity .25s, visibility .25s;
    padding: 16px;
}
.modal-overlay.open { opacity: 1; visibility: visible; }

.modal-box {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
    display: flex; flex-direction: column;
    max-height: 90vh;
    width: 100%;
    animation: modalIn .25s ease;
}
.modal-box.sm  { max-width: 480px; }
.modal-box.md  { max-width: 640px; }
.modal-box.lg  { max-width: 820px; }
.modal-box.xl  { max-width: 1020px; }

@keyframes modalIn {
    from { transform: translateY(-16px) scale(.98); opacity:0; }
    to   { transform: translateY(0) scale(1); opacity:1; }
}

.mhdr {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px;
    border-bottom: 1px solid var(--gray-100);
    flex-shrink: 0;
}
.mhdr-title {
    font-size: 15px; font-weight: 700; color: var(--gray-800);
    display: flex; align-items: center; gap: 8px;
}
.mhdr-sub { font-size: 12px; color: var(--gray-400); margin-top: 2px; }
.modal-close-btn {
    width: 32px; height: 32px;
    border: none; background: var(--gray-100);
    border-radius: 8px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    color: var(--gray-500); transition: var(--transition);
    flex-shrink: 0;
}
.modal-close-btn:hover { background: var(--gray-200); color: var(--gray-700); }

.mbody {
    padding: 22px;
    overflow-y: auto;
    flex: 1;
}

.mfooter {
    padding: 14px 22px;
    border-top: 1px solid var(--gray-100);
    display: flex; justify-content: flex-end; gap: 10px;
    flex-shrink: 0;
    background: var(--gray-50);
    border-radius: 0 0 var(--radius) var(--radius);
}

/* ══════════════════════════════════════════════════════
   MODAL VER — DISEÑO POR BLOQUES MEJORADO
   ══════════════════════════════════════════════════════ */
.modal-tabs {
    display: flex; gap: 0;
    border-bottom: 2px solid var(--gray-100);
    margin: 0 -22px 22px;
    padding: 0 22px;
    flex-wrap: wrap;
    background: var(--gray-50);
}
.modal-tab {
    padding: 12px 18px;
    font-size: 13px; font-weight: 600;
    color: var(--gray-500);
    cursor: pointer;
    border: none; background: none;
    border-bottom: 2.5px solid transparent;
    margin-bottom: -2px;
    transition: var(--transition);
    font-family: var(--font);
    display: flex; align-items: center; gap: 6px;
}
.modal-tab:hover { color: var(--accent); background: rgba(25,118,210,0.04); }
.modal-tab.active { color: var(--accent); border-bottom-color: var(--accent); background: white; }
.modal-tab .tab-badge {
    font-size: 10px; font-weight: 800;
    padding: 1px 7px; border-radius: 10px;
    color: white;
}
.modal-tab .tab-badge.blue { background: var(--accent); }
.modal-tab .tab-badge.red  { background: #E53E3E; }
.modal-tab .tab-badge.green{ background: #16A34A; }

.tab-pane { display: none; }
.tab-pane.active { display: block; }

/* Bloque de sección dentro del modal ver */
.view-block {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    margin-bottom: 18px;
    overflow: hidden;
}
.view-block:last-child { margin-bottom: 0; }

.view-block-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-100);
}
.view-block-icon {
    width: 34px; height: 34px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}
.view-block-icon.blue  { background: #EFF6FF; color: var(--accent); }
.view-block-icon.green { background: #F0FDF4; color: #16A34A; }
.view-block-icon.amber { background: #FFFBEB; color: #D97706; }
.view-block-icon.red   { background: #FEF2F2; color: #DC2626; }
.view-block-icon.purple{ background: #F5F3FF; color: #7C3AED; }

.view-block-title {
    font-size: 13px; font-weight: 700; color: var(--gray-800);
}
.view-block-sub {
    font-size: 11px; color: var(--gray-400);
}

.view-block-body {
    padding: 16px 18px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px 24px;
}
@media (max-width: 560px) { .info-grid { grid-template-columns: 1fr; } }
.info-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
@media (max-width: 700px) { .info-grid.cols-3 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px) { .info-grid.cols-3 { grid-template-columns: 1fr; } }

.info-item { display: flex; flex-direction: column; gap: 3px; }
.info-label {
    font-size: 10.5px; color: var(--gray-400); font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
}
.info-value { font-size: 13.5px; color: var(--gray-800); font-weight: 500; }
.info-value.mono { font-family: monospace; font-size: 13px; color: var(--accent); font-weight: 700; }
.info-value.big  { font-size: 18px; font-weight: 800; }

/* Stats mini dentro del modal */
.view-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
@media (max-width: 600px) { .view-stats-row { grid-template-columns: repeat(2,1fr); } }

.view-stat-card {
    text-align: center;
    padding: 14px 10px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--gray-100);
    background: var(--gray-50);
}
.view-stat-card .stat-num {
    font-size: 22px; font-weight: 800; color: var(--gray-800);
    line-height: 1;
}
.view-stat-card .stat-lbl {
    font-size: 10.5px; font-weight: 600; color: var(--gray-400);
    text-transform: uppercase; letter-spacing: .4px;
    margin-top: 4px;
}
.view-stat-card.accent .stat-num { color: var(--accent); }
.view-stat-card.green  .stat-num { color: #16A34A; }
.view-stat-card.amber  .stat-num { color: #D97706; }
.view-stat-card.red    .stat-num { color: #DC2626; }

/* Mini tabla dentro del modal */
.mini-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mini-table th {
    padding: 10px 14px;
    background: var(--gray-50);
    font-size: 10.5px; font-weight: 700;
    color: var(--gray-400); text-transform: uppercase; letter-spacing: .5px;
    border-bottom: 1px solid var(--gray-200);
    text-align: left;
}
.mini-table td {
    padding: 10px 14px;
    color: var(--gray-700);
    border-bottom: 1px solid var(--gray-100);
    vertical-align: middle;
}
.mini-table tbody tr:last-child td { border-bottom: none; }
.mini-table tbody tr:hover { background: var(--gray-50); }

.empty-state-sm {
    text-align: center; padding: 32px 20px;
    color: var(--gray-400);
}
.empty-state-sm i { font-size: 32px; display: block; margin-bottom: 10px; opacity: .4; }
.empty-state-sm p { font-size: 13px; margin: 0; }

/* ── Formulario ── */
.fsec-title {
    font-size: 11px; font-weight: 700;
    color: var(--gray-400); text-transform: uppercase; letter-spacing: .8px;
    margin: 18px 0 10px;
    display: flex; align-items: center; gap: 6px;
}
.fsec-title::after { content: ''; flex:1; height:1px; background: var(--gray-100); }
.fsec-title:first-child { margin-top: 0; }

.form-grid { display: grid; gap: 14px; }
.form-grid.cols-2 { grid-template-columns: repeat(2,1fr); }
.form-grid.cols-3 { grid-template-columns: repeat(3,1fr); }
.form-grid.cols-4 { grid-template-columns: repeat(4,1fr); }
@media (max-width: 600px) {
    .form-grid.cols-2,
    .form-grid.cols-3,
    .form-grid.cols-4 { grid-template-columns: 1fr; }
}

.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-label { font-size: 12px; font-weight: 600; color: var(--gray-700); }
.form-label.required::after { content: ' *'; color: var(--red-light); }

.form-control {
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 9px 12px;
    font-size: 13px; color: var(--gray-700);
    background: var(--white);
    outline: none;
    font-family: var(--font);
    transition: var(--transition);
    width: 100%;
}
.form-control:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(33,150,243,.10);
}

/* Buscador de cliente */
.client-search-wrap { position: relative; }
.client-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--white); border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow);
    z-index: 100; max-height: 200px; overflow-y: auto;
    display: none;
}
.client-result-item {
    padding: 9px 12px;
    cursor: pointer;
    font-size: 13px; color: var(--gray-700);
    border-bottom: 1px solid var(--gray-100);
    transition: var(--transition);
}
.client-result-item:last-child { border-bottom: none; }
.client-result-item:hover { background: #EFF6FF; color: var(--accent); }
.client-selected-box {
    border: 1px solid #BBF7D0;
    background: #F0FDF4;
    border-radius: var(--radius-sm);
    padding: 10px 12px;
    font-size: 13px; color: #15803D;
    display: flex; align-items: center; justify-content: space-between;
    display: none;
}

/* Beneficiarios dinámicos */
.ben-item {
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 14px;
    margin-bottom: 10px;
    position: relative;
    background: var(--gray-50);
}
.btn-remove-ben {
    position: absolute; top: 8px; right: 8px;
    background: none; border: none;
    color: var(--gray-400); cursor: pointer;
    padding: 4px; font-size: 13px;
    transition: var(--transition);
}
.btn-remove-ben:hover { color: var(--red-light); }

/* Alert global */
.alert-global {
    display: flex; align-items: center; gap: 10px;
    padding: 13px 18px;
    border-radius: var(--radius-sm);
    font-size: 13.5px; font-weight: 500;
    margin-bottom: 20px;
    animation: fadeIn .3s ease;
}
.alert-global.success { background: #F0FDF4; color: #15803D; border: 1px solid #BBF7D0; }
.alert-global.danger  { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }

/* Spinner */
.spinner {
    width: 28px; height: 28px;
    border: 3px solid var(--gray-200);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin .7s linear infinite;
    margin: 30px auto;
}
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
</style>

<!-- ============================================================
     PAGE HEADER
     ============================================================ -->
<div class="page-header fade-in">
    <div>
        <div class="page-title">
            <i class="fas fa-file-contract" style="color:var(--accent);margin-right:8px;font-size:20px;"></i>
            Contratos
        </div>
        <div class="page-subtitle">
            <?php echo number_format($total_registros); ?> contrato<?php echo $total_registros !== 1 ? 's' : ''; ?>
            <?php echo ($filtro_estado || $filtro_vendedor || $buscar) ? 'filtrados' : 'registrados en el sistema'; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="abrirModalNuevo()">
            <i class="fas fa-plus"></i> Nuevo Contrato
        </button>
    </div>
</div>

<?php if ($mensaje): ?>
<div class="alert-global <?php echo $tipo_mensaje; ?>" id="alertaGlobal">
    <i class="fas fa-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($mensaje); ?>
</div>
<?php endif; ?>

<!-- ============================================================
     KPI CARDS CON COLOR
     ============================================================ -->
<div class="kpi-contratos fade-in delay-1">

    <div class="kpi-card blue">
        <div class="kpi-label">Total Contratos</div>
        <div class="kpi-top">
            <div>
                <div class="kpi-value"><?php echo number_format($stats['total']); ?></div>
                <div class="kpi-sub">Contratos registrados</div>
            </div>
            <div class="kpi-icon"><i class="fas fa-file-contract"></i></div>
        </div>
        <div class="kpi-footer">
            <span class="kpi-change"><i class="fas fa-database"></i> Todos</span>
        </div>
    </div>

    <div class="kpi-card green">
        <div class="kpi-label">Contratos Activos</div>
        <div class="kpi-top">
            <div>
                <div class="kpi-value"><?php echo number_format($stats['activos']); ?></div>
                <div class="kpi-sub">RD$<?php echo number_format($stats['monto_activo'], 0); ?>/mes</div>
            </div>
            <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="kpi-footer">
            <span class="kpi-change"><i class="fas fa-arrow-trend-up"></i> Vigentes</span>
        </div>
    </div>

    <div class="kpi-card amber">
        <div class="kpi-label">Suspendidos</div>
        <div class="kpi-top">
            <div>
                <div class="kpi-value"><?php echo number_format($stats['suspendidos']); ?></div>
                <div class="kpi-sub">Contratos en pausa</div>
            </div>
            <div class="kpi-icon"><i class="fas fa-pause-circle"></i></div>
        </div>
        <div class="kpi-footer">
            <span class="kpi-change"><i class="fas fa-clock"></i> En espera</span>
        </div>
    </div>

    <div class="kpi-card red">
        <div class="kpi-label">Cancelados</div>
        <div class="kpi-top">
            <div>
                <div class="kpi-value"><?php echo number_format($stats['cancelados']); ?></div>
                <div class="kpi-sub">Contratos cancelados</div>
            </div>
            <div class="kpi-icon"><i class="fas fa-times-circle"></i></div>
        </div>
        <div class="kpi-footer">
            <span class="kpi-change"><i class="fas fa-ban"></i> Inactivos</span>
        </div>
    </div>

</div>

<!-- ============================================================
     FILTROS + BUSCADOR
     ============================================================ -->
<div class="card fade-in" style="margin-bottom:20px;">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" id="formFiltros" class="filtros-contratos">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="buscar" id="inputBuscar"
                       placeholder="Buscar por número, cliente, cédula, plan…"
                       value="<?php echo htmlspecialchars($buscar); ?>"
                       autocomplete="off">
            </div>

            <select name="estado" class="filter-select" style="min-width:160px;"
                    onchange="this.form.submit()">
                <option value="all"        <?php echo ($filtro_estado === '' || $filtro_estado === 'all') ? 'selected' : ''; ?>>Todos los estados</option>
                <option value="activo"     <?php echo $filtro_estado === 'activo'     ? 'selected' : ''; ?>>Activos</option>
                <option value="suspendido" <?php echo $filtro_estado === 'suspendido' ? 'selected' : ''; ?>>Suspendidos</option>
                <option value="cancelado"  <?php echo $filtro_estado === 'cancelado'  ? 'selected' : ''; ?>>Cancelados</option>
            </select>

            <select name="vendedor" class="filter-select" style="min-width:180px;"
                    onchange="this.form.submit()">
                <option value="all">Todos los vendedores</option>
                <?php foreach ($vendedores as $v): ?>
                    <option value="<?php echo $v['id']; ?>"
                            <?php echo $filtro_vendedor == $v['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($v['nombre_completo']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-search"></i> Buscar
            </button>
            <?php if ($buscar || ($filtro_estado && $filtro_estado !== 'all') || ($filtro_vendedor && $filtro_vendedor !== 'all')): ?>
                <a href="contratos.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times"></i> Limpiar
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ============================================================
     TABLA DE CONTRATOS
     ============================================================ -->
<div class="card fade-in">
    <div class="card-header">
        <div>
            <div class="card-title">Lista de Contratos</div>
            <div class="card-subtitle">
                Mostrando
                <?php echo min($offset + 1, $total_registros); ?>–<?php echo min($offset + $registros_por_pagina, $total_registros); ?>
                de <?php echo number_format($total_registros); ?> registros
                <?php if (!empty($buscar)): ?>
                    &nbsp;·&nbsp; Filtrado por: "<strong><?php echo htmlspecialchars($buscar); ?></strong>"
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>No. Contrato</th>
                    <th>Cliente</th>
                    <th>Plan</th>
                    <th>Monto Mensual</th>
                    <th>Vigencia</th>
                    <th>Vendedor</th>
                    <th style="text-align:center;">Dep.</th>
                    <th>Estado</th>
                    <th style="text-align:center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($contratos)): ?>
                <?php foreach ($contratos as $ct):
                    $initials = strtoupper(substr($ct['cliente_nombre'], 0, 1) . substr($ct['cliente_apellidos'], 0, 1));
                    $jsonData = htmlspecialchars(json_encode([
                        'id'              => $ct['id'],
                        'numero_contrato' => $ct['numero_contrato'],
                        'cliente_id'      => $ct['cliente_id'],
                        'cliente_nombre'  => $ct['cliente_nombre'] . ' ' . $ct['cliente_apellidos'],
                        'cliente_codigo'  => $ct['cliente_codigo'],
                        'plan_id'         => $ct['plan_id'],
                        'plan_nombre'     => $ct['plan_nombre'],
                        'fecha_inicio'    => $ct['fecha_inicio'],
                        'fecha_fin'       => $ct['fecha_fin'],
                        'monto_mensual'   => $ct['monto_mensual'],
                        'monto_total'     => $ct['monto_total'],
                        'dia_cobro'       => $ct['dia_cobro'],
                        'vendedor_id'     => $ct['vendedor_id'],
                        'estado'          => $ct['estado'],
                        'notas'           => $ct['notas'] ?? '',
                    ]), ENT_QUOTES);
                    $badgeClass = match($ct['estado']) {
                        'activo'     => 'badge-activo',
                        'suspendido' => 'badge-suspendido',
                        'cancelado'  => 'badge-cancelado',
                        default      => 'badge-secondary'
                    };
                ?>
                <tr>
                    <td>
                        <div class="contrato-cell">
                            <div class="contrato-avatar"><?php echo $initials; ?></div>
                            <span class="contrato-num"><?php echo htmlspecialchars($ct['numero_contrato']); ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="client-name"><?php echo htmlspecialchars($ct['cliente_nombre'] . ' ' . $ct['cliente_apellidos']); ?></div>
                        <div class="client-code"><?php echo htmlspecialchars($ct['cliente_codigo']); ?></div>
                    </td>
                    <td class="plan-name"><?php echo htmlspecialchars($ct['plan_nombre']); ?></td>
                    <td class="td-amount">RD$<?php echo number_format($ct['monto_mensual'], 2); ?></td>
                    <td>
                        <div class="td-muted"><?php echo date('d/m/Y', strtotime($ct['fecha_inicio'])); ?></div>
                        <div class="td-muted">→ <?php echo date('d/m/Y', strtotime($ct['fecha_fin'])); ?></div>
                    </td>
                    <td class="td-muted"><?php echo htmlspecialchars($ct['vendedor_nombre'] ?? '—'); ?></td>
                    <td style="text-align:center;">
                        <span class="dep-badge">
                            <i class="fas fa-users" style="font-size:10px;"></i>
                            <span class="dep-num <?php echo $ct['total_dependientes'] == 0 ? 'zero' : ''; ?>">
                                <?php echo $ct['total_dependientes']; ?>
                            </span>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $badgeClass; ?>">
                            <?php echo ucfirst($ct['estado']); ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <div style="display:flex;align-items:center;justify-content:center;gap:5px;">
                            <button class="btn-action view" title="Ver detalles"
                                    onclick="verContrato(<?php echo $ct['id']; ?>)">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-action edit" title="Editar contrato"
                                    onclick="editarContrato(<?php echo $jsonData; ?>)">
                                <i class="fas fa-pen"></i>
                            </button>
                            <?php if ($ct['estado'] !== 'cancelado'): ?>
                            <button class="btn-action delete" title="Cancelar contrato"
                                    onclick="confirmarCancelar(<?php echo $jsonData; ?>)">
                                <i class="fas fa-ban"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:48px 20px;">
                        <i class="fas fa-file-contract" style="font-size:40px;color:var(--gray-300);display:block;margin-bottom:12px;"></i>
                        <div style="color:var(--gray-500);font-size:14px;font-weight:600;">Sin contratos</div>
                        <div style="color:var(--gray-400);font-size:13px;margin-top:4px;">
                            <?php echo ($buscar || $filtro_estado || $filtro_vendedor) ? 'No hay resultados con los filtros aplicados.' : 'Aún no hay contratos registrados.'; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── PAGINADOR ── -->
    <?php if ($total_registros > 0): ?>
    <div class="paginador-wrap">
        <div class="paginador-info">
            Mostrando
            <strong><?php echo min($offset + 1, $total_registros); ?></strong>–<strong><?php echo min($offset + $registros_por_pagina, $total_registros); ?></strong>
            de <strong><?php echo number_format($total_registros); ?></strong> contratos
        </div>
        <div class="paginador-pages">
            <a class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>"
               href="<?php echo buildContratoUrl(1, $buscar, $filtro_estado, $filtro_vendedor); ?>" title="Primera">
                <i class="fas fa-angles-left" style="font-size:10px;"></i>
            </a>
            <a class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>"
               href="<?php echo $pagina_actual > 1 ? buildContratoUrl($pagina_actual - 1, $buscar, $filtro_estado, $filtro_vendedor) : '#'; ?>" title="Anterior">
                <i class="fas fa-angle-left" style="font-size:11px;"></i>
            </a>

            <?php
            $rango = 2;
            $ri = max(1, $pagina_actual - $rango);
            $rf = min($total_paginas, $pagina_actual + $rango);

            if ($ri > 1): ?>
                <a class="pag-btn" href="<?php echo buildContratoUrl(1, $buscar, $filtro_estado, $filtro_vendedor); ?>">1</a>
                <?php if ($ri > 2): ?><span class="pag-ellipsis">…</span><?php endif; ?>
            <?php endif;

            for ($p = $ri; $p <= $rf; $p++): ?>
                <a class="pag-btn <?php echo $p === $pagina_actual ? 'active' : ''; ?>"
                   href="<?php echo buildContratoUrl($p, $buscar, $filtro_estado, $filtro_vendedor); ?>">
                    <?php echo $p; ?>
                </a>
            <?php endfor;

            if ($rf < $total_paginas): ?>
                <?php if ($rf < $total_paginas - 1): ?><span class="pag-ellipsis">…</span><?php endif; ?>
                <a class="pag-btn" href="<?php echo buildContratoUrl($total_paginas, $buscar, $filtro_estado, $filtro_vendedor); ?>">
                    <?php echo $total_paginas; ?>
                </a>
            <?php endif; ?>

            <a class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>"
               href="<?php echo $pagina_actual < $total_paginas ? buildContratoUrl($pagina_actual + 1, $buscar, $filtro_estado, $filtro_vendedor) : '#'; ?>" title="Siguiente">
                <i class="fas fa-angle-right" style="font-size:11px;"></i>
            </a>
            <a class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>"
               href="<?php echo buildContratoUrl($total_paginas, $buscar, $filtro_estado, $filtro_vendedor); ?>" title="Última">
                <i class="fas fa-angles-right" style="font-size:10px;"></i>
            </a>
        </div>

        <div class="paginador-rpp">
            <span>Mostrar:</span>
            <select onchange="cambiarRPP(this.value)">
                <?php foreach ([10, 15, 25, 50, 100] as $rpp): ?>
                    <option value="<?php echo $rpp; ?>" <?php echo $registros_por_pagina === $rpp ? 'selected' : ''; ?>>
                        <?php echo $rpp; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span>por página</span>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- ============================================================
     MODAL: VER CONTRATO (carga por AJAX)
     ============================================================ -->
<div class="modal-overlay" id="overlayVer">
    <div class="modal-box xl">
        <div class="mhdr">
            <div>
                <div class="mhdr-title">
                    <i class="fas fa-file-contract" style="color:var(--accent);"></i>
                    <span id="verTitulo">Detalles del Contrato</span>
                </div>
                <div class="mhdr-sub" id="verSubtitulo"></div>
            </div>
            <button class="modal-close-btn" onclick="cerrarOverlay('overlayVer')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mbody" id="verBody">
            <div class="spinner"></div>
        </div>
        <div class="mfooter">
            <a id="btnVerCompleto" href="#" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fas fa-external-link-alt"></i> Ver página completa
            </a>
            <button class="btn btn-primary btn-sm" onclick="cerrarOverlay('overlayVer')">
                <i class="fas fa-check"></i> Cerrar
            </button>
        </div>
    </div>
</div>


<!-- ============================================================
     MODAL: CREAR / EDITAR CONTRATO
     ============================================================ -->
<div class="modal-overlay" id="overlayContrato">
    <div class="modal-box xl">
        <div class="mhdr">
            <div class="mhdr-title" id="tituloModal">
                <i class="fas fa-file-contract" style="color:var(--accent);"></i>
                <span id="textoTitulo">Nuevo Contrato</span>
            </div>
            <button class="modal-close-btn" onclick="cerrarOverlay('overlayContrato')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="mbody">
            <form id="formContrato" method="POST">
                <input type="hidden" name="action" id="accionContrato" value="crear">
                <input type="hidden" name="id"     id="contratoId"     value="">
                <input type="hidden" name="cliente_id" id="clienteIdHidden" value="">

                <div class="fsec-title"><i class="fas fa-info-circle"></i> Datos del Contrato</div>

                <div class="form-grid cols-3">
                    <div class="form-group" id="grupoNumeroContrato">
                        <label class="form-label required" for="numero_contrato">No. Contrato</label>
                        <input type="text" id="numero_contrato" name="numero_contrato"
                               class="form-control" placeholder="00001">
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="plan_id">Plan</label>
                        <select id="plan_id" name="plan_id" class="form-control" required
                                onchange="actualizarMontoPlan()">
                            <option value="">— Seleccione un plan —</option>
                            <?php foreach ($planes as $p): ?>
                                <option value="<?php echo $p['id']; ?>"
                                        data-precio="<?php echo $p['precio_base']; ?>">
                                    <?php echo htmlspecialchars($p['codigo'] . ' — ' . $p['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="vendedor_id">Vendedor</label>
                        <select id="vendedor_id" name="vendedor_id" class="form-control" required>
                            <option value="">— Seleccione un vendedor —</option>
                            <?php foreach ($vendedores as $v): ?>
                                <option value="<?php echo $v['id']; ?>">
                                    <?php echo htmlspecialchars($v['codigo'] . ' — ' . $v['nombre_completo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid cols-4" style="margin-top:14px;">
                    <div class="form-group">
                        <label class="form-label required">Fecha Inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio"
                               class="form-control" required value="<?php echo date('Y-m-d'); ?>"
                               onchange="calcularFechaFin()">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Fecha Fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Día de Cobro</label>
                        <input type="number" id="dia_cobro" name="dia_cobro"
                               class="form-control" required min="1" max="31" placeholder="1–31">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Monto Mensual</label>
                        <input type="number" id="monto_mensual" name="monto_mensual"
                               class="form-control" required min="0" step="0.01" placeholder="0.00">
                    </div>
                </div>

                <div class="form-grid cols-2" style="margin-top:14px;">
                    <div class="form-group">
                        <label class="form-label">Monto Total</label>
                        <input type="number" id="monto_total" name="monto_total"
                               class="form-control" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="form-group" id="grupoEstado" style="display:none;">
                        <label class="form-label required">Estado</label>
                        <select id="estadoContrato" name="estado" class="form-control">
                            <option value="activo">Activo</option>
                            <option value="suspendido">Suspendido</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top:14px;">
                    <label class="form-label">Notas</label>
                    <textarea id="notas" name="notas" class="form-control" rows="2"
                              placeholder="Observaciones opcionales…"></textarea>
                </div>

                <!-- ── CLIENTE (solo crear) ── -->
                <div id="clienteBusquedaWrap">
                    <div class="fsec-title" style="margin-top:20px;"><i class="fas fa-user"></i> Cliente</div>
                    <div class="form-group client-search-wrap">
                        <label class="form-label required">Buscar cliente</label>
                        <input type="text" id="buscarCliente" class="form-control"
                               placeholder="Nombre, apellido o código…"
                               autocomplete="off" oninput="buscarClienteInput()">
                        <div class="client-results" id="clienteResultados"></div>
                    </div>
                    <div class="client-selected-box" id="clienteSeleccionado">
                        <span id="clienteSeleccionadoNombre"></span>
                        <button type="button" class="btn btn-secondary btn-sm"
                                onclick="limpiarClienteSeleccionado()" style="padding:3px 8px;font-size:11px;">
                            <i class="fas fa-times"></i> Cambiar
                        </button>
                    </div>
                </div>

                <div id="clienteEdicionWrap" style="display:none;">
                    <div class="fsec-title" style="margin-top:20px;"><i class="fas fa-user"></i> Cliente</div>
                    <div style="padding:10px 14px;background:var(--gray-50);border-radius:var(--radius-sm);border:1px solid var(--gray-200);">
                        <span style="font-weight:600;color:var(--gray-800);" id="clienteEdicionNombre"></span>
                        <span style="font-size:11px;color:var(--gray-400);margin-left:6px;" id="clienteEdicionCodigo"></span>
                    </div>
                </div>

                <!-- ── BENEFICIARIOS ── -->
                <div class="fsec-title" style="margin-top:20px;">
                    <i class="fas fa-heart"></i> Beneficiarios
                    <button type="button" class="btn btn-secondary btn-sm" style="margin-left:12px;"
                            onclick="agregarBeneficiario()">
                        <i class="fas fa-plus"></i> Agregar
                    </button>
                </div>
                <div id="beneficiariosContainer" style="margin-top:10px;"></div>
                <p id="sinBeneficiarios" style="font-size:13px;color:var(--gray-400);text-align:center;padding:12px 0;">
                    No hay beneficiarios registrados
                </p>
            </form>
        </div>

        <div class="mfooter">
            <button class="btn btn-secondary" onclick="cerrarOverlay('overlayContrato')">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button class="btn btn-primary" onclick="submitContrato()">
                <i class="fas fa-save"></i> <span id="btnSubmitTexto">Guardar Contrato</span>
            </button>
        </div>
    </div>
</div>


<!-- ============================================================
     MODAL: CONFIRMAR CANCELACIÓN
     ============================================================ -->
<div class="modal-overlay" id="overlayCancelar">
    <div class="modal-box sm">
        <div class="mhdr">
            <div class="mhdr-title">
                <i class="fas fa-exclamation-triangle" style="color:#D97706;"></i>
                Cancelar Contrato
            </div>
            <button class="modal-close-btn" onclick="cerrarOverlay('overlayCancelar')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mbody">
            <p style="font-size:14px;color:var(--gray-700);margin:0 0 8px;">
                ¿Estás seguro de que deseas cancelar el contrato
                <strong id="cancelarNumero" style="color:var(--accent);"></strong>?
            </p>
            <p style="font-size:13px;color:var(--gray-400);margin:0;">
                Esta acción cambiará el estado a <em>cancelado</em>. Se puede reactivar editando el contrato.
            </p>
        </div>
        <div class="mfooter">
            <button class="btn btn-secondary" onclick="cerrarOverlay('overlayCancelar')">Cancelar</button>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="id" id="cancelarId">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Sí, cancelar
                </button>
            </form>
        </div>
    </div>
</div>


<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
/* ── Utilidades de overlay ── */
function abrirOverlay(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; }
function cerrarOverlay(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }

document.querySelectorAll('.modal-overlay').forEach(function(o) {
    o.addEventListener('click', function(e) { if (e.target === o) cerrarOverlay(o.id); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(function(o) { cerrarOverlay(o.id); });
});

/* ── Auto-ocultar alerta ── */
(function() {
    var a = document.getElementById('alertaGlobal');
    if (a) setTimeout(function() { a.style.opacity='0'; a.style.transition='.4s'; setTimeout(function(){ a.remove(); }, 400); }, 4000);
})();

/* ── Cambiar registros por página ── */
function cambiarRPP(val) {
    document.cookie = 'contratos_por_pagina=' + val + '; path=/; max-age=31536000';
    var url = new URL(window.location.href);
    url.searchParams.set('pagina', '1');
    window.location.href = url.toString();
}

/* ── Helpers ── */
function esc(s) { var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function esc2(s) { return s.replace(/'/g,"\\'").replace(/"/g,'&quot;'); }
function ucFirst(s) { return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }
function numFmt(n) { return parseFloat(n||0).toLocaleString('es-DO',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function formatDate(d) { if(!d) return '—'; var p=d.split('-'); return p.length===3 ? p[2]+'/'+p[1]+'/'+p[0] : d; }
function setVal(id, v) { var el=document.getElementById(id); if(el) el.value=v||''; }

/* ─────────────────────────────────────────────────────────────
   MODAL VER CONTRATO — DISEÑO POR BLOQUES
───────────────────────────────────────────────────────────── */
function verContrato(id) {
    document.getElementById('verTitulo').textContent   = 'Cargando…';
    document.getElementById('verSubtitulo').textContent = '';
    document.getElementById('verBody').innerHTML        = '<div class="spinner"></div>';
    document.getElementById('btnVerCompleto').href      = 'ver_contrato.php?id=' + id;
    abrirOverlay('overlayVer');

    fetch('ajax_ver_contrato.php?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(d) { renderVerContrato(d); })
        .catch(function() {
            document.getElementById('verBody').innerHTML =
                '<div style="text-align:center;padding:40px;color:var(--red-light);">' +
                '<i class="fas fa-exclamation-circle" style="font-size:36px;display:block;margin-bottom:12px;"></i>' +
                '<p>No se pudo cargar la información del contrato.</p></div>';
        });
}

function renderVerContrato(d) {
    document.getElementById('verTitulo').textContent    = 'Contrato ' + d.numero_contrato;
    document.getElementById('verSubtitulo').textContent = d.cliente_nombre + ' — ' + d.plan_nombre;

    var badgeMap = { activo:'badge-activo', suspendido:'badge-suspendido', cancelado:'badge-cancelado' };
    var badge    = '<span class="badge ' + (badgeMap[d.estado]||'badge-secondary') + '">' + ucFirst(d.estado) + '</span>';

    var html = '';

    /* ── Tabs ── */
    html += '<div class="modal-tabs">' +
        '<button class="modal-tab active" onclick="showTab(\'tabInfo\',this)">' +
            '<i class="fas fa-info-circle"></i> Información</button>' +
        '<button class="modal-tab" onclick="showTab(\'tabDep\',this)">' +
            '<i class="fas fa-users"></i> Dependientes ' +
            '<span class="tab-badge blue">' + (d.total_dependientes||0) + '</span></button>' +
        '<button class="modal-tab" onclick="showTab(\'tabBen\',this)">' +
            '<i class="fas fa-heart"></i> Beneficiarios ' +
            '<span class="tab-badge red">' + (d.beneficiarios ? d.beneficiarios.length : 0) + '</span></button>' +
        '<button class="modal-tab" onclick="showTab(\'tabPagos\',this)">' +
            '<i class="fas fa-money-bill-wave"></i> Pagos ' +
            '<span class="tab-badge green">' + (d.pagos ? d.pagos.length : 0) + '</span></button>' +
        '</div>';

    /* ══════════════════════════════════════════════════
       TAB INFORMACIÓN — diseño por bloques
       ══════════════════════════════════════════════════ */
    html += '<div class="tab-pane active" id="tabInfo">';

    /* Bloque: Datos del Contrato */
    html += '<div class="view-block">';
    html += '<div class="view-block-header">';
    html += '<div class="view-block-icon blue"><i class="fas fa-file-contract"></i></div>';
    html += '<div><div class="view-block-title">Datos del Contrato</div>';
    html += '<div class="view-block-sub">Información general del contrato</div></div>';
    html += '<div style="margin-left:auto;">' + badge + '</div>';
    html += '</div>';
    html += '<div class="view-block-body">';
    html += '<div class="info-grid cols-3">';
    html += infoItem('No. Contrato', '<span class="info-value mono">' + esc(d.numero_contrato) + '</span>');
    html += infoItem('Plan', esc(d.plan_nombre));
    html += infoItem('Vendedor', esc(d.vendedor_nombre || '—'));
    html += infoItem('Monto Mensual', '<span class="info-value big">RD$' + numFmt(d.monto_mensual) + '</span>');
    html += infoItem('Monto Total', 'RD$' + numFmt(d.monto_total));
    html += infoItem('Día de Cobro', d.dia_cobro || '—');
    html += infoItem('Fecha Inicio', formatDate(d.fecha_inicio));
    html += infoItem('Fecha Fin', formatDate(d.fecha_fin));
    html += infoItem('Notas', esc(d.notas && d.notas !== 'null' ? d.notas : 'Sin notas'));
    html += '</div>';
    html += '</div></div>';

    /* Bloque: Datos del Cliente */
    html += '<div class="view-block">';
    html += '<div class="view-block-header">';
    html += '<div class="view-block-icon green"><i class="fas fa-user"></i></div>';
    html += '<div><div class="view-block-title">Datos del Cliente</div>';
    html += '<div class="view-block-sub">Información del titular del contrato</div></div>';
    html += '</div>';
    html += '<div class="view-block-body">';
    html += '<div class="info-grid">';
    html += infoItem('Nombre Completo', '<strong>' + esc(d.cliente_nombre) + '</strong>');
    html += infoItem('Código', '<span class="info-value mono">' + esc(d.cliente_codigo) + '</span>');
    html += infoItem('Dirección', esc(d.cliente_direccion || '—'));
    html += infoItem('Teléfono', esc(d.cliente_telefono1 || '—'));
    html += infoItem('Email', esc(d.cliente_email || '—'));
    html += '</div>';
    html += '</div></div>';

    /* Bloque: Resumen Financiero */
    html += '<div class="view-block">';
    html += '<div class="view-block-header">';
    html += '<div class="view-block-icon amber"><i class="fas fa-chart-pie"></i></div>';
    html += '<div><div class="view-block-title">Resumen Financiero</div>';
    html += '<div class="view-block-sub">Estado económico del contrato</div></div>';
    html += '</div>';
    html += '<div class="view-block-body">';
    html += '<div class="view-stats-row">';
    html += statCard(d.total_dependientes || 0, 'Dependientes', 'accent');
    html += statCard(d.facturas_incompletas || 0, 'Fact. Incompletas', 'amber');
    html += statCard('RD$' + numFmt(d.total_abonado || 0), 'Total Abonado', 'green');
    html += statCard('RD$' + numFmt(d.total_pendiente || 0), 'Total Pendiente', 'red');
    html += '</div>';
    html += '</div></div>';

    html += '</div>'; /* /tabInfo */

    /* ══════════════════════════════════════════════════
       TAB DEPENDIENTES
       ══════════════════════════════════════════════════ */
    html += '<div class="tab-pane" id="tabDep">';
    html += '<div class="view-block">';
    html += '<div class="view-block-header">';
    html += '<div class="view-block-icon blue"><i class="fas fa-users"></i></div>';
    html += '<div><div class="view-block-title">Dependientes del Contrato</div>';
    html += '<div class="view-block-sub">' + (d.total_dependientes||0) + ' dependiente(s) activo(s)</div></div>';
    html += '</div>';
    if (d.dependientes && d.dependientes.length > 0) {
        html += '<div style="overflow-x:auto;"><table class="mini-table"><thead><tr>' +
            '<th>Nombre</th><th>Relación</th><th>Plan</th><th>Estado</th></tr></thead><tbody>';
        d.dependientes.forEach(function(dep) {
            var dBadge = dep.estado === 'activo' ? 'badge-activo' : 'badge-cancelado';
            html += '<tr>' +
                '<td style="font-weight:600;">' + esc(dep.nombre + ' ' + dep.apellidos) + '</td>' +
                '<td>' + esc(dep.relacion || '—') + '</td>' +
                '<td>' + esc(dep.plan_nombre || '—') + '</td>' +
                '<td><span class="badge ' + dBadge + '">' + ucFirst(dep.estado) + '</span></td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
    } else {
        html += '<div class="empty-state-sm"><i class="fas fa-users"></i><p>Sin dependientes registrados</p></div>';
    }
    html += '</div></div>';

    /* ══════════════════════════════════════════════════
       TAB BENEFICIARIOS
       ══════════════════════════════════════════════════ */
    html += '<div class="tab-pane" id="tabBen">';
    html += '<div class="view-block">';
    html += '<div class="view-block-header">';
    html += '<div class="view-block-icon red"><i class="fas fa-heart"></i></div>';
    html += '<div><div class="view-block-title">Beneficiarios</div>';
    html += '<div class="view-block-sub">' + (d.beneficiarios ? d.beneficiarios.length : 0) + ' beneficiario(s) registrado(s)</div></div>';
    html += '</div>';
    if (d.beneficiarios && d.beneficiarios.length > 0) {
        html += '<div style="overflow-x:auto;"><table class="mini-table"><thead><tr>' +
            '<th>Nombre</th><th>Parentesco</th><th>Porcentaje</th><th>Fecha Nac.</th></tr></thead><tbody>';
        d.beneficiarios.forEach(function(ben) {
            html += '<tr>' +
                '<td style="font-weight:600;">' + esc(ben.nombre + ' ' + (ben.apellidos||'')) + '</td>' +
                '<td>' + esc(ben.parentesco || '—') + '</td>' +
                '<td><span style="font-weight:700;color:var(--accent);">' + (ben.porcentaje||0) + '%</span></td>' +
                '<td class="td-muted">' + formatDate(ben.fecha_nacimiento) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
    } else {
        html += '<div class="empty-state-sm"><i class="fas fa-heart"></i><p>Sin beneficiarios registrados</p></div>';
    }
    html += '</div></div>';

    /* ══════════════════════════════════════════════════
       TAB PAGOS — últimos 15
       ══════════════════════════════════════════════════ */
    html += '<div class="tab-pane" id="tabPagos">';
    html += '<div class="view-block">';
    html += '<div class="view-block-header">';
    html += '<div class="view-block-icon purple"><i class="fas fa-money-bill-wave"></i></div>';
    html += '<div><div class="view-block-title">Historial de Pagos</div>';
    html += '<div class="view-block-sub">Últimos 15 pagos registrados</div></div>';
    html += '</div>';
    if (d.pagos && d.pagos.length > 0) {
        var pagosToShow = d.pagos.slice(0, 15);
        html += '<div style="overflow-x:auto;"><table class="mini-table"><thead><tr>' +
            '<th>Fecha</th><th>Factura</th><th>Monto</th><th>Tipo</th><th>Estado</th><th>Comp.</th></tr></thead><tbody>';
        pagosToShow.forEach(function(pago) {
            var bCls = pago.estado === 'procesado' ? 'badge-activo' : 'badge-cancelado';
            html += '<tr>' +
                '<td class="td-muted">' + formatDate(pago.fecha_pago) + '</td>' +
                '<td><span class="info-value mono" style="font-size:12px;">' + esc(pago.numero_factura||'—') + '</span></td>' +
                '<td class="td-amount">RD$' + numFmt(pago.monto) + '</td>' +
                '<td>' + esc(pago.tipo_pago||'—') + '</td>' +
                '<td><span class="badge ' + bCls + '">' + ucFirst(pago.estado) + '</span></td>' +
                '<td>' + (pago.comprobante ? '<a href="ver_comprobante.php?id=' + pago.id + '" target="_blank" class="btn btn-secondary btn-sm" style="padding:3px 8px;font-size:11px;"><i class="fas fa-receipt"></i></a>' : '<span class="td-muted">—</span>') + '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        if (d.pagos.length > 15) {
            html += '<div style="padding:12px 18px;text-align:center;border-top:1px solid var(--gray-100);">' +
                '<span style="font-size:12px;color:var(--gray-400);">Mostrando 15 de ' + d.pagos.length + ' pagos — ' +
                '<a href="ver_contrato.php?id=' + d.id + '" target="_blank" style="color:var(--accent);font-weight:600;">Ver todos</a></span></div>';
        }
    } else {
        html += '<div class="empty-state-sm"><i class="fas fa-money-bill-wave"></i><p>Sin pagos registrados</p></div>';
    }
    html += '</div></div>';

    document.getElementById('verBody').innerHTML = html;
}

function statCard(value, label, color) {
    return '<div class="view-stat-card ' + color + '">' +
        '<div class="stat-num">' + value + '</div>' +
        '<div class="stat-lbl">' + label + '</div></div>';
}

function infoItem(label, value) {
    return '<div class="info-item"><span class="info-label">' + label + '</span>' +
           '<span class="info-value">' + value + '</span></div>';
}
function showTab(id, btn) {
    document.querySelectorAll('#verBody .tab-pane').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('#verBody .modal-tab').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
}

/* ─────────────────────────────────────────────────────────────
   MODAL CREAR / EDITAR
───────────────────────────────────────────────────────────── */
var benIdx = 0;

function abrirModalNuevo() {
    document.getElementById('formContrato').reset();
    document.getElementById('accionContrato').value  = 'crear';
    document.getElementById('contratoId').value      = '';
    document.getElementById('clienteIdHidden').value = '';
    document.getElementById('textoTitulo').textContent = 'Nuevo Contrato';
    document.getElementById('btnSubmitTexto').textContent = 'Guardar Contrato';

    document.getElementById('grupoNumeroContrato').style.display = '';
    document.getElementById('grupoEstado').style.display         = 'none';
    document.getElementById('clienteBusquedaWrap').style.display = '';
    document.getElementById('clienteEdicionWrap').style.display  = 'none';
    document.getElementById('clienteSeleccionado').style.display = 'none';
    document.getElementById('clienteResultados').style.display   = 'none';
    document.getElementById('buscarCliente').value               = '';
    document.getElementById('beneficiariosContainer').innerHTML  = '';

    benIdx = 0;
    actualizarSinBeneficiarios();
    document.getElementById('fecha_inicio').value = new Date().toISOString().split('T')[0];
    abrirOverlay('overlayContrato');
}

function editarContrato(ct) {
    document.getElementById('formContrato').reset();
    document.getElementById('accionContrato').value   = 'editar';
    document.getElementById('contratoId').value       = ct.id;
    document.getElementById('clienteIdHidden').value  = ct.cliente_id;
    document.getElementById('textoTitulo').textContent = 'Editar Contrato';
    document.getElementById('btnSubmitTexto').textContent = 'Actualizar Contrato';

    document.getElementById('grupoNumeroContrato').style.display = 'none';
    document.getElementById('grupoEstado').style.display         = '';
    document.getElementById('clienteBusquedaWrap').style.display = 'none';
    document.getElementById('clienteEdicionWrap').style.display  = '';

    document.getElementById('clienteEdicionNombre').textContent = ct.cliente_nombre;
    document.getElementById('clienteEdicionCodigo').textContent = ct.cliente_codigo ? '(' + ct.cliente_codigo + ')' : '';

    setVal('plan_id',        ct.plan_id);
    setVal('vendedor_id',    ct.vendedor_id);
    setVal('fecha_inicio',   ct.fecha_inicio);
    setVal('fecha_fin',      ct.fecha_fin);
    setVal('dia_cobro',      ct.dia_cobro);
    setVal('monto_mensual',  ct.monto_mensual);
    setVal('monto_total',    ct.monto_total);
    setVal('estadoContrato', ct.estado);
    setVal('notas',          ct.notas || '');

    document.getElementById('beneficiariosContainer').innerHTML = '';
    benIdx = 0;
    fetch('ajax_beneficiarios.php?contrato_id=' + ct.id)
        .then(function(r){ return r.json(); })
        .then(function(bens){
            bens.forEach(function(b){ agregarBeneficiario(b); });
            actualizarSinBeneficiarios();
        })
        .catch(function(){ actualizarSinBeneficiarios(); });

    abrirOverlay('overlayContrato');
}

function confirmarCancelar(ct) {
    document.getElementById('cancelarNumero').textContent = ct.numero_contrato;
    document.getElementById('cancelarId').value           = ct.id;
    abrirOverlay('overlayCancelar');
}

function submitContrato() {
    var form   = document.getElementById('formContrato');
    var accion = document.getElementById('accionContrato').value;

    if (accion === 'crear') {
        document.getElementById('numero_contrato').required = true;
        if (!document.getElementById('clienteIdHidden').value) {
            alert('Por favor seleccione un cliente.');
            return;
        }
    } else {
        document.getElementById('numero_contrato').required = false;
    }

    if (form.checkValidity()) { form.submit(); }
    else { form.reportValidity(); }
}

/* ── Plan / Precio ── */
function actualizarMontoPlan() {
    var sel = document.getElementById('plan_id');
    var opt = sel.options[sel.selectedIndex];
    var precio = opt ? parseFloat(opt.dataset.precio || 0) : 0;
    if (precio > 0) {
        document.getElementById('monto_mensual').value = precio.toFixed(2);
        calcularMontoTotal();
    }
}

function calcularFechaFin() {
    var fi = document.getElementById('fecha_inicio').value;
    if (fi) {
        var d = new Date(fi);
        d.setFullYear(d.getFullYear() + 1);
        document.getElementById('fecha_fin').value = d.toISOString().split('T')[0];
        calcularMontoTotal();
    }
}

function calcularMontoTotal() {
    var mensual = parseFloat(document.getElementById('monto_mensual').value) || 0;
    var fi      = document.getElementById('fecha_inicio').value;
    var ff      = document.getElementById('fecha_fin').value;
    if (mensual > 0 && fi && ff) {
        var meses = Math.round((new Date(ff) - new Date(fi)) / (1000*60*60*24*30));
        document.getElementById('monto_total').value = (mensual * meses).toFixed(2);
    }
}
document.getElementById('monto_mensual').addEventListener('input', calcularMontoTotal);

/* ── Búsqueda de cliente ── */
var buscarClienteTimer = null;
function buscarClienteInput() {
    clearTimeout(buscarClienteTimer);
    buscarClienteTimer = setTimeout(function() {
        var q = document.getElementById('buscarCliente').value.trim();
        if (q.length < 2) { document.getElementById('clienteResultados').style.display = 'none'; return; }
        fetch('ajax_buscar_cliente.php?q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(data) {
                var res = document.getElementById('clienteResultados');
                if (!data.length) {
                    res.innerHTML = '<div class="client-result-item" style="color:var(--gray-400);">Sin resultados</div>';
                } else {
                    res.innerHTML = data.map(function(c) {
                        return '<div class="client-result-item" onclick="seleccionarCliente(' +
                            c.id + ',\'' + esc2(c.nombre + ' ' + c.apellidos) + '\',\'' + esc2(c.codigo) + '\')">' +
                            '<strong>' + esc(c.nombre + ' ' + c.apellidos) + '</strong> ' +
                            '<span style="font-size:11px;color:var(--gray-400);">(' + esc(c.codigo) + ')</span></div>';
                    }).join('');
                }
                res.style.display = 'block';
            });
    }, 300);
}

function seleccionarCliente(id, nombre, codigo) {
    document.getElementById('clienteIdHidden').value = id;
    document.getElementById('clienteSeleccionadoNombre').textContent = nombre + ' (' + codigo + ')';
    document.getElementById('clienteSeleccionado').style.display = 'flex';
    document.getElementById('clienteResultados').style.display   = 'none';
    document.getElementById('buscarCliente').value               = '';
}
function limpiarClienteSeleccionado() {
    document.getElementById('clienteIdHidden').value             = '';
    document.getElementById('clienteSeleccionado').style.display = 'none';
    document.getElementById('buscarCliente').value               = '';
}

/* ── Beneficiarios ── */
function agregarBeneficiario(data) {
    data = data || {};
    var idx  = benIdx++;
    var html =
        '<div class="ben-item" id="ben_' + idx + '">' +
        '<button type="button" class="btn-remove-ben" onclick="quitarBeneficiario(' + idx + ')">' +
        '<i class="fas fa-times"></i></button>' +
        '<div class="form-grid cols-2">' +
        '<div class="form-group"><label class="form-label">Nombre</label>' +
        '<input type="text" name="beneficiarios[' + idx + '][nombre]" class="form-control" value="' + esc(data.nombre||'') + '"></div>' +
        '<div class="form-group"><label class="form-label">Apellidos</label>' +
        '<input type="text" name="beneficiarios[' + idx + '][apellidos]" class="form-control" value="' + esc(data.apellidos||'') + '"></div>' +
        '</div>' +
        '<div class="form-grid cols-3" style="margin-top:10px;">' +
        '<div class="form-group"><label class="form-label">Parentesco</label>' +
        '<input type="text" name="beneficiarios[' + idx + '][parentesco]" class="form-control" value="' + esc(data.parentesco||'') + '"></div>' +
        '<div class="form-group"><label class="form-label">Porcentaje (%)</label>' +
        '<input type="number" name="beneficiarios[' + idx + '][porcentaje]" class="form-control" min="0" max="100" step="0.01" value="' + (data.porcentaje||'') + '"></div>' +
        '<div class="form-group"><label class="form-label">Fecha Nac.</label>' +
        '<input type="date" name="beneficiarios[' + idx + '][fecha_nacimiento]" class="form-control" value="' + (data.fecha_nacimiento||'') + '"></div>' +
        '</div></div>';

    document.getElementById('beneficiariosContainer').insertAdjacentHTML('beforeend', html);
    actualizarSinBeneficiarios();
}

function quitarBeneficiario(idx) {
    var el = document.getElementById('ben_' + idx);
    if (el) el.remove();
    actualizarSinBeneficiarios();
}

function actualizarSinBeneficiarios() {
    var container = document.getElementById('beneficiariosContainer');
    var msg       = document.getElementById('sinBeneficiarios');
    if (msg) msg.style.display = container.children.length === 0 ? '' : 'none';
}
</script>

<?php require_once 'footer.php'; ?>