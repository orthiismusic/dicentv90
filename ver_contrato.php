<?php
require_once 'header.php';

if (!isset($_GET['id'])) {
    header('Location: contratos.php');
    exit();
}

$id = (int)$_GET['id'];

// Obtener datos del contrato con información relacionada
$stmt = $conn->prepare("
    SELECT c.*, 
           cl.codigo as cliente_codigo,
           cl.nombre as cliente_nombre,
           cl.apellidos as cliente_apellidos,
           cl.direccion as cliente_direccion,
           cl.telefono1 as cliente_telefono1,
           cl.telefono2 as cliente_telefono2,
           cl.telefono3 as cliente_telefono3,
           cl.email as cliente_email,
           p.nombre as plan_nombre,
           p.descripcion as plan_descripcion,
           p.cobertura_maxima,
           v.nombre_completo as vendedor_nombre,
           (
               SELECT COUNT(*) 
               FROM dependientes d 
               WHERE d.contrato_id = c.id 
               AND d.estado = 'activo'
           ) as total_dependientes,
           (
               SELECT COUNT(*) 
               FROM dependientes d 
               WHERE d.contrato_id = c.id 
               AND d.estado = 'activo' 
               AND d.plan_id = 5
           ) as total_geriatricos,
           (
               SELECT COUNT(*)
               FROM facturas f
               WHERE f.contrato_id = c.id
               AND f.estado = 'incompleta'
           ) as facturas_incompletas,
           (
               SELECT SUM(p.monto)
               FROM facturas f
               JOIN pagos p ON f.id = p.factura_id
               WHERE f.contrato_id = c.id
               AND p.tipo_pago = 'abono'
               AND p.estado = 'procesado'
           ) as total_abonado,
           (
               SELECT SUM(f.monto)
               FROM facturas f
               WHERE f.contrato_id = c.id
               AND f.estado IN ('pendiente', 'incompleta', 'vencida')
           ) as total_pendiente
    FROM contratos c
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN planes p ON c.plan_id = p.id
    LEFT JOIN vendedores v ON c.vendedor_id = v.id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$contrato = $stmt->fetch();

if (!$contrato) {
    header('Location: contratos.php');
    exit();
}

// Obtener beneficiarios del contrato
$stmt = $conn->prepare("
    SELECT * FROM beneficiarios 
    WHERE contrato_id = ? 
    ORDER BY nombre, apellidos
");
$stmt->execute([$id]);
$beneficiarios = $stmt->fetchAll();

// Obtener dependientes del contrato
$stmt = $conn->prepare("
    SELECT d.*, 
           p.nombre as plan_nombre, 
           p.precio_base,
           DATE_FORMAT(d.fecha_registro, '%Y-%m-%d') as fecha_registro_formateada,
           (
               SELECT COUNT(*)
               FROM historial_cambios_plan_dependientes h
               WHERE h.dependiente_id = d.id
           ) as total_cambios_plan
    FROM dependientes d
    JOIN planes p ON d.plan_id = p.id
    WHERE d.contrato_id = ? 
    AND d.estado = 'activo'
    ORDER BY d.nombre, d.apellidos
");
$stmt->execute([$id]);
$dependientes = $stmt->fetchAll();

// Obtener facturas del contrato
$stmt = $conn->prepare("
    SELECT f.*,
           (
               SELECT SUM(p.monto)
               FROM pagos p
               WHERE p.factura_id = f.id
               AND p.tipo_pago = 'abono'
               AND p.estado = 'procesado'
           ) as total_abonado,
           (
               SELECT GROUP_CONCAT(
                   CONCAT(
                       DATE_FORMAT(p.fecha_pago, '%d/%m/%Y'),
                       ': RD$',
                       FORMAT(p.monto, 2)
                   )
                   ORDER BY p.fecha_pago ASC
                   SEPARATOR '|'
               )
               FROM pagos p
               WHERE p.factura_id = f.id
               AND p.estado = 'procesado'
           ) as detalle_pagos,
           (
               SELECT COUNT(*)
               FROM pagos p
               WHERE p.factura_id = f.id
               AND p.estado = 'procesado'
           ) as total_pagos
    FROM facturas f
    WHERE f.contrato_id = ?
    ORDER BY f.numero_factura DESC
");
$stmt->execute([$id]);
$facturas = $stmt->fetchAll();

// Obtener historial de pagos
$stmt = $conn->prepare("
    SELECT p.*,
           f.numero_factura,
           f.mes_factura,
           u.nombre as cobrador_nombre
    FROM pagos p
    JOIN facturas f ON p.factura_id = f.id
    LEFT JOIN usuarios u ON p.cobrador_id = u.id
    WHERE f.contrato_id = ?
    AND p.estado = 'procesado'
    ORDER BY p.fecha_pago DESC
");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll();

// Calcular estadísticas adicionales
$total_facturado = 0;
$total_cobrado = 0;
$total_pendiente = 0;
$facturas_vencidas = 0;
$facturas_incompletas = 0;

foreach ($facturas as $factura) {
    $total_facturado += $factura['monto'];
    
    if ($factura['estado'] == 'pagada') {
        $total_cobrado += $factura['monto'];
    } else {
        if ($factura['estado'] == 'incompleta') {
            $monto_pendiente = $factura['monto'] - ($factura['total_abonado'] ?? 0);
            $total_pendiente += $monto_pendiente;
            $facturas_incompletas++;
        } else {
            $total_pendiente += $factura['monto'];
        }
        
        if ($factura['estado'] == 'vencida') {
            $facturas_vencidas++;
        }
    }
}

// Procesar mensajes de notificación
$mensaje_toast = '';
$tipo_toast = '';
if (isset($_GET['mensaje'])) {
    switch ($_GET['mensaje']) {
        case 'pago_exitoso':
            $mensaje_toast = 'Pago registrado exitosamente';
            $tipo_toast = 'success';
            break;
        case 'pago_abono':
            $mensaje_toast = 'Abono registrado exitosamente';
            $tipo_toast = 'info';
            break;
        case 'pago_error':
            $mensaje_toast = 'Error al procesar el pago';
            $tipo_toast = 'error';
            break;
    }
}
?>
<div class="contrato-detalle">
    <div class="card">
        <div class="card-header">
            <div class="header-content">
                <h2>Detalles del Contrato</h2>
                <div class="header-actions">
                    <br>
                    <a href="contratos.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Volver
                    </a>
                </div>
            </div>
        </div>

        <div class="contrato-grid">
            <!-- Información del Contrato -->
            <div class="info-section">
                <h3>Información General</h3>
                <div class="info-content">
                    <div class="info-item">
                        <span class="label">Número de Contrato:</span>
                        <strong><span class="value"><?php echo htmlspecialchars($contrato['numero_contrato']); ?></span></strong>
                    </div>
                    <div class="info-item">
                        <span class="label">Fecha de Inicio:</span>
                        <span class="value"><?php echo date('d/m/Y', strtotime($contrato['fecha_inicio'])); ?></span>
                    </div>
                    <div class="info-item">
                        <strong><span class="label">Vigencia hasta el:</span>
                        <span class="value"><?php echo date('d/m/Y', strtotime($contrato['fecha_fin'])); ?></span></strong>
                    </div>
                    <div class="info-item">
                        <span class="label">Día de Cobro:</span>
                        <span class="value"><strong><?php echo $contrato['dia_cobro']; ?></strong> de cada mes</span>
                    </div>
                    <div class="info-item">
                        <span class="label">Vendedor:</span>
                        <span class="value"><?php echo htmlspecialchars($contrato['vendedor_nombre']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Estado:</span>
                        <span class="badge badge-<?php echo $contrato['estado'] == 'activo' ? 'success' : 'danger'; ?>">
                            <?php echo ucfirst($contrato['estado']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Información del Cliente -->
            <div class="info-section">
                <h3>Información del Cliente</h3>
                <div class="info-content">
                    <div class="info-item">
                        <strong><span class="label">Cliente:</span>
                        <span class="value">
                            <?php echo htmlspecialchars($contrato['cliente_nombre'] . ' ' . $contrato['cliente_apellidos']); ?>
                        </span></strong>
                    </div>
                    <div class="info-item">
                        <span class="label">Dirección:</span>
                        <span class="value"><?php echo htmlspecialchars($contrato['cliente_direccion']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Teléfono:</span>
                        <span class="value"><?php echo htmlspecialchars($contrato['cliente_telefono1'] . ' / ' . $contrato['cliente_telefono2'] . ' / ' . $contrato['cliente_telefono3']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Email:</span>
                        <span class="value"><?php echo htmlspecialchars($contrato['cliente_email']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Resumen Financiero -->
            <div class="info-section">
                <h3>Resumen Financiero</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-title">Total Facturado</div>
                        <div class="stat-value">RD$<?php echo number_format($total_facturado, 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Total Cobrado</div>
                        <div class="stat-value text-success">RD$<?php echo number_format($total_cobrado, 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Total Pendiente</div>
                        <div class="stat-value text-danger">RD$<?php echo number_format($total_pendiente, 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Facturas Vencidas</div>
                        <div class="stat-value <?php echo $facturas_vencidas > 0 ? 'text-danger' : ''; ?>">
                            <?php echo $facturas_vencidas; ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Facturas Incompletas</div>
                        <div class="stat-value <?php echo $facturas_incompletas > 0 ? 'text-warning' : ''; ?>">
                            <?php echo $facturas_incompletas; ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Total Abonado</div>
                        <div class="stat-value text-info">RD$<?php echo number_format($contrato['total_abonado'] ?? 0, 2); ?></div>
                    </div>
                </div>
            </div>
            <!-- Sección de Plan y Costos -->
            <div class="info-section">
                <h3>Plan y Costos</h3>
                <div class="info-content">
                    <div class="info-item">
                        <span class="label">Plan:</span>
                        <span class="value"><?php echo htmlspecialchars($contrato['plan_nombre']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Descripción:</span>
                        <span class="value"><?php echo htmlspecialchars($contrato['plan_descripcion']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Cobertura Máxima:</span>
                        <span class="value">RD$<?php echo number_format($contrato['cobertura_maxima'], 2); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Monto Base:</span>
                        <span class="value">RD$<?php echo number_format($contrato['monto_mensual'], 2); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Monto Total:</span>
                        <span class="value">RD$<?php echo number_format($contrato['monto_total'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Sección de Dependientes -->
            <div class="section-dependientes">
                <h3>Dependientes Incluidos</h3>
                <?php if (empty($dependientes)): ?>
                    <p class="text-muted">No hay dependientes registrados</p>
                <?php else: ?>
                    <div class="dependientes-container">
                        <?php foreach ($dependientes as $dependiente): 
                            $edad = date_diff(date_create($dependiente['fecha_nacimiento']), date_create('today'))->y;
                        ?>
                            <div class="dependiente-tarjeta">
                                <div class="dependiente-tarjeta-header">
                                    <h4><?php echo htmlspecialchars($dependiente['nombre'] . ' ' . $dependiente['apellidos']); ?></h4>
                                    <span class="badge badge-<?php echo $edad >= 65 ? 'warning' : 'info'; ?>">
                                        <?php echo $edad; ?> años
                                    </span>
                                </div>
                                <div class="dependiente-tarjeta-body">
                                    <div class="dependiente-info-item">
                                        <span class="info-label">Relación:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($dependiente['relacion']); ?></span>
                                    </div>
                                    <div class="dependiente-info-item">
                                        <span class="info-label">Plan:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($dependiente['plan_nombre']); ?></span>
                                    </div>
                                    <div class="dependiente-info-item">
                                        <span class="info-label">Costo:</span>
                                        <span class="info-value">RD$<?php echo number_format($dependiente['precio_base'], 2); ?></span>
                                    </div>
                                    <div class="dependiente-info-item">
                                        <span class="info-label">Fecha Ingreso:</span>
                                        <span class="info-value">
                                            <?php echo date('d/m/Y', strtotime($dependiente['fecha_registro'])); ?>
                                        </span>
                                    </div>
                                    <?php if ($dependiente['total_cambios_plan'] > 0): ?>
                                        <div class="dependiente-cambios">
                                            <small class="text-muted">
                                                Ha tenido <?php echo $dependiente['total_cambios_plan']; ?> cambio(s) de plan
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dependientes-resumen">
                        <p>Total Dependientes: <strong><?php echo count($dependientes); ?></strong></p>
                        <p>Total Geriátricos: <strong><?php echo $contrato['total_geriatricos']; ?></strong></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Beneficiarios -->
            <div class="section-beneficiarios">
                <h3>Beneficiarios</h3>
                <?php if (empty($beneficiarios)): ?>
                    <p class="text-muted">No hay beneficiarios registrados</p>
                <?php else: ?>
                    <div class="beneficiarios-container">
                        <?php foreach ($beneficiarios as $beneficiario): ?>
                            <div class="beneficiario-tarjeta">
                                <div class="beneficiario-tarjeta-header">
                                    <h4><?php echo htmlspecialchars($beneficiario['nombre'] . ' ' . $beneficiario['apellidos']); ?></h4>
                                    <span class="badge badge-info">
                                        <?php echo htmlspecialchars($beneficiario['porcentaje']); ?>%
                                    </span>
                                </div>
                                <div class="beneficiario-tarjeta-body">
                                    <div class="beneficiario-info-item">
                                        <span class="info-label">Parentesco:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($beneficiario['parentesco']); ?></span>
                                    </div>
                                    <div class="beneficiario-info-item">
                                        <span class="info-label">Fecha Nacimiento:</span>
                                        <span class="info-value"><?php echo date('d/m/Y', strtotime($beneficiario['fecha_nacimiento'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Facturas -->
            <div class="section-facturas">
                <h3>Facturas</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Mes</th>
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Monto Total</th>
                                <th>Abonado</th>
                                <th>Pendiente</th>
                                <th>Estado</th>
                                <th>Historial</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturas as $factura): 
                                $montoPendiente = $factura['monto'] - ($factura['total_abonado'] ?? 0);
                                $pagos_desglosados = $factura['detalle_pagos'] ? explode('|', $factura['detalle_pagos']) : [];
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($factura['numero_factura']); ?></td>
                                    <td><?php echo htmlspecialchars($factura['mes_factura']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($factura['fecha_vencimiento'])); ?></td>
                                    <td>RD$<?php echo number_format($factura['monto'], 2); ?></td>
                                    <td>
                                        <?php if ($factura['total_abonado'] > 0): ?>
                                            <span class="text-success">
                                                RD$<?php echo number_format($factura['total_abonado'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($montoPendiente > 0): ?>
                                            <span class="text-danger">
                                                RD$<?php echo number_format($montoPendiente, 2); ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $factura['estado'] == 'pagada' ? 'success' : 
                                                 ($factura['estado'] == 'pendiente' ? 'warning' : 
                                                 ($factura['estado'] == 'incompleta' ? 'info' : 
                                                 ($factura['estado'] == 'vencida' ? 'danger' : 'secondary'))); 
                                        ?>">
                                            <?php echo ucfirst($factura['estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($pagos_desglosados)): ?>
                                            <button type="button" class="btn btn-sm btn-info" 
                                                    onclick="mostrarHistorialPagos(<?php 
                                                        echo htmlspecialchars(json_encode($pagos_desglosados)); 
                                                    ?>)">
                                                <i class="fas fa-history"></i>
                                            </button>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="ver_factura.php?id=<?php echo $factura['id']; ?>" 
                                               class="btn btn-sm btn-info" title="Ver factura">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($factura['estado'] != 'pagada'): ?>
                                                <a href="registrar_pago.php?factura_id=<?php echo $factura['id']; ?>" 
                                                   class="btn btn-sm btn-success" title="Registrar pago">
                                                    <i class="fas fa-dollar-sign"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Historial de Pagos -->
            <?php if (!empty($pagos)): ?>
                <div class="section-pagos">
                    <h3>Historial de Pagos</h3>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Factura</th>
                                    <th>Mes</th>
                                    <th>Monto</th>
                                    <th>Tipo</th>
                                    <th>Método</th>
                                    <th>Referencia</th>
                                    <th>Cobrador</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagos as $pago): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></td>
                                        <td><?php echo htmlspecialchars($pago['numero_factura']); ?></td>
                                        <td><?php echo htmlspecialchars($pago['mes_factura']); ?></td>
                                        <td>RD$<?php echo number_format($pago['monto'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $pago['tipo_pago'] === 'total' ? 'success' : 'info'; ?>">
                                                <?php echo ucfirst($pago['tipo_pago']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo ucfirst($pago['metodo_pago']); ?></td>
                                        <td><?php echo htmlspecialchars($pago['referencia_pago'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($pago['cobrador_nombre']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" 
                                                    onclick="verComprobante(<?php echo $pago['id']; ?>)">
                                                <i class="fas fa-receipt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            </div>
    </div>
</div>

<!-- Modal para mostrar historial de pagos -->
<div class="modal" id="historialPagosModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Historial de Pagos</h5>
                <button type="button" class="close" onclick="cerrarModalHistorial()">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="listaPagos"></div>
            </div>
        </div>
    </div>
</div>

<style>
.contrato-detalle {
    margin-bottom: 2rem;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

.contrato-grid {
    display: grid;
    gap: 1.5rem;
    padding: 1.5rem;
}

.info-section {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.info-section h3 {
    margin-bottom: 1rem;
    color: var(--primary-color);
    border-bottom: 2px solid var(--primary-color);
    padding-bottom: 0.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: #f9fafb;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}

.stat-title {
    font-size: 0.875rem;
    color: #4b5563;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--primary-color);
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-dialog {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
}

.dependientes-container {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.dependiente-tarjeta {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    padding: 1.5rem;
    border: 1px solid #e5e7eb;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.dependiente-tarjeta:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.dependiente-tarjeta-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.dependiente-tarjeta-header h4 {
    margin: 0;
    color: var(--primary-color);
    font-size: 1.1rem;
}

.dependiente-tarjeta-body {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.dependiente-info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem;
    background-color: #f9fafb;
    border-radius: 6px;
}

.dependiente-info-item .info-label {
    color: #6b7280;
    font-weight: 500;
}

.dependiente-info-item .info-value {
    color: #111827;
    font-weight: 500;
}

.dependiente-cambios {
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px dashed #e5e7eb;
    text-align: center;
}

.dependientes-resumen {
    margin-top: 1.5rem;
    padding: 1rem;
    background-color: #f9fafb;
    border-radius: 8px;
    display: flex;
    justify-content: space-around;
}


.beneficiarios-container {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.beneficiario-tarjeta {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    padding: 1.5rem;
    border: 1px solid #e5e7eb;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.beneficiario-tarjeta:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.beneficiario-tarjeta-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.beneficiario-tarjeta-header h4 {
    margin: 0;
    color: var(--primary-color);
    font-size: 1.1rem;
}

.beneficiario-tarjeta-body {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}


.badge-success { background-color: #dcfce7; color: #166534; }
.badge-warning { background-color: #fef3c7; color: #92400e; }
.badge-info { background-color: #ffedd5; color: #c2410c; }
.badge-danger { background-color: #fee2e2; color: #991b1b; }





/* Responsive design para tablets y móviles */
@media (max-width: 992px) {
    .dependientes-container {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 576px) {
    .dependientes-container {
        grid-template-columns: 1fr;
    }
}

/* Responsive design para tablets y móviles */
@media (max-width: 992px) {
    .beneficiarios-container {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 576px) {
    .beneficiarios-container {
        grid-template-columns: 1fr;
    }
}




@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        gap: 1rem;
    }

    .header-actions {
        flex-direction: column;
        width: 100%;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
// Función para mostrar notificaciones toast
function mostrarToast(mensaje, tipo = 'info', duracion = 4000) {
    Toastify({
        text: mensaje,
        duration: duracion,
        close: true,
        gravity: "top",
        position: "right",
        backgroundColor: tipo === 'success' ? "#28a745" : 
                        (tipo === 'error' ? "#dc3545" : 
                        (tipo === 'warning' ? "#ffc107" : "#17a2b8")),
        stopOnFocus: true
    }).showToast();
}

function mostrarHistorialPagos(pagos) {
    const lista = document.getElementById('listaPagos');
    lista.innerHTML = '<ul class="lista-pagos">' +
        pagos.map(pago => `<li>${pago}</li>`).join('') +
        '</ul>';
    document.getElementById('historialPagosModal').classList.add('show');
}

function cerrarModalHistorial() {
    document.getElementById('historialPagosModal').classList.remove('show');
}

function verComprobante(id) {
    window.open(`ver_comprobante.php?id=${id}`, '_blank');
}

// Mostrar notificación toast si hay mensaje
<?php if (!empty($mensaje_toast)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        mostrarToast('<?php echo $mensaje_toast; ?>', '<?php echo $tipo_toast; ?>');
    });
<?php endif; ?>

// Cerrar modal con Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalHistorial();
    }
});
</script>

<?php require_once 'footer.php'; ?>