<?php
require_once 'header.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Obtener lista de cobradores activos
$stmt = $conn->query("
    SELECT id, codigo, nombre_completo 
    FROM cobradores 
    WHERE estado = 'activo' 
    ORDER BY nombre_completo
");
$cobradores = $stmt->fetchAll();

// Calcular estadísticas para las tarjetas superiores
$sql_stats = "
    SELECT 
        COUNT(DISTINCT CASE WHEN af.estado = 'activa' AND f.estado IN ('pendiente', 'incompleta') THEN af.id END) as total_asignadas_pendientes,
        COUNT(DISTINCT CASE WHEN f.estado = 'pendiente' THEN f.id END) as total_pendientes,
        SUM(CASE WHEN f.estado IN ('pendiente', 'incompleta') THEN f.monto ELSE 0 END) as monto_pendiente,
        SUM(CASE WHEN af.estado = 'activa' AND f.estado IN ('pendiente', 'incompleta') THEN f.monto ELSE 0 END) as monto_total_asignadas
    FROM facturas f
    LEFT JOIN asignaciones_facturas af ON f.id = af.factura_id 
    WHERE af.estado = 'activa' OR f.estado IN ('pendiente', 'incompleta')
";
$stats = $conn->query($sql_stats)->fetch();

// Procesar filtros de búsqueda
$where = "1=1";
$params = [];

if (isset($_GET['contrato']) && !empty($_GET['contrato'])) {
    $where .= " AND c.numero_contrato LIKE ?";
    $params[] = '%' . $_GET['contrato'] . '%';
}

if (isset($_GET['cobrador_id']) && !empty($_GET['cobrador_id'])) {
    $where .= " AND af.cobrador_id = ?";
    $params[] = $_GET['cobrador_id'];
}

if (isset($_GET['fecha_desde']) && !empty($_GET['fecha_desde'])) {
    $where .= " AND af.fecha_asignacion >= ?";
    $params[] = $_GET['fecha_desde'];
}

if (isset($_GET['fecha_hasta']) && !empty($_GET['fecha_hasta'])) {
    $where .= " AND af.fecha_asignacion <= ?";
    $params[] = $_GET['fecha_hasta'];
}

if (isset($_GET['estado']) && !empty($_GET['estado'])) {
    $where .= " AND f.estado = ?";
    $params[] = $_GET['estado'];
}

// Configuración de paginación
$por_pagina = isset($_COOKIE['asignaciones_por_pagina']) ? (int)$_COOKIE['asignaciones_por_pagina'] : 50;
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_actual - 1) * $por_pagina;

// Obtener el total de registros según los filtros
$sql_total = "
    SELECT COUNT(*) as total
    FROM asignaciones_facturas af
    JOIN facturas f ON af.factura_id = f.id
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN cobradores co ON af.cobrador_id = co.id
    WHERE $where
";

$stmt_total = $conn->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = $stmt_total->fetch()['total'];
$total_paginas = ceil($total_registros / $por_pagina);



// Consulta principal para obtener las facturas asignadas
$sql = "
    SELECT 
        af.id as asignacion_id,
        af.fecha_asignacion,
        f.numero_factura,
        c.numero_contrato,
        cl.nombre as cliente_nombre,
        cl.apellidos as cliente_apellidos,
        f.mes_factura,
        c.dia_cobro,
        f.monto,
        f.estado,
        co.nombre_completo as cobrador_nombre
    FROM asignaciones_facturas af
    JOIN facturas f ON af.factura_id = f.id
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN cobradores co ON af.cobrador_id = co.id
    WHERE $where
    ORDER BY af.fecha_asignacion DESC, co.nombre_completo
    LIMIT $por_pagina
    OFFSET $offset
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$asignaciones = $stmt->fetchAll();
?>

