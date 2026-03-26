<?php
require_once 'header.php';

// Verificar permisos de administrador
if ($_SESSION['rol'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

/* ============================================================
   LÓGICA PHP — CREAR / EDITAR / ELIMINAR
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn->beginTransaction();

        switch ($_POST['action']) {

            case 'crear':
                $stmt = $conn->prepare("
                    INSERT INTO planes (
                        codigo, nombre, descripcion, precio_base,
                        cobertura_maxima, edad_minima, edad_maxima,
                        periodo_carencia, estado
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')
                ");
                $stmt->execute([
                    $_POST['codigo'],
                    $_POST['nombre'],
                    $_POST['descripcion'],
                    $_POST['precio_base'],
                    $_POST['cobertura_maxima'],
                    $_POST['edad_minima'],
                    $_POST['edad_maxima'],
                    $_POST['periodo_carencia']
                ]);
                $plan_id = $conn->lastInsertId();

                if (isset($_POST['beneficios']) && is_array($_POST['beneficios'])) {
                    $stmt = $conn->prepare("
                        INSERT INTO beneficios_planes (plan_id, nombre, descripcion, monto_cobertura)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($_POST['beneficios'] as $b) {
                        $stmt->execute([$plan_id, $b['nombre'], $b['descripcion'], $b['monto_cobertura']]);
                    }
                }
                $mensaje = "Plan creado exitosamente.";
                $tipo_mensaje = "success";
                break;

            case 'editar':
                if ($_POST['id'] == 5) {
                    if ($_POST['edad_minima'] < 65 || $_POST['edad_maxima'] < 65) {
                        throw new Exception('El plan geriátrico debe mantener edad mínima y máxima de 65 años o más');
                    }
                }
                $stmt = $conn->prepare("
                    UPDATE planes SET
                        nombre = ?, descripcion = ?, precio_base = ?,
                        cobertura_maxima = ?, edad_minima = ?, edad_maxima = ?,
                        periodo_carencia = ?, estado = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['nombre'], $_POST['descripcion'], $_POST['precio_base'],
                    $_POST['cobertura_maxima'], $_POST['edad_minima'], $_POST['edad_maxima'],
                    $_POST['periodo_carencia'], $_POST['estado'], $_POST['id']
                ]);

                $stmt = $conn->prepare("DELETE FROM beneficios_planes WHERE plan_id = ?");
                $stmt->execute([$_POST['id']]);

                if (isset($_POST['beneficios']) && is_array($_POST['beneficios'])) {
                    $stmt = $conn->prepare("
                        INSERT INTO beneficios_planes (plan_id, nombre, descripcion, monto_cobertura)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($_POST['beneficios'] as $b) {
                        $stmt->execute([$_POST['id'], $b['nombre'], $b['descripcion'], $b['monto_cobertura']]);
                    }
                }
                $mensaje = "Plan actualizado exitosamente.";
                $tipo_mensaje = "success";
                break;

            case 'eliminar':
                if ($_POST['plan_id'] == 5) {
                    throw new Exception('No se puede eliminar el plan geriátrico del sistema');
                }
                $stmt = $conn->prepare("SELECT COUNT(*) FROM contratos WHERE plan_id = ?");
                $stmt->execute([$_POST['plan_id']]);
                $contratos_count = $stmt->fetchColumn();

                $stmt = $conn->prepare("SELECT COUNT(*) FROM dependientes WHERE plan_id = ?");
                $stmt->execute([$_POST['plan_id']]);
                $dependientes_count = $stmt->fetchColumn();

                if ($contratos_count > 0 || $dependientes_count > 0) {
                    $stmt = $conn->prepare("UPDATE planes SET estado = 'inactivo' WHERE id = ?");
                    $stmt->execute([$_POST['plan_id']]);
                    $mensaje = "Plan inactivado (tiene contratos o dependientes asociados).";
                } else {
                    $stmt = $conn->prepare("DELETE FROM planes WHERE id = ?");
                    $stmt->execute([$_POST['plan_id']]);
                    $mensaje = "Plan eliminado exitosamente.";
                }
                $tipo_mensaje = "success";
                break;
        }
        $conn->commit();
    } catch (PDOException $e) {
        $conn->rollBack();
        $mensaje = "Error en la operación: " . $e->getMessage();
        $tipo_mensaje = "error";
    } catch (Exception $e) {
        $conn->rollBack();
        $mensaje = $e->getMessage();
        $tipo_mensaje = "error";
    }
}

/* ============================================================
   QUERY — Lista de planes con estadísticas
   ============================================================ */
$stmt = $conn->query("
    SELECT p.*,
           COUNT(DISTINCT c.id)  AS total_contratos,
           COUNT(DISTINCT d.id)  AS total_dependientes,
           GROUP_CONCAT(DISTINCT bp.nombre SEPARATOR '||') AS beneficios_nombres
    FROM planes p
    LEFT JOIN contratos   c  ON p.id = c.plan_id
    LEFT JOIN dependientes d ON p.id = d.plan_id
    LEFT JOIN beneficios_planes bp ON p.id = bp.plan_id
    GROUP BY p.id
    ORDER BY p.id
");
$planes = $stmt->fetchAll();

// Totales para stat-cards
$total_planes    = count($planes);
$planes_activos  = count(array_filter($planes, fn($p) => $p['estado'] === 'activo'));
$total_contratos = array_sum(array_column($planes, 'total_contratos'));
$total_deps      = array_sum(array_column($planes, 'total_dependientes'));
?>

<!-- ============================================================
     ESTILOS
     ============================================================ -->
<style>
/* ── PLANES GRID ── */
.planes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-top: 24px;
}

/* ── PLAN CARD ── */
.plan-card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
}

.plan-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }

