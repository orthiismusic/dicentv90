<?php
require_once 'header.php';

/* ============================================================
   FUNCIONES
   ============================================================ */
function obtenerSiguienteCodigoCliente($conn) {
    $stmt = $conn->query("SELECT MAX(CAST(codigo AS UNSIGNED)) as ultimo_codigo FROM clientes");
    $resultado = $stmt->fetch();
    return str_pad(($resultado['ultimo_codigo'] ?? 0) + 1, 5, '0', STR_PAD_LEFT);
}

/* ============================================================
   PROCESAR POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            $conn->beginTransaction();

            switch ($_POST['action']) {
                case 'crear':
                case 'editar':
                    $stmt = $conn->prepare("SELECT id FROM clientes WHERE cedula = ? AND id != ?");
                    $stmt->execute([$_POST['cedula'], $_POST['id'] ?? 0]);
                    if ($stmt->fetch()) {
                        throw new Exception("Ya existe un cliente con esta identificación.");
                    }

                    if ($_POST['action'] == 'crear') {
                        $codigo = obtenerSiguienteCodigoCliente($conn);
                        $stmt = $conn->prepare("
                            INSERT INTO clientes (
                                codigo, nombre, apellidos, cedula,
                                telefono1, telefono2, telefono3,
                                direccion, email, fecha_nacimiento,
                                fecha_registro, estado,
                                cobrador_id, vendedor_id, notas
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?, ?, ?)
                        ");
                        $stmt->execute([
                            $codigo,
                            $_POST['nombre'],
                            $_POST['apellidos'],
                            $_POST['cedula'],
                            $_POST['telefono1'],
                            $_POST['telefono2'] ?? null,
                            $_POST['telefono3'] ?? null,
                            $_POST['direccion'],
                            $_POST['email'] ?? null,
                            $_POST['fecha_nacimiento'],
                            $_POST['fecha_registro'],
                            $_POST['cobrador_id'] ?: null,
                            $_POST['vendedor_id'] ?: null,
                            $_POST['notas'] ?? null,
                        ]);
                        $mensaje = "Cliente registrado exitosamente.";
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE clientes SET
                                nombre = ?, apellidos = ?, cedula = ?,
                                telefono1 = ?, telefono2 = ?, telefono3 = ?,
                                direccion = ?, email = ?, fecha_nacimiento = ?,
                                fecha_registro = ?, cobrador_id = ?,
                                vendedor_id = ?, notas = ?, estado = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $_POST['nombre'],
                            $_POST['apellidos'],
                            $_POST['cedula'],
                            $_POST['telefono1'],
                            $_POST['telefono2'] ?? null,
                            $_POST['telefono3'] ?? null,
                            $_POST['direccion'],
                            $_POST['email'] ?? null,
                            $_POST['fecha_nacimiento'],
                            $_POST['fecha_registro'],
                            $_POST['cobrador_id'] ?: null,
                            $_POST['vendedor_id'] ?: null,
                            $_POST['notas'] ?? null,
                            $_POST['estado'],
                            $_POST['id'],
                        ]);
                        $mensaje = "Cliente actualizado exitosamente.";
                    }
                    $tipo_mensaje = "success";
                    break;

                case 'desactivar':
                    $conn->prepare("UPDATE clientes SET estado = 'inactivo' WHERE id = ?")
                         ->execute([$_POST['id']]);
                    $mensaje = "Cliente desactivado exitosamente.";
                    $tipo_mensaje = "success";
                    break;
            }
            $conn->commit();
        }
    } catch (Exception $e) {
        $conn->rollBack();
        $mensaje     = "Error: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

/* ============================================================
   LISTAS DE APOYO
   ============================================================ */
$stmt = $conn->query("SELECT id, codigo, nombre_completo FROM cobradores WHERE estado='activo' ORDER BY nombre_completo");
$cobradores = $stmt->fetchAll();

$stmt = $conn->query("SELECT id, codigo, nombre_completo FROM vendedores WHERE estado='activo' ORDER BY nombre_completo");
$vendedores = $stmt->fetchAll();

$stmt = $conn->query("SELECT id, nombre, precio_base FROM planes WHERE estado='activo' ORDER BY nombre");
$planes = $stmt->fetchAll();

/* ============================================================
   FILTROS & PAGINACIÓN
   ============================================================ */
$registros_por_pagina = isset($_COOKIE['clientes_por_pagina']) ? (int)$_COOKIE['clientes_por_pagina'] : 10;
$pagina_actual        = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset               = ($pagina_actual - 1) * $registros_por_pagina;

$filtro_estado   = $_GET['estado']   ?? '';
$filtro_vendedor = $_GET['vendedor'] ?? '';
$buscar          = trim($_GET['buscar'] ?? '');

$where  = "1=1";
$params = [];

if (!empty($filtro_estado) && $filtro_estado !== 'all') {
    $where   .= " AND clientes.estado = ?";
    $params[] = $filtro_estado;
}
if (!empty($filtro_vendedor) && $filtro_vendedor !== 'all') {
    $where   .= " AND clientes.vendedor_id = ?";
    $params[] = $filtro_vendedor;
}
if (!empty($buscar)) {
    $t = "%$buscar%";
    $where .= " AND (clientes.nombre LIKE ? OR clientes.apellidos LIKE ? OR clientes.cedula LIKE ?
                  OR clientes.telefono1 LIKE ? OR clientes.telefono2 LIKE ? OR clientes.codigo LIKE ?)";
    $params = array_merge($params, [$t, $t, $t, $t, $t, $t]);
}

/* ── Total para paginación ── */
$stmt = $conn->prepare("SELECT COUNT(*) FROM clientes WHERE $where");
$stmt->execute($params);
$total_registros = $stmt->fetchColumn();
$total_paginas   = max(1, ceil($total_registros / $registros_por_pagina));

/* ── Query principal ── */
$sql = "
    SELECT clientes.*,
           cb.nombre_completo AS cobrador_nombre,
           v.nombre_completo  AS vendedor_nombre,
           (
               SELECT COUNT(*)
               FROM contratos c
               JOIN dependientes d ON d.contrato_id = c.id
               WHERE c.cliente_id = clientes.id AND d.estado = 'activo'
           ) AS total_dependientes
    FROM clientes
    LEFT JOIN cobradores cb ON clientes.cobrador_id = cb.id
    LEFT JOIN vendedores  v  ON clientes.vendedor_id  = v.id
    WHERE $where
    ORDER BY clientes.id DESC
    LIMIT ? OFFSET ?
