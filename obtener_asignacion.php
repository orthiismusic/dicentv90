<?php
require_once 'config.php';

// Verificar si se proporcionó el ID de la asignación
if (!isset($_GET['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de asignación no proporcionado'
    ]);
    exit;
}

try {
    // Obtener información detallada de la asignación
    $sql = "
        SELECT 
            af.id,
            af.fecha_asignacion,
            af.estado,
            f.id as factura_id,
            f.numero_factura,
            f.monto,
            f.estado as factura_estado,
            c.numero_contrato,
            cl.nombre as cliente_nombre,
            cl.apellidos as cliente_apellidos,
            co.id as cobrador_id,
            co.codigo as cobrador_codigo,
            co.nombre_completo as cobrador_nombre
        FROM asignaciones_facturas af
        JOIN facturas f ON af.factura_id = f.id
        JOIN contratos c ON f.contrato_id = c.id
        JOIN clientes cl ON c.cliente_id = cl.id
        JOIN cobradores co ON af.cobrador_id = co.id
        WHERE af.id = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$_GET['id']]);
    $asignacion = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asignacion) {
        echo json_encode([
            'success' => false,
            'message' => 'Asignación no encontrada'
        ]);
        exit;
    }

    // Formatear la fecha para mostrar
    $asignacion['fecha_asignacion_formatted'] = date('d/m/Y', strtotime($asignacion['fecha_asignacion']));

    // Verificar si hay un historial de reasignaciones
    $sql = "
        SELECT 
            hr.*,
            co_ant.nombre_completo as cobrador_anterior_nombre,
            co_new.nombre_completo as cobrador_nuevo_nombre
        FROM historial_reasignaciones hr
        JOIN cobradores co_ant ON hr.cobrador_anterior_id = co_ant.id
        JOIN cobradores co_new ON hr.cobrador_nuevo_id = co_new.id
        WHERE hr.asignacion_id = ?
        ORDER BY hr.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$_GET['id']]);
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Preparar la respuesta
    $response = [
        'success' => true,
        'asignacion' => [
            'id' => $asignacion['id'],
            'fecha_asignacion' => $asignacion['fecha_asignacion'],
            'fecha_asignacion_formatted' => $asignacion['fecha_asignacion_formatted'],
            'estado' => $asignacion['estado'],
            'factura' => [
                'id' => $asignacion['factura_id'],
                'numero_factura' => $asignacion['numero_factura'],
                'monto' => $asignacion['monto'],
                'estado' => $asignacion['factura_estado'],
                'contrato' => $asignacion['numero_contrato'],
                'cliente' => $asignacion['cliente_nombre'] . ' ' . $asignacion['cliente_apellidos']
            ],
            'cobrador' => [
                'id' => $asignacion['cobrador_id'],
                'codigo' => $asignacion['cobrador_codigo'],
                'nombre' => $asignacion['cobrador_nombre']
            ]
        ]
    ];

    // Agregar historial si existe
    if (!empty($historial)) {
        $response['historial'] = array_map(function($h) {
            return [
                'fecha_anterior' => date('d/m/Y', strtotime($h['fecha_anterior'])),
                'fecha_nueva' => date('d/m/Y', strtotime($h['fecha_nueva'])),
                'cobrador_anterior' => $h['cobrador_anterior_nombre'],
                'cobrador_nuevo' => $h['cobrador_nuevo_nombre'],
                'fecha_cambio' => date('d/m/Y H:i', strtotime($h['created_at']))
            ];
        }, $historial);
    }

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Error en obtener_asignacion: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener la información de la asignación'
    ]);
}
?>