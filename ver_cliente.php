<?php
require_once 'header.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_GET['id'])) {
    header('Location: clientes.php');
    exit();
}

$id = (int)$_GET['id'];

// Obtener datos del cliente con estadísticas mejoradas
$stmt = $conn->prepare("
    SELECT 
        c.*,
        (SELECT COUNT(DISTINCT id) FROM contratos WHERE cliente_id = c.id) as total_contratos,
        (
            SELECT COUNT(DISTINCT f.id) 
            FROM contratos con 
            JOIN facturas f ON con.id = f.contrato_id 
            WHERE con.cliente_id = c.id
        ) as total_facturas,
        (
            SELECT COUNT(DISTINCT d.id) 
            FROM contratos con
            JOIN dependientes d ON d.contrato_id = con.id 
            WHERE con.cliente_id = c.id AND d.estado = 'activo'
        ) as total_dependientes,
        COALESCE(
            (
                SELECT SUM(f.monto)
                FROM contratos con 
                JOIN facturas f ON con.id = f.contrato_id 
                WHERE con.cliente_id = c.id 
                AND f.estado IN ('pendiente', 'vencida', 'incompleta')
            ), 0
        ) as total_pendiente,
        COALESCE(
            (
                SELECT SUM(p.monto)
                FROM contratos con 
                JOIN facturas f ON con.id = f.contrato_id
                JOIN pagos p ON f.id = p.factura_id
                WHERE con.cliente_id = c.id 
                AND p.estado = 'procesado'
                AND p.tipo_pago = 'abono'
            ), 0
        ) as total_abonado,
        (
            SELECT COUNT(DISTINCT f.id)
            FROM contratos con 
            JOIN facturas f ON con.id = f.contrato_id 
            WHERE con.cliente_id = c.id 
            AND f.estado = 'incompleta'
        ) as facturas_incompletas
    FROM clientes c
    WHERE c.id = ?
");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    header('Location: clientes.php');
    exit();
}

