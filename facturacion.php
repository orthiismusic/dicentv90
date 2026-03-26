<?php
require_once 'header.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function puedeGenerarFactura($conn, $contrato_id) {
    try {
        // Verificar si el contrato está activo
        $stmt = $conn->prepare("
            SELECT estado 
            FROM contratos 
            WHERE id = ? 
            AND estado = 'activo'
        ");
        $stmt->execute([$contrato_id]);
        if (!$stmt->fetch()) {
            return false;
        }

        // Verificar si ya existe una factura pendiente o incompleta
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM facturas 
            WHERE contrato_id = ? 
            AND estado IN ('pendiente', 'incompleta')
        ");
        $stmt->execute([$contrato_id]);
        $facturasPendientes = $stmt->fetchColumn();

        // Si hay más de una factura pendiente o incompleta, no generar nueva factura
        if ($facturasPendientes > 1) {
            return false;
        }

        // Verificar si ya existe factura para el mes actual
        $mesActual = date('m/Y');
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM facturas 
            WHERE contrato_id = ? 
            AND mes_factura = ?
        ");
        $stmt->execute([$contrato_id, $mesActual]);
        if ($stmt->fetchColumn() > 0) {
            return false;
        }

        return true;

    } catch (PDOException $e) {
        error_log("Error en puedeGenerarFactura: " . $e->getMessage());
        return false;
    }
}

