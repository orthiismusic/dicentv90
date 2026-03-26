<?php
require_once 'header.php';

/* ============================================================
   LÓGICA PHP — CRUD
   ============================================================ */
function obtenerSiguienteCodigoCobrador($conn) {
    $stmt = $conn->query("SELECT MAX(CAST(codigo AS UNSIGNED)) as ultimo FROM cobradores");
    $ult  = $stmt->fetch()['ultimo'] ?? 0;
    return str_pad($ult + 1, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn->beginTransaction();
        switch ($_POST['action']) {
            case 'crear':
                $codigo = obtenerSiguienteCodigoCobrador($conn);
                $conn->prepare("
                    INSERT INTO cobradores (codigo, nombre_completo, descripcion, fecha_ingreso, estado)
                    VALUES (?, ?, ?, ?, 'activo')
                ")->execute([$codigo, $_POST['nombre_completo'], $_POST['descripcion'], $_POST['fecha_ingreso']]);
                $mensaje = "Cobrador registrado exitosamente.";
                $tipo_mensaje = "success";
                break;

            case 'editar':
                $conn->prepare("
                    UPDATE cobradores SET nombre_completo=?, descripcion=?, fecha_ingreso=?, estado=?
                    WHERE id=?
                ")->execute([$_POST['nombre_completo'], $_POST['descripcion'], $_POST['fecha_ingreso'], $_POST['estado'], $_POST['id']]);
                $mensaje = "Cobrador actualizado exitosamente.";
                $tipo_mensaje = "success";
                break;

            case 'eliminar':
                $stmt = $conn->prepare("SELECT COUNT(*) FROM clientes WHERE cobrador_id = ?");
                $stmt->execute([$_POST['id']]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("No se puede eliminar: el cobrador tiene clientes asignados.");
                }
                $conn->prepare("DELETE FROM cobradores WHERE id = ?")->execute([$_POST['id']]);
                $mensaje = "Cobrador eliminado exitosamente.";
                $tipo_mensaje = "success";
                break;
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $mensaje     = "Error: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

/* ============================================================
   QUERY — Lista de cobradores con estadísticas
   ============================================================ */
$stmt = $conn->query("
    SELECT cb.*,
           COUNT(DISTINCT cl.id)  AS total_clientes,
           COUNT(DISTINCT p.id)   AS total_pagos,
           COALESCE(SUM(p.monto), 0) AS total_cobrado
    FROM cobradores cb
    LEFT JOIN clientes  cl ON cl.cobrador_id = cb.id
    LEFT JOIN pagos     p  ON p.cobrador_id  = cb.id AND p.estado = 'procesado'
    GROUP BY cb.id
    ORDER BY cb.nombre_completo ASC
");
$cobradores = $stmt->fetchAll();

$total_cob     = count($cobradores);
$cob_activos   = count(array_filter($cobradores, fn($c) => $c['estado'] === 'activo'));
$total_clients = array_sum(array_column($cobradores, 'total_clientes'));
$total_cobrado = array_sum(array_column($cobradores, 'total_cobrado'));
?>

<!-- ============================================================
     ESTILOS (reutiliza los de vendedores que ya están en styles.css)
     ============================================================ -->
<style>
.personal-table-wrap {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    overflow: hidden;
}

.avatar-circle {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #E65100, #F57F17);
    color: white;
    font-size: 13px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.person-cell  { display: flex; align-items: center; gap: 10px; }
.person-name  { font-weight: 600; color: var(--gray-800); font-size: 13.5px; }
.person-code  { font-size: 11px; color: var(--gray-400); font-family: monospace; }
</style>

<!-- ============================================================
     STAT CARDS
     ============================================================ -->
<div class="dashboard-stats fade-in">
    <div class="stat-card yellow">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-motorcycle"></i></div>
            <div class="stat-info">
                <h3>Total Cobradores</h3>
                <p class="stat-value"><?php echo $total_cob; ?></p>
                <p class="stat-label">Registrados en el sistema</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-motorcycle"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
            <div class="stat-info">
                <h3>Activos</h3>
                <p class="stat-value"><?php echo $cob_activos; ?></p>
                <p class="stat-label">En operación actualmente</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-check"></i></div>
    </div>
    <div class="stat-card clients">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <h3>Clientes Asignados</h3>
                <p class="stat-value"><?php echo number_format($total_clients); ?></p>
                <p class="stat-label">Entre todos los cobradores</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-arrow-up"></i></div>
    </div>
    <div class="stat-card income">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-hand-holding-dollar"></i></div>
            <div class="stat-info">
                <h3>Total Cobrado</h3>
                <p class="stat-value">RD$<?php echo number_format($total_cobrado, 0); ?></p>
                <p class="stat-label">Pagos procesados</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-arrow-up"></i></div>
    </div>
</div>

<!-- ALERTA -->
<?php if (isset($mensaje)): ?>
<div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'danger'; ?> fade-in">
    <i class="fas fa-<?php echo $tipo_mensaje === 'success' ? 'circle-check' : 'circle-xmark'; ?>"></i>
    <?php echo htmlspecialchars($mensaje); ?>
</div>
<?php endif; ?>

<!-- ============================================================
     TABLA DE COBRADORES
     ============================================================ -->
<div class="personal-table-wrap fade-in delay-1">
    <div class="card-header">
        <div>
            <div class="card-title">Gestión de Cobradores</div>
            <div class="card-subtitle">Administra el equipo de cobranza</div>
        </div>
        <button class="btn btn-primary" onclick="mostrarModalNuevoCobrador()">
            <i class="fas fa-plus"></i> Nuevo Cobrador
        </button>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Cobrador</th>
                    <th>Fecha Ingreso</th>
                    <th>Descripción</th>
                    <th>Clientes</th>
                    <th>Pagos Cobrados</th>
                    <th>Total Cobrado</th>
                    <th>Estado</th>
                    <th style="text-align:right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cobradores as $c): ?>
                <?php
                    $iniciales = strtoupper(
                        substr($c['nombre_completo'], 0, 1) .
                        (strpos($c['nombre_completo'], ' ') !== false
                            ? substr(strrchr($c['nombre_completo'], ' '), 1, 1)
                            : '')
                    );
                ?>
                <tr>
                    <td>
                        <div class="person-cell">
                            <div class="avatar-circle"><?php echo $iniciales; ?></div>
                            <div>
                                <div class="person-name"><?php echo htmlspecialchars($c['nombre_completo']); ?></div>
                                <div class="person-code">Cód. <?php echo htmlspecialchars($c['codigo']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="td-muted"><?php echo date('d/m/Y', strtotime($c['fecha_ingreso'])); ?></td>
                    <td style="font-size:13px;color:var(--gray-500);max-width:200px;">
                        <?php echo htmlspecialchars($c['descripcion'] ?: '—'); ?>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--gray-800);">
                            <?php echo number_format($c['total_clientes']); ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--accent);">
                            <?php echo number_format($c['total_pagos']); ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--green);">
                            RD$<?php echo number_format($c['total_cobrado'], 0); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $c['estado'] === 'activo' ? 'badge-activo' : 'badge-inactivo'; ?>">
                            <?php echo ucfirst($c['estado']); ?>
                        </span>
                    </td>
                    <td class="actions-cell">
                        <button class="btn-action edit" title="Editar"
                                onclick="editarCobrador(<?php echo htmlspecialchars(json_encode($c)); ?>)">
                            <i class="fas fa-pen"></i>
                        </button>
                        <?php if ($c['total_clientes'] == 0): ?>
                        <button class="btn-action delete" title="Eliminar"
                                onclick="eliminarCobrador(<?php echo $c['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($cobradores)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:32px;color:var(--gray-400);">
                        <i class="fas fa-motorcycle" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                        No hay cobradores registrados
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================
     MODAL COBRADOR (Crear / Editar)
     ============================================================ -->
<div class="modal-overlay" id="cobradorModal" style="display:none;align-items:center;justify-content:center;">
    <div class="modal-container" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="modal-title" id="cobradorModalTitle">
                <i class="fas fa-motorcycle" style="color:var(--accent);margin-right:8px;"></i>Nuevo Cobrador
            </h3>
            <button class="modal-close" onclick="cerrarModalCobrador()"><i class="fas fa-times"></i></button>
        </div>
        <form id="cobradorForm" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="cobradorAction" value="crear">
                <input type="hidden" name="id"     id="cobradorId">

                <div class="form-group">
                    <label>Código</label>
                    <input type="text" id="codigoCobrador" name="codigo" class="form-control"
                           readonly style="background:var(--gray-50);color:var(--gray-500);">
                </div>
                <div class="form-group">
                    <label>Nombre Completo <span style="color:var(--red-light)">*</span></label>
                    <input type="text" id="nombre_completo_cob" name="nombre_completo"
                           class="form-control" required placeholder="Nombre y apellidos">
                </div>
                <div class="form-group">
                    <label>Descripción / Notas</label>
                    <textarea id="descripcion_cob" name="descripcion" class="form-control" rows="3"
                              placeholder="Información adicional (opcional)"></textarea>
                </div>
                <div class="form-group">
                    <label>Fecha de Ingreso <span style="color:var(--red-light)">*</span></label>
                    <input type="date" id="fecha_ingreso_cob" name="fecha_ingreso" class="form-control" required>
                </div>
                <div class="form-group" id="estadoGroupCob" style="display:none;">
                    <label>Estado <span style="color:var(--red-light)">*</span></label>
                    <select id="estadoCob" name="estado" class="form-control" required>
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalCobrador()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
function mostrarModalNuevoCobrador() {
    document.getElementById('cobradorModalTitle').innerHTML =
        '<i class="fas fa-motorcycle" style="color:var(--accent);margin-right:8px;"></i>Nuevo Cobrador';
    document.getElementById('cobradorAction').value       = 'crear';
    document.getElementById('cobradorId').value           = '';
    document.getElementById('cobradorForm').reset();
    document.getElementById('codigoCobrador').value       = 'Se generará automáticamente';
    document.getElementById('estadoGroupCob').style.display = 'none';
    document.getElementById('fecha_ingreso_cob').valueAsDate = new Date();

    document.getElementById('cobradorModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function cerrarModalCobrador() {
    document.getElementById('cobradorModal').style.display = 'none';
    document.body.style.overflow = '';
}

function editarCobrador(c) {
    document.getElementById('cobradorModalTitle').innerHTML =
        '<i class="fas fa-pen" style="color:var(--accent);margin-right:8px;"></i>Editar Cobrador';
    document.getElementById('cobradorAction').value          = 'editar';
    document.getElementById('cobradorId').value              = c.id;
    document.getElementById('codigoCobrador').value          = c.codigo;
    document.getElementById('nombre_completo_cob').value     = c.nombre_completo;
    document.getElementById('descripcion_cob').value         = c.descripcion || '';
    document.getElementById('fecha_ingreso_cob').value       = c.fecha_ingreso;
    document.getElementById('estadoCob').value               = c.estado;
    document.getElementById('estadoGroupCob').style.display  = 'block';

    document.getElementById('cobradorModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function eliminarCobrador(id) {
    if (confirm('¿Está seguro de que desea eliminar este cobrador?')) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="action" value="eliminar">
                       <input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
    }
}

/* Cerrar con clic fuera */
document.getElementById('cobradorModal').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalCobrador();
});
</script>

<?php require_once 'footer.php'; ?>