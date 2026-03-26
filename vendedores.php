<?php
require_once 'header.php';

/* ============================================================
   LÓGICA PHP — CRUD
   ============================================================ */
function obtenerSiguienteCodigoVendedor($conn) {
    $stmt = $conn->query("SELECT MAX(CAST(codigo AS UNSIGNED)) as ultimo FROM vendedores");
    $ult  = $stmt->fetch()['ultimo'] ?? 0;
    return str_pad($ult + 1, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn->beginTransaction();
        switch ($_POST['action']) {
            case 'crear':
                $codigo = obtenerSiguienteCodigoVendedor($conn);
                $conn->prepare("
                    INSERT INTO vendedores (codigo, nombre_completo, descripcion, fecha_ingreso, estado)
                    VALUES (?, ?, ?, ?, 'activo')
                ")->execute([$codigo, $_POST['nombre_completo'], $_POST['descripcion'], $_POST['fecha_ingreso']]);
                $mensaje = "Vendedor registrado exitosamente.";
                $tipo_mensaje = "success";
                break;

            case 'editar':
                $conn->prepare("
                    UPDATE vendedores SET nombre_completo=?, descripcion=?, fecha_ingreso=?, estado=?
                    WHERE id=?
                ")->execute([$_POST['nombre_completo'], $_POST['descripcion'], $_POST['fecha_ingreso'], $_POST['estado'], $_POST['id']]);
                $mensaje = "Vendedor actualizado exitosamente.";
                $tipo_mensaje = "success";
                break;

            case 'eliminar':
                $stmt = $conn->prepare("SELECT COUNT(*) FROM clientes WHERE vendedor_id = ?");
                $stmt->execute([$_POST['id']]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("No se puede eliminar: el vendedor tiene clientes asignados.");
                }
                $conn->prepare("DELETE FROM vendedores WHERE id = ?")->execute([$_POST['id']]);
                $mensaje = "Vendedor eliminado exitosamente.";
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
   QUERY — Lista de vendedores con estadísticas
   ============================================================ */
$stmt = $conn->query("
    SELECT v.*,
           COUNT(DISTINCT cl.id)  AS total_clientes,
           COUNT(DISTINCT co.id)  AS total_contratos,
           COALESCE(SUM(f.monto), 0) AS total_facturado
    FROM vendedores v
    LEFT JOIN clientes   cl ON cl.vendedor_id = v.id
    LEFT JOIN contratos  co ON co.vendedor_id = v.id AND co.estado = 'activo'
    LEFT JOIN facturas   f  ON f.contrato_id  = co.id AND f.estado = 'pagada'
    GROUP BY v.id
    ORDER BY v.nombre_completo ASC
");
$vendedores = $stmt->fetchAll();

$total_vend    = count($vendedores);
$vend_activos  = count(array_filter($vendedores, fn($v) => $v['estado'] === 'activo'));
$total_clients = array_sum(array_column($vendedores, 'total_clientes'));
$total_fact    = array_sum(array_column($vendedores, 'total_facturado'));
?>

<!-- ============================================================
     ESTILOS
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
    background: linear-gradient(135deg, var(--accent), var(--blue-dark));
    color: white;
    font-size: 13px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.person-cell { display: flex; align-items: center; gap: 10px; }
.person-name { font-weight: 600; color: var(--gray-800); font-size: 13.5px; }
.person-code { font-size: 11px; color: var(--gray-400); font-family: monospace; }
</style>

<!-- ============================================================
     STAT CARDS
     ============================================================ -->
<div class="dashboard-stats fade-in">
    <div class="stat-card blue">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
            <div class="stat-info">
                <h3>Total Vendedores</h3>
                <p class="stat-value"><?php echo $total_vend; ?></p>
                <p class="stat-label">Registrados en el sistema</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-briefcase"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
            <div class="stat-info">
                <h3>Activos</h3>
                <p class="stat-value"><?php echo $vend_activos; ?></p>
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
                <p class="stat-label">Entre todos los vendedores</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-arrow-up"></i></div>
    </div>
    <div class="stat-card income">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-info">
                <h3>Total Facturado</h3>
                <p class="stat-value">RD$<?php echo number_format($total_fact, 0); ?></p>
                <p class="stat-label">Facturas pagadas</p>
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
     TABLA DE VENDEDORES
     ============================================================ -->
<div class="personal-table-wrap fade-in delay-1">
    <div class="card-header">
        <div>
            <div class="card-title">Gestión de Vendedores</div>
            <div class="card-subtitle">Administra el equipo de ventas</div>
        </div>
        <button class="btn btn-primary" onclick="mostrarModalNuevoVendedor()">
            <i class="fas fa-plus"></i> Nuevo Vendedor
        </button>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vendedor</th>
                    <th>Fecha Ingreso</th>
                    <th>Descripción</th>
                    <th>Clientes</th>
                    <th>Contratos</th>
                    <th>Total Facturado</th>
                    <th>Estado</th>
                    <th style="text-align:right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vendedores as $v): ?>
                <?php
                    $iniciales = strtoupper(
                        substr($v['nombre_completo'], 0, 1) .
                        (strpos($v['nombre_completo'], ' ') !== false
                            ? substr(strrchr($v['nombre_completo'], ' '), 1, 1)
                            : '')
                    );
                ?>
                <tr>
                    <td>
                        <div class="person-cell">
                            <div class="avatar-circle"><?php echo $iniciales; ?></div>
                            <div>
                                <div class="person-name"><?php echo htmlspecialchars($v['nombre_completo']); ?></div>
                                <div class="person-code">Cód. <?php echo htmlspecialchars($v['codigo']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="td-muted"><?php echo date('d/m/Y', strtotime($v['fecha_ingreso'])); ?></td>
                    <td style="font-size:13px;color:var(--gray-500);max-width:200px;">
                        <?php echo htmlspecialchars($v['descripcion'] ?: '—'); ?>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--gray-800);">
                            <?php echo number_format($v['total_clientes']); ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--accent);">
                            <?php echo number_format($v['total_contratos']); ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--green);">
                            RD$<?php echo number_format($v['total_facturado'], 0); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $v['estado'] === 'activo' ? 'badge-activo' : 'badge-inactivo'; ?>">
                            <?php echo ucfirst($v['estado']); ?>
                        </span>
                    </td>
                    <td class="actions-cell">
                        <button class="btn-action edit" title="Editar"
                                onclick="editarVendedor(<?php echo htmlspecialchars(json_encode($v)); ?>)">
                            <i class="fas fa-pen"></i>
                        </button>
                        <?php if ($v['total_clientes'] == 0): ?>
                        <button class="btn-action delete" title="Eliminar"
                                onclick="eliminarVendedor(<?php echo $v['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vendedores)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:32px;color:var(--gray-400);">
                        <i class="fas fa-briefcase" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                        No hay vendedores registrados
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================
     MODAL VENDEDOR (Crear / Editar)
     ============================================================ -->
<div class="modal-overlay" id="vendedorModal" style="display:none;align-items:center;justify-content:center;">
    <div class="modal-container" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="modal-title" id="vendedorModalTitle">
                <i class="fas fa-briefcase" style="color:var(--accent);margin-right:8px;"></i>Nuevo Vendedor
            </h3>
            <button class="modal-close" onclick="cerrarModalVendedor()"><i class="fas fa-times"></i></button>
        </div>
        <form id="vendedorForm" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="vendedorAction" value="crear">
                <input type="hidden" name="id"     id="vendedorId">

                <div class="form-group">
                    <label>Código</label>
                    <input type="text" id="codigoVendedor" name="codigo" class="form-control"
                           readonly style="background:var(--gray-50);color:var(--gray-500);">
                </div>
                <div class="form-group">
                    <label>Nombre Completo <span style="color:var(--red-light)">*</span></label>
                    <input type="text" id="nombre_completo" name="nombre_completo"
                           class="form-control" required placeholder="Nombre y apellidos">
                </div>
                <div class="form-group">
                    <label>Descripción / Notas</label>
                    <textarea id="descripcion" name="descripcion" class="form-control" rows="3"
                              placeholder="Información adicional (opcional)"></textarea>
                </div>
                <div class="form-group">
                    <label>Fecha de Ingreso <span style="color:var(--red-light)">*</span></label>
                    <input type="date" id="fecha_ingreso" name="fecha_ingreso" class="form-control" required>
                </div>
                <div class="form-group" id="estadoGroupVend" style="display:none;">
                    <label>Estado <span style="color:var(--red-light)">*</span></label>
                    <select id="estadoVend" name="estado" class="form-control" required>
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalVendedor()">
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
function mostrarModalNuevoVendedor() {
    document.getElementById('vendedorModalTitle').innerHTML =
        '<i class="fas fa-briefcase" style="color:var(--accent);margin-right:8px;"></i>Nuevo Vendedor';
    document.getElementById('vendedorAction').value    = 'crear';
    document.getElementById('vendedorId').value        = '';
    document.getElementById('vendedorForm').reset();
    document.getElementById('codigoVendedor').value    = 'Se generará automáticamente';
    document.getElementById('estadoGroupVend').style.display = 'none';
    document.getElementById('fecha_ingreso').valueAsDate = new Date();

    document.getElementById('vendedorModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function cerrarModalVendedor() {
    document.getElementById('vendedorModal').style.display = 'none';
    document.body.style.overflow = '';
}

function editarVendedor(v) {
    document.getElementById('vendedorModalTitle').innerHTML =
        '<i class="fas fa-pen" style="color:var(--accent);margin-right:8px;"></i>Editar Vendedor';
    document.getElementById('vendedorAction').value     = 'editar';
    document.getElementById('vendedorId').value         = v.id;
    document.getElementById('codigoVendedor').value     = v.codigo;
    document.getElementById('nombre_completo').value    = v.nombre_completo;
    document.getElementById('descripcion').value        = v.descripcion || '';
    document.getElementById('fecha_ingreso').value      = v.fecha_ingreso;
    document.getElementById('estadoVend').value         = v.estado;
    document.getElementById('estadoGroupVend').style.display = 'block';

    document.getElementById('vendedorModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function eliminarVendedor(id) {
    if (confirm('¿Está seguro de que desea eliminar este vendedor?')) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="action" value="eliminar">
                       <input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
    }
}

/* Cerrar con clic fuera */
document.getElementById('vendedorModal').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalVendedor();
});
</script>

<?php require_once 'footer.php'; ?>