//---------------------------
// Función para verificar bloqueo de facturas
function verificarBloqueoFacturas($conn) {
    $stmt = $conn->prepare("
        SELECT fl.*, u.nombre as usuario_nombre 
        FROM generacion_facturas_lock fl
        JOIN usuarios u ON fl.usuario_id = u.id 
        WHERE fl.estado = 'activo' 
        AND fl.timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute();
    return $stmt->fetch();
}

//------------------------------
// Función para obtener siguiente número de factura
function obtenerSiguienteNumeroFactura($conn) {
    $stmt = $conn->query("
        SELECT CAST(numero_factura AS UNSIGNED) as num 
        FROM facturas 
        ORDER BY num DESC 
        LIMIT 1
    ");
    $ultimo = $stmt->fetch();
    
    if ($ultimo) {
        return str_pad($ultimo['num'] + 1, 7, '0', STR_PAD_LEFT);
    }
    return '0000001';
}

//------------------------
// Función para calcular el monto de la factura
function calcularMontoFactura($conn, $contrato_id) {
    // Obtener información del contrato
    $stmt = $conn->prepare("
        SELECT monto_mensual 
        FROM contratos 
        WHERE id = ?
    ");
    $stmt->execute([$contrato_id]);
    $contrato = $stmt->fetch();
    
    if (!$contrato) {
        return 0;
    }
    
    // Retornar solo el monto mensual del contrato
    return $contrato['monto_mensual'];
}

//--------------------------
function ajustarFechaEmision($fecha, $dia_cobro) {
    $year = (int)$fecha->format('Y');
    $month = (int)$fecha->format('m');
    
    // Crear una nueva fecha con el primer día del mes para evitar problemas
    $fechaAjustada = new DateTime("$year-$month-01");
    
    // Obtener el último día del mes
    $ultimoDiaMes = (int)$fechaAjustada->format('t');
    
    // Determinar el día ajustado según el mes
    $diaAjustado = $dia_cobro;
    
    // Ajustes especiales por mes
    if ($month == 2) { // Febrero
        if ($dia_cobro >= 29) {
            // Verificar si es año bisiesto
            $esBisiesto = date('L', mktime(0, 0, 0, 1, 1, $year)) == 1;
            $diaAjustado = $esBisiesto ? 29 : 28;
        }
    } elseif ($ultimoDiaMes == 30 && $dia_cobro > 30) {
        $diaAjustado = 30;
    } elseif ($dia_cobro > $ultimoDiaMes) {
        $diaAjustado = $ultimoDiaMes;
    }
    
    // Establecer el día ajustado
    $fechaAjustada->setDate($year, $month, $diaAjustado);
    
    return $fechaAjustada;
}


function actualizarFechaFinContrato($conn, $contrato_id) {
    try {
        // Primero verificamos si el contrato existe y está activo
        $stmt = $conn->prepare("
            SELECT id 
            FROM contratos 
            WHERE id = ? 
            AND estado = 'activo'
        ");
        $stmt->execute([$contrato_id]);
        if (!$stmt->fetch()) {
            error_log("Contrato $contrato_id no encontrado o inactivo");
            return false;
        }

        // Obtener la fecha de vencimiento de la última factura pagada
        $stmt = $conn->prepare("
            SELECT f.fecha_vencimiento
            FROM facturas f
            WHERE f.contrato_id = ?
            AND f.estado = 'pagada'
            ORDER BY f.fecha_vencimiento DESC
            LIMIT 1
        ");
        
        $stmt->execute([$contrato_id]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resultado) {
            // Antes de actualizar, verificar la fecha actual
            $stmt = $conn->prepare("
                SELECT fecha_fin 
                FROM contratos 
                WHERE id = ?
            ");
            $stmt->execute([$contrato_id]);
            $contratoActual = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $nueva_fecha = $resultado['fecha_vencimiento'];
            $fecha_actual = $contratoActual['fecha_fin'];
            
            error_log("Contrato $contrato_id - Fecha actual: $fecha_actual, Nueva fecha: $nueva_fecha");
            
            // Actualizar la fecha_fin del contrato
            $stmt = $conn->prepare("
                UPDATE contratos 
                SET fecha_fin = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $exito = $stmt->execute([$nueva_fecha, $contrato_id]);
            
            if (!$exito) {
                error_log("Error al actualizar fecha_fin del contrato ID: $contrato_id");
                return false;
            }
            
            error_log("Fecha fin actualizada exitosamente para el contrato ID: $contrato_id - Nueva fecha: $nueva_fecha");
            return true;
        } else {
            error_log("No se encontró última factura pagada para el contrato ID: $contrato_id");
            return false;
        }
        
    } catch (PDOException $e) {
        error_log("Error en actualizarFechaFinContrato para contrato $contrato_id: " . $e->getMessage());
        return false;
    }
}

function formatearFecha($fecha) {
    $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha);
    $meses = [
        '01' => 'Ene', '02' => 'Feb', '03' => 'Mar',
        '04' => 'Abr', '05' => 'May', '06' => 'Jun',
        '07' => 'Jul', '08' => 'Ago', '09' => 'Sep',
        '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'
    ];
    return $fecha_obj->format('d') . '/' . 
           $meses[$fecha_obj->format('m')] . '/' . 
           $fecha_obj->format('Y');
}



// Procesamiento de generación de facturas
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'generar_facturas') {
    try {
        // Verificar bloqueo
        $bloqueo = verificarBloqueoFacturas($conn);
        if ($bloqueo && $bloqueo['usuario_id'] != $_SESSION['usuario_id']) {
            throw new Exception("El usuario {$bloqueo['usuario_nombre']} está generando facturas actualmente.");
        }

        $conn->beginTransaction();

        // Crear bloqueo
        $stmt = $conn->prepare("
            INSERT INTO generacion_facturas_lock (usuario_id, timestamp, estado) 
            VALUES (?, NOW(), 'activo')
        ");
        $stmt->execute([$_SESSION['usuario_id']]);

        // Obtener contratos que necesitan facturación
        $stmt = $conn->prepare("
            SELECT c.* 
            FROM contratos c
            WHERE c.estado = 'activo'
            AND (
                -- Caso 1: Sin facturas previas
                NOT EXISTS (
                    SELECT 1 
                    FROM facturas f 
                    WHERE f.contrato_id = c.id
                )
                OR
                -- Caso 2: Todas las facturas existentes están pagadas
                (
                    NOT EXISTS (
                        SELECT 1
                        FROM facturas f
                        WHERE f.contrato_id = c.id
                        AND f.estado IN ('pendiente', 'incompleta', 'vencida')
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM facturas f
                        WHERE f.contrato_id = c.id
                        AND f.estado = 'pagada'
                    )
                )
            )
        ");
        
        $stmt->execute();
        $contratos = $stmt->fetchAll();
        $facturasGeneradas = 0;

        foreach ($contratos as $contrato) {
            // Obtener última factura del contrato
            $stmt = $conn->prepare("
                SELECT cuota, fecha_emision, mes_factura
                FROM facturas 
                WHERE contrato_id = ? 
                ORDER BY fecha_emision DESC, cuota DESC 
                LIMIT 1
            ");
            $stmt->execute([$contrato['id']]);
            $ultimaFactura = $stmt->fetch();

            // Determinar fecha de emisión y cuota
            if ($ultimaFactura) {
                // Si hay factura anterior, usar el siguiente mes
                $fecha_emision = new DateTime($ultimaFactura['fecha_emision']);
                $fecha_emision->modify('first day of next month');
                $proximaCuota = $ultimaFactura['cuota'] + 1;
            } else {
                // Si es contrato nuevo, usar el mes siguiente a la fecha de inicio
                $fecha_emision = new DateTime($contrato['fecha_inicio']);
                $fecha_emision->modify('first day of next month');
                $proximaCuota = 1;
            }

            // Ajustar la fecha al día de cobro
            $fecha_emision = ajustarFechaEmision($fecha_emision, $contrato['dia_cobro']);
            
            // Calcular fecha de vencimiento
            $fecha_vencimiento = clone $fecha_emision;
            $mes_emision = (int)$fecha_emision->format('m');
            $year_emision = (int)$fecha_emision->format('Y');
            
            // Primero sumamos un mes
            $fecha_vencimiento->modify('+1 month');
            
            // Verificamos si la fecha de emisión es enero y el día es 30 o 31
            if ($mes_emision == 1 && (int)$fecha_emision->format('d') >= 30) {
                // Ajustar específicamente para febrero
                $esBisiesto = date('L', mktime(0, 0, 0, 1, 1, $year_emision)) == 1;
                $ultimoDiaFebrero = $esBisiesto ? 29 : 28;
                // Importante: mantenemos el mismo año de la fecha de emisión
                $fecha_vencimiento->setDate($year_emision, 2, $ultimoDiaFebrero);
            } else {
                // Para todos los demás casos, simplemente restamos un día
                $fecha_vencimiento->modify('-1 day');
            }

            // Formatear mes de factura
            $mesFactura = $fecha_emision->format('m/Y');

            // Verificar si ya existe factura para este mes
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM facturas 
                WHERE contrato_id = ? 
                AND mes_factura = ?
            ");
            $stmt->execute([$contrato['id'], $mesFactura]);
            if ($stmt->fetchColumn() > 0) {
                continue;
            }

            // Calcular monto y obtener información de dependientes
            $montoTotal = calcularMontoFactura($conn, $contrato['id']);

            // Obtener información de dependientes
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_dependientes,
                    SUM(CASE WHEN p.id = 5 THEN 1 ELSE 0 END) as total_geriatricos
                FROM dependientes d
                JOIN planes p ON d.plan_id = p.id
                WHERE d.contrato_id = ? 
                AND d.estado = 'activo'
            ");
            $stmt->execute([$contrato['id']]);
            $deps = $stmt->fetch();

            // Obtener siguiente número de factura
            $numeroFactura = obtenerSiguienteNumeroFactura($conn);

            // Insertar nueva factura
            $stmt = $conn->prepare("
                INSERT INTO facturas (
                    numero_factura, cuota, mes_factura, contrato_id,
                    fecha_emision, fecha_vencimiento, monto,
                    cantidad_dependientes, tiene_geriatrico, estado
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, 'pendiente'
                )
            ");

            $stmt->execute([
                $numeroFactura,
                $proximaCuota,
                $mesFactura,
                $contrato['id'],
                $fecha_emision->format('Y-m-d'),
                $fecha_vencimiento->format('Y-m-d'),
                $montoTotal,
                $deps['total_dependientes'],
                $deps['total_geriatricos'] > 0 ? 1 : 0
            ]);

            // Actualizar fecha_fin del contrato
            $actualizacion = actualizarFechaFinContrato($conn, $contrato['id']);
            if (!$actualizacion) {
                error_log("No se pudo actualizar la fecha_fin para el contrato ID: " . $contrato['id']);
            } else {
                error_log("Fecha fin actualizada exitosamente para el contrato ID: " . $contrato['id']);
            }

            $facturasGeneradas++;
        }

        // Liberar bloqueo
        $stmt = $conn->prepare("
            UPDATE generacion_facturas_lock 
            SET estado = 'inactivo' 
            WHERE usuario_id = ? 
            AND estado = 'activo'
        ");
        $stmt->execute([$_SESSION['usuario_id']]);

        $conn->commit();
        $mensaje = "Se generaron $facturasGeneradas facturas exitosamente.";
        $tipo_mensaje = "success";

    } catch(PDOException $e) {
        $conn->rollBack();
        $mensaje = "Error al generar facturas: " . $e->getMessage();
        $tipo_mensaje = "error";

        // Liberar bloqueo en caso de error
        $stmt = $conn->prepare("
            UPDATE generacion_facturas_lock 
            SET estado = 'inactivo' 
            WHERE usuario_id = ? 
            AND estado = 'activo'
        ");
        $stmt->execute([$_SESSION['usuario_id']]);
    }
}


// Obtener filtros
$where = "1=1";
$params = [];

if (isset($_GET['estado']) && !empty($_GET['estado'])) {
    $where .= " AND f.estado = ?";
    $params[] = $_GET['estado'];
}

if (isset($_GET['numero_contrato']) && !empty($_GET['numero_contrato'])) {
    $where .= " AND c.numero_contrato = ?";
    $params[] = $_GET['numero_contrato'];
}

if (isset($_GET['numero_factura']) && !empty($_GET['numero_factura'])) {
    $where .= " AND f.numero_factura = ?";
    $params[] = $_GET['numero_factura'];
}

if (isset($_GET['mes_desde']) && !empty($_GET['mes_desde'])) {
    $mes_desde = date('m/Y', strtotime($_GET['mes_desde']));
    $where .= " AND f.mes_factura >= ?";
    $params[] = $mes_desde;
}

if (isset($_GET['mes_hasta']) && !empty($_GET['mes_hasta'])) {
    $mes_hasta = date('m/Y', strtotime($_GET['mes_hasta']));
    $where .= " AND f.mes_factura <= ?";
    $params[] = $mes_hasta;
}


// Configuración de paginación
$por_pagina = isset($_COOKIE['facturas_por_pagina']) ? (int)$_COOKIE['facturas_por_pagina'] : 50;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_actual - 1) * $por_pagina;

// Obtener el total de facturas según los filtros
$sql_total = "
    SELECT COUNT(*) as total
    FROM facturas f
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN planes p ON c.plan_id = p.id
    WHERE $where
";

$stmt_total = $conn->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = $stmt_total->fetch()['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Consulta principal con paginación
$sql = "
    SELECT f.*,
           c.numero_contrato,
           cl.codigo as cliente_codigo,
           cl.nombre as cliente_nombre,
           cl.apellidos as cliente_apellidos,
           p.nombre as plan_nombre,
           (
               SELECT SUM(p.monto)
               FROM pagos p
               WHERE p.factura_id = f.id
               AND p.tipo_pago = 'abono'
               AND p.estado = 'procesado'
           ) as total_abonado,
           (
               SELECT COUNT(*)
               FROM asignaciones_facturas af 
               WHERE af.factura_id = f.id 
               AND af.estado = 'activa'
           ) as esta_asignada
    FROM facturas f
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN planes p ON c.plan_id = p.id
    WHERE $where
    ORDER BY CAST(f.numero_factura AS UNSIGNED) DESC
    LIMIT $por_pagina
    OFFSET $offset
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$facturas = $stmt->fetchAll();

// Calcular totales
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_facturas,
        SUM(CASE WHEN f.estado = 'pendiente' THEN 1 ELSE 0 END) as facturas_pendientes,
        SUM(CASE WHEN f.estado = 'incompleta' THEN 1 ELSE 0 END) as facturas_incompletas,
        COUNT(DISTINCT f.contrato_id) as total_clientes
    FROM facturas f
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN planes p ON c.plan_id = p.id
    WHERE $where
");
$stmt->execute($params);
$totales = $stmt->fetch();
?>

<div class="facturacion-container">
    <!-- Resumen de Pagos -->
    <div class="dashboard-stats fade-in">
        <div class="stat-card blue">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3>Clientes Atendidos</h3>
                    <p class="stat-value"><?php echo number_format($totales['total_clientes'] ?? 0); ?></p>
                    <p class="stat-label">Total registrados</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card green">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-info">
                    <h3>Facturas Mostradas</h3>
                    <p class="stat-value"><?php echo number_format($totales['total_facturas'] ?? 0); ?></p>
                    <p class="stat-label">Total facturas</p>
                </div>
            </div>
            <div class="stat-trend up"><i class="fas fa-file-invoice"></i></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <h3>Facturas Pendientes</h3>
                    <p class="stat-value"><?php echo number_format($totales['facturas_pendientes'] ?? 0); ?></p>
                    <p class="stat-label">Por cobrar</p>
                </div>
            </div>
            <div class="stat-trend down"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-card red">
            <div class="stat-content">
                <div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-info">
                    <h3>Facturas Incompletas</h3>
                    <p class="stat-value"><?php echo number_format($totales['facturas_incompletas'] ?? 0); ?></p>
                    <p class="stat-label">Con abonos parciales</p>
                </div>
            </div>
            <div class="stat-trend down"><i class="fas fa-exclamation-circle"></i></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Gestión de Facturación</h2>
            <div class="header-buttons">
                <div class="button-group left">
                    <button class="btn btn-primary" onclick="mostrarModalImpresion()">
                        <i class="fas fa-print"></i> Imprimir Facturas
                    </button>
                    <button class="btn btn-info" onclick="mostrarModalImpresionLote()">
                        <i class="fas fa-print"></i> Imprimir por Lote
                    </button>
                    <button class="btn btn-secondary" onclick="exportarFacturas()">
                        <i class="fas fa-download"></i> Exportar
                    </button>
                </div>
                <div class="button-group right">
                    <button class="btn btn-danger" onclick="verificarYGenerarFacturas()">
                        <i class="fas fa-file-invoice"></i> Generar Facturas
                    </button>
                    <button class="btn btn-warning" onclick="mostrarModalGeneracionLote()">
                        <i class="fas fa-layer-group"></i> Generar Por Lote
                    </button>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filtros-bar fade-in">
            <div class="search-wrap">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" placeholder="Buscar factura o contrato..." value="<?php echo isset($_GET['numero_contrato']) ? htmlspecialchars($_GET['numero_contrato']) : ''; ?>">
            </div>
            <select id="estadoFilter" class="filter-select" onchange="aplicarFiltros()">
                <option value="" <?php echo empty($_GET['estado']) ? 'selected' : ''; ?>>Todos los estados</option>
                <option value="pendiente" <?php echo ($_GET['estado'] ?? '') === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                <option value="pagada" <?php echo ($_GET['estado'] ?? '') === 'pagada' ? 'selected' : ''; ?>>Pagada</option>
                <option value="incompleta" <?php echo ($_GET['estado'] ?? '') === 'incompleta' ? 'selected' : ''; ?>>Incompleta</option>
                <option value="vencida" <?php echo ($_GET['estado'] ?? '') === 'vencida' ? 'selected' : ''; ?>>Vencida</option>
            </select>
            <button class="btn btn-secondary btn-sm" onclick="limpiarFiltros()"><i class="fas fa-rotate-right"></i> Limpiar</button>
            <button class="btn btn-secondary btn-sm" id="toggleAdvancedFilters"><i class="fas fa-sliders-h"></i> Filtros avanzados</button>
        </div>

        <!-- Filtros avanzados - desplegable -->
        <div class="advanced-filters" id="advancedFilters" style="display:none; margin-top: 15px;">
            <div class="filter-row">
                <div class="filter-item">
                    <label for="mes_desde">Mes Factura Desde</label>
                    <input type="month" id="mes_desde" name="mes_desde" class="form-control"
                           value="<?php echo $_GET['mes_desde'] ?? ''; ?>">
                </div>
                <div class="filter-item">
                    <label for="mes_hasta">Mes Factura Hasta</label>
                    <input type="month" id="mes_hasta" name="mes_hasta" class="form-control"
                           value="<?php echo $_GET['mes_hasta'] ?? ''; ?>">
                </div>
                <div class="filter-item">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="aplicarFiltros()">
                        <i class="fas fa-search"></i> Aplicar filtros
                    </button>
                </div>
            </div>
        </div>

        <!-- Tabla de Facturas -->
        <div class="clientes-table-wrap fade-in delay-1">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-file-invoice"></i> Facturas</div>
                    <div class="card-subtitle">Mostrando <?php echo min($offset + 1, $total_registros); ?>–<?php echo min($offset + $por_pagina, $total_registros); ?> de <?php echo number_format($total_registros); ?> facturas</div>
                </div>
            </div>
            <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>No Factura</th>
                        <th>Asignada</th>
                        <th>Nombres y Apellidos</th>
                        <th>Contrato</th>
                        <th>Mes</th>
                        <th>Emisión</th>
                        <th>Vencimiento</th>
                        <th>Monto</th>
                        <th>Pendiente</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facturas as $factura): 
                        $montoPendiente = $factura['monto'] - ($factura['total_abonado'] ?? 0);
                    ?>
                        <tr>
                            <td><input type="checkbox" class="factura-checkbox"></td>
                            <td><?php echo htmlspecialchars($factura['numero_factura']); ?></td>
                            <td>
                                <?php 
                                    if ($factura['esta_asignada'] > 0) {
                                        echo '<span class="badge badge-danger" style="font-size: 14px;">SI</span>';
                                    } else {
                                        echo '<span style="font-size: 14px;">NO</span>';
                                    }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($factura['cliente_nombre'] . ' ' . $factura['cliente_apellidos']); ?></td>
                            <td><?php echo htmlspecialchars($factura['numero_contrato']); ?></td>
                            <td><?php
                                    $meses = [
                                        '01' => 'Ene', '02' => 'Feb', '03' => 'Mar',
                                        '04' => 'Abr', '05' => 'May', '06' => 'Jun',
                                        '07' => 'Jul', '08' => 'Ago', '09' => 'Sep',
                                        '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'
                                    ];
                                    $mesYear = explode('/', $factura['mes_factura']);
                                    echo $meses[$mesYear[0]] . '/' . $mesYear[1];
                                ?></td>
                            <td><?php echo formatearFecha($factura['fecha_emision']); ?></td>
                            <td><?php echo formatearFecha($factura['fecha_vencimiento']); ?></td>
                            <td>RD$<?php echo number_format($factura['monto'], 2); ?></td>
                            <td>
                                <?php if ($factura['estado'] === 'incompleta'): ?>
                                    <span class="text-warning">RD$<?php echo number_format($montoPendiente, 2); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status <?php 
                                    echo $factura['estado'] == 'pagada' ? 'active' : 
                                        ($factura['estado'] == 'pendiente' ? 'warning' : 
                                        ($factura['estado'] == 'vencida' ? 'inactive' : 'pending')); 
                                ?>">
                                    <?php echo ucfirst($factura['estado']); ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <button class="btn-action view" title="Imprimir factura" onclick="imprimirFactura(<?php echo $factura['id']; ?>)">
                                    <i class="fas fa-print"></i>
                                </button>
                                <?php if ($factura['estado'] != 'pagada'): ?>
                                    <a href="registrar_pago.php?factura_id=<?php echo $factura['id']; ?>" 
                                       class="btn-action edit" title="Registrar pago"
                                       <?php if ($factura['estado'] == 'pendiente'): ?>
                                           onclick="return verificarFacturasIncompletas(<?php echo $factura['contrato_id']; ?>)"
                                       <?php endif; ?>>
                                        <i class="fas fa-dollar-sign"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($total_paginas > 1):
    $params_url = http_build_query(array_filter([
        'numero_contrato' => $_GET['numero_contrato'] ?? '',
        'estado' => $_GET['estado'] ?? '',
        'mes_desde' => $_GET['mes_desde'] ?? '',
        'mes_hasta' => $_GET['mes_hasta'] ?? '',
    ]));
?>
<div class="paginador-wrap">
    <div class="paginador-info">
        Mostrando <strong><?php echo min($offset + 1, $total_registros); ?></strong>–<strong><?php echo min($offset + $por_pagina, $total_registros); ?></strong>
        de <strong><?php echo number_format($total_registros); ?></strong> facturas
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
</div>

<!-- Modal de Confirmación de Generación de Facturas -->
<div class="modal" id="confirmacionModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Generación de Facturas en Proceso</h5>
                <button type="button" class="close" onclick="cerrarModal()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p id="mensajeBloqueo"></p>
                <p>¿Desea continuar con la generación de facturas?</p>
                <div class="contador-container">
                    <p>El botón se habilitará en: <span id="contador">10</span> segundos</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" onclick="cerrarModal()">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnGenerarFacturas" disabled
                        onclick="generarFacturas()">Si, Generar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Impresión de Facturas -->
<div class="modal" id="impresionModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Impresión de Facturas por Días</h4>
                <button type="button" class="close-modal" onclick="cerrarModalImpresion()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="formImpresion">
                    <div class="filtros-grid">
                        <div class="form-group">
                            <label for="estado_factura">Estado Factura</label>
                            <select class="form-control" id="estado_factura" name="estado">
                                <option value="">Todos</option>
                                <option value="pendiente" selected>Pendiente</option>
                                <option value="pagada">Pagada</option>
                                <option value="vencida">Vencida</option>
                                <option value="incompleta">Incompleta</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="contrato">Filtrar por Contrato</label>
                            <input type="text" class="form-control" id="contrato" name="contrato" 
                                   placeholder="Ej: 00001">
                        </div>
                        
                        <div class="form-group">
                            <label for="dia_cobro_desde">Día Cobro Desde</label>
                            <input type="number" 
                                   class="form-control" 
                                   id="dia_cobro_desde" 
                                   name="dia_cobro_desde" 
                                   min="1" 
                                   max="31" 
                                   step="1" 
                                   oninput="validarDiaCobro(this)"
                                   placeholder="Solo del 1 al 31">
                        </div>
                
                        <div class="form-group">
                            <label for="dia_cobro_hasta">Día Cobro Hasta</label>
                            <input type="number" 
                                   class="form-control" 
                                   id="dia_cobro_hasta" 
                                   name="dia_cobro_hasta" 
                                   min="1" 
                                   max="31" 
                                   step="1" 
                                   oninput="validarDiaCobro(this)"
                                   placeholder="Solo del 1 al 31">
                        </div>

                        <div class="form-group">
                            <label for="fecha_desde_modal">Fecha Emisión Desde</label>
                            <input type="date" class="form-control" id="fecha_desde_modal" name="fecha_desde">
                        </div>

                        <div class="form-group">
                            <label for="fecha_hasta_modal">Fecha Emisión Hasta</label>
                            <input type="date" class="form-control" id="fecha_hasta_modal" name="fecha_hasta">
                        </div>
                        
                        <div class="form-group">
                            <label for="mes_desde_modal">Mes Factura Desde</label>
                            <input type="month" class="form-control" id="mes_desde_modal" name="mes_desde">
                        </div>

                        <div class="form-group">
                            <label for="mes_hasta_modal">Mes Factura Hasta</label>
                            <input type="month" class="form-control" id="mes_hasta_modal" name="mes_hasta">
                        </div>
                    </div>
                    
                    <div class="form-group">
                            <label for="estatus_contrato">Estatus Contrato</label>
                            <select class="form-control" id="estatus_contrato" name="estatus_contrato">
                                <option value="activo" selected>Activo</option>
                                <option value="cancelado">Cancelado</option>
                                <option value="suspendido">Suspendido</option>
                                <option value="">Todos</option>
                            </select>
                        </div>

                    <div id="preview-facturas" style="display: none; max-height: 400px; overflow-y: auto;">
                        <h6>Facturas Encontradas:</h6>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" id="seleccionar-todas" 
                                                   onclick="seleccionarTodas(this)">
                                        </th>
                                        <th>No Factura</th>
                                        <th>Asignada</th>
                                        <th>Contrato</th>
                                        <th>Cliente</th>
                                        <th>Monto</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody id="lista-facturas"></tbody>
                            </table>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <div class="counter-section">
                    <span id="total-seleccionadas" class="facturas-counter">0 facturas seleccionadas</span>
                </div>
                <button type="button" class="btn btn-secondary" onclick="cerrarModalImpresion()">
                    Cerrar
                </button>
                <button type="button" class="btn btn-primary" onclick="cargarFacturas()">
                    Cargar Facturas
                </button>
                <button type="button" class="btn btn-success" id="btn-imprimir" style="display: none;" onclick="imprimirFacturas('preview')">
                    Ver Facturas
                </button>
                <button type="button" class="btn btn-warning" id="btn-imprimir-directo" style="display: none;" onclick="imprimirFacturas('direct')">
                    Imprimir Directo
                </button>
            </div>
        </div>
    </div>
</div>
    
    
    <!-- Modal de Generación por Lote -->
<div class="modal" id="generacionLoteModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Generar Facturas Por Lote</h4>
                <button type="button" class="close" onclick="cerrarModalGeneracionLote()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="formGeneracionLote">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="contrato_lote">Número de Contrato</label>
                            <input type="text" class="form-control" id="contrato_lote" 
                                   placeholder="Ej: 00102" onchange="verificarContrato()">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="cantidad_facturas">Cantidad de Facturas</label>
                            <input type="number" class="form-control" id="cantidad_facturas" 
                                   min="1" max="12" placeholder="Máximo 12">
                        </div>
                    </div>
                    <div id="info_cliente" style="display: none;">
                        <div class="alert alert-info">
                            <strong>Cliente:</strong> <span id="nombre_cliente"></span>
                        </div>
                    </div>
                    <div id="preview_facturas" style="display: none;">
                        <h5>Vista Previa de Facturas</h5>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Contrato</th>
                                        <th>Mes</th>
                                        <th>Monto</th>
                                        <th>Cuota</th>
                                    </tr>
                                </thead>
                                <tbody id="preview_facturas_body"></tbody>
                            </table>
                        </div>
                    </div>
                    <div id="progreso_generacion" style="display: none;">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="preGenerarFacturas()">
                    Pre-generar
                </button>
                <button type="button" class="btn btn-success" id="btn_generar" 
                        style="display: none;" onclick="generarFacturasLote()">
                    Generar
                </button>
                <button type="button" class="btn btn-secondary" onclick="cerrarModalGeneracionLote()">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmación -->
<div class="modal" id="confirmacionGeneracionModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Generación Exitosa</h5>
                <button type="button" class="close" onclick="cerrarConfirmacionGeneracion()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Las facturas han sido generadas exitosamente.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="cerrarConfirmacionGeneracion()">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>


<!-- Modal de Impresión por Lote -->
<div class="modal" id="modalImpresionLote">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Imprimir Facturas por Lote</h4>
                <button type="button" class="close" onclick="cerrarModalImpresionLote()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="formImpresionLote">
                    <div class="form-group">
                        <label for="numero_factura_lote">Número de Factura</label>
                        <div class="input-group">
                            <input type="text" id="numero_factura_lote" class="form-control" 
                                   placeholder="Ingrese el número de factura y presione Enter">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-primary" onclick="agregarFacturaLote()">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de facturas a imprimir -->
                    <div id="lista_facturas_lote" class="table-responsive" style="display: none;">
                        <h5>Facturas seleccionadas:</h5>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>No.Factura</th>
                                    <th>Asignada</th>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Mes</th>
                                    <th>Monto</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="facturas_seleccionadas_lote"></tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalImpresionLote()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="imprimirFacturasLote()">
                    Imprimir
                </button>
            </div>
        </div>
    </div>
</div>
    
    
    
    
</div>

<style>



/* DISEÑO PARA EL SCROLL DEL MODAL "Generar Facturas Por Lote"
#preview_facturas .table-responsive {
    margin-bottom: 1rem;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

#preview_facturas thead {
    position: sticky;
    top: 0;
    background-color: white;
    z-index: 1;
    border-bottom: 2px solid #dee2e6;
}

#preview_facturas tbody tr:last-child {
    border-bottom: none;
}


#preview_facturas .table-responsive::-webkit-scrollbar {
    width: 8px;
}

#preview_facturas .table-responsive::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

#preview_facturas .table-responsive::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

#preview_facturas .table-responsive::-webkit-scrollbar-thumb:hover {
    background: #555;
}
*/

/* Estilo para checkbox indeterminado */
input[type="checkbox"]:indeterminate {
    background-color: #1a73e8;
    border-color: #1a73e8;
}

input[type="checkbox"]:indeterminate::after {
    content: '';
    position: absolute;
    top: 8px;
    left: 4px;
    width: 10px;
    height: 2px;
    background-color: white;
}

/* Estilos para checkboxes */
input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    border: 1px solid #8e9299;
    border-radius: 3px;
    background-color: white;
    appearance: none;
    -webkit-appearance: none;
    position: relative;
    vertical-align: middle;
    transition: all 0.2s;
}

input[type="checkbox"]:checked {
    background-color: #1a73e8;
    border-color: #1a73e8;
}

input[type="checkbox"]:checked::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 6px;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

input[type="checkbox"]:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
}

#selectAll {
    margin: 0;
}

.usuario-checkbox {
    margin: 0;
}


/* Estilo específico para el botón de cierre del modal */
.close-modal {
    background: none;
    border: none;
    color: #5f6368;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.close-modal:hover {
    background-color: rgba(0, 0, 0, 0.05);
    color: #1a73e8;
}

.close-modal i {
    line-height: 1;
}







/* Estilos específicos para facturación */
.badge-danger {
    background-color: #dc3545;
    color: white;
    font-size: 14px;
}

.td-asignacion {
    font-size: 14px;
}

.facturacion-container {
    margin-bottom: 2rem;
}

.filtros-section {
    padding: 1.5rem;
    background-color: #f9fafb;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.filtros-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr); /* Ajustado a 5 columnas en lugar de 7 */
    gap: 1rem;
    align-items: start;
}

.form-actions {
    display: flex;
    gap: 1rem;
}

.header-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.left-buttons {
    display: flex;
    gap: 10px;
}

.right-buttons {
    margin-left: auto;
}

.btn-danger {
    background-color: #dc3545;
    border-color: #dc3545;
    color: white;
}

.btn-danger:hover {
    background-color: #c82333;
    border-color: #bd2130;
}

.btn-group {
    display: flex;
    gap: 0.5rem;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal.show {
    display: flex !important;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    
}

.modal.show {
    pointer-events: auto !important;
}

.modal-dialog {
    position: relative;
    width: 100%;
    max-width: 800px;
    margin: 30px auto;
    z-index: 1001;
}


.contador-container {
    text-align: center;
    margin: 1rem 0;
    font-size: 1.2rem;
    font-weight: bold;
}

.badge-info {
    background-color: #ff9800;
    color: white;
}














.modal-dialog.modal-lg {
    max-width: 1000px;
    width: 95%;
    margin: 0 auto;
    transition: transform 0.3s ease;
}

.modal-content {
    display: flex;
    flex-direction: column;
    max-height: 80vh;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.modal-header {
    border-bottom: 1px solid #dee2e6;
    padding: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    background: white;
    z-index: 2;
}

.modal-header .close {
    padding: 0.5rem;
    margin: -0.5rem -0.5rem -0.5rem auto;
    font-size: 1.5rem;
    background: none;
    border: none;
    cursor: pointer;
}

.modal-body {
    flex: 1;
    overflow: hidden;
    padding: 1rem;
    position: relative;
}

#lista_facturas_lote {
    max-height: calc(70vh - 180px); /* Ajusta este valor según necesites */
    overflow-y: auto;
    margin-top: 1rem;
}



.counter-section {
    display: flex;
    align-items: center;
}

.facturas-counter {
    font-size: 14px;
    font-weight: 500;
    color: #5f6368;
    background-color: #e8f0fe;
    padding: 6px 12px;
    border-radius: 16px;
    display: inline-block;
}

.buttons-section {
    display: flex;
    gap: 8px;
}

/* Estilo para la tabla dentro del contenedor scrolleable */
#lista_facturas_lote .table {
    margin-bottom: 0;
}
















.form-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.form-group label {
    font-weight: 500;
    color: #374151;
}

.botones-filtro {
    display: flex;
    gap: 1rem;
    
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: 12px 20px;
    border-top: 1px solid #e9ecef;
    background-color: #f8f9fa;
    border-radius: 0 0 8px 8px;
}


.modal-footer .btn {
    padding: 0.5rem 1rem;
    border-radius: 4px;
}

#impresionModal .filtros-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

#impresionModal .form-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

@media (max-width: 1200px) {
    #impresionModal .filtros-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    #impresionModal .filtros-grid {
        grid-template-columns: 1fr;
    }
    
    .filters-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-item {
        width: 100% !important;
        margin-bottom: 10px;
    }
    
    .modal-footer {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .buttons-section {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .counter-section {
        width: 100%;
        justify-content: center;
        margin-bottom: 8px;
    }
}


@media (max-width: 768px) {
    .filtros-grid {
        grid-template-columns: 1fr;
    }

    .header-actions,
    .form-actions {
        flex-direction: column;
    }

    .btn-group {
        flex-direction: column;
    }
}

.paginador {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin: 2rem 0;
    padding: 1rem;
}

.paginador .btn-link {
    text-decoration: none;
    color: #007bff;
    font-weight: 500;
}

.paginador .btn-link.disabled {
    color: #6c757d;
    pointer-events: none;
}

.paginador .pagina-actual {
    padding: 0.5rem 1rem;
    background-color: #f8f9fa;
    border-radius: 4px;
    color: #495057;
}

/* Estilos para filtros-bar */
.filtros-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 1rem;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.search-wrap {
    position: relative;
    flex: 1;
    min-width: 250px;
}

.search-wrap input {
    width: 100%;
    padding: 0.5rem 0.75rem 0.5rem 2.5rem;
    border: 1px solid #dadce0;
    border-radius: 6px;
    font-size: 14px;
}

.search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #5f6368;
    font-size: 14px;
}

.filter-select {
    padding: 0.5rem 0.75rem;
    border: 1px solid #dadce0;
    border-radius: 6px;
    background-color: white;
    font-size: 14px;
    color: #202124;
    min-width: 160px;
}

.btn-sm {
    padding: 0.5rem 0.75rem;
    font-size: 13px;
}

/* Estilos para clientes-table-wrap */
.clientes-table-wrap {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    overflow: hidden;
    margin-bottom: 1rem;
}

.clientes-table-wrap .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem;
    border-bottom: 1px solid #e9ecef;
    background-color: #f8f9fa;
}

.clientes-table-wrap .card-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 16px;
    font-weight: 600;
    color: #202124;
    margin: 0;
}

.clientes-table-wrap .card-subtitle {
    font-size: 13px;
    color: #5f6368;
    margin-top: 0.25rem;
}

/* Estilos para paginador-wrap */
.paginador-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background-color: #f8f9fa;
    border-radius: 0 0 8px 8px;
    flex-wrap: wrap;
    gap: 1rem;
}

.paginador-info {
    font-size: 14px;
    color: #5f6368;
}

.paginador-pages {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pag-btn {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #dadce0;
    border-radius: 50%;
    background-color: white;
    color: #5f6368;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 14px;
    text-decoration: none;
}

.pag-btn:hover:not(.disabled):not(.ellipsis) {
    background-color: #e8f0fe;
    color: #1a73e8;
    border-color: #1a73e8;
}

.pag-btn.active {
    background-color: #1a73e8;
    color: white;
    border-color: #1a73e8;
}

.pag-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pag-btn.ellipsis {
    cursor: default;
    border: none;
}

/* Animaciones */
.fade-in {
    animation: fadeIn 0.3s ease-in;
}

.delay-1 {
    animation-delay: 0.1s;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

</style>

<script>
let contadorInterval;
let tiempoRestante = 10;

function verificarYGenerarFacturas() {
    fetch('verificar_bloqueo_facturas.php')
        .then(response => response.json())
        .then(data => {
            if (data.bloqueo) {
                mostrarModalConfirmacion(data.mensaje);
            } else {
                generarFacturas();
            }
        })
        .catch(error => console.error('Error:', error));
}

function mostrarModalConfirmacion(mensaje) {
    document.getElementById('mensajeBloqueo').textContent = mensaje;
    document.getElementById('confirmacionModal').classList.add('show');
    iniciarContador();
}

function cerrarModal() {
    document.getElementById('confirmacionModal').classList.remove('show');
    detenerContador();
}

function iniciarContador() {
    tiempoRestante = 10;
    document.getElementById('contador').textContent = tiempoRestante;
    document.getElementById('btnGenerarFacturas').disabled = true;
    
    contadorInterval = setInterval(() => {
        tiempoRestante--;
        document.getElementById('contador').textContent = tiempoRestante;
        
        if (tiempoRestante <= 0) {
            detenerContador();
            document.getElementById('btnGenerarFacturas').disabled = false;
        }
    }, 1000);
}

function detenerContador() {
    clearInterval(contadorInterval);
    tiempoRestante = 10;
    document.getElementById('contador').textContent = tiempoRestante;
}

function generarFacturas() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="generar_facturas">';
    document.body.appendChild(form);
    form.submit();
}

function exportarFacturas() {
    const estado = document.getElementById('estado').value;
    const fechaDesde = document.getElementById('fecha_desde').value;
    const fechaHasta = document.getElementById('fecha_hasta').value;

    let url = 'exportar_facturas.php?formato=excel';
    if (estado) url += `&estado=${estado}`;
    if (fechaDesde) url += `&fecha_desde=${fechaDesde}`;
    if (fechaHasta) url += `&fecha_hasta=${fechaHasta}`;

    window.location.href = url;
}

function limpiarFiltros() {
    document.getElementById('estado').value = '';
    document.getElementById('numero_factura').value = '';
    document.getElementById('numero_contrato').value = '';
    document.getElementById('mes_desde').value = '';
    document.getElementById('mes_hasta').value = '';
    document.getElementById('filtrosForm').submit();
}

function imprimirFactura(id) {
    window.open(`imprimir_factura.php?id=${id}`, '_blank');
}

async function verificarFacturasIncompletas(contratoId) {
    try {
        const response = await fetch(`verificar_facturas.php?contrato_id=${contratoId}`);
        const data = await response.json();
        
        if (data.tiene_incompletas) {
            mostrarToast('Debe pagar primero la factura incompleta', 'error', 8000);
            return false;
        }
        return true;
    } catch (error) {
        console.error('Error:', error);
        return false;
    }
}


function validarDiaCobro(input) {
    // Remover cualquier número decimal
    input.value = input.value.replace(/[.,]/g, '');
    
    // Convertir a número entero
    let valor = parseInt(input.value);
    
    // Si no es un número, dejar vacío
    if (isNaN(valor)) {
        input.value = '';
        return;
    }
    
    // Limitar al rango 1-31
    if (valor < 1) {
        input.value = '1';
    } else if (valor > 31) {
        input.value = '31';
    } else {
        input.value = valor;
    }

    // Validar que el rango desde-hasta sea correcto
    const diaDesde = parseInt(document.getElementById('dia_cobro_desde').value) || 0;
    const diaHasta = parseInt(document.getElementById('dia_cobro_hasta').value) || 0;

    if (diaDesde && diaHasta && diaDesde > diaHasta) {
        mostrarToast('El día desde no puede ser mayor que el día hasta', 'error');
        document.getElementById('dia_cobro_desde').value = '';
        document.getElementById('dia_cobro_hasta').value = '';
    }
}

// Agregar validación al formulario antes de enviar
document.getElementById('formImpresion').addEventListener('submit', function(e) {
    const diaDesde = parseInt(document.getElementById('dia_cobro_desde').value);
    const diaHasta = parseInt(document.getElementById('dia_cobro_hasta').value);

    if (diaDesde && diaHasta && diaDesde > diaHasta) {
        e.preventDefault();
        mostrarToast('El día desde no puede ser mayor que el día hasta', 'error');
        return false;
    }
});




function mostrarToast(mensaje, tipo = 'info', duracion = 5000) {
    Toastify({
        text: mensaje,
        duration: duracion,
        close: true,
        gravity: "top",
        position: "right",
        backgroundColor: tipo === 'error' ? "#dc3545" : 
                        (tipo === 'success' ? "#28a745" : 
                        (tipo === 'warning' ? "#ffc107" : "#17a2b8")),
        stopOnFocus: true
    }).showToast();
}

 

/*
ESTE CODIGO ES PARA QUE LAS FECHA DE "Fecha Emisión Desde" y "Fecha Emisión Hasta" TENGA VALORES 
DESDE SERA EL PRIMER DIA DEL  MES ACTUAL Y HASTA TENDRA EL DIA ACTUAL EN EL MES ACTUAL


document.addEventListener('DOMContentLoaded', function() {
    if (!window.location.search.includes('fecha_desde')) {
        const hoy = new Date();
        const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
        
        document.getElementById('fecha_desde').valueAsDate = primerDiaMes;
        document.getElementById('fecha_hasta').valueAsDate = hoy;
    }

    document.getElementById('filtrosForm').addEventListener('submit', function(e) {
        const fechaDesde = document.getElementById('fecha_desde').value;
        const fechaHasta = document.getElementById('fecha_hasta').value;

        if (fechaDesde && fechaHasta && fechaDesde > fechaHasta) {
            e.preventDefault();
            mostrarToast('La fecha desde no puede ser mayor que la fecha hasta', 'error');
        }
    });
});

// Agregar las nuevas funciones para el modal de impresión
function mostrarModalImpresion() {
    document.getElementById('impresionModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Establecer fechas por defecto
    const hoy = new Date();
    const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    document.getElementById('fecha_desde').valueAsDate = primerDiaMes;
    document.getElementById('fecha_hasta').valueAsDate = hoy;
}

ESTE CODIGO ES PARA QUE LAS FECHA DE "Fecha Emisión Desde" y "Fecha Emisión Hasta" TENGA VALORES 
DESDE SERA EL PRIMER DIA DEL  MES ACTUAL Y HASTA TENDRA EL DIA ACTUAL EN EL MES ACTUAL
*/

document.addEventListener('DOMContentLoaded', function() {
    // Eliminamos la asignación automática de fechas
    document.getElementById('filtrosForm').addEventListener('submit', function(e) {
        const fechaDesde = document.getElementById('fecha_desde').value;
        const fechaHasta = document.getElementById('fecha_hasta').value;

        if (fechaDesde && fechaHasta && fechaDesde > fechaHasta) {
            e.preventDefault();
            mostrarToast('La fecha desde no puede ser mayor que la fecha hasta', 'error');
        }
    });
});

// Función del modal de impresión modificada
function mostrarModalImpresion() {
    document.getElementById('impresionModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Eliminamos la asignación automática de fechas en el modal
    document.getElementById('fecha_desde_modal').value = '';
    document.getElementById('fecha_hasta_modal').value = '';
    limpiarFormularioImpresion();
}


function limpiarFormularioImpresion() {
    // Limpiar todos los campos del formulario
    document.getElementById('estado_factura').value = 'pendiente'; // Volver al valor por defecto
    document.getElementById('estatus_contrato').value = 'activo'; // Valor por defecto para estatus contrato
    document.getElementById('contrato').value = '';
    document.getElementById('dia_cobro_desde').value = '';
    document.getElementById('dia_cobro_hasta').value = '';
    document.getElementById('fecha_desde_modal').value = '';
    document.getElementById('fecha_hasta_modal').value = '';
    document.getElementById('mes_desde_modal').value = '';
    document.getElementById('mes_hasta_modal').value = '';

    // Limpiar la tabla de facturas
    document.getElementById('lista-facturas').innerHTML = '';
    
    // Ocultar la sección de preview y los botones
    document.getElementById('preview-facturas').style.display = 'none';
    document.getElementById('btn-imprimir').style.display = 'none';
    document.getElementById('btn-imprimir-directo').style.display = 'none';
}


function cerrarModalImpresion() {
    document.getElementById('impresionModal').classList.remove('show');
    document.body.style.overflow = '';
    limpiarFormularioImpresion();
}

function formatearMesFactura(mesFactura) {
    // Separar el mes y el año
    const [mes, año] = mesFactura.split('/');
    
    // Array con los nombres abreviados de los meses
    const meses = {
        '01': 'Ene',
        '02': 'Feb',
        '03': 'Mar',
        '04': 'Abr',
        '05': 'May',
        '06': 'Jun',
        '07': 'Jul',
        '08': 'Ago',
        '09': 'Sep',
        '10': 'Oct',
        '11': 'Nov',
        '12': 'Dic'
    };
    
    // Retornar el formato deseado
    return `${meses[mes]}/${año}`;
}

function cargarFacturas() {
    const formData = new FormData(document.getElementById('formImpresion'));
    const params = new URLSearchParams(formData);
    
    fetch('buscar_facturas.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('lista-facturas');
            tbody.innerHTML = '';
            
            data.forEach(factura => {
                tbody.innerHTML += `
                    <tr>
                        <td><input type="checkbox" name="facturas[]" value="${factura.id}" class="factura-check"></td>
                        <td>${factura.numero_factura}</td>
                        <td>${factura.esta_asignada > 0 ? 
                            '<span class="badge badge-danger" style="font-size: 14px;">SI</span>' : 
                            '<span style="font-size: 14px;">NO</span>'}</td>
                        <td>${factura.numero_contrato}</td>
                        <td>${factura.cliente_nombre} ${factura.cliente_apellidos}</td>
                        <td>RD$${parseFloat(factura.monto).toFixed(2)}</td>
                        <td>${formatearMesFactura(factura.mes_factura)}</td>
                    </tr>
                `;
            });
            
            document.getElementById('preview-facturas').style.display = 'block';
            document.getElementById('btn-imprimir').style.display = 'block';
            document.getElementById('btn-imprimir-directo').style.display = 'block';
            actualizarContador();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al cargar las facturas');
        });
}

function seleccionarTodas(checkbox) {
    const checkboxes = document.getElementsByClassName('factura-check');
    for (let cb of checkboxes) {
        cb.checked = checkbox.checked;
    }
    actualizarContador();
}

function actualizarContador() {
    const seleccionadas = document.querySelectorAll('.factura-check:checked').length;
    const totalSeleccionadas = document.getElementById('total-seleccionadas');
    
    if (totalSeleccionadas) {
        totalSeleccionadas.textContent = `${seleccionadas} factura${seleccionadas !== 1 ? 's' : ''} seleccionada${seleccionadas !== 1 ? 's' : ''}`;
        
        // Añadir una clase visual basada en si hay facturas seleccionadas
        if (seleccionadas > 0) {
            totalSeleccionadas.classList.add('has-selected');
        } else {
            totalSeleccionadas.classList.remove('has-selected');
        }
    }
}

function imprimirFacturas(tipo) {
    const facturas = Array.from(document.querySelectorAll('.factura-check:checked')).map(cb => cb.value);
    if (facturas.length === 0) {
        alert('Por favor, seleccione al menos una factura para imprimir');
        return;
    }
    
    const params = new URLSearchParams(new FormData(document.getElementById('formImpresion')));
    params.delete('facturas[]');
    facturas.forEach(id => params.append('facturas[]', id));
    params.append('tipo', tipo);
    
    window.open('imprimirpordias.php?' + params.toString(), '_blank');
}

// Agregar listener para actualizar contador
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('factura-check')) {
        actualizarContador();
    }
    
});
    
// Variables globales para el control del modal
let modalLoteAbierto = false;
let datosPreGeneracion = null;

function mostrarModalGeneracionLote() {
    // Verificar si el modal ya está abierto por otro usuario
    fetch('verificar_modal_lote.php')
        .then(response => response.json())
        .then(data => {
            if (data.bloqueado) {
                mostrarToast('El modal está siendo utilizado por otro usuario. Por favor, espere.', 'warning');
                return;
            }
            
            // Abrir el modal y registrar el bloqueo
            document.getElementById('generacionLoteModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            registrarBloqueoModal();
            modalLoteAbierto = true;
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarToast('Error al verificar disponibilidad del modal', 'error');
        });
}

function cerrarModalGeneracionLote() {
    if (!modalLoteAbierto) return;
    
    fetch('liberar_modal_lote.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('generacionLoteModal').classList.remove('show');
                document.body.style.overflow = '';
                document.getElementById('formGeneracionLote').reset();
                document.getElementById('preview_facturas').style.display = 'none';
                document.getElementById('info_cliente').style.display = 'none';
                document.getElementById('btn_generar').style.display = 'none';
                modalLoteAbierto = false;
                datosPreGeneracion = null;
            } else {
                throw new Error(data.error || 'Error al liberar el modal');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarToast('Error al liberar el modal: ' + error.message, 'error');
        });
}

function verificarContrato() {
    const numeroContrato = document.getElementById('contrato_lote').value;
    if (!numeroContrato) return;

    // Agregar log para verificar el valor enviado
    console.log('Verificando contrato:', numeroContrato);

    fetch(`verificar_contrato.php?contrato_id=${numeroContrato}`)
        .then(response => {
            // Agregar log de la respuesta
            console.log('Respuesta del servidor:', response);
            return response.json();
        })
        .then(data => {
            console.log('Datos recibidos:', data);
            
            if (data.error) {
                mostrarToast(data.error, 'error');
                document.getElementById('info_cliente').style.display = 'none';
                return;
            }
            
            document.getElementById('nombre_cliente').textContent = 
                `${data.cliente.nombre} ${data.cliente.apellidos}`;
            document.getElementById('info_cliente').style.display = 'block';
        })
        .catch(error => {
            console.error('Error en la verificación:', error);
            mostrarToast('Error al verificar el contrato: ' + error.message, 'error');
        });
}

function preGenerarFacturas() {
    const numeroContrato = document.getElementById('contrato_lote').value;
    const cantidadFacturas = document.getElementById('cantidad_facturas').value;

    if (!numeroContrato || !cantidadFacturas) {
        mostrarToast('Por favor, complete todos los campos', 'warning');
        return;
    }

    if (cantidadFacturas > 12) {
        mostrarToast('La cantidad máxima de facturas es 12', 'warning');
        return;
    }

    // Agregar log
    console.log('Enviando datos para pre-generación:', {
        contrato_id: numeroContrato,
        cantidad: parseInt(cantidadFacturas)
    });

    fetch('pre_generar_facturas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            contrato_id: numeroContrato,
            cantidad: parseInt(cantidadFacturas)
        })
    })
    .then(response => {
        console.log('Respuesta del servidor:', response);
        return response.json();
    })
    .then(data => {
        console.log('Datos recibidos:', data);
        
        if (data.error) {
            throw new Error(data.error);
        }

        const tbody = document.getElementById('preview_facturas_body');
        tbody.innerHTML = '';
        
        data.facturas.forEach(factura => {
            tbody.innerHTML += `
                <tr>
                    <td>${factura.contrato}</td>
                    <td>${factura.mes}</td>
                    <td>RD$${parseFloat(factura.monto).toFixed(2)}</td>
                    <td>${factura.cuota}</td>
                </tr>
            `;
        });

        document.getElementById('preview_facturas').style.display = 'block';
        document.getElementById('btn_generar').style.display = 'block';
        datosPreGeneracion = data;
    })
    .catch(error => {
        console.error('Error en pre-generación:', error);
        mostrarToast(error.message || 'Error al pre-generar las facturas', 'error');
    });
}

