<?php
require_once 'config.php';
header('Content-Type: application/json');

// Para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['asignaciones_ids']) || !isset($data['nuevo_cobrador_id']) || !isset($data['nueva_fecha'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Datos incompletos para la reasignación'
    ]);
    exit;
}

$ids = $data['asignaciones_ids'];
$nuevoCobrador = $data['nuevo_cobrador_id'];
$nuevaFecha = $data['nueva_fecha'];

try {
    $conn->beginTransaction();
    
    $contador = 0;
    
    foreach ($ids as $id) {
        // Asegurarnos de que el ID es un valor numérico válido
        $id = trim($id);
        if (!is_numeric($id)) continue;
        
        // Obtener la información actual de la asignación
        $stmt = $conn->prepare("
            SELECT id, cobrador_id, fecha_asignacion, factura_id
            FROM asignaciones_facturas 
            WHERE id = ? AND estado = 'activa'
        ");
        $stmt->execute([$id]);
        $asignacion = $stmt->fetch();
        
        if (!$asignacion) continue;
        
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
            $id,
            $asignacion['cobrador_id'],
            $asignacion['fecha_asignacion'],
            $nuevoCobrador,
            $nuevaFecha
        ]);
        
        // Primero eliminar de historial_reasignaciones donde esta asignación es referenciada
        $stmt = $conn->prepare("DELETE FROM historial_reasignaciones WHERE asignacion_id = ?");
        $stmt->execute([$id]);
        
        // Ahora eliminar la asignación original
        $stmt = $conn->prepare("DELETE FROM asignaciones_facturas WHERE id = ?");
        $stmt->execute([$id]);
        
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
            $nuevoCobrador,
            $nuevaFecha
        ]);
        
        $contador++;
    }
    
    // Si no se procesó ningún registro, algo está mal
    if ($contador == 0) {
        throw new Exception("No se procesó ninguna asignación válida");
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Asignaciones reasignadas exitosamente',
        'registros_procesados' => $contador
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error en reasignar_asignaciones_grupo: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al reasignar las asignaciones: ' . $e->getMessage()
    ]);
}
?>