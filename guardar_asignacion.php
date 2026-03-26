<?php
require_once 'config.php';

// Verificar que sea una petición POST con datos JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

// Obtener datos JSON
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['cobrador_id']) || !isset($data['fecha_asignacion']) || !isset($data['facturas'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Datos incompletos'
    ]);
    exit;
}

try {
    $conn->beginTransaction();

    // Para cada factura a asignar
    foreach ($data['facturas'] as $factura_id) {
        // Verificar si ya existe una asignación activa
        $stmt = $conn->prepare("
            SELECT af.id, af.cobrador_id, af.fecha_asignacion, co.nombre_completo
            FROM asignaciones_facturas af
            JOIN cobradores co ON af.cobrador_id = co.id
            WHERE af.factura_id = ? AND af.estado = 'activa'
        ");
        $stmt->execute([$factura_id]);
        $asignacion_existente = $stmt->fetch();

        if ($asignacion_existente) {
            // Marcar la asignación anterior como reasignada
            $stmt = $conn->prepare("
                UPDATE asignaciones_facturas 
                SET estado = 'reasignada' 
                WHERE id = ?
            ");
            $stmt->execute([$asignacion_existente['id']]);

            // Registrar en el historial
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
                $asignacion_existente['id'],
                $asignacion_existente['cobrador_id'],
                $asignacion_existente['fecha_asignacion'],
                $data['cobrador_id'],
                $data['fecha_asignacion']
            ]);
        }

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
            $factura_id,
            $data['cobrador_id'],
            $data['fecha_asignacion']
        ]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Facturas asignadas correctamente'
    ]);

} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Error en guardar_asignacion: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al guardar las asignaciones'
    ]);
}