function generarFacturasLote() {
    if (!datosPreGeneracion) {
        mostrarToast('No hay datos para generar facturas', 'error');
        return;
    }

    const progressBar = document.querySelector('#progreso_generacion .progress-bar');
    document.getElementById('progreso_generacion').style.display = 'block';
    progressBar.style.width = '0%';

    fetch('generar_facturas_lote.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(datosPreGeneracion)
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            mostrarToast(data.error, 'error');
            return;
        }

        // Mostrar modal de confirmación
        document.getElementById('confirmacionGeneracionModal').classList.add('show');
        
        // Recargar la página para mostrar las nuevas facturas
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarToast('Error al generar las facturas', 'error');
    })
    .finally(() => {
        document.getElementById('progreso_generacion').style.display = 'none';
    });
}

function cerrarConfirmacionGeneracion() {
    document.getElementById('confirmacionGeneracionModal').classList.remove('show');
    cerrarModalGeneracionLote();
}

function registrarBloqueoModal() {
    fetch('bloquear_modal_lote.php', {
        method: 'POST'
    }).catch(error => console.error('Error al registrar bloqueo:', error));
}

function liberarBloqueoModal() {
    fetch('liberar_modal_lote.php', {
        method: 'POST'
    }).catch(error => console.error('Error al liberar bloqueo:', error));
}
    
    