";
$params_pag = array_merge($params, [$registros_por_pagina, $offset]);
$stmt = $conn->prepare($sql);
foreach ($params_pag as $k => $v_val) {
    $stmt->bindValue($k + 1, $v_val, is_int($v_val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$clientes = $stmt->fetchAll();

/* ── Estadísticas ── */
$stmt = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado='activo'    THEN 1 ELSE 0 END) AS activos,
        SUM(CASE WHEN estado='inactivo'  THEN 1 ELSE 0 END) AS inactivos,
        SUM(CASE WHEN estado='suspendido'THEN 1 ELSE 0 END) AS suspendidos
    FROM clientes
");
$stats = $stmt->fetch();
?>

<!-- ============================================================
     ESTILOS
     ============================================================ -->
<style>
/* ── Filtros ── */
.filtros-bar {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
    box-shadow: var(--shadow-sm);
}

.search-wrap {
    position: relative;
    flex: 1;
    min-width: 200px;
}

.search-wrap input {
    width: 100%;
    padding: 9px 14px 9px 38px;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-family: var(--font);
    outline: none;
    transition: var(--transition);
    color: var(--gray-700);
}

.search-wrap input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(33,150,243,0.1); }

.search-icon {
    position: absolute;
    left: 12px; top: 50%;
    transform: translateY(-50%);
    color: var(--gray-400);
    font-size: 13px;
    pointer-events: none;
}

.filter-select {
    padding: 9px 12px;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-family: var(--font);
    color: var(--gray-700);
    outline: none;
    background: var(--white);
    min-width: 160px;
    transition: var(--transition);
    cursor: pointer;
}

.filter-select:focus { border-color: var(--accent); }

/* ── Tabla ── */
.clientes-table-wrap {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    overflow: hidden;
}

/* ── Avatar ── */
.avatar-cl {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #0D47A1);
    color: white;
    font-size: 12px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.client-cell { display: flex; align-items: center; gap: 10px; }
.client-name { font-weight: 600; color: var(--gray-800); font-size: 13.5px; line-height: 1.3; }
.client-code { font-size: 11px; color: var(--gray-400); font-family: monospace; }

/* ── Botón dependientes con badge ── */
.btn-deps {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: var(--radius-sm);
    border: 1.5px solid var(--gray-200);
    background: var(--white);
    color: var(--gray-600);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    font-family: var(--font);
    text-decoration: none;
}

.btn-deps:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: #EFF6FF;
}

.deps-count {
    background: var(--accent);
    color: white;
    font-size: 10px;
    font-weight: 800;
    min-width: 18px;
    height: 18px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
}

.deps-count.zero { background: var(--gray-300); }

/* ── Paginador ── */
.paginador-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-top: 1px solid var(--gray-100);
    background: var(--gray-50);
    flex-wrap: wrap;
    gap: 10px;
}

.paginador-info {
    font-size: 12.5px;
    color: var(--gray-500);
}

.paginador-info strong { color: var(--gray-700); }

.paginador-pages {
    display: flex;
    align-items: center;
    gap: 4px;
}

.pag-btn {
    width: 34px; height: 34px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--radius-sm);
    border: 1px solid var(--gray-200);
    background: var(--white);
    color: var(--gray-600);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: var(--transition);
    cursor: pointer;
    font-family: var(--font);
}

.pag-btn:hover   { border-color: var(--accent); color: var(--accent); background: #EFF6FF; }
.pag-btn.active  { background: var(--accent); border-color: var(--accent); color: white; }
.pag-btn.disabled{ opacity: 0.4; pointer-events: none; }
.pag-btn.ellipsis{ border: none; background: none; cursor: default; color: var(--gray-400); }

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

/* ── MODALES ── */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.modal-overlay.open { display: flex; }

.modal-box {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    width: 100%;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.modal-box.sm  { max-width: 460px; }
.modal-box.md  { max-width: 680px; }
.modal-box.lg  { max-width: 860px; }
.modal-box.xl  { max-width: 1020px; }

.mhdr {
    padding: 18px 22px;
    border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
    position: sticky; top: 0;
    background: var(--white);
    z-index: 1;
}

.mhdr-title {
    font-size: 15px; font-weight: 700;
    color: var(--gray-800);
    display: flex; align-items: center; gap: 8px;
}

.mbody { padding: 22px; flex: 1; }

.mftr {
    padding: 14px 22px;
    border-top: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: flex-end;
    gap: 10px;
    background: var(--gray-50);
    flex-shrink: 0;
    position: sticky; bottom: 0;
}

.modal-close-btn {
    width: 30px; height: 30px;
    border-radius: 50%;
    border: none;
    background: var(--gray-100);
    color: var(--gray-500);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    transition: var(--transition);
}

.modal-close-btn:hover { background: var(--gray-200); color: var(--gray-700); }

/* Sección de formulario dentro del modal */
.fsec-title {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.7px;
    margin: 18px 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--gray-100);
}

.fsec-title:first-child { margin-top: 0; }

/* Grid de dependientes en modal */
.dep-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
    transition: var(--transition);
}

