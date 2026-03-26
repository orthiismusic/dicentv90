<?php
require_once 'config.php';
header('Content-Type: application/json');

// Para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['asignacion_id']) || !isset($data['nuevo_cobrador_id']) || !isset($data['nueva_fecha'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Datos incompletos para la reasignación'
    ]);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Obtener la información actual de la asignación
    $stmt = $conn->prepare("
        SELECT id, cobrador_id, fecha_asignacion, factura_id
        FROM asignaciones_facturas 
        WHERE id = ? AND estado = 'activa'
    ");
    $stmt->execute([$data['asignacion_id']]);
    $asignacion = $stmt->fetch();
    
    if (!$asignacion) {
        echo json_encode([
            'success' => false,
            'message' => 'La asignación no existe o no está activa'
        ]);
        $conn->rollBack();
        exit;
    }
    
    // Registrar en el historial antes de eliminar
    $stmt = $conn->prepare("
        INSERT INTO historial_reasignaciones (
            asignacion_id, 
            cobrador_anterior_id,
            fecha_anterior,
            cobrador_nuevo_id,
            fecha_nueva
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['asignacion_id'],
        $asignacion['cobrador_id'],
        $asignacion['fecha_asignacion'],
        $data['nuevo_cobrador_id'],
        $data['nueva_fecha']
    ]);
    
    // Primero eliminar de historial_reasignaciones donde esta asignación es referenciada
    $stmt = $conn->prepare("DELETE FROM historial_reasignaciones WHERE asignacion_id = ?");
    $stmt->execute([$data['asignacion_id']]);
    
    // Ahora eliminar la asignación original
    $stmt = $conn->prepare("DELETE FROM asignaciones_facturas WHERE id = ?");
    $stmt->execute([$data['asignacion_id']]);
    
    // Crear nueva asignación
    $stmt = $conn->prepare("
        INSERT INTO asignaciones_facturas (
            factura_id,
            cobrador_id,
            fecha_asignacion,
            estado
        ) VALUES (?, ?, ?, 'activa')
    ");
    $stmt->execute([
        $asignacion['factura_id'],
        $data['nuevo_cobrador_id'],
        $data['nueva_fecha']
    ]);
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Asignación reasignada exitosamente'
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error en reasignar_asignacion: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al reasignar la asignación: ' . $e->getMessage()
    ]);
}
?>