// Obtener contratos del cliente con información de pagos
$stmt = $conn->prepare("
    SELECT c.*, 
           p.nombre as plan_nombre,
           (
               SELECT COUNT(f.id) 
               FROM facturas f 
               WHERE f.contrato_id = c.id 
               AND f.estado IN ('pendiente', 'vencida', 'incompleta')
           ) as facturas_pendientes,
           (
               SELECT COUNT(f.id) 
               FROM facturas f 
               WHERE f.contrato_id = c.id 
               AND f.estado = 'incompleta'
           ) as facturas_incompletas,
           (
               SELECT SUM(p.monto)
               FROM facturas f 
               JOIN pagos p ON f.id = p.factura_id
               WHERE f.contrato_id = c.id 
               AND p.estado = 'procesado'
               AND p.tipo_pago = 'abono'
           ) as total_abonado
    FROM contratos c
    JOIN planes p ON c.plan_id = p.id 
    WHERE cliente_id = ? 
    ORDER BY fecha_inicio DESC
");
$stmt->execute([$id]);
$contratos = $stmt->fetchAll();
// Obtener últimas facturas con información de pagos
$stmt = $conn->prepare("
    SELECT f.*,
           c.numero_contrato,
           (
               SELECT SUM(p.monto)
               FROM pagos p
               WHERE p.factura_id = f.id
               AND p.estado = 'procesado'
               AND p.tipo_pago = 'abono'
           ) as total_abonado,
           (
               SELECT COUNT(p.id)
               FROM pagos p
               WHERE p.factura_id = f.id
               AND p.estado = 'procesado'
           ) as total_pagos
    FROM facturas f
    JOIN contratos c ON f.contrato_id = c.id
    WHERE c.cliente_id = ?
    ORDER BY f.fecha_emision DESC
    LIMIT 12
");
$stmt->execute([$id]);
$facturas = $stmt->fetchAll();

// Obtener dependientes del cliente con información de planes
$stmt = $conn->prepare("
    SELECT d.*,
           p.nombre as plan_nombre,
           p.precio_base,
           DATE_FORMAT(d.fecha_registro, '%d/%m/%Y') as fecha_registro_formateada,
           (
               SELECT COUNT(*)
               FROM historial_cambios_plan_dependientes h
               WHERE h.dependiente_id = d.id
           ) as total_cambios_plan
    FROM contratos c
    JOIN dependientes d ON d.contrato_id = c.id
    JOIN planes p ON d.plan_id = p.id
    WHERE c.cliente_id = ? 
    AND d.estado = 'activo'
    ORDER BY d.nombre, d.apellidos
");
$stmt->execute([$id]);
$dependientes = $stmt->fetchAll();

// Obtener historial de pagos recientes
$stmt = $conn->prepare("
    SELECT p.*,
           f.numero_factura,
           c.numero_contrato,
           u.nombre as cobrador_nombre
    FROM pagos p
    JOIN facturas f ON p.factura_id = f.id
    JOIN contratos c ON f.contrato_id = c.id
    LEFT JOIN usuarios u ON p.cobrador_id = u.id
    WHERE c.cliente_id = ?
    AND p.estado = 'procesado'
    ORDER BY p.fecha_pago DESC
    LIMIT 10
");
$stmt->execute([$id]);
$pagos_recientes = $stmt->fetchAll();

// Calcular estadísticas adicionales
$total_monto_pendiente = 0;
$total_abonado = 0;
foreach ($facturas as $factura) {
    if ($factura['estado'] === 'incompleta') {
        $monto_pendiente = $factura['monto'] - ($factura['total_abonado'] ?? 0);
        $total_monto_pendiente += $monto_pendiente;
    } elseif ($factura['estado'] === 'pendiente') {
        $total_monto_pendiente += $factura['monto'];
    }
    $total_abonado += $factura['total_abonado'] ?? 0;
}
?>
<div class="cliente-detalle">
    <?php if (isset($_GET['mensaje'])): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($_GET['mensaje']); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="header-content">
                <h2>Detalles del Cliente</h2>
                <div class="header-actions"><br>
                    <a href="clientes.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Volver
                    </a>
                </div>
            </div>
        </div>

        <div class="info-grid">
            <!-- Información Personal -->
            <div class="info-section">
                <h3>Información Personal</h3>
                <div class="info-content">
                    
                    <div class="info-item">
                        <span class="label">Cliente: </span>
                        <span class="value"><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellidos']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Email:</span>
                        <span class="value"><?php echo htmlspecialchars($cliente['email']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Teléfonos:</span>
                        <span class="value">
                            <?php echo htmlspecialchars($cliente['telefono1']); ?>
                            <?php if ($cliente['telefono2']) echo '<br>' . htmlspecialchars($cliente['telefono2']); ?>
                            <?php if ($cliente['telefono3']) echo '<br>' . htmlspecialchars($cliente['telefono3']); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label">Dirección:</span>
                        <span class="value"><?php echo htmlspecialchars($cliente['direccion']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Estado:</span>
                        <span class="badge badge-<?php echo $cliente['estado'] == 'activo' ? 'success' : 'danger'; ?>">
                            <?php echo ucfirst($cliente['estado']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Resumen Financiero -->
            <div class="info-section">
                <h3>Resumen Financiero</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-title">Contratos Activos</div>
                        <div class="stat-value"><?php echo number_format($cliente['total_contratos']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Total Facturas</div>
                        <div class="stat-value"><?php echo number_format($cliente['total_facturas']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Facturas Incompletas</div>
                        <div class="stat-value <?php echo $cliente['facturas_incompletas'] > 0 ? 'text-warning' : ''; ?>">
                            <?php echo number_format($cliente['facturas_incompletas']); ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Monto Pendiente</div>
                        <div class="stat-value <?php echo $total_monto_pendiente > 0 ? 'text-danger' : ''; ?>">
                            RD$<?php echo number_format($total_monto_pendiente, 2); ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Total Abonado</div>
                        <div class="stat-value text-success">
                            RD$<?php echo number_format($total_abonado, 2); ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Dependientes</div>
                        <div class="stat-value"><?php echo number_format($cliente['total_dependientes']); ?></div>
                    </div>
                </div>
            </div>
            <!-- Sección de Dependientes -->
            <div class="section-dependientes">
                <div class="section-header">
                    <h3>Dependientes</h3>
                    
                </div>
                
                <?php if (empty($dependientes)): ?>
                    <p class="text-muted">No hay dependientes registrados</p>
                <?php else: ?>
                    <div class="dependientes-grid">
                        <?php foreach ($dependientes as $dependiente): 
                            $edad = date_diff(date_create($dependiente['fecha_nacimiento']), date_create('today'))->y;
                        ?>
                            <div class="dependiente-card">
                                <div class="dependiente-header">
                                    <h4><?php echo htmlspecialchars($dependiente['nombre'] . ' ' . $dependiente['apellidos']); ?></h4>
                                    <span class="badge badge-<?php echo $edad >= 65 ? 'warning' : 'info'; ?>">
                                        <?php echo $edad; ?> años
                                    </span>
                                </div>
                                <div class="dependiente-info">
                                    <p><strong>Relación:</strong> <?php echo htmlspecialchars($dependiente['relacion']); ?></p>
                                    <p><strong>Plan:</strong> <?php echo htmlspecialchars($dependiente['plan_nombre']); ?></p>
                                    <p><strong>Costo:</strong> RD$<?php echo number_format($dependiente['precio_base'], 2); ?></p>
                                    <p><strong>Fecha Ingreso:</strong> <?php echo $dependiente['fecha_registro_formateada']; ?></p>
                                    <?php if ($dependiente['total_cambios_plan'] > 0): ?>
                                        <p><small class="text-muted">Ha tenido <?php echo $dependiente['total_cambios_plan']; ?> cambio(s) de plan</small></p>
                                    <?php endif; ?>
                                </div>
                                <div class="dependiente-actions">
                                    <button class="btn btn-sm btn-primary" onclick="editarDependiente(<?php echo htmlspecialchars(json_encode($dependiente)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="verHistorialPlan(<?php echo $dependiente['id']; ?>)">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="eliminarDependiente(<?php echo $dependiente['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Contratos -->
            <div class="section-contratos">
                <h3>Contratos</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Plan</th>
                                <th>Fecha Inicio</th>
                                <th>Monto Base</th>
                                <th>Estado</th>
                                <th>Facturas Pendientes</th>
                                <th>Facturas Incompletas</th>
                                <th>Total Abonado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contratos as $contrato): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($contrato['numero_contrato']); ?></td>
                                    <td><?php echo htmlspecialchars($contrato['plan_nombre']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($contrato['fecha_inicio'])); ?></td>
                                    <td>RD$<?php echo number_format($contrato['monto_mensual'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $contrato['estado'] == 'activo' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($contrato['estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($contrato['facturas_pendientes'] > 0): ?>
                                            <span class="badge badge-warning">
                                                <?php echo $contrato['facturas_pendientes']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($contrato['facturas_incompletas'] > 0): ?>
                                            <span class="badge badge-info">
                                                <?php echo $contrato['facturas_incompletas']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($contrato['total_abonado'] > 0): ?>
                                            <span class="text-success">
                                                RD$<?php echo number_format($contrato['total_abonado'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="ver_contrato.php?id=<?php echo $contrato['id']; ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Últimas Facturas -->
            <div class="section-facturas">
                <h3>Últimas Facturas</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Contrato</th>
                                <th>Mes</th>
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Monto Total</th>
                                <th>Abonado</th>
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
                                    <td><?php echo htmlspecialchars($factura['numero_factura']); ?></td>
                                    <td><?php echo htmlspecialchars($factura['numero_contrato']); ?></td>
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

            <!-- Historial de Pagos Recientes -->
            <?php if (!empty($pagos_recientes)): ?>
                <div class="section-pagos">
                    <h3>Últimos Pagos</h3>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Factura</th>
                                    <th>Contrato</th>
                                    <th>Monto</th>
                                    <th>Tipo</th>
                                    <th>Método</th>
                                    <th>Referencia</th>
                                    <th>Cobrador</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagos_recientes as $pago): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></td>
                                        <td><?php echo htmlspecialchars($pago['numero_factura']); ?></td>
                                        <td><?php echo htmlspecialchars($pago['numero_contrato']); ?></td>
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

<style>
.cliente-detalle {
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

.info-grid {
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

.dependientes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.dependiente-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    border: 1px solid #dee2e6;
}

.dependiente-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.dependiente-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    justify-content: flex-end;
}

.section-dependientes,
.section-contratos,
.section-facturas,
.section-pagos {
    margin-top: 2rem;
    padding: 1.5rem;
}

.text-success { color: #2f855a !important; }
.text-danger { color: #dc2626 !important; }
.text-warning { color: #c2410c !important; }
.text-info { color: #0891b2 !important; }

.badge-success { background-color: #dcfce7; color: #166534; }
.badge-warning { background-color: #fef3c7; color: #92400e; }
.badge-info { background-color: #ffedd5; color: #c2410c; }
.badge-danger { background-color: #fee2e2; color: #991b1b; }

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        gap: 1rem;
    }

    .header-actions {
        flex-direction: column;
        width: 100%;
    }

    .dependientes-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
function verComprobante(id) {
    window.open(`ver_comprobante.php?id=${id}`, '_blank');
}

function verHistorialPlan(dependienteId) {
    window.open(`historial_plan_dependiente.php?id=${dependienteId}`, '_blank');
}

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

// Mostrar notificación si viene de una operación exitosa
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_GET['mensaje'])): ?>
        mostrarToast('<?php echo $_GET['mensaje']; ?>', 'success');
    <?php endif; ?>
});
</script>

<?php require_once 'footer.php'; ?>