.dep-card:hover { border-color: var(--accent); background: #EFF6FF; }

.dep-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16A34A, #15803D);
    color: white;
    font-size: 12px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.dep-info { flex: 1; }
.dep-name   { font-size: 13px; font-weight: 600; color: var(--gray-800); }
.dep-meta   { font-size: 11px; color: var(--gray-500); margin-top: 2px; }

.dep-empty {
    text-align: center;
    padding: 32px;
    color: var(--gray-400);
}

.dep-empty i { font-size: 28px; display: block; margin-bottom: 8px; }

/* Alert dentro del modal */
.modal-alert {
    padding: 12px 14px;
    border-radius: var(--radius-sm);
    margin-bottom: 14px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-alert.danger { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
.modal-alert.warn   { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }

/* Spinner */
.spinner {
    width: 28px; height: 28px;
    border: 3px solid var(--gray-200);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 20px auto;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Responsive */
@media (max-width: 768px) {
    .filtros-bar { flex-direction: column; align-items: stretch; }
    .search-wrap { min-width: unset; }
    .paginador-wrap { flex-direction: column; align-items: center; }
}
</style>

<!-- ============================================================
     STAT CARDS
     ============================================================ -->
<div class="dashboard-stats fade-in">
    <div class="stat-card blue">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <h3>Total Clientes</h3>
                <p class="stat-value"><?php echo number_format($stats['total']); ?></p>
                <p class="stat-label">Registrados en el sistema</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-users"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-info">
                <h3>Activos</h3>
                <p class="stat-value"><?php echo number_format($stats['activos']); ?></p>
                <p class="stat-label">Con contratos vigentes</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-check"></i></div>
    </div>
    <div class="stat-card payments">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-user-slash"></i></div>
            <div class="stat-info">
                <h3>Inactivos</h3>
                <p class="stat-value"><?php echo number_format($stats['inactivos']); ?></p>
                <p class="stat-label">Desactivados</p>
            </div>
        </div>
        <div class="stat-trend down"><i class="fas fa-arrow-down"></i></div>
    </div>
    <div class="stat-card contracts">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
            <div class="stat-info">
                <h3>Suspendidos</h3>
                <p class="stat-value"><?php echo number_format($stats['suspendidos']); ?></p>
                <p class="stat-label">Temporalmente pausados</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-clock"></i></div>
    </div>
</div>

<!-- ALERTA -->
<?php if (isset($mensaje)): ?>
<div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'danger'; ?> fade-in">
    <i class="fas fa-<?php echo $tipo_mensaje === 'success' ? 'circle-check' : 'circle-xmark'; ?>"></i>
    <?php echo htmlspecialchars($mensaje); ?>
</div>
<?php endif; ?>

<!-- ============================================================
     BARRA DE FILTROS
     ============================================================ -->
<div class="filtros-bar fade-in">
    <!-- Búsqueda -->
    <div class="search-wrap">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="searchInput" placeholder="Buscar por nombre, cédula, código, teléfono..."
               value="<?php echo htmlspecialchars($buscar); ?>">
    </div>

    <!-- Estado -->
    <select class="filter-select" id="statusFilter">
        <option value="all"       <?php echo (empty($filtro_estado) || $filtro_estado === 'all')        ? 'selected' : ''; ?>>Todos los estados</option>
        <option value="activo"    <?php echo $filtro_estado === 'activo'     ? 'selected' : ''; ?>>Activos</option>
        <option value="inactivo"  <?php echo $filtro_estado === 'inactivo'   ? 'selected' : ''; ?>>Inactivos</option>
        <option value="suspendido"<?php echo $filtro_estado === 'suspendido' ? 'selected' : ''; ?>>Suspendidos</option>
    </select>

    <!-- Vendedor -->
    <select class="filter-select" id="vendorFilter">
        <option value="all" <?php echo (empty($filtro_vendedor) || $filtro_vendedor === 'all') ? 'selected' : ''; ?>>
            Todos los vendedores
        </option>
        <?php foreach ($vendedores as $vd): ?>
            <option value="<?php echo $vd['id']; ?>"
                    <?php echo $filtro_vendedor == $vd['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($vd['nombre_completo']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button class="btn btn-secondary btn-sm" onclick="limpiarFiltros()">
        <i class="fas fa-rotate-right"></i> Limpiar
    </button>
    <button class="btn btn-primary" onclick="abrirModalCliente()">
        <i class="fas fa-plus"></i> Nuevo Cliente
    </button>
</div>

<!-- ============================================================
     TABLA DE CLIENTES
     ============================================================ -->
<div class="clientes-table-wrap fade-in delay-1">
    <div class="card-header">
        <div>
            <div class="card-title">Listado de Clientes</div>
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
                    <th width="32"><input type="checkbox" id="selectAll" title="Seleccionar todos"></th>
                    <th>Cliente</th>
                    <th>Cédula</th>
                    <th>Teléfono</th>
                    <th>Cobrador</th>
                    <th>Vendedor</th>
                    <th>Estado</th>
                    <th>Dependientes</th>
                    <th>Registro</th>
                    <th style="text-align:right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clientes)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:40px;color:var(--gray-400);">
                        <i class="fas fa-users" style="font-size:32px;display:block;margin-bottom:10px;"></i>
                        <div style="font-size:14px;font-weight:600;">No se encontraron clientes</div>
                        <div style="font-size:12px;margin-top:4px;">
                            <?php echo !empty($buscar) ? 'Intenta con otro término de búsqueda.' : 'Comienza registrando un nuevo cliente.'; ?>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($clientes as $cl): ?>
                <?php
                    $iniciales = strtoupper(
                        substr($cl['nombre'], 0, 1) .
                        (strpos($cl['apellidos'], ' ') !== false
                            ? substr($cl['apellidos'], 0, 1)
                            : substr($cl['apellidos'], 0, 1))
                    );
                    $est_cls = match($cl['estado']) {
                        'activo'     => 'badge-activo',
                        'inactivo'   => 'badge-inactivo',
                        'suspendido' => 'badge-pendiente',
                        default      => 'badge-inactivo'
                    };
                    $deps = (int)$cl['total_dependientes'];
                ?>
                <tr>
                    <td><input type="checkbox" class="cl-check" value="<?php echo $cl['id']; ?>"></td>
                    <td>
                        <div class="client-cell">
                            <div class="avatar-cl"><?php echo $iniciales; ?></div>
                            <div>
                                <div class="client-name">
                                    <?php echo htmlspecialchars($cl['nombre'] . ' ' . $cl['apellidos']); ?>
                                </div>
                                <div class="client-code">Cód. <?php echo htmlspecialchars($cl['codigo']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="font-family:monospace;font-size:12px;color:var(--gray-600);">
                        <?php echo htmlspecialchars($cl['cedula']); ?>
                    </td>
                    <td style="font-size:13px;color:var(--gray-600);">
                        <?php echo htmlspecialchars($cl['telefono1']); ?>
                    </td>
                    <td style="font-size:13px;color:var(--gray-500);">
                        <?php echo htmlspecialchars($cl['cobrador_nombre'] ?: '—'); ?>
                    </td>
                    <td style="font-size:13px;color:var(--gray-500);">
                        <?php echo htmlspecialchars($cl['vendedor_nombre'] ?: '—'); ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $est_cls; ?>">
                            <?php echo ucfirst($cl['estado']); ?>
                        </span>
                    </td>
                    <td>
                        <!-- Botón dependientes con contador -->
                        <button class="btn-deps"
                                onclick="abrirModalDependientes(<?php echo htmlspecialchars(json_encode([
                                    'id'       => $cl['id'],
                                    'nombre'   => $cl['nombre'] . ' ' . $cl['apellidos'],
                                    'codigo'   => $cl['codigo'],
                                ])); ?>)"
                                title="Ver / gestionar dependientes">
                            <i class="fas fa-user-group" style="font-size:11px;"></i>
                            <span class="deps-count <?php echo $deps === 0 ? 'zero' : ''; ?>">
                                <?php echo $deps; ?>
                            </span>
                        </button>
                    </td>
                    <td class="td-muted">
                        <?php echo date('d/m/Y', strtotime($cl['fecha_registro'])); ?>
                    </td>
                    <td class="actions-cell">
                        <a href="ver_cliente.php?id=<?php echo $cl['id']; ?>"
                           class="btn-action view" title="Ver detalles">
                            <i class="fas fa-eye"></i>
                        </a>
                        <button class="btn-action edit" title="Editar"
                                onclick="editarCliente(<?php echo htmlspecialchars(json_encode($cl)); ?>)">
                            <i class="fas fa-pen"></i>
                        </button>
                        <?php if ($cl['estado'] === 'activo'): ?>
                        <button class="btn-action delete" title="Desactivar"
                                onclick="confirmarDesactivar(<?php echo htmlspecialchars(json_encode($cl)); ?>)">
                            <i class="fas fa-ban"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ============================================================
         PAGINADOR
         ============================================================ -->
    <?php if ($total_registros > 0): ?>
    <div class="paginador-wrap">
        <!-- Info -->
        <div class="paginador-info">
            Mostrando <strong><?php echo min($offset + 1, $total_registros); ?></strong>–<strong><?php echo min($offset + $registros_por_pagina, $total_registros); ?></strong>
            de <strong><?php echo number_format($total_registros); ?></strong> clientes
        </div>

        <!-- Páginas -->
        <?php
        // Función para construir URL con parámetros actuales
        function buildUrl($pagina, $buscar, $filtro_estado, $filtro_vendedor) {
            $p = ['pagina' => $pagina];
            if (!empty($buscar))        $p['buscar']   = $buscar;
            if (!empty($filtro_estado) && $filtro_estado !== 'all')   $p['estado']   = $filtro_estado;
            if (!empty($filtro_vendedor) && $filtro_vendedor !== 'all') $p['vendedor'] = $filtro_vendedor;
            return 'clientes.php?' . http_build_query($p);
        }
        $rango = 2; // páginas a mostrar a cada lado de la actual
        ?>
        <div class="paginador-pages">
            <!-- Primera / Anterior -->
            <a href="<?php echo buildUrl(1, $buscar, $filtro_estado, $filtro_vendedor); ?>"
               class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>" title="Primera">
                <i class="fas fa-angles-left" style="font-size:10px;"></i>
            </a>
            <a href="<?php echo buildUrl($pagina_actual - 1, $buscar, $filtro_estado, $filtro_vendedor); ?>"
               class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>" title="Anterior">
                <i class="fas fa-angle-left" style="font-size:11px;"></i>
            </a>

            <?php
            // Calcula rango de páginas a mostrar
            $inicio = max(1, $pagina_actual - $rango);
            $fin    = min($total_paginas, $pagina_actual + $rango);

            if ($inicio > 1): ?>
                <a href="<?php echo buildUrl(1, $buscar, $filtro_estado, $filtro_vendedor); ?>" class="pag-btn">1</a>
                <?php if ($inicio > 2): ?><span class="pag-btn ellipsis">…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $inicio; $p <= $fin; $p++): ?>
                <a href="<?php echo buildUrl($p, $buscar, $filtro_estado, $filtro_vendedor); ?>"
                   class="pag-btn <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                    <?php echo $p; ?>
                </a>
            <?php endfor; ?>

            <?php if ($fin < $total_paginas): ?>
                <?php if ($fin < $total_paginas - 1): ?><span class="pag-btn ellipsis">…</span><?php endif; ?>
                <a href="<?php echo buildUrl($total_paginas, $buscar, $filtro_estado, $filtro_vendedor); ?>"
                   class="pag-btn"><?php echo $total_paginas; ?></a>
            <?php endif; ?>

            <!-- Siguiente / Última -->
            <a href="<?php echo buildUrl($pagina_actual + 1, $buscar, $filtro_estado, $filtro_vendedor); ?>"
               class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>" title="Siguiente">
                <i class="fas fa-angle-right" style="font-size:11px;"></i>
            </a>
            <a href="<?php echo buildUrl($total_paginas, $buscar, $filtro_estado, $filtro_vendedor); ?>"
               class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>" title="Última">
                <i class="fas fa-angles-right" style="font-size:10px;"></i>
            </a>
        </div>

        <!-- Registros por página -->
        <div class="paginador-rpp">
            <span>Mostrar:</span>
            <select onchange="cambiarRPP(this.value)">
                <?php foreach ([10, 25, 50, 100] as $rpp): ?>
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


<!-- ==============================================================
     MODAL: CREAR / EDITAR CLIENTE
     ============================================================== -->
<div class="modal-overlay" id="modalCliente">
    <div class="modal-box lg">
        <div class="mhdr">
            <div class="mhdr-title" id="modalClienteTitle">
                <i class="fas fa-user-plus" style="color:var(--accent);"></i>
                Nuevo Cliente
            </div>
            <button class="modal-close-btn" onclick="cerrarModalCliente()"><i class="fas fa-times"></i></button>
        </div>

        <form id="clienteForm" method="POST">
            <div class="mbody">
                <input type="hidden" name="action" id="clienteAction" value="crear">
                <input type="hidden" name="id"     id="clienteId">

                <!-- Datos personales -->
                <div class="fsec-title">Datos Personales</div>
                <div class="form-grid cols-3">
                    <div class="form-group">
                        <label>Código</label>
                        <input type="text" id="codigoCl" class="form-control"
                               readonly style="background:var(--gray-50);color:var(--gray-500);">
                    </div>
                    <div class="form-group">
                        <label>Nombre <span style="color:var(--red-light)">*</span></label>
                        <input type="text" name="nombre" id="cl_nombre" class="form-control" required
                               placeholder="Nombre(s)">
                    </div>
                    <div class="form-group">
                        <label>Apellidos <span style="color:var(--red-light)">*</span></label>
                        <input type="text" name="apellidos" id="cl_apellidos" class="form-control" required
                               placeholder="Apellido(s)">
                    </div>
                    <div class="form-group">
                        <label>Cédula / Identificación <span style="color:var(--red-light)">*</span></label>
                        <input type="text" name="cedula" id="cl_cedula" class="form-control" required
                               placeholder="000-0000000-0" maxlength="13">
                    </div>
                    <div class="form-group">
                        <label>Fecha de Nacimiento <span style="color:var(--red-light)">*</span></label>
                        <input type="date" name="fecha_nacimiento" id="cl_fecha_nacimiento"
                               class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha de Registro <span style="color:var(--red-light)">*</span></label>
                        <input type="date" name="fecha_registro" id="cl_fecha_registro"
                               class="form-control" required>
                    </div>
                </div>

                <!-- Contacto -->
                <div class="fsec-title">Información de Contacto</div>
                <div class="form-grid cols-3">
                    <div class="form-group">
                        <label>Teléfono Principal <span style="color:var(--red-light)">*</span></label>
                        <input type="text" name="telefono1" id="cl_telefono1" class="form-control" required
                               placeholder="809-000-0000">
                    </div>
                    <div class="form-group">
                        <label>Teléfono 2</label>
                        <input type="text" name="telefono2" id="cl_telefono2" class="form-control"
                               placeholder="Opcional">
                    </div>
                    <div class="form-group">
                        <label>Teléfono 3</label>
                        <input type="text" name="telefono3" id="cl_telefono3" class="form-control"
                               placeholder="Opcional">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="cl_email" class="form-control"
                               placeholder="correo@ejemplo.com">
                    </div>
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Dirección <span style="color:var(--red-light)">*</span></label>
                        <textarea name="direccion" id="cl_direccion" class="form-control"
                                  rows="2" required placeholder="Calle, sector, ciudad..."></textarea>
                    </div>
                </div>

                <!-- Asignaciones -->
                <div class="fsec-title">Asignaciones</div>
                <div class="form-grid cols-2">
                    <div class="form-group">
                        <label>Cobrador</label>
                        <select name="cobrador_id" id="cl_cobrador_id" class="form-control">
                            <option value="">Sin cobrador</option>
                            <?php foreach ($cobradores as $cb): ?>
                                <option value="<?php echo $cb['id']; ?>">
                                    <?php echo htmlspecialchars($cb['codigo'] . ' - ' . $cb['nombre_completo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Vendedor</label>
                        <select name="vendedor_id" id="cl_vendedor_id" class="form-control">
                            <option value="">Sin vendedor</option>
                            <?php foreach ($vendedores as $vd): ?>
                                <option value="<?php echo $vd['id']; ?>">
                                    <?php echo htmlspecialchars($vd['codigo'] . ' - ' . $vd['nombre_completo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Estado (solo edición) -->
                <div id="estadoGrp" style="display:none;">
                    <div class="fsec-title">Estado</div>
                    <div class="form-grid cols-2">
                        <div class="form-group">
                            <label>Estado del Cliente</label>
                            <select name="estado" id="cl_estado" class="form-control">
                                <option value="activo">Activo</option>
                                <option value="inactivo">Inactivo</option>
                                <option value="suspendido">Suspendido</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Notas -->
                <div class="fsec-title">Notas / Referencia</div>
                <div class="form-group">
                    <textarea name="notas" id="cl_notas" class="form-control" rows="2"
                              placeholder="Observaciones adicionales (opcional)"></textarea>
                </div>
            </div>
            <div class="mftr">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalCliente()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar Cliente
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ==============================================================
     MODAL: DEPENDIENTES DEL CLIENTE
     ============================================================== -->
<div class="modal-overlay" id="modalDependientes">
    <div class="modal-box xl">
        <div class="mhdr">
            <div class="mhdr-title">
                <i class="fas fa-user-group" style="color:#16A34A;"></i>
                <span id="depModalNombre">Dependientes</span>
            </div>
            <button class="modal-close-btn" onclick="cerrarModalDependientes()"><i class="fas fa-times"></i></button>
        </div>
        <div class="mbody" id="depModalBody">
            <div class="spinner"></div>
        </div>
    </div>
</div>


<!-- ==============================================================
     MODAL: FORMULARIO DE DEPENDIENTE (crear / editar)
     ============================================================== -->
<div class="modal-overlay" id="modalFormDep">
    <div class="modal-box md">
        <div class="mhdr">
            <div class="mhdr-title" id="formDepTitle">
                <i class="fas fa-user-plus" style="color:#16A34A;"></i>
                Nuevo Dependiente
            </div>
            <button class="modal-close-btn" onclick="cerrarFormDep()"><i class="fas fa-times"></i></button>
        </div>
        <form id="formDependiente">
            <div class="mbody">
                <input type="hidden" id="dep_id"          name="id">
                <input type="hidden" id="dep_contrato_id" name="contrato_id">

                <!-- Selector de contrato (solo para crear cuando cliente tiene múltiples) -->
                <div id="contratoSelectorWrap" style="display:none;">
                    <div class="fsec-title">Contrato</div>
                    <div class="form-group">
                        <label>Seleccionar Contrato <span style="color:var(--red-light)">*</span></label>
                        <select id="dep_selector_contrato" class="form-control"
                                onchange="document.getElementById('dep_contrato_id').value = this.value">
                            <option value="">— Seleccione un contrato —</option>
                        </select>
                    </div>
                </div>

                <div class="fsec-title">Datos Personales</div>
                <div class="form-grid cols-2">
                    <div class="form-group">
                        <label>Nombre <span style="color:var(--red-light)">*</span></label>
                        <input type="text" name="nombre" id="dep_nombre" class="form-control" required placeholder="Nombre(s)">
                    </div>
                    <div class="form-group">
                        <label>Apellidos <span style="color:var(--red-light)">*</span></label>
                        <input type="text" name="apellidos" id="dep_apellidos" class="form-control" required placeholder="Apellido(s)">
                    </div>
                    <div class="form-group">
                        <label>Identificación <span style="color:var(--red-light)">*</span></label>
                        <input type="text" name="identificacion" id="dep_identificacion" class="form-control" required placeholder="Cédula o ID">
                    </div>
                    <div class="form-group">
                        <label>Relación / Parentesco <span style="color:var(--red-light)">*</span></label>
                        <select name="relacion" id="dep_relacion" class="form-control" required>
                            <option value="">— Seleccione —</option>
                            <option value="conyuge">Cónyuge</option>
                            <option value="hijo">Hijo/a</option>
                            <option value="padre">Padre/Madre</option>
                            <option value="hermano">Hermano/a</option>
                            <option value="abuelo">Abuelo/a</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de Nacimiento <span style="color:var(--red-light)">*</span></label>
                        <input type="date" name="fecha_nacimiento" id="dep_fecha_nacimiento"
                               class="form-control" required onchange="verificarEdadDepPlan()">
                    </div>
                    <div class="form-group">
                        <label>Fecha de Ingreso <span style="color:var(--red-light)">*</span></label>
                        <input type="date" name="fecha_registro" id="dep_fecha_registro" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono" id="dep_telefono" class="form-control" placeholder="Opcional">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="dep_email" class="form-control" placeholder="Opcional">
                    </div>
                    <div class="form-group">
                        <label>Plan <span style="color:var(--red-light)">*</span></label>
                        <select name="plan_id" id="dep_plan_id" class="form-control" required>
                            <?php foreach ($planes as $pl): ?>
                                <option value="<?php echo $pl['id']; ?>"
                                        data-precio="<?php echo $pl['precio_base']; ?>">
                                    <?php echo htmlspecialchars($pl['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="depEstadoGrp" style="display:none;">
                        <label>Estado</label>
                        <select name="estado" id="dep_estado" class="form-control">
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>

                <!-- Aviso plan geriátrico -->
                <div id="aviso_geriatrico" class="modal-alert warn" style="display:none;">
                    <i class="fas fa-triangle-exclamation"></i>
                    El dependiente tiene 65 o más años — se asignará automáticamente al plan <strong>Geriátrico</strong>.
                </div>
            </div>
            <div class="mftr">
                <button type="button" class="btn btn-secondary" onclick="cerrarFormDep()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="guardarDependiente()">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ==============================================================
     MODAL: CONFIRMAR DESACTIVACIÓN
     ============================================================== -->
<div class="modal-overlay" id="modalDesactivar">
    <div class="modal-box sm">
        <div class="mhdr">
            <div class="mhdr-title">
                <i class="fas fa-ban" style="color:#DC2626;"></i>
                Confirmar Desactivación
            </div>
            <button class="modal-close-btn" onclick="cerrarModalDesactivar()"><i class="fas fa-times"></i></button>
        </div>
        <div class="mbody">
            <div class="modal-alert danger">
                <i class="fas fa-triangle-exclamation"></i>
                Esta acción cambiará el estado del cliente a <strong>inactivo</strong>.
            </div>
            <div id="desactivarDetalles" style="font-size:13px;color:var(--gray-700);line-height:1.8;"></div>
        </div>
        <div class="mftr">
            <button class="btn btn-secondary" onclick="cerrarModalDesactivar()">Cancelar</button>
            <button class="btn btn-danger" onclick="ejecutarDesactivar()">
                <i class="fas fa-ban"></i> Sí, Desactivar
            </button>
        </div>
    </div>
</div>


<!-- ==============================================================
     JAVASCRIPT — 100% FUNCIONAL
     ============================================================== -->
<script>
/* ============================================================
   VARIABLES GLOBALES
   ============================================================ */
let clienteSeleccionado  = null;
let contratoSeleccionado = null;
let clienteADesactivar   = null;

/* ============================================================
   FILTROS & PAGINACIÓN
   ============================================================ */
function aplicarFiltros() {
    const url = new URL(window.location.href);
    const buscar   = document.getElementById('searchInput').value.trim();
    const estado   = document.getElementById('statusFilter').value;
    const vendedor = document.getElementById('vendorFilter').value;

    buscar   ? url.searchParams.set('buscar',   buscar)   : url.searchParams.delete('buscar');
    estado && estado !== 'all'   ? url.searchParams.set('estado',   estado)   : url.searchParams.delete('estado');
    vendedor && vendedor !== 'all' ? url.searchParams.set('vendedor', vendedor) : url.searchParams.delete('vendedor');
    url.searchParams.set('pagina', '1');
    window.location.href = url.toString();
}

function limpiarFiltros() {
    window.location.href = 'clientes.php';
}

function cambiarRPP(val) {
    document.cookie = `clientes_por_pagina=${val}; path=/; max-age=31536000`;
    const url = new URL(window.location.href);
    url.searchParams.set('pagina', '1');
    window.location.href = url.toString();
}

// Búsqueda con debounce
let searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(aplicarFiltros, 700);
});

document.getElementById('statusFilter').addEventListener('change', aplicarFiltros);
document.getElementById('vendorFilter').addEventListener('change', aplicarFiltros);

// Seleccionar todos
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.cl-check').forEach(cb => cb.checked = this.checked);
});

/* ============================================================
   MODAL CLIENTE — ABRIR / CERRAR
   ============================================================ */
function abrirModalCliente() {
    document.getElementById('modalClienteTitle').innerHTML =
        '<i class="fas fa-user-plus" style="color:var(--accent);"></i> Nuevo Cliente';
    document.getElementById('clienteAction').value = 'crear';
    document.getElementById('clienteId').value     = '';
    document.getElementById('clienteForm').reset();
    document.getElementById('codigoCl').value = 'Se generará automáticamente';
    document.getElementById('estadoGrp').style.display = 'none';
    document.getElementById('cl_fecha_registro').valueAsDate = new Date();

    document.getElementById('modalCliente').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function cerrarModalCliente() {
    document.getElementById('modalCliente').classList.remove('open');
    document.body.style.overflow = '';
}

function editarCliente(cl) {
    document.getElementById('modalClienteTitle').innerHTML =
        '<i class="fas fa-pen" style="color:var(--accent);"></i> Editar Cliente';
    document.getElementById('clienteAction').value = 'editar';
    document.getElementById('clienteId').value     = cl.id;

    document.getElementById('codigoCl').value         = cl.codigo;
    document.getElementById('cl_nombre').value         = cl.nombre;
    document.getElementById('cl_apellidos').value      = cl.apellidos;
    document.getElementById('cl_cedula').value         = cl.cedula;
    document.getElementById('cl_telefono1').value      = cl.telefono1;
    document.getElementById('cl_telefono2').value      = cl.telefono2 || '';
    document.getElementById('cl_telefono3').value      = cl.telefono3 || '';
    document.getElementById('cl_direccion').value      = cl.direccion;
    document.getElementById('cl_email').value          = cl.email || '';
    document.getElementById('cl_fecha_nacimiento').value = cl.fecha_nacimiento || '';
    document.getElementById('cl_fecha_registro').value   = cl.fecha_registro
        ? cl.fecha_registro.split(' ')[0] : '';
    document.getElementById('cl_cobrador_id').value   = cl.cobrador_id || '';
    document.getElementById('cl_vendedor_id').value   = cl.vendedor_id || '';
    document.getElementById('cl_notas').value         = cl.notas || '';
    document.getElementById('cl_estado').value        = cl.estado || 'activo';
    document.getElementById('estadoGrp').style.display = 'block';

    document.getElementById('modalCliente').classList.add('open');
    document.body.style.overflow = 'hidden';
}

/* ============================================================
   MODAL DEPENDIENTES
   ============================================================ */
function abrirModalDependientes(cliente) {
    clienteSeleccionado  = cliente;
    contratoSeleccionado = null;

    document.getElementById('depModalNombre').textContent =
        cliente.nombre + '  ·  Cód. ' + cliente.codigo;

    const body = document.getElementById('depModalBody');
    body.innerHTML = '<div class="spinner"></div>';

    document.getElementById('modalDependientes').classList.add('open');
    document.body.style.overflow = 'hidden';

    cargarDependientes(cliente.id);
}

function cerrarModalDependientes() {
    document.getElementById('modalDependientes').classList.remove('open');
    document.body.style.overflow = '';
    clienteSeleccionado  = null;
    contratoSeleccionado = null;
}

function cargarDependientes(clienteId) {
    fetch('get_dependientes.php?cliente_id=' + clienteId)
        .then(r => r.json())
        .then(data => renderDependientes(data))
        .catch(() => {
            document.getElementById('depModalBody').innerHTML =
                '<div class="dep-empty"><i class="fas fa-triangle-exclamation"></i>Error al cargar dependientes</div>';
        });
}

function cargarDependientesContrato(contratoId) {
    fetch('get_dependientes.php?contrato_id=' + contratoId)
        .then(r => r.json())
        .then(data => renderDependientes(data))
        .catch(() => {
            document.getElementById('depModalBody').innerHTML =
                '<div class="dep-empty"><i class="fas fa-triangle-exclamation"></i>Error al cargar dependientes</div>';
        });
}

function renderDependientes(data) {
    const body = document.getElementById('depModalBody');

    if (!data.success) {
        body.innerHTML = `<div class="dep-empty"><i class="fas fa-circle-exclamation"></i>${data.message}</div>`;
        return;
    }

    // Si hay múltiples contratos, mostrar selector
    if (data.multiple_contratos) {
        let html = `
            <div style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:var(--gray-700);display:block;margin-bottom:6px;">
                    Este cliente tiene varios contratos. Seleccione uno:
                </label>
                <select class="form-control" id="selectorContratoModal"
                        onchange="seleccionarContratoModal(this.value)"
                        style="max-width:360px;">
                    <option value="">— Seleccione contrato —</option>
                    ${data.contratos.map(c => `<option value="${c.id}">${c.numero_contrato}</option>`).join('')}
                </select>
            </div>
            <div id="listaDepsContrato"></div>`;
        body.innerHTML = html;
        return;
    }

    contratoSeleccionado = data.contrato_actual;
    const deps = data.dependientes;

    let html = `
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
            <div style="font-size:13px;color:var(--gray-500);">
                Contrato: <strong style="color:var(--accent);">${data.contrato_actual?.numero_contrato || '—'}</strong>
                &nbsp;·&nbsp;
                <strong>${deps.length}</strong> dependiente${deps.length !== 1 ? 's' : ''} activo${deps.length !== 1 ? 's' : ''}
            </div>
            <button class="btn btn-primary btn-sm" onclick="abrirFormNuevoDep()">
                <i class="fas fa-plus"></i> Nuevo Dependiente
            </button>
        </div>`;

    if (deps.length === 0) {
        html += `<div class="dep-empty">
            <i class="fas fa-user-group"></i>
            <div style="font-size:13px;font-weight:600;margin-top:4px;">Sin dependientes activos</div>
            <div style="font-size:12px;margin-top:4px;">Agrega el primer dependiente para este contrato.</div>
        </div>`;
    } else {
        html += `<div style="display:flex;flex-direction:column;gap:6px;">`;
        deps.forEach(d => {
            const ini = (d.nombre[0] + (d.apellidos[0] || '')).toUpperCase();
            html += `
            <div class="dep-card">
                <div class="dep-avatar">${ini}</div>
                <div class="dep-info">
                    <div class="dep-name">${escHtml(d.nombre + ' ' + d.apellidos)}</div>
                    <div class="dep-meta">
                        ${escHtml(d.relacion)} &nbsp;·&nbsp;
                        ${d.edad} años &nbsp;·&nbsp;
                        Plan: ${escHtml(d.plan_nombre)}
                        ${d.es_geriatrico ? '<span class="badge badge-pendiente" style="margin-left:4px;">Geriátrico</span>' : ''}
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <button class="btn-action edit" title="Editar" onclick="abrirFormEditarDep(${JSON.stringify(d).replace(/"/g, '&quot;')})">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button class="btn-action delete" title="Eliminar" onclick="eliminarDependiente(${d.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>`;
        });
        html += `</div>`;
    }

    body.innerHTML = html;
}

function seleccionarContratoModal(contratoId) {
    if (!contratoId) return;
    const sub = document.getElementById('listaDepsContrato');
    if (sub) sub.innerHTML = '<div class="spinner"></div>';
    cargarDependientesContrato(contratoId);
}

/* ============================================================
   FORMULARIO DE DEPENDIENTE — Nuevo / Editar
   ============================================================ */
function abrirFormNuevoDep() {
    document.getElementById('formDepTitle').innerHTML =
        '<i class="fas fa-user-plus" style="color:#16A34A;"></i> Nuevo Dependiente';
    document.getElementById('dep_id').value = '';
    document.getElementById('formDependiente').reset();
    document.getElementById('dep_fecha_registro').valueAsDate = new Date();
    document.getElementById('depEstadoGrp').style.display = 'none';
    document.getElementById('aviso_geriatrico').style.display = 'none';
    document.getElementById('dep_plan_id').disabled = false;

    // Manejo de contrato
    if (contratoSeleccionado) {
        document.getElementById('dep_contrato_id').value = contratoSeleccionado.id;
        document.getElementById('contratoSelectorWrap').style.display = 'none';
    } else if (clienteSeleccionado) {
        // Recargar contratos del cliente para el selector
        fetch('get_dependientes.php?cliente_id=' + clienteSeleccionado.id)
            .then(r => r.json())
            .then(d => {
                if (d.multiple_contratos) {
                    const sel = document.getElementById('dep_selector_contrato');
                    sel.innerHTML = '<option value="">— Seleccione contrato —</option>' +
                        d.contratos.map(c => `<option value="${c.id}">${c.numero_contrato}</option>`).join('');
                    document.getElementById('contratoSelectorWrap').style.display = 'block';
                } else if (d.contrato_actual) {
                    document.getElementById('dep_contrato_id').value = d.contrato_actual.id;
                    document.getElementById('contratoSelectorWrap').style.display = 'none';
                }
            });
    }

    document.getElementById('modalFormDep').classList.add('open');
}

function abrirFormEditarDep(dep) {
    document.getElementById('formDepTitle').innerHTML =
        '<i class="fas fa-pen" style="color:#16A34A;"></i> Editar Dependiente';

    document.getElementById('dep_id').value              = dep.id;
    document.getElementById('dep_contrato_id').value     = dep.contrato_id;
    document.getElementById('dep_nombre').value          = dep.nombre;
    document.getElementById('dep_apellidos').value       = dep.apellidos;
    document.getElementById('dep_identificacion').value  = dep.identificacion;
    document.getElementById('dep_relacion').value        = dep.relacion;
    document.getElementById('dep_fecha_nacimiento').value= dep.fecha_nacimiento;
    document.getElementById('dep_fecha_registro').value  = dep.fecha_registro;
    document.getElementById('dep_telefono').value        = dep.telefono || '';
    document.getElementById('dep_email').value           = dep.email || '';
    document.getElementById('dep_plan_id').value         = dep.plan_id;
    document.getElementById('dep_estado').value          = dep.estado || 'activo';
    document.getElementById('depEstadoGrp').style.display = 'block';
    document.getElementById('contratoSelectorWrap').style.display = 'none';

    verificarEdadDepPlan();

    document.getElementById('modalFormDep').classList.add('open');
}

function cerrarFormDep() {
    document.getElementById('modalFormDep').classList.remove('open');
}

function verificarEdadDepPlan() {
    const fn  = document.getElementById('dep_fecha_nacimiento').value;
    if (!fn) return;
    const edad = calcularEdad(fn);
    const aviso = document.getElementById('aviso_geriatrico');
    const planSel = document.getElementById('dep_plan_id');

    if (edad >= 65) {
        planSel.value    = '5'; // ID plan geriátrico
        planSel.disabled = true;
        aviso.style.display = 'flex';
    } else {
        planSel.disabled = false;
        aviso.style.display = 'none';
    }
}

function guardarDependiente() {
    const form     = document.getElementById('formDependiente');
    const formData = new FormData(form);
    const isEdit   = !!document.getElementById('dep_id').value;
    const contratoId = document.getElementById('dep_contrato_id').value;

    if (!contratoId) {
        mostrarToast('Debe seleccionar un contrato', 'error');
        return;
    }

    formData.append('action', isEdit ? 'editar' : 'crear');
    // Si el plan está disabled, agregar manualmente
    if (document.getElementById('dep_plan_id').disabled) {
        formData.set('plan_id', '5');
    }

    fetch('dependientes.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                cerrarFormDep();
                mostrarToast(data.message || 'Dependiente guardado exitosamente', 'success');
                // Recargar lista de dependientes
                if (contratoSeleccionado) {
                    cargarDependientesContrato(contratoSeleccionado.id);
                } else if (clienteSeleccionado) {
                    cargarDependientes(clienteSeleccionado.id);
                }
                // Actualizar el contador en la tabla sin recargar
                setTimeout(() => actualizarContadorDep(clienteSeleccionado?.id), 600);
            } else {
                mostrarToast(data.message || 'Error al guardar el dependiente', 'error');
            }
        })
        .catch(() => mostrarToast('Error de comunicación con el servidor', 'error'));
}

function eliminarDependiente(id) {
    if (!confirm('¿Está seguro de que desea eliminar este dependiente?')) return;

    fetch('dependientes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'eliminar', id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            mostrarToast('Dependiente eliminado', 'success');
            if (contratoSeleccionado) {
                cargarDependientesContrato(contratoSeleccionado.id);
            } else if (clienteSeleccionado) {
                cargarDependientes(clienteSeleccionado.id);
            }
            setTimeout(() => actualizarContadorDep(clienteSeleccionado?.id), 600);
        } else {
            mostrarToast(data.message || 'Error al eliminar', 'error');
        }
    })
    .catch(() => mostrarToast('Error de comunicación', 'error'));
}