// Variables globales para el manejo de facturas por lote
let facturasLoteSeleccionadas = [];


// Función para formatear el número de contrato
function formatearContrato(numero) {
    if (!numero) return '';
    // Asegurarse de que el número sea un string y rellenarlo con ceros
    return numero.toString().padStart(5, '0');
}


// Función para formatear el mes
function formatearMes(mesFactura) {
    if (!mesFactura) return '';
    
    const meses = {
        '01': 'Ene',
        '02': 'Feb',
        '03': 'Mar',
        '04': 'Abr',
        '05': 'May',
        '06': 'Jun',
        '07': 'Jul',
        '08': 'Ago',
        '09': 'Sep',
        '10': 'Oct',
        '11': 'Nov',
        '12': 'Dic'
    };
    
    const [mes, año] = mesFactura.split('/');
    return `${meses[mes] || mes}/${año}`;
}


function mostrarModalImpresionLote() {
    document.getElementById('modalImpresionLote').classList.add('show');
    document.body.style.overflow = 'hidden';
    document.getElementById('numero_factura_lote').value = '';
    document.getElementById('lista_facturas_lote').style.display = 'none';
    facturasLoteSeleccionadas = [];
}

function cerrarModalImpresionLote() {
    document.getElementById('modalImpresionLote').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formImpresionLote').reset();
    document.getElementById('facturas_seleccionadas_lote').innerHTML = '';
    document.getElementById('lista_facturas_lote').style.display = 'none';
    facturasLoteSeleccionadas = [];
}

