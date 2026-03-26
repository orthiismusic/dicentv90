<?php
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['factura_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de factura no proporcionado'
    ]);
    exit;
}

try {
    $conn->beginTransaction();

    // Obtener la asignación actual
    $stmt = $conn->prepare("
        SELECT id, cobrador_id, fecha_asignacion 
        FROM asignaciones_facturas 
        WHERE factura_id = ? AND estado = 'activa'
    ");
    $stmt->execute([$data['factura_id']]);
    $asignacion = $stmt->fetch();

    if ($asignacion) {
        // Eliminar la asignación actual
        $stmt = $conn->prepare("
            DELETE FROM asignaciones_facturas 
            WHERE factura_id = ? AND estado = 'activa'
        ");
        $stmt->execute([$data['factura_id']]);
    }

    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Asignación eliminada correctamente'
    ]);

} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Error en eliminar_asignacion_factura: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar la asignación'
    ]);
}
?>