/* Actualiza el badge de contador en la fila de la tabla sin recargar */
function actualizarContadorDep(clienteId) {
    if (!clienteId) { location.reload(); return; }
    fetch('get_dependientes.php?cliente_id=' + clienteId)
        .then(r => r.json())
        .then(data => {
            // Encontrar todos los botones de dep del cliente
            document.querySelectorAll('.cl-check').forEach(cb => {
                if (cb.value == clienteId) {
                    const fila  = cb.closest('tr');
                    const badge = fila?.querySelector('.deps-count');
                    if (badge) {
                        const cant = data.dependientes?.length ?? 0;
                        badge.textContent = cant;
                        badge.className   = 'deps-count' + (cant === 0 ? ' zero' : '');
                    }
                }
            });
        })
        .catch(() => location.reload());
}

/* ============================================================
   DESACTIVAR CLIENTE
   ============================================================ */
function confirmarDesactivar(cl) {
    clienteADesactivar = cl;
    document.getElementById('desactivarDetalles').innerHTML = `
        <p><strong>Cliente:</strong> ${escHtml(cl.nombre + ' ' + cl.apellidos)}</p>
        <p><strong>Cédula:</strong>  ${escHtml(cl.cedula)}</p>
        <p><strong>Código:</strong>  ${escHtml(cl.codigo)}</p>
    `;
    document.getElementById('modalDesactivar').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function cerrarModalDesactivar() {
    document.getElementById('modalDesactivar').classList.remove('open');
    document.body.style.overflow = '';
    clienteADesactivar = null;
}

function ejecutarDesactivar() {
    if (!clienteADesactivar) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `
        <input type="hidden" name="action" value="desactivar">
        <input type="hidden" name="id"     value="${clienteADesactivar.id}">
    `;
    document.body.appendChild(f);
    f.submit();
}

/* ============================================================
   CERRAR MODALES CON CLIC FUERA O ESC
   ============================================================ */
['modalCliente','modalDependientes','modalFormDep','modalDesactivar'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['modalCliente','modalDependientes','modalFormDep','modalDesactivar'].forEach(id => {
            document.getElementById(id).classList.remove('open');
        });
        document.body.style.overflow = '';
    }
});

/* ============================================================
   UTILIDADES
   ============================================================ */
function calcularEdad(fecha) {
    const hoy  = new Date();
    const nac  = new Date(fecha);
    let edad   = hoy.getFullYear() - nac.getFullYear();
    const m    = hoy.getMonth() - nac.getMonth();
    if (m < 0 || (m === 0 && hoy.getDate() < nac.getDate())) edad--;
    return edad;
}

function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

// Formateo automático cédula dominicana
document.getElementById('cl_cedula').addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '').substr(0, 11);
    if (v.length > 3)  v = v.substr(0,3) + '-' + v.substr(3);
    if (v.length > 11) v = v.substr(0,11) + '-' + v.substr(11);
    this.value = v;
});
</script>

<?php require_once 'footer.php'; ?>