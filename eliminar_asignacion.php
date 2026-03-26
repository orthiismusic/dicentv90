<?php
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['asignacion_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de asignación no proporcionado'
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM asignaciones_facturas WHERE id = ?");
    $stmt->execute([$data['asignacion_id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Asignación eliminada exitosamente'
    ]);
} catch (PDOException $e) {
    error_log("Error en eliminar_asignacion: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar la asignación'
    ]);
}
?>