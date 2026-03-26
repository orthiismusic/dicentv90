<?php
require_once 'header.php';

if (!isset($_GET['id'])) {
    header('Location: facturacion.php');
    exit();
}

$id = (int)$_GET['id'];

// Obtener datos de la factura con información relacionada
$stmt = $conn->prepare("
    SELECT f.*,
           c.numero_contrato, 
           c.monto_mensual,
           cl.codigo as cliente_codigo,
           cl.nombre as cliente_nombre,
           cl.apellidos as cliente_apellidos,
           cl.direccion as cliente_direccion,
           cl.telefono1 as cliente_telefono,
           cl.email as cliente_email,
           p.nombre as plan_nombre,
           p.descripcion as plan_descripcion,
           (SELECT SUM(p.monto)
            FROM pagos p
            WHERE p.factura_id = f.id
            AND p.tipo_pago = 'abono'
            AND p.estado = 'procesado') as total_abonado
    FROM facturas f
    JOIN contratos c ON f.contrato_id = c.id
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN planes p ON c.plan_id = p.id
    WHERE f.id = ?
");
$stmt->execute([$id]);
$factura = $stmt->fetch();

if (!$factura) {
    header('Location: facturacion.php');
    exit();
}

// Obtener historial de pagos
$stmt = $conn->prepare("
    SELECT p.*, u.nombre as cobrador_nombre 
    FROM pagos p
    LEFT JOIN usuarios u ON p.cobrador_id = u.id
    WHERE p.factura_id = ?
    ORDER BY p.fecha_pago DESC
");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll();

// Calcular monto pendiente real
$montoPendiente = $factura['monto'] - ($factura['total_abonado'] ?? 0);
?>
<div class="factura-detalle">
    <?php if (isset($_GET['mensaje'])): ?>
        <div class="alert alert-success">
            <?php 
                $mensaje = $_GET['mensaje'];
                if ($mensaje === 'pago_exitoso') {
                    echo "Pago registrado exitosamente";
                } elseif ($mensaje === 'pago_abono') {
                    echo "Abono registrado exitosamente";
                }
            ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="header-content">
                <h2>Detalle de Factura</h2>
                <div class="header-actions">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button onclick="exportarPDF()" class="btn btn-secondary">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </button>
                    <a href="facturacion.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver
                    </a>
                </div>
            </div>
        </div>

        <div class="factura-content">
            <!-- Encabezado de la empresa -->
            <div class="empresa-header">
                <div class="logo">
                    <img src="assets/img/logo.png" alt="Logo">
                </div>
                <div class="empresa-info">
                    <h1>EMPRESA DE SEGUROS</h1>
                    <p>RIF: J-12345678-9</p>
                    <p>Dirección de la empresa</p>
                    <p>Teléfono: (123) 456-7890</p>
                    <p>Email: info@empresa.com</p>
                </div>
            </div>

            <!-- Información de la factura -->
            <div class="factura-header">
                <div class="factura-numero">
                    <h2>FACTURA</h2>
                    <div class="numero"><?php echo htmlspecialchars($factura['numero_factura']); ?></div>
                    <div class="estado">
                        <span class="badge badge-<?php 
                            echo $factura['estado'] == 'pagada' ? 'success' : 
                                 ($factura['estado'] == 'pendiente' ? 'warning' : 
                                 ($factura['estado'] == 'incompleta' ? 'info' : 
                                 ($factura['estado'] == 'vencida' ? 'danger' : 'secondary'))); 
                        ?>">
                            <?php echo ucfirst($factura['estado']); ?>
                        </span>
                    </div>
                </div>
                <div class="factura-fechas">
                    <div class="fecha-item">
                        <span class="label">Fecha de Emisión:</span>
                        <strong><?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></strong>
                    </div>
                    <div class="fecha-item">
                        <span class="label">Fecha de Vencimiento:</span>
                        <strong><?php echo date('d/m/Y', strtotime($factura['fecha_vencimiento'])); ?></strong>
                    </div>
                    <div class="fecha-item">
                        <span class="label">Mes Facturado:</span>
                        <strong><?php echo htmlspecialchars($factura['mes_factura']); ?></strong>
                    </div>
                </div>
            </div>
            <!-- Información del cliente -->
            <div class="cliente-info">
                <h3>Información del Cliente</h3>
                <div class="info-grid">
                    <div class="info-group">
                        <span class="label">Cliente:</span>
                        <span class="value">
                            <?php echo htmlspecialchars($factura['cliente_nombre'] . ' ' . $factura['cliente_apellidos']); ?>
                        </span>
                    </div>
                    <div class="info-group">
                        <span class="label">Código:</span>
                        <span class="value"><?php echo htmlspecialchars($factura['cliente_codigo']); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Dirección:</span>
                        <span class="value"><?php echo htmlspecialchars($factura['cliente_direccion']); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Teléfono:</span>
                        <span class="value"><?php echo htmlspecialchars($factura['cliente_telefono']); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Email:</span>
                        <span class="value"><?php echo htmlspecialchars($factura['cliente_email']); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Contrato:</span>
                        <span class="value"><?php echo htmlspecialchars($factura['numero_contrato']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Detalles de la factura -->
            <div class="factura-detalles">
                <h3>Detalles del Servicio</h3>
                <table class="detalles-table">
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th>Contrato</th>
                            <th>Cuota</th>
                            <th>Monto Base</th>
                            <?php if ($factura['monto_pendiente'] > 0): ?>
                                <th>Monto Pendiente Anterior</th>
                            <?php endif; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($factura['plan_nombre']); ?></strong><br>
                                <small><?php echo htmlspecialchars($factura['plan_descripcion']); ?></small>
                                <?php if ($factura['cantidad_dependientes'] > 0): ?>
                                    <br>
                                    <small>
                                        Incluye <?php echo $factura['cantidad_dependientes']; ?> dependiente(s)
                                        <?php if ($factura['tiene_geriatrico']): ?>
                                            (incluye geriátrico)
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($factura['numero_contrato']); ?></td>
                            <td><?php echo htmlspecialchars($factura['cuota']); ?></td>
                            <td>RD$<?php echo number_format($factura['monto_mensual'], 2); ?></td>
                            <?php if ($factura['monto_pendiente'] > 0): ?>
                                <td>RD$<?php echo number_format($factura['monto_pendiente'], 2); ?></td>
                            <?php endif; ?>
                            <td>RD$<?php echo number_format($factura['monto'], 2); ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="<?php echo $factura['monto_pendiente'] > 0 ? '5' : '4'; ?>" class="total-label">
                                Total a Pagar:
                            </td>
                            <td class="total-amount">RD$<?php echo number_format($factura['monto'], 2); ?></td>
                        </tr>
                        <?php if ($factura['total_abonado'] > 0): ?>
                            <tr>
                                <td colspan="<?php echo $factura['monto_pendiente'] > 0 ? '5' : '4'; ?>" class="total-label">
                                    Total Abonado:
                                </td>
                                <td class="total-abonado">RD$<?php echo number_format($factura['total_abonado'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="<?php echo $factura['monto_pendiente'] > 0 ? '5' : '4'; ?>" class="total-label">
                                    Monto Pendiente:
                                </td>
                                <td class="total-pendiente">RD$<?php echo number_format($montoPendiente, 2); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
            <!-- Historial de Pagos -->
            <?php if (!empty($pagos)): ?>
                <div class="pagos-historial">
                    <h3>Historial de Pagos</h3>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Monto</th>
                                    <th>Tipo</th>
                                    <th>Método</th>
                                    <th>Referencia</th>
                                    <th>Cobrador</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagos as $pago): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></td>
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
                                            <span class="badge badge-<?php echo $pago['estado'] === 'procesado' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($pago['estado']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php if ($pago['notas']): ?>
                                        <tr class="notas-row">
                                            <td colspan="7">
                                                <small class="text-muted">
                                                    <strong>Notas:</strong> <?php echo htmlspecialchars($pago['notas']); ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Notas y Términos -->
            <div class="factura-footer">
                <div class="notas">
                    <p><strong>Notas:</strong></p>
                    <ol>
                        <li>Esta factura es un documento legal válido.</li>
                        <li>El pago debe realizarse antes de la fecha de vencimiento.</li>
                        <li>Conserve este documento para futuras referencias.</li>
                        <?php if ($factura['estado'] === 'incompleta'): ?>
                            <li class="text-warning">Esta factura tiene un saldo pendiente que debe ser cancelado.</li>
                        <?php endif; ?>
                    </ol>
                </div>

                <!-- Acciones disponibles -->
                <?php if ($factura['estado'] !== 'pagada'): ?>
                    <div class="acciones-factura">
                        <a href="registrar_pago.php?factura_id=<?php echo $factura['id']; ?>" 
                           class="btn btn-success">
                            <i class="fas fa-dollar-sign"></i> 
                            <?php echo $factura['estado'] === 'incompleta' ? 'Completar Pago' : 'Registrar Pago'; ?>
                        </a>
                    </div>
                <?php endif; ?>

                <div class="firma">
                    <div class="linea-firma"></div>
                    <p>Firma Autorizada</p>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
.factura-detalle {
    max-width: 1000px;
    margin: 0 auto;
    padding: 2rem;
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

.empresa-header {
    display: flex;
    gap: 2rem;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #eee;
}

.logo img {
    max-height: 80px;
    width: auto;
}

.empresa-info h1 {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.factura-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2rem;
}

.factura-numero h2 {
    color: var(--primary-color);
    margin-bottom: 0.5rem;
}

.numero {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--primary-color);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.info-group {
    padding: 0.75rem;
    background: #f8f9fa;
    border-radius: 4px;
}

.label {
    font-weight: 600;
    color: #4b5563;
}

.detalles-table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.5rem 0;
}

.detalles-table th,
.detalles-table td {
    padding: 0.75rem;
    border: 1px solid #e5e7eb;
}

.detalles-table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.total-label {
    text-align: right;
    font-weight: bold;
}

.total-amount {
    font-weight: bold;
    color: var(--primary-color);
}

.total-abonado {
    color: #2f855a;
    font-weight: bold;
}

.total-pendiente {
    color: #c05621;
    font-weight: bold;
}

.pagos-historial {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e5e7eb;
}

.table {
    width: 100%;
    margin-bottom: 1rem;
}

.table th,
.table td {
    padding: 0.75rem;
    vertical-align: top;
    border-bottom: 1px solid #e5e7eb;
}

.notas-row {
    background-color: #f8f9fa;
}

.factura-footer {
    margin-top: 3rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e5e7eb;
}

.acciones-factura {
    margin: 2rem 0;
    text-align: center;
}

.firma {
    width: 200px;
    margin-top: 3rem;
    text-align: center;
}

.linea-firma {
    border-top: 1px solid #000;
    margin-bottom: 0.5rem;
}

.text-warning {
    color: #c05621;
}

.badge {
    padding: 0.35em 0.65em;
    border-radius: 0.25rem;
    font-size: 0.75em;
    font-weight: 600;
}

.badge-success { background-color: #dcfce7; color: #166534; }
.badge-warning { background-color: #fef3c7; color: #92400e; }
.badge-info { background-color: #ffedd5; color: #c2410c; }
.badge-danger { background-color: #fee2e2; color: #991b1b; }

@media print {
    .header-actions,
    .acciones-factura {
        display: none;
    }

    .factura-detalle {
        padding: 0;
    }

    .card {
        box-shadow: none;
        border: none;
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

    .empresa-header {
        flex-direction: column;
        text-align: center;
    }

    .factura-header {
        flex-direction: column;
        gap: 1rem;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function exportarPDF() {
    window.open('generar_pdf_factura.php?id=<?php echo $id; ?>', '_blank');
}

// Mostrar notificación si viene de un pago exitoso
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_GET['mensaje'])): ?>
        const mensaje = '<?php echo $_GET['mensaje']; ?>';
        let textoMensaje = '';
        let tipo = 'success';
        
        if (mensaje === 'pago_exitoso') {
            textoMensaje = 'Pago registrado exitosamente';
        } else if (mensaje === 'pago_abono') {
            textoMensaje = 'Abono registrado exitosamente';
            tipo = 'info';
        }

        if (textoMensaje) {
            mostrarToast(textoMensaje, tipo);
        }
    <?php endif; ?>
});

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
</script>

<?php require_once 'footer.php'; ?>