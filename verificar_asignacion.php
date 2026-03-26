<?php
require_once 'config.php';

// Verificar si se proporcionó el número de factura
if (!isset($_GET['numero_factura'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Número de factura no proporcionado'
    ]);
    exit;
}

try {
    $numero_factura = $_GET['numero_factura'];

    // Primero verificar si la factura existe y está pendiente
    $sql = "
        SELECT 
            f.id,
            f.numero_factura,
            f.monto,
            f.estado,
            f.mes_factura,
            c.numero_contrato,
            cl.nombre as cliente_nombre,
            cl.apellidos as cliente_apellidos,
            c.dia_cobro
        FROM facturas f
        JOIN contratos c ON f.contrato_id = c.id
        JOIN clientes cl ON c.cliente_id = cl.id
        WHERE f.numero_factura = ?
        AND f.estado = 'pendiente'
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$numero_factura]);
    $factura = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        echo json_encode([
            'success' => false,
            'message' => 'Factura no encontrada o no está pendiente de pago'
        ]);
        exit;
    }

    // Verificar si ya está asignada
    $sql = "
        SELECT 
            af.id,
            af.fecha_asignacion,
            co.nombre_completo as cobrador_nombre
        FROM asignaciones_facturas af
        JOIN cobradores co ON af.cobrador_id = co.id
        WHERE af.factura_id = ?
        AND af.estado = 'activa'
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$factura['id']]);
    $asignacion = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($asignacion) {
        echo json_encode([
            'success' => true,
            'asignada' => true,
            'factura' => $factura,
            'asignacion' => [
                'id' => $asignacion['id'],
                'fecha_asignacion' => $asignacion['fecha_asignacion'],
                'cobrador_nombre' => $asignacion['cobrador_nombre']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'asignada' => false,
            'factura' => $factura
        ]);
    }

} catch (PDOException $e) {
    error_log("Error en verificar_asignacion: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al verificar la factura'
    ]);
}