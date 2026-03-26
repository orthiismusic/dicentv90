<?php
require_once 'header.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Procesar filtros
$where = "1=1";
$params = [];

if (isset($_GET['numero_contrato']) && !empty($_GET['numero_contrato'])) {
    $where .= " AND c.numero_contrato = ?";
    $params[] = $_GET['numero_contrato'];
}

if (isset($_GET['fecha_desde']) && !empty($_GET['fecha_desde'])) {
    $where .= " AND p.fecha_pago >= ?";
    $params[] = $_GET['fecha_desde'] . ' 00:00:00';
}

if (isset($_GET['fecha_hasta']) && !empty($_GET['fecha_hasta'])) {
    $where .= " AND p.fecha_pago <= ?";
    $params[] = $_GET['fecha_hasta'] . ' 23:59:59';
}

if (isset($_GET['metodo_pago']) && !empty($_GET['metodo_pago'])) {
    $where .= " AND p.metodo_pago = ?";
    $params[] = $_GET['metodo_pago'];
}

if (isset($_GET['cobrador_id']) && !empty($_GET['cobrador_id'])) {
    $where .= " AND p.cobrador_id = ?";
    $params[] = $_GET['cobrador_id'];
}

// Configuración de paginación
$por_pagina = 50; // pagos por página
$por_pagina = isset($_COOKIE['pagos_por_pagina']) ? (int)$_COOKIE['pagos_por_pagina'] : 50;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_actual - 1) * $por_pagina;

// Obtener el total de pagos según los filtros
$sql_total = "
    SELECT COUNT(*) as total
    FROM pagos p
    JOIN facturas f ON p.factura_id = f.id
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    WHERE $where
";