async function agregarFacturaLote() {
    const numeroFactura = document.getElementById('numero_factura_lote').value.trim();
    if (!numeroFactura) {
        mostrarToast('Por favor ingrese un número de factura', 'warning');
        return;
    }

    try {
        // Agregar console.log para debugging
        console.log('Enviando petición para factura:', numeroFactura);
        
        const response = await fetch(`verificar_factura.php?numero_factura=${numeroFactura}`);
        const data = await response.json();
        
        // Agregar console.log para ver la respuesta
        console.log('Respuesta del servidor:', data);

        if (!data.success) {
            mostrarToast(data.message || 'Factura no encontrada', 'error');
            return;
        }

        // Verificación adicional de los datos recibidos
        if (!data.factura || !data.factura.numero_factura) {
            console.error('Datos de factura incompletos:', data);
            mostrarToast('Error: Datos de factura incompletos', 'error');
            return;
        }

        // Verificar si la factura ya está en la lista
        if (facturasLoteSeleccionadas.some(f => f.numero_factura === data.factura.numero_factura)) {
            mostrarToast('Esta factura ya está en la lista', 'warning');
            document.getElementById('numero_factura_lote').value = '';
            return;
        }

        // Agregar factura a la lista
        facturasLoteSeleccionadas.unshift(data.factura);
        actualizarTablaFacturasLote();
        document.getElementById('numero_factura_lote').value = '';
        
        // Mostrar mensaje de éxito
        mostrarToast('Factura agregada correctamente', 'success');

    } catch (error) {
        console.error('Error completo:', error);
        mostrarToast('Error al verificar la factura: ' + error.message, 'error');
    }
}

