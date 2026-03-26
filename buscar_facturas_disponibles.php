<?php
require_once 'config.php';

// Verificar si se han proporcionado los parámetros necesarios
if (!isset($_GET['cobrador_id']) || !isset($_GET['fecha'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Faltan parámetros requeridos'
    ]);
    exit;
}

try {
    $cobrador_id = $_GET['cobrador_id'];
    $fecha = $_GET['fecha'];

    // Construir la consulta base
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
            c.dia_cobro,
            af.fecha_asignacion
        FROM facturas f
        JOIN asignaciones_facturas af ON f.id = af.factura_id
        JOIN contratos c ON f.contrato_id = c.id
        JOIN clientes cl ON c.cliente_id = cl.id
        WHERE af.cobrador_id = ?
        AND af.fecha_asignacion = ?
        AND af.estado = 'activa'
    ";

    $params = [$cobrador_id, $fecha];

    // Agregar filtro de estado si se proporciona
    if (isset($_GET['estado']) && !empty($_GET['estado'])) {
        $sql .= " AND f.estado = ?";
        $params[] = $_GET['estado'];
    }

    // Agregar ordenamiento
    $sql .= " ORDER BY c.dia_cobro ASC, cl.nombre ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular totales
    $total_facturas = count($facturas);
    $total_monto = array_sum(array_column($facturas, 'monto'));

    echo json_encode([
        'success' => true,
        'facturas' => $facturas,
        'totales' => [
            'cantidad' => $total_facturas,
            'monto' => $total_monto
        ]
    ]);

} catch (PDOException $e) {
    error_log("Error en buscar_facturas_disponibles: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al buscar las facturas'
    ]);
}