<div class="asignaciones-container">
    <!-- Tarjetas de estadísticas -->
    <div class="dashboard-stats fade-in">
        <div class="stat-card blue">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-info">
                    <h3>Total Facturas</h3>
                    <p class="stat-value"><?php echo number_format($stats['total_pendientes'] ?? 0); ?></p>
                    <p class="stat-label">Registradas en el sistema</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-file-invoice"></i></div>
        </div>
        <div class="stat-card green">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-info">
                    <h3>Asignadas</h3>
                    <p class="stat-value"><?php echo number_format($stats['total_asignadas_pendientes'] ?? 0); ?></p>
                    <p class="stat-label">Con cobrador asignado</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-user-check"></i></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-info">
                    <h3>Monto Total</h3>
                    <p class="stat-value">RD$<?php echo number_format($stats['monto_pendiente'] ?? 0, 2); ?></p>
                    <p class="stat-label">Pendiente de cobro</p>
                </div>
            </div>
            <div class="stat-trend down"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
        <div class="stat-card red">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-info">
                    <h3>Asignado</h3>
                    <p class="stat-value">RD$<?php echo number_format($stats['monto_total_asignadas'] ?? 0, 2); ?></p>
                    <p class="stat-label">Valor en gestión de cobro</p>
                </div>
            </div>
            <div class="stat-trend down"><i class="fas fa-dollar-sign"></i></div>
        </div>
    </div>

    <!-- Card principal -->
    <div class="card">
        <div class="card-header">
            <h2>Gestión de Asignaciones</h2>
            <div class="header-buttons">
                <div class="button-group left">
                    <button class="btn btn-info" onclick="mostrarModalImpresion()">
                        <i class="fas fa-print"></i> Imprimir Relación
                    </button>
                    <button id="btnEliminarGrupo" class="btn btn-danger" onclick="eliminarGrupo()" disabled>
                        <i class="fas fa-trash"></i> Eliminar <span id="contadorSeleccionadas"></span>
                    </button>
                </div>
                <div class="button-group right">
                    <button class="btn btn-primary" onclick="mostrarModalAsignacion()">
                        <i class="fas fa-plus"></i> Asignar Facturas
                    </button>
                    <button id="btnReasignarGrupo" class="btn btn-warning" onclick="reasignarGrupo()" disabled>
                        <i class="fas fa-exchange-alt"></i> Reasignar <span id="contadorSeleccionadasReasignar"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Filtros de búsqueda -->
        <div class="filtros-bar fade-in">
            <div class="search-wrap">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="contratoSearch" placeholder="Buscar por contrato o cliente..."
                       value="<?php echo isset($_GET['contrato']) ? htmlspecialchars($_GET['contrato']) : ''; ?>">
            </div>

            <select id="cobradorFilter" class="filter-select" name="cobrador_id">
                <option value="">Todos los cobradores</option>
                <?php foreach ($cobradores as $cobrador): ?>
                    <option value="<?php echo $cobrador['id']; ?>"
                            <?php echo ($_GET['cobrador_id'] ?? '') == $cobrador['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cobrador['codigo'] . ' - ' . $cobrador['nombre_completo']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="estadoFilter" class="filter-select" name="estado">
                <option value="" <?php echo (empty($_GET['estado'])) ? 'selected' : ''; ?>>Todos los estados</option>
                <option value="pendiente" <?php echo ($_GET['estado'] ?? '') === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
                <option value="incompleta" <?php echo ($_GET['estado'] ?? '') === 'incompleta' ? 'selected' : ''; ?>>Incompletas</option>
                <option value="pagada" <?php echo ($_GET['estado'] ?? '') === 'pagada' ? 'selected' : ''; ?>>Pagadas</option>
            </select>

            <button class="btn btn-secondary btn-sm" onclick="limpiarFiltros()">
                <i class="fas fa-rotate-right"></i> Limpiar
            </button>

            <!-- Botón Búsqueda Avanzada -->
            <button class="btn btn-secondary btn-sm" id="toggleAdvancedFilters">
                <i class="fas fa-sliders-h"></i> Filtros avanzados
            </button>

            <!-- Filtros avanzados - desplegable -->
            <div class="advanced-filters" id="advancedFilters" style="display:none; margin-top: 15px;">
                <div class="filter-row">
                    <div class="filter-item">
                        <label for="fecha_desde">Fecha Desde</label>
                        <input type="date" id="fecha_desde" name="fecha_desde" class="form-control"
                               value="<?php echo $_GET['fecha_desde'] ?? ''; ?>">
                    </div>
                    <div class="filter-item">
                        <label for="fecha_hasta">Fecha Hasta</label>
                        <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control"
                               value="<?php echo $_GET['fecha_hasta'] ?? ''; ?>">
                    </div>
                    <div class="filter-item">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary" onclick="aplicarFiltros()">
                            <i class="fas fa-search"></i> Aplicar filtros
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de facturas asignadas -->
        <div class="clientes-table-wrap fade-in delay-1">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-tasks"></i> Asignación de Facturas</div>
                    <div class="card-subtitle">Mostrando <?php echo min($offset + 1, $total_registros); ?>–<?php echo min($offset + $por_pagina, $total_registros); ?> de <?php echo number_format($total_registros); ?> registros</div>
                </div>
            </div>
            <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="seleccionarTodas" onclick="seleccionarTodasFacturas(this)"></th>
                        <th>Fecha</th>
                        <th>Factura</th>
                        <th>Contrato</th>
                        <th>Cliente</th>
                        <th>Mes</th>
                        <th>Día Pago</th>
                        <th>Monto</th>
                        <th>Cobrador</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($asignaciones as $asignacion): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="seleccion-factura" 
                                       data-asignacion-id="<?php echo $asignacion['asignacion_id']; ?>" 
                                       onchange="actualizarContadorSeleccionadas()">
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($asignacion['fecha_asignacion'])); ?></td>
                            <td><?php echo htmlspecialchars($asignacion['numero_factura']); ?></td>
                            <td><?php echo htmlspecialchars($asignacion['numero_contrato']); ?></td>
                            <td><?php echo htmlspecialchars($asignacion['cliente_nombre'] . ' ' . $asignacion['cliente_apellidos']); ?></td>
                            <td><?php 
                                $mes_anio = explode('/', $asignacion['mes_factura']);
                                $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
                                if (count($mes_anio) == 2) {
                                    $mes = intval($mes_anio[0]);
                                    $anio = $mes_anio[1];
                                    echo $meses[$mes - 1] . '/' . $anio;
                                } else {
                                    echo htmlspecialchars($asignacion['mes_factura']);
                                }
                            ?></td>
                            <td><?php echo $asignacion['dia_cobro']; ?></td>
                            <td>RD$<?php echo number_format($asignacion['monto'], 2); ?></td>
                            <td><?php echo htmlspecialchars($asignacion['cobrador_nombre']); ?></td>
                            <td>
                                <span class="status <?php 
                                    echo $asignacion['estado'] == 'pagada' ? 'active' : 
                                         ($asignacion['estado'] == 'pendiente' ? 'warning' : 
                                         ($asignacion['estado'] == 'incompleta' ? 'pending' : 'inactive')); 
                                ?>">
                                    <?php echo ucfirst($asignacion['estado']); ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <button class="btn-action edit" title="Reasignar factura" 
                                        onclick="reasignarFactura(<?php echo $asignacion['asignacion_id']; ?>)">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                                <button class="btn-action delete" title="Eliminar asignación" 
                                        onclick="eliminarAsignacion(<?php echo $asignacion['asignacion_id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>


            <!-- Paginador -->
            <?php if ($total_registros > 0): ?>
            <div class="paginador-wrap">
                <!-- Info -->
                <div class="paginador-info">
                    Mostrando <strong><?php echo min($offset + 1, $total_registros); ?></strong>–<strong><?php echo min($offset + $por_pagina, $total_registros); ?></strong>
                    de <strong><?php echo number_format($total_registros); ?></strong> asignaciones
                </div>

                <!-- Páginas -->
                <?php
                // Función para construir URL con parámetros actuales
                function buildUrlAsignacion($pagina) {
                    global $_GET;
                    $p = ['pagina' => $pagina];
                    if (!empty($_GET['contrato']))     $p['contrato']     = $_GET['contrato'];
                    if (!empty($_GET['cobrador_id']))  $p['cobrador_id']  = $_GET['cobrador_id'];
                    if (!empty($_GET['fecha_desde']))  $p['fecha_desde']  = $_GET['fecha_desde'];
                    if (!empty($_GET['fecha_hasta']))  $p['fecha_hasta']  = $_GET['fecha_hasta'];
                    if (!empty($_GET['estado']))       $p['estado']       = $_GET['estado'];
                    return 'asignacion.php?' . http_build_query($p);
                }
                $rango = 2; // páginas a mostrar a cada lado de la actual
                ?>
                <div class="paginador-pages">
                    <!-- Primera / Anterior -->
                    <a href="<?php echo buildUrlAsignacion(1); ?>"
                       class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>" title="Primera">
                        <i class="fas fa-angles-left" style="font-size:10px;"></i>
                    </a>
                    <a href="<?php echo buildUrlAsignacion($pagina_actual - 1); ?>"
                       class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>" title="Anterior">
                        <i class="fas fa-angle-left" style="font-size:11px;"></i>
                    </a>

                    <?php
                    // Calcula rango de páginas a mostrar
                    $inicio = max(1, $pagina_actual - $rango);
                    $fin    = min($total_paginas, $pagina_actual + $rango);

                    if ($inicio > 1): ?>
                        <a href="<?php echo buildUrlAsignacion(1); ?>" class="pag-btn">1</a>
                        <?php if ($inicio > 2): ?><span class="pag-btn ellipsis">…</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $inicio; $p <= $fin; $p++): ?>
                        <a href="<?php echo buildUrlAsignacion($p); ?>"
                           class="pag-btn <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($fin < $total_paginas): ?>
                        <?php if ($fin < $total_paginas - 1): ?><span class="pag-btn ellipsis">…</span><?php endif; ?>
                        <a href="<?php echo buildUrlAsignacion($total_paginas); ?>"
                           class="pag-btn"><?php echo $total_paginas; ?></a>
                    <?php endif; ?>

                    <!-- Siguiente / Última -->
                    <a href="<?php echo buildUrlAsignacion($pagina_actual + 1); ?>"
                       class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>" title="Siguiente">
                        <i class="fas fa-angle-right" style="font-size:11px;"></i>
                    </a>
                    <a href="<?php echo buildUrlAsignacion($total_paginas); ?>"
                       class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>" title="Última">
                        <i class="fas fa-angles-right" style="font-size:10px;"></i>
                    </a>
                </div>

                <!-- Registros por página -->
                <div class="paginador-rpp">
                    <span>Mostrar:</span>
                    <select id="itemsPerPage" onchange="cambiarRegistrosPorPagina(this.value)">
                        <option value="25" <?php echo $por_pagina == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $por_pagina == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $por_pagina == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $por_pagina == 200 ? 'selected' : ''; ?>>200</option>
                    </select>
                    <span>registros</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        
    </div>