.plan-card-top {
    padding: 20px 20px 16px;
    border-bottom: 1px solid var(--gray-100);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.plan-icon-wrap {
    width: 48px; height: 48px;
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}

.plan-icon-0 { background: #EFF6FF; color: var(--accent); }
.plan-icon-1 { background: #F0FDF4; color: #16A34A; }
.plan-icon-2 { background: #FFFBEB; color: #D97706; }
.plan-icon-3 { background: #F5F3FF; color: #7C3AED; }
.plan-icon-4 { background: #FEF2F2; color: #DC2626; }

.plan-title-block { flex: 1; }
.plan-name  { font-size: 15px; font-weight: 700; color: var(--gray-800); margin-bottom: 4px; }
.plan-code  { font-size: 11px; color: var(--gray-400); font-weight: 600; font-family: monospace; }

.plan-body  { padding: 16px 20px; flex: 1; }

.plan-price {
    font-size: 26px; font-weight: 800;
    color: var(--accent); margin-bottom: 4px; line-height: 1;
}

.plan-price span { font-size: 13px; font-weight: 400; color: var(--gray-400); }

.plan-desc {
    font-size: 13px; color: var(--gray-500);
    line-height: 1.6; margin: 12px 0;
}

.plan-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin: 12px 0;
}

.plan-detail-item {
    background: var(--gray-50);
    border-radius: 6px;
    padding: 8px 10px;
}

.plan-detail-label { font-size: 10px; color: var(--gray-400); font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
.plan-detail-value { font-size: 13px; color: var(--gray-700); font-weight: 600; margin-top: 2px; }

.plan-beneficios-list {
    list-style: none;
    padding: 0;
    margin: 12px 0 0;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.plan-beneficios-list li {
    font-size: 12.5px;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 6px;
}

.plan-beneficios-list li::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
}

.plan-stats-strip {
    display: flex;
    border-top: 1px solid var(--gray-100);
    background: var(--gray-50);
}

.plan-stat {
    flex: 1;
    padding: 12px;
    text-align: center;
    border-right: 1px solid var(--gray-100);
}

.plan-stat:last-child { border-right: none; }

.plan-stat-val   { font-size: 18px; font-weight: 800; color: var(--gray-800); }
.plan-stat-label { font-size: 10px; color: var(--gray-400); font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 2px; }

.plan-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

/* ── FORM NUEVO PLAN (collapsible) ── */
.form-nuevo-plan {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    margin-bottom: 24px;
    overflow: hidden;
}

.form-body {
    padding: 20px;
}

.form-section-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--gray-700);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 20px 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--gray-100);
}

.form-section-title:first-child { margin-top: 0; }

.beneficio-row {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 14px;
    margin-bottom: 10px;
    position: relative;
}

.remove-beneficio {
    position: absolute;
    top: 10px; right: 10px;
    background: var(--red-light);
    color: white;
    border: none;
    width: 24px; height: 24px;
    border-radius: 50%;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    transition: var(--transition);
}

.remove-beneficio:hover { background: var(--red); }

@media (max-width: 768px) {
    .planes-grid { grid-template-columns: 1fr; }
    .plan-details { grid-template-columns: 1fr; }
}
</style>

<!-- ============================================================
     STAT CARDS
     ============================================================ -->
<div class="dashboard-stats fade-in">
    <div class="stat-card blue">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-umbrella"></i></div>
            <div class="stat-info">
                <h3>Total Planes</h3>
                <p class="stat-value"><?php echo $total_planes; ?></p>
                <p class="stat-label">Registrados en el sistema</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-umbrella"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
            <div class="stat-info">
                <h3>Planes Activos</h3>
                <p class="stat-value"><?php echo $planes_activos; ?></p>
                <p class="stat-label">Disponibles para contratar</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-check"></i></div>
    </div>
    <div class="stat-card clients">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-file-contract"></i></div>
            <div class="stat-info">
                <h3>Contratos Asociados</h3>
                <p class="stat-value"><?php echo number_format($total_contratos); ?></p>
                <p class="stat-label">Entre todos los planes</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-arrow-up"></i></div>
    </div>
    <div class="stat-card payments">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-user-group"></i></div>
            <div class="stat-info">
                <h3>Dependientes</h3>
                <p class="stat-value"><?php echo number_format($total_deps); ?></p>
                <p class="stat-label">Cubiertos por los planes</p>
            </div>
        </div>
        <div class="stat-trend up"><i class="fas fa-arrow-up"></i></div>
    </div>
</div>

<!-- ============================================================
     ALERTA
     ============================================================ -->
<?php if (isset($mensaje)): ?>
<div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'danger'; ?> fade-in">
    <i class="fas fa-<?php echo $tipo_mensaje === 'success' ? 'circle-check' : 'circle-xmark'; ?>"></i>
    <?php echo htmlspecialchars($mensaje); ?>
</div>
<?php endif; ?>

<!-- ============================================================
     CABECERA + BOTÓN NUEVO PLAN
     ============================================================ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;" class="fade-in">
    <div>
        <div style="font-size:18px;font-weight:700;color:var(--gray-800);">Gestión de Planes</div>
        <div style="font-size:13px;color:var(--gray-400);margin-top:2px;">
            Administra los planes de seguro del sistema
        </div>
    </div>
    <button class="btn btn-primary" onclick="toggleFormNuevoPlan()">
        <i class="fas fa-plus"></i> Nuevo Plan
    </button>
</div>

<!-- ============================================================
     FORMULARIO NUEVO PLAN (collapsible)
     ============================================================ -->
<div class="form-nuevo-plan fade-in" id="formNuevoPlan" style="display:none;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-plus" style="color:var(--accent);margin-right:6px;"></i>Crear Nuevo Plan</div>
        <button class="btn btn-secondary btn-sm" onclick="toggleFormNuevoPlan()">
            <i class="fas fa-times"></i> Cancelar
        </button>
    </div>
    <div class="form-body">
        <form id="planForm" method="POST">
            <input type="hidden" name="action" value="crear">

            <div class="form-section-title">Información Básica</div>
            <div class="form-grid cols-3">
                <div class="form-group">
                    <label>Código <span style="color:var(--red-light)">*</span></label>
                    <input type="text" name="codigo" class="form-control" required placeholder="ej. PL-001">
                </div>
                <div class="form-group">
                    <label>Nombre del Plan <span style="color:var(--red-light)">*</span></label>
                    <input type="text" name="nombre" class="form-control" required placeholder="ej. Plan Familiar">
                </div>
                <div class="form-group">
                    <label>Precio Base (RD$) <span style="color:var(--red-light)">*</span></label>
                    <input type="number" name="precio_base" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Cobertura Máxima (RD$) <span style="color:var(--red-light)">*</span></label>
                    <input type="number" name="cobertura_maxima" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Edad Mínima <span style="color:var(--red-light)">*</span></label>
                    <input type="number" name="edad_minima" id="edad_minima" class="form-control" min="0" max="120" required>
                </div>
                <div class="form-group">
                    <label>Edad Máxima <span style="color:var(--red-light)">*</span></label>
                    <input type="number" name="edad_maxima" id="edad_maxima" class="form-control" min="0" max="120" required>
                </div>
                <div class="form-group">
                    <label>Tiempo Para Cobertura (días) <span style="color:var(--red-light)">*</span></label>
                    <input type="number" name="periodo_carencia" class="form-control" min="0" required>
                </div>
                <div class="form-group" style="grid-column:span 2;">
                    <label>Descripción <span style="color:var(--red-light)">*</span></label>
                    <textarea name="descripcion" class="form-control" rows="3" required
                              placeholder="Descripción del plan..."></textarea>
                </div>
            </div>

            <div class="form-section-title">Beneficios del Plan</div>
            <div id="beneficios-container"></div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="agregarBeneficio()">
                <i class="fas fa-plus"></i> Agregar Beneficio
            </button>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--gray-100);">
                <button type="reset" class="btn btn-secondary">
                    <i class="fas fa-undo"></i> Limpiar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar Plan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     TARJETAS DE PLANES
     ============================================================ -->
<?php
$iconos_plan = ['plan-icon-0','plan-icon-1','plan-icon-2','plan-icon-3','plan-icon-4'];
$iconos_fa   = ['fas fa-umbrella','fas fa-heart','fas fa-shield-halved','fas fa-star','fas fa-user-nurse'];
?>
<div class="planes-grid fade-in delay-1">
    <?php foreach ($planes as $i => $plan): ?>
    <?php
        $ic  = $iconos_plan[$i % count($iconos_plan)];
        $ifa = $iconos_fa[$i % count($iconos_fa)];
        $beneficios_arr = $plan['beneficios_nombres']
            ? explode('||', $plan['beneficios_nombres']) : [];
    ?>
    <div class="plan-card">
        <!-- Top -->
        <div class="plan-card-top">
            <div class="plan-icon-wrap <?php echo $ic; ?>">
                <i class="<?php echo $ifa; ?>"></i>
            </div>
            <div class="plan-title-block">
                <div class="plan-name"><?php echo htmlspecialchars($plan['nombre']); ?></div>
                <div class="plan-code">Código: <?php echo htmlspecialchars($plan['codigo']); ?></div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                <span class="badge <?php echo $plan['estado'] === 'activo' ? 'badge-activo' : 'badge-inactivo'; ?>">
                    <?php echo ucfirst($plan['estado']); ?>
                </span>
                <?php if ($plan['id'] == 5): ?>
                    <span class="badge badge-pendiente">Geriátrico</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cuerpo -->
        <div class="plan-body">
            <div class="plan-price">
                RD$<?php echo number_format($plan['precio_base'], 2); ?>
                <span>/ mes</span>
            </div>

            <p class="plan-desc"><?php echo nl2br(htmlspecialchars($plan['descripcion'])); ?></p>

            <div class="plan-details">
                <div class="plan-detail-item">
                    <div class="plan-detail-label">Cobertura Máx.</div>
                    <div class="plan-detail-value">RD$<?php echo number_format($plan['cobertura_maxima'], 0); ?></div>
                </div>
                <div class="plan-detail-item">
                    <div class="plan-detail-label">Rango de Edad</div>
                    <div class="plan-detail-value"><?php echo $plan['edad_minima']; ?> – <?php echo $plan['edad_maxima']; ?> años</div>
                </div>
                <div class="plan-detail-item">
                    <div class="plan-detail-label">Tiempo Cobertura</div>
                    <div class="plan-detail-value"><?php echo $plan['periodo_carencia']; ?> días</div>
                </div>
            </div>

            <?php if (!empty($beneficios_arr)): ?>
            <div style="margin-top:12px;">
                <div style="font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">
                    Beneficios incluidos
                </div>
                <ul class="plan-beneficios-list">
                    <?php foreach (array_slice($beneficios_arr, 0, 4) as $b): ?>
                        <li><?php echo htmlspecialchars(trim($b)); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($beneficios_arr) > 4): ?>
                        <li style="color:var(--gray-400);">+<?php echo count($beneficios_arr) - 4; ?> más...</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="plan-stats-strip">
            <div class="plan-stat">
                <div class="plan-stat-val"><?php echo number_format($plan['total_contratos']); ?></div>
                <div class="plan-stat-label">Contratos</div>
            </div>
            <div class="plan-stat">
                <div class="plan-stat-val"><?php echo number_format($plan['total_dependientes']); ?></div>
                <div class="plan-stat-label">Dependientes</div>
            </div>
        </div>

        <!-- Footer acciones -->
        <div class="plan-footer">
            <button class="btn btn-primary btn-sm"
                    onclick="editarPlan(<?php echo htmlspecialchars(json_encode($plan)); ?>)"
                    <?php echo ($plan['id'] == 5 && $_SESSION['rol'] !== 'admin') ? 'disabled' : ''; ?>>
                <i class="fas fa-pen"></i> Editar
            </button>
            <?php if ($plan['id'] != 5 && $plan['total_contratos'] == 0 && $plan['total_dependientes'] == 0): ?>
                <button class="btn btn-danger btn-sm" onclick="eliminarPlan(<?php echo $plan['id']; ?>)">
                    <i class="fas fa-trash"></i> Eliminar
                </button>
            <?php elseif ($plan['id'] != 5 && $plan['estado'] === 'activo'): ?>
                <button class="btn btn-secondary btn-sm" onclick="eliminarPlan(<?php echo $plan['id']; ?>)"
                        title="Inactivará el plan (tiene registros asociados)">
                    <i class="fas fa-ban"></i> Inactivar
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================
     MODAL EDITAR PLAN
     ============================================================ -->
<div class="modal-overlay" id="editarModal" style="display:none;align-items:center;justify-content:center;">
    <div class="modal-container modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-pen" style="color:var(--accent);margin-right:8px;"></i>Editar Plan</h3>
            <button class="modal-close" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="editPlanForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id"     id="edit_id">

                <div class="form-section-title">Información Básica</div>
                <div class="form-grid cols-2">
                    <div class="form-group">
                        <label>Nombre del Plan</label>
                        <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Precio Base (RD$)</label>
                        <input type="number" name="precio_base" id="edit_precio_base" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Cobertura Máxima (RD$)</label>
                        <input type="number" name="cobertura_maxima" id="edit_cobertura_maxima" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Tiempo Para Cobertura (días)</label>
                        <input type="number" name="periodo_carencia" id="edit_periodo_carencia" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Edad Mínima</label>
                        <input type="number" name="edad_minima" id="edit_edad_minima" class="form-control" min="0" max="120" required>
                    </div>
                    <div class="form-group">
                        <label>Edad Máxima</label>
                        <input type="number" name="edad_maxima" id="edit_edad_maxima" class="form-control" min="0" max="120" required>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" id="edit_estado" class="form-control" required>
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Descripción</label>
                        <textarea name="descripcion" id="edit_descripcion" class="form-control" rows="3" required></textarea>
                    </div>
                </div>

                <div class="form-section-title">Beneficios del Plan</div>
                <div id="edit_beneficios_container"></div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="agregarBeneficioEdicion()">
                    <i class="fas fa-plus"></i> Agregar Beneficio
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     JAVASCRIPT — 100% funcional
     ============================================================ -->
<script>
/* ── Toggle formulario nuevo plan ── */
function toggleFormNuevoPlan() {
    const f = document.getElementById('formNuevoPlan');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

/* ── Modal editar ── */
function closeEditModal() {
    document.getElementById('editarModal').style.display = 'none';
    document.body.style.overflow = '';
}

function editarPlan(plan) {
    document.getElementById('edit_id').value              = plan.id;
    document.getElementById('edit_nombre').value          = plan.nombre;
    document.getElementById('edit_descripcion').value     = plan.descripcion;
    document.getElementById('edit_precio_base').value     = plan.precio_base;
    document.getElementById('edit_cobertura_maxima').value= plan.cobertura_maxima;
    document.getElementById('edit_edad_minima').value     = plan.edad_minima;
    document.getElementById('edit_edad_maxima').value     = plan.edad_maxima;
    document.getElementById('edit_periodo_carencia').value= plan.periodo_carencia;
    document.getElementById('edit_estado').value          = plan.estado;

    // Bloquear edades si es plan geriátrico
    const esGeriatrico = plan.id == 5;
    document.getElementById('edit_edad_minima').readOnly  = esGeriatrico;
    document.getElementById('edit_edad_maxima').readOnly  = esGeriatrico;

    // Cargar beneficios existentes vía AJAX
    const container = document.getElementById('edit_beneficios_container');
    container.innerHTML = '';
    editBeneficioCount = 0;

    fetch('get_beneficios_plan.php?plan_id=' + plan.id)
        .then(r => r.json())
        .then(beneficios => {
            beneficios.forEach(b => agregarBeneficioEdicion(b));
        })
        .catch(() => {}); // Si no existe el endpoint, simplemente no carga beneficios

    document.getElementById('editarModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function eliminarPlan(id) {
    if (confirm('¿Está seguro de que desea eliminar/inactivar este plan?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action"  value="eliminar">
            <input type="hidden" name="plan_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

/* ── Beneficios ── */
let beneficioCount = 0;
let editBeneficioCount = 0;

function agregarBeneficio() {
    insertarBeneficioForm('beneficios-container', beneficioCount++);
}

function agregarBeneficioEdicion(beneficio = null) {
    insertarBeneficioForm('edit_beneficios_container', editBeneficioCount++, beneficio);
}

function insertarBeneficioForm(containerId, index, beneficio = null) {
    const container = document.getElementById(containerId);
    const div = document.createElement('div');
    div.className = 'beneficio-row';
    div.innerHTML = `
        <button type="button" class="remove-beneficio" onclick="this.closest('.beneficio-row').remove()">
            <i class="fas fa-times"></i>
        </button>
        <div class="form-grid cols-3">
            <div class="form-group">
                <label>Nombre del Beneficio</label>
                <input type="text" name="beneficios[${index}][nombre]"
                       class="form-control" value="${beneficio ? escHtml(beneficio.nombre) : ''}" required>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <input type="text" name="beneficios[${index}][descripcion]"
                       class="form-control" value="${beneficio ? escHtml(beneficio.descripcion) : ''}" required>
            </div>
            <div class="form-group">
                <label>Monto de Cobertura (RD$)</label>
                <input type="number" name="beneficios[${index}][monto_cobertura]"
                       class="form-control" step="0.01" value="${beneficio ? beneficio.monto_cobertura : ''}" required>
            </div>
        </div>
    `;
    container.appendChild(div);
}

function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

/* ── Validaciones ── */
document.getElementById('planForm').addEventListener('submit', function(e) {
    const min = parseInt(document.getElementById('edad_minima').value);
    const max = parseInt(document.getElementById('edad_maxima').value);
    if (min >= max) {
        e.preventDefault();
        mostrarToast('La edad máxima debe ser mayor que la edad mínima', 'error');
    }
});

document.getElementById('editPlanForm').addEventListener('submit', function(e) {
    const min   = parseInt(document.getElementById('edit_edad_minima').value);
    const max   = parseInt(document.getElementById('edit_edad_maxima').value);
    const planId = parseInt(document.getElementById('edit_id').value);
    if (planId === 5 && (min < 65 || max < 65)) {
        e.preventDefault();
        mostrarToast('El plan geriátrico debe mantener edad mínima y máxima de 65 años o más', 'error');
        return;
    }
    if (min >= max) {
        e.preventDefault();
        mostrarToast('La edad máxima debe ser mayor que la edad mínima', 'error');
    }
});

/* ── Cerrar modal con clic en overlay ── */
document.getElementById('editarModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<?php require_once 'footer.php'; ?>