function actualizarTablaFacturasLote() {
    const tbody = document.getElementById('facturas_seleccionadas_lote');
    tbody.innerHTML = '';
    
    facturasLoteSeleccionadas.forEach((factura, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${factura.numero_factura}</td>
            <td>${factura.esta_asignada > 0 ? 
                '<span class="badge badge-danger" style="font-size: 14px;">SI</span>' : 
                '<span style="font-size: 14px;">NO</span>'}</td>
            <td>${formatearContrato(factura.numero_contrato)}</td>
            <td>${factura.cliente_nombre} ${factura.cliente_apellidos}</td>
            <td>${formatearMes(factura.mes_factura)}</td>
            <td>RD$${parseFloat(factura.monto).toFixed(2)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removerFacturaLote(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('lista_facturas_lote').style.display = 
        facturasLoteSeleccionadas.length > 0 ? 'block' : 'none';
}

function removerFacturaLote(index) {
    facturasLoteSeleccionadas.splice(index, 1);
    actualizarTablaFacturasLote();
}

function imprimirFacturasLote() {
    if (facturasLoteSeleccionadas.length === 0) {
        mostrarToast('Debe seleccionar al menos una factura', 'warning');
        return;
    }

    const facturas_ids = facturasLoteSeleccionadas.map(f => f.id);
    const url = `imprimirporlote.php?facturas[]=${facturas_ids.join('&facturas[]=')}`;
    window.open(url, '_blank');
}

// Agregar event listener para el campo de número de factura
document.getElementById('numero_factura_lote').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        agregarFacturaLote();
    }
});
    
    
    
//*****************CODIGO JS PARA EL MENU DE USUARIO*****************//

// Configuración de listeners para elementos de UI
document.addEventListener('DOMContentLoaded', function() {
    console.log("DOM cargado, configurando event listeners...");
    
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
    const estadoFilter = document.getElementById('estadoFilter');
    const contratoFilter = document.getElementById('contratoFilter');
    const btnBuscar = document.getElementById('btnBuscar');
    
    // Botón de búsqueda
    if (btnBuscar) {
        btnBuscar.addEventListener('click', function() {
            aplicarFiltros();
        });
    }
    
    // Cambio en select de estado
    if (estadoFilter) {
        estadoFilter.addEventListener('change', function() {
            aplicarFiltros();
        });
    }
    
    // Enter en campo de búsqueda
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                aplicarFiltros();
            }
        });
    }
    
    // Enter en campo de contrato
    if (contratoFilter) {
        contratoFilter.addEventListener('keypress', function(e) {
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
    const mesDesde = document.getElementById('mes_desde');
    const mesHasta = document.getElementById('mes_hasta');
    if ((mesDesde && mesDesde.value) || (mesHasta && mesHasta.value)) {
        const advancedFilters = document.getElementById('advancedFilters');
        if (advancedFilters) {
            advancedFilters.style.display = 'block';
            if (toggleAdvancedFilters) {
                toggleAdvancedFilters.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar filtros';
            }
        }
    }
});

// Función para aplicar filtros
function aplicarFiltros() {
    const searchValue = document.getElementById('searchInput').value.trim();
    const estadoValue = document.getElementById('estadoFilter').value;
    const contratoValue = document.getElementById('contratoFilter').value.trim();
    const mesDesdeValue = document.getElementById('mes_desde')?.value;
    const mesHastaValue = document.getElementById('mes_hasta')?.value;
    
    let url = 'facturacion.php?pagina=1';
    
    if (searchValue) {
        url += `&numero_factura=${encodeURIComponent(searchValue)}`;
    }
    
    if (estadoValue) {
        url += `&estado=${encodeURIComponent(estadoValue)}`;
    }
    
    if (contratoValue) {
        url += `&numero_contrato=${encodeURIComponent(contratoValue)}`;
    }
    
    if (mesDesdeValue) {
        url += `&mes_desde=${encodeURIComponent(mesDesdeValue)}`;
    }
    
    if (mesHastaValue) {
        url += `&mes_hasta=${encodeURIComponent(mesHastaValue)}`;
    }
    
    window.location.href = url;
}

function limpiarFiltros() {
    window.location.href = 'facturacion.php';
}

function cambiarRegistrosPorPagina(valor) {
    document.cookie = `facturas_por_pagina=${valor}; path=/; max-age=31536000`;
    
    // Obtener parámetros actuales
    const url = new URL(window.location.href);
    
    // Conservar otros parámetros excepto página
    url.searchParams.delete('pagina');
    url.searchParams.set('pagina', '1');
    
    window.location.href = url.toString();
}

// Implementar selección de checkbox
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.factura-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
        
        // Actualizar estado de "seleccionar todo" basado en checkboxes individuales
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('factura-checkbox')) {
                const checkboxes = document.querySelectorAll('.factura-checkbox');
                const allChecked = Array.from(checkboxes).every(c => c.checked);
                const anyChecked = Array.from(checkboxes).some(c => c.checked);
                
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = anyChecked && !allChecked;
            }
        });
    }
});

// Funciones para efectos interactivos
function setupInteractions() {
    // Add hover effects to stat cards
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Botones de acción
    const actionButtons = document.querySelectorAll('.btn-action');
    actionButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
}

// Llamar a setupInteractions cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', setupInteractions);

//*****************CODIGO JS PARA EL MENU DE USUARIO*****************//
    
    
    

</script>

<?php require_once 'footer.php'; ?>