<!-- Modal de Asignación de Facturas -->
<div class="modal" id="modalAsignacion">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Asignar Facturas</h4>
                <button type="button" class="close" onclick="cerrarModalAsignacion()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="formAsignacion">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="cobrador_asignacion">Cobrador</label>
                            <select id="cobrador_asignacion" class="form-control" required>
                                <option value="">Seleccione un cobrador</option>
                                <?php foreach ($cobradores as $cobrador): ?>
                                    <option value="<?php echo $cobrador['id']; ?>">
                                        <?php echo htmlspecialchars($cobrador['codigo'] . ' - ' . $cobrador['nombre_completo']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="fecha_asignacion">Fecha de Asignación</label>
                            <input type="date" id="fecha_asignacion" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="numero_factura">Número de Factura</label>
                        <div class="input-group">
                            <input type="text" id="numero_factura" class="form-control" 
                                   placeholder="Ingrese el número de factura y presione Enter">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-primary" onclick="agregarFactura()">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de facturas a asignar -->
                    <div id="lista_facturas" class="table-responsive" style="display: none;">
                        <h5>Facturas seleccionadas:</h5>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>No. Factura</th>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Mes</th>
                                    <th>Monto</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="facturas_seleccionadas"></tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalAsignacion()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="guardarAsignacion()">
                    Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Impresión -->
<div class="modal" id="modalImpresion">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Imprimir Relación de Facturas</h4>
                <button type="button" class="close" onclick="cerrarModalImpresion()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="formImpresion">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="cobrador_impresion">Cobrador</label>
                            <select id="cobrador_impresion" class="form-control" required>
                                <option value="">Seleccione un cobrador</option>
                                <?php foreach ($cobradores as $cobrador): ?>
                                    <option value="<?php echo $cobrador['id']; ?>">
                                        <?php echo htmlspecialchars($cobrador['codigo'] . ' - ' . $cobrador['nombre_completo']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="fecha_impresion">Fecha</label>
                            <input type="date" id="fecha_impresion" class="form-control" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="estado_impresion">Estado</label>
                            <select id="estado_impresion" class="form-control">
                                <option value="">Todos</option>
                                <option value="pendiente">Pendiente</option>
                                <option value="pagada">Pagada</option>
                                <option value="vencida">Vencida</option>
                                <option value="incompleta">Incompleta</option>
                                <option value="anulada">Anulada</option>
                            </select>
                        </div>
                    </div>
                </form>

                <!-- Preview de facturas a imprimir -->
                <div id="preview_facturas" style="display: none;">
                    <h5>Facturas encontradas:</h5>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>No. Factura</th>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Mes</th>
                                    <th>Monto</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody id="facturas_imprimir"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalImpresion()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-info" onclick="previewImpresion()">
                    Vista Previa
                </button>
                <button type="button" class="btn btn-primary" onclick="imprimirRelacion()">
                    Imprimir
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmación de Reasignación -->
<div class="modal" id="modalReasignacion">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reasignar Factura</h5>
                <button type="button" class="close" onclick="cerrarModalReasignacion()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Esta factura ya está asignada:</p>
                <div id="info_asignacion_actual" class="alert alert-info"></div>
                <form id="formReasignacion">
                    <input type="hidden" id="asignacion_id">
                    <div class="form-group">
                        <label for="nuevo_cobrador">Nuevo Cobrador</label>
                        <select id="nuevo_cobrador" class="form-control" required>
                            <option value="">Seleccione un cobrador</option>
                            <?php foreach ($cobradores as $cobrador): ?>
                                <option value="<?php echo $cobrador['id']; ?>">
                                    <?php echo htmlspecialchars($cobrador['codigo'] . ' - ' . $cobrador['nombre_completo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nueva_fecha">Nueva Fecha</label>
                        <input type="date" id="nueva_fecha" class="form-control" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalReasignacion()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmarReasignacion()">
                    Confirmar Reasignación
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Información de Factura Ya Asignada -->
<div class="modal" id="modalFacturaAsignada">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Factura Ya Asignada</h5>
                <button type="button" class="close" onclick="cerrarModalFacturaAsignada()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <div id="info_factura_asignada"></div>
                </div>
                <p>¿Desea reasignar esta factura?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalFacturaAsignada()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmarReasignacionDesdeVerificacion()">
                    Confirmar Reasignación
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmación de Eliminación -->
<div class="modal" id="modalConfirmarEliminar">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar Eliminación</h5>
                <button type="button" class="close" onclick="cerrarModalConfirmarEliminar()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <p>¿Está seguro que desea eliminar esta asignación? Esta acción no se puede deshacer.</p>
                </div>
                <input type="hidden" id="asignacion_eliminar_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalConfirmarEliminar()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-danger" onclick="confirmarEliminarAsignacion()" style="background-color: #dc3545; border-color: #dc3545; color: white;">
                    Eliminar
                </button>
            </div>
        </div>
    </div>
</div>


<!-- Agregar después de los otros modales -->
<div class="modal" id="modalConfirmarEliminarGrupo">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar Eliminación Múltiple</h5>
                <button type="button" class="close" onclick="this.closest('.modal').classList.remove('show')">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <p>¿Está seguro que desea eliminar las asignaciones seleccionadas? Esta acción no se puede deshacer.</p>
                </div>
                <input type="hidden" id="asignaciones_eliminar_ids">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" 
                        onclick="this.closest('.modal').classList.remove('show')">
                    Cancelar
                </button>
                <button type="button" class="btn btn-danger" onclick="confirmarEliminarGrupo()">
                    Eliminar
                </button>
            </div>
        </div>
    </div>
</div>


<!-- Modal de Reasignación Masiva -->
<div class="modal" id="modalReasignacionGrupo">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reasignar Facturas en Grupo</h5>
                <button type="button" class="close" onclick="cerrarModalReasignacionGrupo()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Seleccione el nuevo cobrador y fecha para las asignaciones seleccionadas:</p>
                <form id="formReasignacionGrupo">
                    <input type="hidden" id="asignaciones_reasignar_ids">
                    <div class="form-group">
                        <label for="nuevo_cobrador_grupo">Nuevo Cobrador</label>
                        <select id="nuevo_cobrador_grupo" class="form-control" required>
                            <option value="">Seleccione un cobrador</option>
                            <?php foreach ($cobradores as $cobrador): ?>
                                <option value="<?php echo $cobrador['id']; ?>">
                                    <?php echo htmlspecialchars($cobrador['codigo'] . ' - ' . $cobrador['nombre_completo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nueva_fecha_grupo">Nueva Fecha</label>
                        <input type="date" id="nueva_fecha_grupo" class="form-control" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalReasignacionGrupo()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmarReasignacionGrupo()">
                    Confirmar Reasignación
                </button>
            </div>
        </div>
    </div>
</div>



<script>


function formatearContrato(numero) {
    return numero.toString().padStart(5, '0');
}


//funcion para determinar el mes de las facturas
function formatearMes(mesFactura) {
    if (!mesFactura) return '';
    
    const meses = {
        '01': 'Ene', '02': 'Feb', '03': 'Mar', '04': 'Abr', 
        '05': 'May', '06': 'Jun', '07': 'Jul', '08': 'Ago',
        '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dic'
    };
    
    const [mes, año] = mesFactura.split('/');
    return `${meses[mes] || mes}/${año}`;
}




// Variable global para almacenar la factura que se está intentando reasignar
let facturaParaReasignar = null;

function mostrarModalFacturaAsignada(factura, asignacionActual) {
    facturaParaReasignar = factura;
    
    // Formatear la fecha
    const fecha = new Date(asignacionActual.fecha_asignacion + 'T00:00:00');
    const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    const fechaFormateada = `${fecha.getDate().toString().padStart(2, '0')}/${meses[fecha.getMonth()]}/${fecha.getFullYear()}`;
    
    document.getElementById('info_factura_asignada').innerHTML = `
        <strong>Factura:</strong> ${factura.numero_factura}<br>
        <strong>Cliente:</strong> ${factura.cliente_nombre} ${factura.cliente_apellidos}<br>
        <strong>Contrato:</strong> ${factura.numero_contrato}<br>
        <strong>Monto:</strong> RD$${parseFloat(factura.monto).toFixed(2)}<br>
        <strong>Cobrador actual:</strong> ${asignacionActual.cobrador_nombre}<br>
        <strong>Fecha actual:</strong> ${fechaFormateada}
    `;
    
    document.getElementById('modalFacturaAsignada').classList.add('show');
}

function cerrarModalFacturaAsignada() {
    document.getElementById('modalFacturaAsignada').classList.remove('show');
    facturaParaReasignar = null;
}

async function confirmarReasignacionDesdeVerificacion() {
    if (facturaParaReasignar) {
        try {
            // Primero eliminar la asignación existente
            const response = await fetch('eliminar_asignacion_factura.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    factura_id: facturaParaReasignar.id
                })
            });

            const data = await response.json();
            
            if (data.success) {
                // Una vez eliminada la asignación anterior, agregar la nueva
                agregarFacturaALista(facturaParaReasignar);
                cerrarModalFacturaAsignada();
            } else {
                mostrarToast('Error al eliminar la asignación anterior', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            mostrarToast('Error al procesar la reasignación', 'error');
        }
    }
}

function agregarFacturaALista(factura) {
    if (facturasSeleccionadas.some(f => f.id === factura.id)) {
        mostrarToast('Esta factura ya está en la lista', 'warning');
        return;
    }

    // Agregar al inicio del array en lugar del final
    facturasSeleccionadas.unshift(factura);
    actualizarTablaFacturas();
}

// Función para eliminar asignación
function eliminarAsignacion(asignacionId) {
    document.getElementById('asignacion_eliminar_id').value = asignacionId;
    document.getElementById('modalConfirmarEliminar').classList.add('show');
    document.body.style.overflow = 'hidden';
}

// Función para cerrar el modal de confirmación
function cerrarModalConfirmarEliminar() {
    document.getElementById('modalConfirmarEliminar').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('asignacion_eliminar_id').value = '';
}

// Función para procesar la eliminación
// Función para mostrar el modal de confirmación
function eliminarAsignacion(asignacionId) {
    document.getElementById('asignacion_eliminar_id').value = asignacionId;
    document.getElementById('modalConfirmarEliminar').classList.add('show');
    document.body.style.overflow = 'hidden';
}

// Función para cerrar el modal de confirmación
function cerrarModalConfirmarEliminar() {
    document.getElementById('modalConfirmarEliminar').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('asignacion_eliminar_id').value = '';
}

// Función para procesar la eliminación
async function confirmarEliminarAsignacion() {
    const asignacionId = document.getElementById('asignacion_eliminar_id').value;
    
    try {
        const response = await fetch('eliminar_asignacion.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ asignacion_id: asignacionId })
        });

        const data = await response.json();

        if (data.success) {
            mostrarToast('Asignación eliminada exitosamente', 'success');
            cerrarModalConfirmarEliminar();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            mostrarToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al eliminar la asignación', 'error');
    }
}

    
// Variables globales
let facturasSeleccionadas = [];
let timeoutId;

// Funciones para el Modal de Asignación
function mostrarModalAsignacion() {
    document.getElementById('modalAsignacion').classList.add('show');
    document.body.style.overflow = 'hidden';
    // Establecer la fecha actual por defecto
    document.getElementById('fecha_asignacion').valueAsDate = new Date();
    limpiarFormularioAsignacion();
}

function cerrarModalAsignacion() {
    document.getElementById('modalAsignacion').classList.remove('show');
    document.body.style.overflow = '';
    limpiarFormularioAsignacion();
}

function limpiarFormularioAsignacion() {
    document.getElementById('formAsignacion').reset();
    document.getElementById('facturas_seleccionadas').innerHTML = '';
    document.getElementById('lista_facturas').style.display = 'none';
    facturasSeleccionadas = [];
}

// Manejo de facturas
async function agregarFactura() {
    const numeroFactura = document.getElementById('numero_factura').value.trim();
    if (!numeroFactura) return;

    try {
        const response = await fetch(`verificar_asignacion.php?numero_factura=${numeroFactura}`);
        const data = await response.json();

        if (!data.success) {
            mostrarToast(data.message, 'error');
            return;
        }

        if (data.asignada) {
            // Mostrar modal de confirmación de reasignación
            mostrarModalFacturaAsignada(data.factura, data.asignacion);
            document.getElementById('numero_factura').value = '';
            return;
        }

        // Agregar factura a la lista
        agregarFacturaALista(data.factura);
        document.getElementById('numero_factura').value = '';

    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al verificar la factura', 'error');
    }
}

function actualizarTablaFacturas() {
    const tbody = document.getElementById('facturas_seleccionadas');
    tbody.innerHTML = '';
    
    facturasSeleccionadas.forEach((factura, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${factura.numero_factura}</td>
            <td>${formatearContrato(factura.numero_contrato)}</td>
            <td>${factura.cliente_nombre} ${factura.cliente_apellidos}</td>
            <td>${formatearMes(factura.mes_factura)}</td>
            <td>RD$${parseFloat(factura.monto).toFixed(2)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removerFactura(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('lista_facturas').style.display = 
        facturasSeleccionadas.length > 0 ? 'block' : 'none';
}

function removerFactura(index) {
    facturasSeleccionadas.splice(index, 1);
    actualizarTablaFacturas();
}

// Guardar asignación
async function guardarAsignacion() {
    if (facturasSeleccionadas.length === 0) {
        mostrarToast('Debe seleccionar al menos una factura', 'warning');
        return;
    }

    const cobradorId = document.getElementById('cobrador_asignacion').value;
    const fechaAsignacion = document.getElementById('fecha_asignacion').value;

    if (!cobradorId || !fechaAsignacion) {
        mostrarToast('Debe completar todos los campos', 'warning');
        return;
    }

    try {
        const response = await fetch('guardar_asignacion.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                cobrador_id: cobradorId,
                fecha_asignacion: fechaAsignacion,
                facturas: facturasSeleccionadas.map(f => f.id)
            })
        });

        const data = await response.json();

        if (data.success) {
            mostrarToast('Asignación guardada exitosamente', 'success');
            cerrarModalAsignacion();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            mostrarToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al guardar la asignación', 'error');
    }
}

// Funciones para el Modal de Impresión
function mostrarModalImpresion() {
    document.getElementById('modalImpresion').classList.add('show');
    document.body.style.overflow = 'hidden';
    document.getElementById('fecha_impresion').valueAsDate = new Date();
}

function cerrarModalImpresion() {
    document.getElementById('modalImpresion').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formImpresion').reset();
    document.getElementById('preview_facturas').style.display = 'none';
}

async function previewImpresion() {
    const cobradorId = document.getElementById('cobrador_impresion').value;
    const fecha = document.getElementById('fecha_impresion').value;
    const estado = document.getElementById('estado_impresion').value;

    if (!cobradorId || !fecha) {
        mostrarToast('Debe seleccionar un cobrador y una fecha', 'warning');
        return;
    }

    try {
        const response = await fetch(`buscar_facturas_disponibles.php?cobrador_id=${cobradorId}&fecha=${fecha}&estado=${estado}`);
        const data = await response.json();

        const tbody = document.getElementById('facturas_imprimir');
        tbody.innerHTML = '';

        if (data.facturas.length === 0) {
            mostrarToast('No se encontraron facturas para los criterios seleccionados', 'info');
            document.getElementById('preview_facturas').style.display = 'none';
            return;
        }
        
        // Agregar console.log para debug
        console.log('Datos de facturas:', data.facturas);

        data.facturas.forEach(factura => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${factura.numero_factura}</td>
            <td>${formatearContrato(factura.numero_contrato)}</td>
            <td>${factura.cliente_nombre} ${factura.cliente_apellidos}</td>
            <td>${formatearMes(factura.mes_factura)}</td>
            <td>RD$${parseFloat(factura.monto).toFixed(2)}</td>
            <td><span class="badge badge-${getEstadoBadgeClass(factura.estado)}">${factura.estado}</span></td>
        `;
        tbody.appendChild(tr);
    });

        document.getElementById('preview_facturas').style.display = 'block';

    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al obtener las facturas', 'error');
    }
}

function imprimirRelacion() {
    const cobradorId = document.getElementById('cobrador_impresion').value;
    const fecha = document.getElementById('fecha_impresion').value;
    const estado = document.getElementById('estado_impresion').value;

    if (!cobradorId || !fecha) {
        mostrarToast('Debe seleccionar un cobrador y una fecha', 'warning');
        return;
    }

    window.open(`imprimir_relacion.php?cobrador_id=${cobradorId}&fecha=${fecha}&estado=${estado}`, '_blank');
}

// Función auxiliar para determinar la clase del badge según el estado
function getEstadoBadgeClass(estado) {
    switch(estado) {
        case 'pendiente':
            return 'warning';
        case 'pagada':
            return 'success';
        case 'vencida':
            return 'danger';
        case 'incompleta':
            return 'info';
        case 'anulada':
            return 'secondary';
        default:
            return 'secondary';
    }
}

// Funciones para reasignación
async function reasignarFactura(asignacionId) {
    try {
        const response = await fetch(`obtener_asignacion.php?id=${asignacionId}`);
        const data = await response.json();

        if (!data.success) {
            mostrarToast(data.message, 'error');
            return;
        }

        // Formatear la fecha
        const fecha = new Date(data.asignacion.fecha_asignacion + 'T00:00:00');
        const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        const fechaFormateada = `${fecha.getDate().toString().padStart(2, '0')}/${meses[fecha.getMonth()]}/${fecha.getFullYear()}`;

        document.getElementById('asignacion_id').value = asignacionId;
        document.getElementById('info_asignacion_actual').innerHTML = `
            <strong>Factura:</strong> ${data.asignacion.factura.numero_factura}<br>
            <strong>Cliente:</strong> ${data.asignacion.factura.cliente}<br>
            <strong>Contrato:</strong> ${data.asignacion.factura.contrato}<br>
            <strong>Monto:</strong> RD$${parseFloat(data.asignacion.factura.monto).toFixed(2)}<br>
            <strong>Cobrador actual:</strong> ${data.asignacion.cobrador.nombre}<br>
            <strong>Fecha actual:</strong> ${fechaFormateada}
        `;

        // Establecer la fecha actual en el campo de nueva fecha
        document.getElementById('nueva_fecha').valueAsDate = new Date();
        
        document.getElementById('modalReasignacion').classList.add('show');
        document.body.style.overflow = 'hidden';

    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al obtener la información de la asignación', 'error');
    }
}

function cerrarModalReasignacion() {
    document.getElementById('modalReasignacion').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formReasignacion').reset();
}

// Funciones auxiliares
function limpiarFiltros() {
    document.getElementById('filtrosForm').reset();
    window.location.href = 'asignacion.php';
}

// Event Listeners
document.getElementById('numero_factura').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        agregarFactura();
    }
});

// Modificar el event listener existente para incluir el nuevo modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalAsignacion();
        cerrarModalImpresion();
        cerrarModalReasignacion();
        cerrarModalConfirmarEliminar(); // Agregar esta línea
    }
});

// Cerrar modales con Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalAsignacion();
        cerrarModalImpresion();
        cerrarModalReasignacion();
    }
});


// Agregar al final del script existente
let facturasSeleccionadasParaEliminar = new Set();

function actualizarContadorSeleccionadas() {
    const cantidad = document.querySelectorAll('.seleccion-factura:checked').length;
    
    // Para el botón de eliminar
    const btnEliminar = document.getElementById('btnEliminarGrupo');
    const contador = document.getElementById('contadorSeleccionadas');
    
    // Para el botón de reasignar
    const btnReasignar = document.getElementById('btnReasignarGrupo');
    const contadorReasignar = document.getElementById('contadorSeleccionadasReasignar');
    
    if (cantidad > 0) {
        btnEliminar.disabled = false;
        contador.textContent = `(${cantidad})`;
        
        btnReasignar.disabled = false;
        contadorReasignar.textContent = `(${cantidad})`;
    } else {
        btnEliminar.disabled = true;
        contador.textContent = '';
        
        btnReasignar.disabled = true;
        contadorReasignar.textContent = '';
    }
}

function seleccionarTodasFacturas(checkbox) {
    const checkboxes = document.querySelectorAll('.seleccion-factura');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    actualizarContadorSeleccionadas();
}

function eliminarGrupo() {
    const asignacionesSeleccionadas = Array.from(document.querySelectorAll('.seleccion-factura:checked'))
        .map(cb => cb.dataset.asignacionId);
    
    if (asignacionesSeleccionadas.length === 0) return;

    document.getElementById('asignaciones_eliminar_ids').value = asignacionesSeleccionadas.join(',');
    document.getElementById('modalConfirmarEliminarGrupo').classList.add('show');
}

async function confirmarEliminarGrupo() {
    const idsString = document.getElementById('asignaciones_eliminar_ids').value;
    const ids = idsString.split(',');
    
    // Agregar logs para depurar
    console.log('IDs a eliminar:', ids);
    
    try {
        const response = await fetch('eliminar_asignaciones_grupo.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ asignaciones_ids: ids })
        });

        // Imprimir respuesta para depuración
        const responseText = await response.text();
        console.log('Respuesta del servidor:', responseText);
        
        // Intentar parsear como JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('Error al parsear JSON:', e);
            mostrarToast('Error en la respuesta del servidor', 'error');
            return;
        }

        if (data.success) {
            mostrarToast(`Asignaciones eliminadas exitosamente (${data.registros_eliminados})`, 'success');
            document.getElementById('modalConfirmarEliminarGrupo').classList.remove('show');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            mostrarToast(data.message || 'Error al eliminar las asignaciones', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al comunicarse con el servidor', 'error');
    }
}


// Funciones para la reasignación masiva
function reasignarGrupo() {
    const asignacionesSeleccionadas = Array.from(document.querySelectorAll('.seleccion-factura:checked'))
        .map(cb => cb.dataset.asignacionId);
    
    if (asignacionesSeleccionadas.length === 0) return;

    document.getElementById('asignaciones_reasignar_ids').value = asignacionesSeleccionadas.join(',');
    
    // Establecer la fecha actual en el campo de nueva fecha
    document.getElementById('nueva_fecha_grupo').valueAsDate = new Date();
    
    document.getElementById('modalReasignacionGrupo').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function cerrarModalReasignacionGrupo() {
    document.getElementById('modalReasignacionGrupo').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formReasignacionGrupo').reset();
}

async function confirmarReasignacionGrupo() {
    const idsString = document.getElementById('asignaciones_reasignar_ids').value;
    const ids = idsString.split(',');
    const nuevoCobrador = document.getElementById('nuevo_cobrador_grupo').value;
    const nuevaFecha = document.getElementById('nueva_fecha_grupo').value;
    
    if (!nuevoCobrador || !nuevaFecha) {
        mostrarToast('Por favor complete todos los campos', 'warning');
        return;
    }
    
    try {
        const response = await fetch('reasignar_asignaciones_grupo.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                asignaciones_ids: ids,
                nuevo_cobrador_id: nuevoCobrador,
                nueva_fecha: nuevaFecha
            })
        });

        const data = await response.json();

        if (data.success) {
            mostrarToast(`Asignaciones reasignadas exitosamente (${data.registros_procesados})`, 'success');
            cerrarModalReasignacionGrupo();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            mostrarToast(data.message || 'Error al reasignar las asignaciones', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al comunicarse con el servidor', 'error');
    }
}


async function confirmarReasignacion() {
    const asignacionId = document.getElementById('asignacion_id').value;
    const nuevoCobrador = document.getElementById('nuevo_cobrador').value;
    const nuevaFecha = document.getElementById('nueva_fecha').value;
    
    if (!asignacionId || !nuevoCobrador || !nuevaFecha) {
        mostrarToast('Por favor complete todos los campos', 'warning');
        return;
    }
    
    try {
        const response = await fetch('reasignar_asignacion.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                asignacion_id: asignacionId,
                nuevo_cobrador_id: nuevoCobrador,
                nueva_fecha: nuevaFecha
            })
        });

        const data = await response.json();

        if (data.success) {
            mostrarToast('Asignación reasignada exitosamente', 'success');
            cerrarModalReasignacion();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            mostrarToast(data.message || 'Error al reasignar la asignación', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al comunicarse con el servidor', 'error');
    }
}

// Función para manejar el cambio de registros por página
function cambiarRegistrosPorPagina(valor) {
    document.cookie = `asignaciones_por_pagina=${valor}; path=/; max-age=31536000`;
    
    // Obtener parámetros actuales
    const url = new URL(window.location.href);
    
    // Conservar otros parámetros excepto página
    url.searchParams.delete('pagina');
    url.searchParams.set('pagina', '1');
    
    window.location.href = url.toString();
}

// Configurar filtros avanzados al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    const toggleAdvancedFilters = document.getElementById('toggleAdvancedFilters');
    if (toggleAdvancedFilters) {
        toggleAdvancedFilters.addEventListener('click', function() {
            const advancedFilters = document.getElementById('advancedFilters');
            if (advancedFilters.style.display === 'none') {
                advancedFilters.style.display = 'block';
                toggleAdvancedFilters.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar filtros';
            } else {
                advancedFilters.style.display = 'none';
                toggleAdvancedFilters.innerHTML = '<i class="fas fa-sliders-h"></i> Filtros avanzados';
            }
        });
    }
    
    // Mostrar filtros avanzados si ya están en uso
    const fechaDesde = document.getElementById('fecha_desde');
    const fechaHasta = document.getElementById('fecha_hasta');
    if ((fechaDesde && fechaDesde.value) || (fechaHasta && fechaHasta.value)) {
        const advancedFilters = document.getElementById('advancedFilters');
        if (advancedFilters) {
            advancedFilters.style.display = 'block';
            if (toggleAdvancedFilters) {
                toggleAdvancedFilters.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar filtros';
            }
        }
    }
    
    // Configurar la búsqueda con Enter
    const searchInput = document.getElementById('contratoSearch');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                aplicarFiltros();
            }
        });
    }
    
    // Aplicar filtros al cambiar el selector de cobradores
    const cobradorFilter = document.getElementById('cobradorFilter');
    if (cobradorFilter) {
        cobradorFilter.addEventListener('change', function() {
            aplicarFiltros();
        });
    }
    
    // Aplicar filtros al cambiar el selector de estado
    const estadoFilter = document.getElementById('estadoFilter');
    if (estadoFilter) {
        estadoFilter.addEventListener('change', function() {
            aplicarFiltros();
        });
    }
    
    // Configurar botón de búsqueda
    const btnBuscar = document.getElementById('btnBuscar');
    if (btnBuscar) {
        btnBuscar.addEventListener('click', function() {
            aplicarFiltros();
        });
    }
});

// Función para aplicar filtros
function aplicarFiltros() {
    const contratoValue = document.getElementById('contratoSearch').value.trim();
    const cobradorValue = document.getElementById('cobradorFilter').value;
    const estadoValue = document.getElementById('estadoFilter').value;
    const fechaDesdeValue = document.getElementById('fecha_desde')?.value;
    const fechaHastaValue = document.getElementById('fecha_hasta')?.value;
    
    let url = 'asignacion.php?pagina=1';
    
    if (contratoValue) {
        url += `&contrato=${encodeURIComponent(contratoValue)}`;
    }
    
    if (cobradorValue) {
        url += `&cobrador_id=${encodeURIComponent(cobradorValue)}`;
    }
    
    if (estadoValue) {
        url += `&estado=${encodeURIComponent(estadoValue)}`;
    }
    
    if (fechaDesdeValue) {
        url += `&fecha_desde=${encodeURIComponent(fechaDesdeValue)}`;
    }
    
    if (fechaHastaValue) {
        url += `&fecha_hasta=${encodeURIComponent(fechaHastaValue)}`;
    }
    
    window.location.href = url;
}

// Función para limpiar filtros
function limpiarFiltros() {
    window.location.href = 'asignacion.php';
}

// Funciones para manejar selección de facturas
function seleccionarTodasFacturas(checkbox) {
    const checkboxes = document.querySelectorAll('.seleccion-factura');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    actualizarContadorSeleccionadas();
}

function actualizarContadorSeleccionadas() {
    const cantidad = document.querySelectorAll('.seleccion-factura:checked').length;
    
    // Para el botón de eliminar
    const btnEliminar = document.getElementById('btnEliminarGrupo');
    const contador = document.getElementById('contadorSeleccionadas');
    
    // Para el botón de reasignar
    const btnReasignar = document.getElementById('btnReasignarGrupo');
    const contadorReasignar = document.getElementById('contadorSeleccionadasReasignar');
    
    if (cantidad > 0) {
        btnEliminar.disabled = false;
        contador.textContent = `(${cantidad})`;
        
        btnReasignar.disabled = false;
        contadorReasignar.textContent = `(${cantidad})`;
    } else {
        btnEliminar.disabled = true;
        contador.textContent = '';
        
        btnReasignar.disabled = true;
        contadorReasignar.textContent = '';
    }
}

// Verificar si hay seleccionadas al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    actualizarContadorSeleccionadas();
    
    // Inicializar tooltips si estás usando bootstrap
    if (typeof $().tooltip === 'function') {
        $('[title]').tooltip();
    }
});


// Configuración del menú de usuario
document.addEventListener('DOMContentLoaded', function() {
    console.log("Inicializando menú de usuario");
    
    const profileUsername = document.getElementById('profileUsername');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileUsername && profileDropdown) {
        profileUsername.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('active');
            console.log("Toggle menu de usuario");
        });
        
        // Cerrar cuando se hace clic fuera
        document.addEventListener('click', function() {
            profileDropdown.classList.remove('active');
        });
        
        // Evitar que se cierre al hacer clic dentro
        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    } else {
        console.log("No se encontraron elementos del menú de usuario", {
            profileUsername: !!profileUsername,
            profileDropdown: !!profileDropdown
        });
    }
});


</script>
<style>

/* Asignación específica - estilos únicos */
.asignaciones-container {
    max-width: 100%;
    margin: 0 auto;
    padding: 1rem;
}

/* Estilos específicos para los modales de asignación */
.input-group {
    display: flex;
    gap: 0.5rem;
}

#lista_facturas, #preview_facturas {
    margin-top: 1.5rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}

/* Animaciones */
.modal.show {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

/* Estilos para scroll personalizado en modales */
.modal-body::-webkit-scrollbar {
    width: 8px;
}

.modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

.modal-body::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .header-buttons {
        flex-direction: column;
    }

    .button-group {
        width: 100%;
    }

    .button-group.right {
        margin-left: 0;
    }

    .modal-dialog {
        margin: 0.5rem;
    }
}

</style>


<?php require_once 'footer.php'; ?>
