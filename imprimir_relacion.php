<?php
require_once 'config.php';

// Agregar esto al inicio del archivo, después del require_once
function formatearMes($fecha) {
    $meses = [
        '01' => 'Ene',
        '02' => 'Feb',
        '03' => 'Mar',
        '04' => 'Abr',
        '05' => 'May',
        '06' => 'Jun',
        '07' => 'Jul',
        '08' => 'Ago',
        '09' => 'Sep',
        '10' => 'Oct',
        '11' => 'Nov',
        '12' => 'Dic'
    ];
    
    $partes = explode('/', $fecha);
    if (count($partes) == 2) {
        return $meses[$partes[0]] . '/' . $partes[1];
    }
    return $fecha;
}

// Verificar parámetros
if (!isset($_GET['cobrador_id']) || !isset($_GET['fecha'])) {
    die('Parámetros incompletos');
}

try {
    // Obtener información del cobrador
    $stmt = $conn->prepare("
        SELECT codigo, nombre_completo
        FROM cobradores
        WHERE id = ?
    ");
    $stmt->execute([$_GET['cobrador_id']]);
    $cobrador = $stmt->fetch();

    // Obtener facturas asignadas
    $sql = "
        SELECT 
            f.numero_factura,
            f.monto,
            f.mes_factura,
            f.estado,
            c.numero_contrato,
            c.dia_cobro,
            cl.nombre as cliente_nombre,
            cl.apellidos as cliente_apellidos
        FROM asignaciones_facturas af
        JOIN facturas f ON af.factura_id = f.id
        JOIN contratos c ON f.contrato_id = c.id
        JOIN clientes cl ON c.cliente_id = cl.id
        WHERE af.cobrador_id = ?
        AND af.fecha_asignacion = ?
        AND af.estado = 'activa'
    ";

    $params = [$_GET['cobrador_id'], $_GET['fecha']];

    // Agregar filtro de estado si se proporciona
    if (isset($_GET['estado']) && !empty($_GET['estado'])) {
        $sql .= " AND f.estado = ?";
        $params[] = $_GET['estado'];
    }

    $sql .= " ORDER BY c.dia_cobro ASC, cl.nombre ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $facturas = $stmt->fetchAll();

    // Calcular totales
    $total_facturas = count($facturas);
    $monto_total = array_sum(array_column($facturas, 'monto'));

    // Obtener configuración del sistema
    $stmtConfig = $conn->prepare("SELECT *, logo_url FROM configuracion_sistema WHERE id = 1");
    $stmtConfig->execute();
    $config = $stmtConfig->fetch();
} catch (PDOException $e) {
    die('Error al obtener los datos');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Relación de Facturas</title>
    <style>
        @page {
            size: 8.5in 11in;
            margin: 0;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: "Draft", "Letter Gothic", "Arial", monospace;
        }
        .pagina {
            width: 8.5in;
            height: 11in;
            padding: 0.5in;
            box-sizing: border-box;
            position: relative;
            page-break-after: always;
        }
        .header {
            text-align: left;
            margin-bottom: 0.3in;
            display: flex;
            align-items: center;
            gap: 0.2in;
        }
        .logo {
            max-height: 0.9in;
        }
        .header-text {
            flex: 1;
        }
        .company-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 0.05in;
            text-transform: uppercase;
            border-bottom: 1px solid #000;
            padding-bottom: 0.05in;
        }
        .company-address {
            font-size: 13px;
            line-height: 1.2;
            text-align: center;
        }
        .info-cobrador {
            margin: 0.2in 0;
            padding: 0.1in;
            border: 1px solid #000;
            font-size: 13px;  /* Aumentado de 12px a 15px */
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.2in;
            font-size: 13px;  /* Aumentado de 11px a 15px */
        }
        th, td {
            border: 1px solid #000;
            padding: 0.05in;
            text-align: left;
            font-size: 13px;  /* Aumentado a 15px */
        }
        th {
            background-color: #f4f4f4;
        }
        .estado-badge {
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 13px;  /* Aumentado de 10px a 15px */
        }
        .estado-pendiente {
            background-color: #fff3cd;
            color: #856404;
        }
        .estado-pagada {
            background-color: #d4edda;
            color: #155724;
        }
        .control-buttons {
            text-align: left;
            padding: 13px;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }
        .btn {
            padding: 8px 14px;
            margin-left: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background-color: #0066cc;
            color: white;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        .totales {
            margin-top: 0.2in;
            text-align: right;
            font-size: 14px;
            font-weight: bold;
        }
        
        td.dia-cobro {
            text-align: center;
        }
        
        td.no-factura {
            text-align: center;
        }
        
        td.no-contrato {
            text-align: center;
        }
        
        td.mes-factura {
            text-align: center;
        }
        
        th.centrar-titulos {
            text-align: center;
        }
        
        th.dia-cobro {
            text-align: center;
        }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="padding: 20px;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Imprimir
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="fas fa-times"></i> Cerrar
        </button>
    </div>

    <?php 
    $registros_por_pagina = 20;
    $total_paginas = ceil($total_facturas / $registros_por_pagina);
    
    for ($pagina = 0; $pagina < $total_paginas; $pagina++): 
        $inicio = $pagina * $registros_por_pagina;
        $facturas_pagina = array_slice($facturas, $inicio, $registros_por_pagina);
    ?>
    <div class="pagina">
        
                
                
        <?php if ($config['logo_url']): ?>
                <img src="<?php echo htmlspecialchars($config['logo_url']); ?>" alt="Logo" class="logo">
            <?php endif; ?>
            
        <div style="text-align: center; margin-bottom: 0.1in;">
                <h3 style="margin: 0;">Relación de Facturas Asignadas</h3>
                <p style="margin: 0.1in 0;">Fecha: <?php echo date('d/m/y', strtotime($_GET['fecha'])); ?></p>
        </div>

        <div class="info-cobrador">
            <div><strong>Cobrador:</strong> <?php echo $cobrador['codigo'] . ' - ' . $cobrador['nombre_completo']; ?></div>
            <div><strong>Total Facturas:</strong> <?php echo $total_facturas; ?></div>
            <div><strong>Monto Total:</strong> RD$<?php echo number_format($monto_total, 2); ?></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="centrar-titulos">No. Factura</th>
                    <th class="centrar-titulos">No. Contrato</th>
                    <th class="centrar-titulos">Cliente</th>
                    <th class="centrar-titulos">Mes</th>
                    <th class="centrar-titulos">Día Pago</th>
                    <th class="centrar-titulos">Monto</th>
                    <th class="centrar-titulos">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facturas_pagina as $factura): ?>
                    <tr>
                        <td class="no-factura"><?php echo str_pad($factura['numero_factura'], 7, '0', STR_PAD_LEFT); ?></td>
                        <td class="no-contrato"><?php echo str_pad($factura['numero_contrato'], 6, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo strtoupper($factura['cliente_nombre'] . ' ' . $factura['cliente_apellidos']); ?></td>
                        <td class="mes-factura"><?php echo formatearMes($factura['mes_factura']); ?></td>
                        <td class="dia-cobro"><?php echo $factura['dia_cobro']; ?></td>
                        <td>RD$<?php echo number_format($factura['monto'], 2); ?></td>
                        <td>
                            <span class="estado-badge estado-<?php echo strtolower($factura['estado']); ?>">
                                <?php echo ucfirst($factura['estado']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totales">
            <div>Subtotal Página: RD$<?php echo number_format(array_sum(array_column($facturas_pagina, 'monto')), 2); ?></div>
            <?php if ($pagina === $total_paginas - 1): ?>
                <div style="margin-top: 0.1in;">Total General: RD$<?php echo number_format($monto_total, 2); ?></div>
            <?php endif; ?>
        </div>

        <div style="position: absolute; bottom: 0.3in; right: 0.5in; font-size: 12px;">
            Página <?php echo ($pagina + 1); ?> de <?php echo $total_paginas; ?>
        </div>
    </div>
    <?php endfor; ?>
</body>
</html>