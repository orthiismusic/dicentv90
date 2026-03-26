<?php
require_once 'config.php';
header('Content-Type: application/json');

// Para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['asignaciones_ids']) || empty($data['asignaciones_ids'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No se proporcionaron IDs de asignaciones'
    ]);
    exit;
}

$ids = $data['asignaciones_ids'];

try {
    $conn->beginTransaction();
    
    $contador = 0;
    
    foreach ($ids as $id) {
        // Asegurarnos de que el ID es un valor numérico válido
        $id = trim($id);
        if (!is_numeric($id)) continue;
        
        // PRIMERO: Eliminar registros relacionados en historial_reasignaciones
        $stmt = $conn->prepare("DELETE FROM historial_reasignaciones WHERE asignacion_id = ?");
        $stmt->execute([$id]);
        
        // DESPUÉS: Eliminar la asignación
        $stmt = $conn->prepare("DELETE FROM asignaciones_facturas WHERE id = ?");
        $stmt->execute([$id]);
        $contador++;
    }
    
    // Si no se procesó ningún registro, algo está mal
    if ($contador == 0) {
        throw new Exception("No se procesó ningún ID válido");
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Asignaciones eliminadas exitosamente',
        'registros_eliminados' => $contador
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error en eliminar_asignaciones_grupo: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar las asignaciones: ' . $e->getMessage()
    ]);
}
?>