$stmt_total = $conn->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = $stmt_total->fetch()['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Calcular total del mes actual
$mes_actual = date('m');
$anio_actual = date('Y');
$stmt_mes = $conn->prepare("
    SELECT 
        SUM(p.monto) as total_mes
    FROM pagos p
    WHERE MONTH(p.fecha_pago) = ?
    AND YEAR(p.fecha_pago) = ?
    AND p.estado = 'procesado'
");
$stmt_mes->execute([$mes_actual, $anio_actual]);
$total_mes = $stmt_mes->fetch()['total_mes'] ?? 0;

// Consulta principal con paginación
$sql = "
    SELECT p.*,
           f.numero_factura,
           c.numero_contrato,
           cl.nombre as cliente_nombre,
           cl.apellidos as cliente_apellidos,
           co.nombre_completo as cobrador_nombre
    FROM pagos p
    JOIN facturas f ON p.factura_id = f.id
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    LEFT JOIN cobradores co ON p.cobrador_id = co.id
    WHERE $where
    ORDER BY p.fecha_pago DESC
    LIMIT $por_pagina OFFSET $offset
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll();

// Obtener cobradores para el filtro
$stmt = $conn->query("SELECT id, nombre_completo as nombre FROM cobradores WHERE estado = 'activo'");
$cobradores = $stmt->fetchAll();

// Calcular totales
$stmt = $conn->prepare("
    SELECT 
        SUM(p.monto) as total,
        COUNT(DISTINCT f.id) as total_facturas,
        COUNT(DISTINCT cl.id) as total_clientes
    FROM pagos p
    JOIN facturas f ON p.factura_id = f.id
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    WHERE $where
");
$stmt->execute($params);
$totales = $stmt->fetch();
?>

<div class="pagos-container">
    <!-- Resumen de Pagos -->
    <div class="dashboard-stats fade-in">
        <div class="stat-card blue">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-info">
                    <h3>Total Recaudado</h3>
                    <p class="stat-value">RD$ <?php echo number_format($totales['total'] ?? 0, 2); ?></p>
                    <p class="stat-label">Recaudo general</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-dollar-sign"></i></div>
        </div>
        <div class="stat-card green">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-info">
                    <h3>Total del Mes</h3>
                    <p class="stat-value">RD$ <?php echo number_format($total_mes ?? 0, 2); ?></p>
                    <p class="stat-label">Mes actual</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-calendar-check"></i></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-info">
                    <h3>Facturas Pagadas</h3>
                    <p class="stat-value"><?php echo number_format($totales['total_facturas'] ?? 0); ?></p>
                    <p class="stat-label">Total facturas cobradas</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
        <div class="stat-card red">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3>Clientes Atendidos</h3>
                    <p class="stat-value"><?php echo number_format($totales['total_clientes'] ?? 0); ?></p>
                    <p class="stat-label">Clientes con pagos</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-users"></i></div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card">
        <div class="card-header">
            <h2>Gestión de Pagos</h2>
            <div class="header-buttons">
                <div class="button-group right">
                    <!--
                    <button class="btn btn-secondary" onclick="exportarReporte()">
                        <i class="fas fa-download"></i> Exportar Reporte
                    </button>  
                    -->
                </div>
            </div>
        </div>
    
        <div class="filtros-bar fade-in">
            <div class="search-wrap">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" placeholder="Buscar por contrato o cliente..." value="<?php echo isset($_GET['numero_contrato']) ? htmlspecialchars($_GET['numero_contrato']) : ''; ?>">
            </div>
            <select id="metodoFilter" class="filter-select" onchange="aplicarFiltros()">
                <option value="">Todos los métodos</option>
                <option value="efectivo" <?php echo ($_GET['metodo_pago'] ?? '') === 'efectivo' ? 'selected' : ''; ?>>Efectivo</option>
                <option value="transferencia" <?php echo ($_GET['metodo_pago'] ?? '') === 'transferencia' ? 'selected' : ''; ?>>Transferencia</option>
                <option value="cheque" <?php echo ($_GET['metodo_pago'] ?? '') === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                <option value="tarjeta" <?php echo ($_GET['metodo_pago'] ?? '') === 'tarjeta' ? 'selected' : ''; ?>>Tarjeta</option>
            </select>
            <select id="cobradorFilter" class="filter-select" onchange="aplicarFiltros()">
                <option value="">Todos los cobradores</option>
                <?php if (isset($cobradores) && is_array($cobradores)): foreach ($cobradores as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo ($_GET['cobrador_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['nombre_completo']); ?>
                    </option>
                <?php endforeach; endif; ?>
            </select>
            <button class="btn btn-secondary btn-sm" onclick="limpiarFiltros()"><i class="fas fa-rotate-right"></i> Limpiar</button>
            <button class="btn btn-secondary btn-sm" id="toggleAdvancedFilters"><i class="fas fa-sliders-h"></i> Filtros avanzados</button>
        </div>

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

        <!-- Tabla de Pagos -->
        <div class="clientes-table-wrap fade-in delay-1">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-money-bill-wave"></i> Pagos Registrados</div>
                    <div class="card-subtitle">Mostrando <?php echo min($offset + 1, $total_registros); ?>–<?php echo min($offset + $por_pagina, $total_registros); ?> de <?php echo number_format($total_registros); ?> pagos</div>
                </div>
            </div>
            <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Fecha</th>
                        <th>Factura</th>
                        <th>Contrato</th>
                        <th>Cliente</th>
                        <th>Monto</th>
                        <th>Método</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th>Cobrador</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pagos as $pago): ?>
                    <tr>
                        <td><input type="checkbox" class="pago-checkbox" value="<?php echo $pago['id']; ?>"></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></td>
                        <td>
                            <a href="ver_factura.php?id=<?php echo $pago['factura_id']; ?>">
                                <?php echo htmlspecialchars($pago['numero_factura']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($pago['numero_contrato']); ?></td>
                        <td><?php echo htmlspecialchars($pago['cliente_nombre'] . ' ' . $pago['cliente_apellidos']); ?></td>
                        <td>$<?php echo number_format($pago['monto'], 2); ?></td>
                        <td>
                            <span class="status <?php 
                                echo $pago['metodo_pago'] === 'efectivo' ? 'active' : 
                                     ($pago['metodo_pago'] === 'transferencia' ? 'warning' : 
                                     ($pago['metodo_pago'] === 'cheque' ? 'pending' : 'inactive')); 
                            ?>">
                                <?php echo ucfirst($pago['metodo_pago']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status <?php echo $pago['tipo_pago'] === 'total' ? 'active' : 'warning'; ?>">
                                <?php echo ucfirst($pago['tipo_pago']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status <?php echo $pago['estado'] === 'procesado' ? 'active' : 'inactive'; ?>">
                                <?php echo ucfirst($pago['estado']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($pago['cobrador_nombre']); ?></td>
                        <td class="actions-cell">
                            <button class="btn-action view" title="Ver comprobante" onclick="verComprobante(<?php echo $pago['id']; ?>)">
                                <i class="fas fa-print"></i>
                            </button>
                            <button class="btn-action edit" title="Imprimir comprobante" onclick="imprimirComprobante(<?php echo $pago['id']; ?>)">
                                <i class="fas fa-briefcase"></i>
                            </button>
                            <?php if ($pago['estado'] == 'procesado' && $_SESSION['rol'] == 'admin'): ?>
                                <button class="btn-action delete" title="Anular pago" onclick="anularPago(<?php echo $pago['id']; ?>)">
                                    <i class="fas fa-times"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Paginador -->
        <?php if ($total_paginas > 1):
    $params_url = http_build_query(array_filter([
        'numero_contrato' => $_GET['numero_contrato'] ?? '',
        'metodo_pago' => $_GET['metodo_pago'] ?? '',
        'cobrador_id' => $_GET['cobrador_id'] ?? '',
        'fecha_desde' => $_GET['fecha_desde'] ?? '',
        'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    ]));
?>
<div class="paginador-wrap">
    <div class="paginador-info">
        Mostrando <strong><?php echo min($offset + 1, $total_registros); ?></strong>–<strong><?php echo min($offset + $por_pagina, $total_registros); ?></strong>
        de <strong><?php echo number_format($total_registros); ?></strong> pagos
    </div>
    <div class="paginador-pages">
        <a href="?pagina=1&<?php echo $params_url; ?>" class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>"><i class="fas fa-angles-left" style="font-size:10px;"></i></a>
        <a href="?pagina=<?php echo $pagina_actual - 1; ?>&<?php echo $params_url; ?>" class="pag-btn <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>"><i class="fas fa-angle-left" style="font-size:11px;"></i></a>
        <?php
        $ri = max(1, $pagina_actual - 2);
        $rf = min($total_paginas, $pagina_actual + 2);
        if ($ri > 1) { echo '<a href="?pagina=1&'.$params_url.'" class="pag-btn">1</a>'; }
        if ($ri > 2) { echo '<span class="pag-btn ellipsis">…</span>'; }
        for ($p = $ri; $p <= $rf; $p++):
        ?>
            <a href="?pagina=<?php echo $p; ?>&<?php echo $params_url; ?>" class="pag-btn <?php echo $p === $pagina_actual ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
        <?php if ($rf < $total_paginas - 1): ?><span class="pag-btn ellipsis">…</span><?php endif; ?>
        <?php if ($rf < $total_paginas): ?><a href="?pagina=<?php echo $total_paginas; ?>&<?php echo $params_url; ?>" class="pag-btn"><?php echo $total_paginas; ?></a><?php endif; ?>
        <a href="?pagina=<?php echo $pagina_actual + 1; ?>&<?php echo $params_url; ?>" class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>"><i class="fas fa-angle-right" style="font-size:11px;"></i></a>
        <a href="?pagina=<?php echo $total_paginas; ?>&<?php echo $params_url; ?>" class="pag-btn <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>"><i class="fas fa-angles-right" style="font-size:10px;"></i></a>
    </div>
</div>
<?php endif; ?>
        
    </div>
</div>

<style>
/* Estilos específicos de pagos.php solamente */

.pagos-container {
    margin-bottom: 2rem;
}

.badge-orange {
    background-color: #fd7e14;
    color: white;
}

.badge-warning {
    background-color: #ffc107;
    color: #212529;
}

.swal2-popup {
    font-size: 1rem !important;
}

.swal2-input {
    margin: 1em auto !important;
}

.advanced-filters {
    border-top: 1px solid #e0e0e0;
    padding-top: 15px;
}

.filter-row {
    display: flex;
    gap: 15px;
    align-items: flex-end;
}

.filter-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-item label {
    font-size: 14px;
    color: #5f6368;
    font-weight: 500;
}

.filter-item input[type="date"],
.filter-item .form-control {
    padding: 0.5rem;
    border: 1px solid #dadce0;
    border-radius: 8px;
    font-size: 14px;
    width: 100%;
    height: 38px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Menú desplegable de usuario
    const profileUsername = document.getElementById('profileUsername');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileUsername && profileDropdown) {
        profileUsername.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('active');
            console.log("Toggle menú de perfil");
        });
        
        // Cerrar cuando se hace clic fuera
        document.addEventListener('click', function() {
            profileDropdown.classList.remove('active');
        });
        
        // Evitar que se cierre al hacer clic dentro
        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
    
    // Configurar filtros
    const searchInput = document.getElementById('searchInput');
    const metodoFilter = document.getElementById('metodoFilter');
    const cobradorFilter = document.getElementById('cobradorFilter');

    // Enter en campo de búsqueda
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                aplicarFiltros();
            }
        });
    }
    
    // Filtros avanzados
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
});

function exportarReporte() {
    const filtros = new URLSearchParams(window.location.search);
    window.location.href = `exportar_pagos.php?${filtros.toString()}`;
}

// Función para aplicar filtros
function aplicarFiltros() {
    const searchValue = document.getElementById('searchInput').value.trim();
    const metodoPagoValue = document.getElementById('metodoFilter').value;
    const cobradorValue = document.getElementById('cobradorFilter').value;
    const fechaDesdeValue = document.getElementById('fecha_desde')?.value;
    const fechaHastaValue = document.getElementById('fecha_hasta')?.value;

    let url = 'pagos.php?pagina=1';

    if (searchValue) {
        url += `&numero_contrato=${encodeURIComponent(searchValue)}`;
    }

    if (metodoPagoValue) {
        url += `&metodo_pago=${encodeURIComponent(metodoPagoValue)}`;
    }

    if (cobradorValue) {
        url += `&cobrador_id=${encodeURIComponent(cobradorValue)}`;
    }

    if (fechaDesdeValue) {
        url += `&fecha_desde=${encodeURIComponent(fechaDesdeValue)}`;
    }

    if (fechaHastaValue) {
        url += `&fecha_hasta=${encodeURIComponent(fechaHastaValue)}`;
    }

    window.location.href = url;
}

function limpiarFiltros() {
    window.location.href = 'pagos.php';
}

function cambiarRegistrosPorPagina(valor) {
    document.cookie = `pagos_por_pagina=${valor}; path=/; max-age=31536000`;
    
    // Obtener parámetros actuales
    const url = new URL(window.location.href);
    
    // Conservar otros parámetros excepto página
    url.searchParams.delete('pagina');
    url.searchParams.set('pagina', '1');
    
    window.location.href = url.toString();
}

function verComprobante(id) {
    window.open(`ver_comprobante.php?id=${id}`, '_blank');
}

function imprimirComprobante(id) {
    window.open(`imprimir_comprobante.php?id=${id}`, '_blank');
}

function anularPago(id) {
    // Primero verificar el tiempo del pago
    fetch(`verificar_tiempo_pago.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.requiere_password) {
                // Modal con contraseña para pagos mayor a 5 minutos
                Swal.fire({
                    title: 'Confirmar Anulación',
                    html: `
                        <p>Este pago tiene más de 5 minutos.</p>
                        <p>Por favor, confirme su contraseña para continuar.</p>
                        <div class="form-group">
                            <input type="password" id="admin-password" class="swal2-input" placeholder="Ingrese su contraseña">
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Sí, anular',
                    cancelButtonText: 'Cancelar',
                    focusConfirm: false,
                    preConfirm: () => {
                        const password = document.getElementById('admin-password').value;
                        if (!password) {
                            Swal.showValidationMessage('Por favor ingrese su contraseña');
                            return false;
                        }
                        return password;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const password = result.value;
                        procesarAnulacion(id, password);
                    }
                });
            } else {
                // Modal simple para pagos menor a 5 minutos
                Swal.fire({
                    title: '¿Está seguro?',
                    text: "¿Desea anular este pago? Esta acción no se puede deshacer.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Sí, anular',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        procesarAnulacion(id);
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarToast('Error al verificar el pago', 'error');
        });
}

function procesarAnulacion(id, password = null) {
    const data = {
        id: id,
        password: password
    };

    fetch('anular_pago.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: '¡Éxito!',
                text: data.message,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                title: 'Error',
                text: data.message,
                icon: 'error'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            title: 'Error',
            text: 'Error al procesar la solicitud',
            icon: 'error'
        });
    });
}

// Implementar selección de checkbox
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.pago-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
        
        // Actualizar estado de "seleccionar todo" basado en checkboxes individuales
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('pago-checkbox')) {
                const checkboxes = document.querySelectorAll('.pago-checkbox');
                const allChecked = Array.from(checkboxes).every(c => c.checked);
                const anyChecked = Array.from(checkboxes).some(c => c.checked);
                
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = anyChecked && !allChecked;
                
                // Opcional: mostrar acciones en lote cuando se selecciona al menos un elemento
                // const batchActions = document.getElementById('batch-actions');
                // if (batchActions) {
                //     batchActions.style.display = anyChecked ? 'flex' : 'none';
                // }
            }
        });
    }
});


//ESTE CODIGO SIRVE PARA QUE LAS FECHAS CARGUEN CON VALORES DEL MES ACTUAL

//document.addEventListener('DOMContentLoaded', function() {
//    // Establecer fechas por defecto si no hay filtros
//    if (!window.location.search.includes('fecha_desde')) {
//        const hoy = new Date();
//        const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
//        document.getElementById('fecha_desde').valueAsDate = primerDiaMes;
//        document.getElementById('fecha_hasta').valueAsDate = hoy;
//    }
//});


</script>

<?php require_once 'footer.php'; ?>