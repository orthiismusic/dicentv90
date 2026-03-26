<?php
require_once 'header.php';

// Verificar permisos de administrador
if ($_SESSION['rol'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'crear':
                    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        INSERT INTO usuarios (
                            usuario, password, nombre, email, rol, estado
                        ) VALUES (?, ?, ?, ?, ?, 'activo')
                    ");
                    $stmt->execute([
                        $_POST['usuario'],
                        $password_hash,
                        $_POST['nombre'],
                        $_POST['email'],
                        $_POST['rol']
                    ]);
                    $mensaje = "Usuario creado exitosamente.";
                    $tipo_mensaje = "success";
                    break;

                case 'editar':
                    $sql = "
                        UPDATE usuarios SET 
                            nombre = ?,
                            email = ?,
                            rol = ?,
                            estado = ?
                    ";
                    $params = [
                        $_POST['nombre'],
                        $_POST['email'],
                        $_POST['rol'],
                        $_POST['estado']
                    ];

                    if (!empty($_POST['password'])) {
                        $sql .= ", password = ?";
                        $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    }

                    $sql .= " WHERE id = ?";
                    $params[] = $_POST['usuario_id'];

                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                    $mensaje = "Usuario actualizado exitosamente.";
                    $tipo_mensaje = "success";
                    break;

                case 'eliminar':
                    $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
                    $stmt->execute([$_POST['usuario_id']]);
                    $mensaje = "Usuario eliminado exitosamente.";
                    $tipo_mensaje = "success";
                    break;
            }
        } catch(PDOException $e) {
            $mensaje = "Error en la operación: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }
}

// Obtener lista de usuarios
$stmt = $conn->query("SELECT * FROM usuarios ORDER BY nombre");
$usuarios = $stmt->fetchAll();
?>

<div class="usuarios-container">
    <!-- Resumen de Estadísticas -->
    <div class="dashboard-stats">
        <div class="stat-card clients">
            <div class="stat-content">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Usuarios</h3>
                    <p class="stat-value"><?php echo count($usuarios); ?></p>
                    <p class="stat-label">Registrados en el sistema</p>
                </div>
            </div>
            <div class="stat-trend up">
                <i class="fas fa-arrow-up"></i>
                <span>5%</span>
            </div>
        </div>
    
        <div class="stat-card income">
            <div class="stat-content">
                <div class="stat-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="stat-info">
                    <h3>Administradores</h3>
                    <p class="stat-value"><?php echo count(array_filter($usuarios, function($u) { return $u['rol'] === 'admin'; })); ?></p>
                    <p class="stat-label">Con acceso completo</p>
                </div>
            </div>
            <div class="stat-trend up">
                <i class="fas fa-arrow-up"></i>
                <span>3%</span>
            </div>
        </div>
    
        <div class="stat-card payments">
            <div class="stat-content">
                <div class="stat-icon">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div class="stat-info">
                    <h3>Vendedores</h3>
                    <p class="stat-value"><?php echo count(array_filter($usuarios, function($u) { return $u['rol'] === 'vendedor'; })); ?></p>
                    <p class="stat-label">Registrados</p>
                </div>
            </div>
            <div class="stat-trend up">
                <i class="fas fa-arrow-up"></i>
                <span>2%</span>
            </div>
        </div>
        
        <!-- Nueva tarjeta para cobradores -->
        <div class="stat-card contracts">
            <div class="stat-content">
                <div class="stat-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-info">
                    <h3>Cobradores</h3>
                    <p class="stat-value"><?php echo count(array_filter($usuarios, function($u) { return $u['rol'] === 'cobrador'; })); ?></p>
                    <p class="stat-label">Registrados</p>
                </div>
            </div>
            <div class="stat-trend up">
                <i class="fas fa-arrow-up"></i>
                <span>1%</span>
            </div>
        </div>
    
        <div class="stat-card contracts" style="border-top-color: #34a853;">
            <div class="stat-content">
                <div class="stat-icon" style="background-color: rgba(52, 168, 83, 0.1); color: #34a853;">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-info">
                    <h3>Usuarios Activos</h3>
                    <p class="stat-value"><?php echo count(array_filter($usuarios, function($u) { return $u['estado'] === 'activo'; })); ?></p>
                    <p class="stat-label">En el sistema</p>
                </div>
            </div>
            <div class="stat-trend down">
                <i class="fas fa-arrow-down"></i>
                <span>1%</span>
            </div>
        </div>
    </div>

    <!-- Mensaje de resultado -->
    <?php if (isset($mensaje)): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2>Gestión de Usuarios</h2>
            <div class="header-buttons">
                <div class="button-group left">
                    <button type="button" class="btn btn-primary" onclick="toggleForm()">
                        <i class="fas fa-plus"></i> Nuevo Usuario
                    </button>
                    <button class="btn btn-secondary" onclick="exportarUsuarios()">
                        <i class="fas fa-download"></i> Exportar
                    </button>
                </div>
            </div>
        </div>

        

        <!-- Filtros -->
        <div class="search-filter-container">
            <div class="filters-row">
                <!-- Buscador -->
                <div class="filter-item search-box">
                    <input type="text" id="searchInput" placeholder="Buscar usuario..." style="padding-left: 35px;">
                    <button class="btn-search" id="btnBuscar"><i class="fas fa-search"></i></button>
                </div>
                
                <!-- Estado -->
                <div class="filter-item state-select">
                    <select id="estadoFilter" name="estado">
                        <option value="">Todos los estados</option>
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>
                </div>
                
                <!-- Rol -->
                <div class="filter-item state-select">
                    <select id="rolFilter" name="rol">
                        <option value="">Todos los roles</option>
                        <option value="admin">Administrador</option>
                        <option value="vendedor">Vendedor</option>
                        <option value="cobrador">Cobrador</option>
                        <option value="supervisor">Supervisor</option>
                    </select>
                </div>
                
                <!-- Botón Limpiar -->
                <div class="filter-item">
                    <button class="btn btn-outline btn-sm" onclick="limpiarFiltros()">
                        <i class="fas fa-sync-alt"></i> Limpiar filtros
                    </button>
                </div>
            </div>
        </div>

        <!-- Tabla de Usuarios -->
        <div class="data-table-container">
            <table class="data-table clients-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Último Acceso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                        <tr class="usuario-row" 
                            data-usuario="<?php echo strtolower(htmlspecialchars($usuario['usuario'])); ?>"
                            data-rol="<?php echo strtolower(htmlspecialchars($usuario['rol'])); ?>"
                            data-estado="<?php echo strtolower(htmlspecialchars($usuario['estado'])); ?>">
                            <td><input type="checkbox" class="usuario-checkbox" data-id="<?php echo $usuario['id']; ?>"></td>
                            <td><?php echo htmlspecialchars($usuario['usuario']); ?></td>
                            <td><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                            <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                            <td>
                                <span class="status <?php 
                                    echo $usuario['rol'] == 'admin' ? 'active' : 
                                        ($usuario['rol'] == 'vendedor' ? 'warning' : 
                                        ($usuario['rol'] == 'cobrador' ? 'pending' : 'inactive')); 
                                ?>">
                                    <?php echo ucfirst($usuario['rol']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status <?php echo $usuario['estado'] == 'activo' ? 'active' : 'inactive'; ?>">
                                    <?php echo ucfirst($usuario['estado']); ?>
                                </span>
                            </td>
                            <td><?php echo $usuario['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($usuario['ultimo_acceso'])) : 'Nunca'; ?></td>
                            <td class="actions-cell">
                                <button class="btn-action edit" title="Editar usuario" onclick="editarUsuario(<?php echo htmlspecialchars(json_encode($usuario)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($usuario['id'] != $_SESSION['usuario_id']): ?>
                                    <button class="btn-action delete" title="Eliminar usuario" onclick="eliminarUsuario(<?php echo $usuario['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="pagination-container">
            <div class="items-per-page">
                <span>Mostrar:</span>
                <select id="itemsPerPage" onchange="cambiarRegistrosPorPagina(this.value)">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span>registros</span>
            </div>
            <div class="pagination">
                <button class="btn-page" title="Primera página"><i class="fas fa-angle-double-left"></i></button>
                <button class="btn-page" title="Página anterior"><i class="fas fa-angle-left"></i></button>
                <span class="page-info">Página 1 de 1</span>
                <button class="btn-page" title="Página siguiente"><i class="fas fa-angle-right"></i></button>
                <button class="btn-page" title="Última página"><i class="fas fa-angle-double-right"></i></button>
            </div>
        </div>
    </div>
    
    <!-- Modal para Nuevo Usuario -->
<div class="modal" id="nuevoUsuarioModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Crear Nuevo Usuario</h5>
                <button type="button" class="close" onclick="cerrarModalNuevo()">
                    <span>&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="crear">
                    
                    <div class="form-group">
                        <label for="usuario">Nombre de Usuario</label>
                        <input type="text" id="usuario" name="usuario" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre">Nombre Completo</label>
                        <input type="text" id="nombre" name="nombre" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="rol">Rol</label>
                        <select id="rol" name="rol" class="form-control" required>
                            <option value="admin">Administrador</option>
                            <option value="vendedor">Vendedor</option>
                            <option value="cobrador">Cobrador</option>
                            <option value="supervisor">Supervisor</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalNuevo()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>
    
    
</div>

<!-- Modal de edición -->
<div class="modal" id="editarModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Usuario</h5>
                <button type="button" class="close" onclick="closeModal()">
                    <span>&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="editar">
                    <input type="hidden" name="usuario_id" id="edit_usuario_id">
                    
                    <div class="form-group">
                        <label for="edit_usuario">Nombre de Usuario</label>
                        <input type="text" id="edit_usuario" name="nombre" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_nombre">Nombre Completo</label>
                        <input type="text" id="edit_nombre" name="nombre" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_password">Nueva Contraseña (dejar en blanco para mantener)</label>
                        <input type="password" id="edit_password" name="password" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="edit_rol">Rol</label>
                        <select id="edit_rol" name="rol" class="form-control" required>
                            <option value="admin">Administrador</option>
                            <option value="vendedor">Vendedor</option>
                            <option value="cobrador">Cobrador</option>
                            <option value="supervisor">Supervisor</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit_estado">Estado</label>
                        <select id="edit_estado" name="estado" class="form-control" required>
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>

/* Estilo para checkbox indeterminado */
input[type="checkbox"]:indeterminate {
    background-color: #1a73e8;
    border-color: #1a73e8;
}

input[type="checkbox"]:indeterminate::after {
    content: '';
    position: absolute;
    top: 8px;
    left: 4px;
    width: 10px;
    height: 2px;
    background-color: white;
}

/* Estilos para checkboxes */
input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    border: 1px solid #8e9299;
    border-radius: 3px;
    background-color: white;
    appearance: none;
    -webkit-appearance: none;
    position: relative;
    vertical-align: middle;
    transition: all 0.2s;
}

input[type="checkbox"]:checked {
    background-color: #1a73e8;
    border-color: #1a73e8;
}

input[type="checkbox"]:checked::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 6px;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

input[type="checkbox"]:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
}

#selectAll {
    margin: 0;
}

.usuario-checkbox {
    margin: 0;
}


/* Ajustes al campo de búsqueda */
.filter-item.search-box input {
    padding-left: 35px; /* Dar espacio al icono de búsqueda */
}

.filter-item.search-box .btn-search {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: #5f6368;
}

/* Estilos para selectores de registros por página */
#itemsPerPage {
    padding: 4px 8px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    background-color: white;
    color: #202124;
    font-size: 14px;
    cursor: pointer;
}

#itemsPerPage:focus {
    outline: none;
    border-color: #1a73e8;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
}

/* Ajustes para modales */
.modal-dialog {
    background: white;
    border-radius: 8px;
    width: 100%;
    max-width: 500px;
    margin: 20px auto;
    z-index: 1001;
}

.modal-content {
    display: flex;
    flex-direction: column;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.modal-header {
    border-bottom: 1px solid #dee2e6;
    padding: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: white;
}

.modal-body {
    padding: 1rem;
    overflow-y: auto;
    max-height: 60vh;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    padding: 1rem;
    border-top: 1px solid #dee2e6;
    gap: 10px;
}

.close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0;
    margin: 0;
    line-height: 1;
}


.usuarios-container {
    margin-bottom: 2rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    padding: 1.5rem;
    background-color: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.form-actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

/* Estilos para tarjetas de dashboard */
.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.actions-cell {
    display: flex;
    gap: 0.25rem;
    justify-content: flex-end;
}

.btn-action {
    width: 38px;
    height: 38px;
    border-radius: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    color: white;
    cursor: pointer;
    transition: all 0.2s;
    margin: 0 2px;
    position: relative;
}

.btn-action i {
    font-size: 16px;
}

.btn-action:hover {
    transform: translateY(-3px);
    box-shadow: 0 3px 5px rgba(0, 0, 0, 0.2);
    opacity: 0.9;
}

.btn-action.view {
    background-color: #2563eb !important;
}

.btn-action.edit {
    background-color: #12b839;
}

.btn-action.delete {
    background-color: #ef4444;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal.show {
    display: flex !important;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    pointer-events: auto !important;
}

/* Estilo para los status badges */
.status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
}

.status.active {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
    border: 1px solid rgba(52, 168, 83, 0.2);
}

.status.inactive {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
    border: 1px solid rgba(234, 67, 53, 0.2);
}

.status.warning {
    background-color: rgba(251, 188, 5, 0.1);
    color: #fbbc05;
    border: 1px solid rgba(251, 188, 5, 0.2);
}

.status.pending {
    background-color: rgba(26, 115, 232, 0.1);
    color: #1a73e8;
    border: 1px solid rgba(26, 115, 232, 0.2);
}

/* Estilos para filtros */
.search-filter-container {
    background-color: #ffffff;
    border-radius: 8px;
    padding: 1rem;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}

.filters-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: nowrap;
    width: 100%;
}

.filter-item {
    position: relative;
    flex: 1;
    min-width: 250px;
    margin-bottom: 0;
}

.filter-item.search-box {
    position: relative;
    flex: 1;
    min-width: 250px;
}

.filter-item.state-select {
    width: 160px;
    min-width: 160px;
}

.filter-item input, 
.filter-item select {
    padding: 0.5rem;
    border: 1px solid #dadce0;
    border-radius: 8px;
    font-size: 14px;
    width: 100%;
    height: 38px;
}

.btn-search {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: #5f6368;
}

.filter-item .btn {
    white-space: nowrap;
    height: 38px;
}

/* Paginación */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background-color: #f8f9fa;
    border-radius: 0 0 8px 8px;
    flex-wrap: wrap;
    gap: 1rem;
}

.items-per-page {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #5f6368;
    font-size: 14px;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-page {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #dadce0;
    border-radius: 50%;
    background-color: white;
    color: #5f6368;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-page:hover:not([disabled]) {
    background-color: #e8f0fe;
    color: #1a73e8;
    border-color: #1a73e8;
}

.btn-page[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}

.page-info {
    margin: 0 0.5rem;
    color: #5f6368;
    font-size: 14px;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .filters-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-item {
        width: 100% !important;
        margin-bottom: 10px;
    }
}
</style>

<script>
// Función para abrir el modal de nuevo usuario
function toggleForm() {
    document.getElementById('nuevoUsuarioModal').classList.add('show');
}

// Función para cerrar el modal de nuevo usuario
function cerrarModalNuevo() {
    document.getElementById('nuevoUsuarioModal').classList.remove('show');
}

// Función para editar usuario
function editarUsuario(usuario) {
    document.getElementById('edit_usuario_id').value = usuario.id;
    document.getElementById('edit_usuario').value = usuario.usuario;
    document.getElementById('edit_nombre').value = usuario.nombre;
    document.getElementById('edit_email').value = usuario.email;
    document.getElementById('edit_rol').value = usuario.rol;
    document.getElementById('edit_estado').value = usuario.estado;
    document.getElementById('edit_password').value = '';
    
    document.getElementById('editarModal').classList.add('show');
}

// Función para cerrar el modal de edición
function closeModal() {
    document.getElementById('editarModal').classList.remove('show');
}

// Función para eliminar usuario
function eliminarUsuario(id) {
    if (confirm('¿Está seguro de que desea eliminar este usuario?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="eliminar">
            <input type="hidden" name="usuario_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Función para exportar usuarios
function exportarUsuarios() {
    // Esta función se implementaría para exportar usuarios
    alert('Función de exportación no implementada');
}

// Función para limpiar filtros
function limpiarFiltros() {
    document.getElementById('searchInput').value = '';
    document.getElementById('estadoFilter').value = '';
    document.getElementById('rolFilter').value = '';
    filtrarUsuarios();
}

// Función para filtrar usuarios
function filtrarUsuarios() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const estadoFilter = document.getElementById('estadoFilter').value.toLowerCase();
    const rolFilter = document.getElementById('rolFilter').value.toLowerCase();
    
    const rows = document.querySelectorAll('.usuario-row');
    
    rows.forEach(row => {
        const usuario = row.dataset.usuario;
        const estado = row.dataset.estado;
        const rol = row.dataset.rol;
        
        const matchSearch = usuario.includes(searchTerm) || row.textContent.toLowerCase().includes(searchTerm);
        const matchEstado = estadoFilter === '' || estado === estadoFilter;
        const matchRol = rolFilter === '' || rol === rolFilter;
        
        if (matchSearch && matchEstado && matchRol) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    actualizarPaginacion();
}

// Variables para la paginación
let paginaActual = 1;
let registrosPorPagina = 10;
let totalPaginas = 1;

// Función para cambiar registros por página
function cambiarRegistrosPorPagina(valor) {
    registrosPorPagina = parseInt(valor);
    paginaActual = 1; // Reiniciar a la primera página
    aplicarPaginacion();
    actualizarPaginacion();
}

// Función para aplicar la paginación
function aplicarPaginacion() {
    const filas = document.querySelectorAll('.usuario-row:not([style*="display: none"])');
    const inicio = (paginaActual - 1) * registrosPorPagina;
    const fin = inicio + registrosPorPagina;
    
    filas.forEach((fila, index) => {
        if (index >= inicio && index < fin) {
            fila.classList.add('pagina-actual');
            fila.style.display = '';
        } else {
            fila.classList.remove('pagina-actual');
            fila.style.display = 'none';
        }
    });
}

// Función para actualizar la paginación
function actualizarPaginacion() {
    const filas = document.querySelectorAll('.usuario-row:not([style*="display: none"])');
    totalPaginas = Math.ceil(filas.length / registrosPorPagina);
    
    if (paginaActual > totalPaginas && totalPaginas > 0) {
        paginaActual = totalPaginas;
    }
    
    // Actualizar texto de la paginación
    document.querySelector('.page-info').textContent = `Página ${paginaActual} de ${totalPaginas || 1}`;
    
    // Habilitar/deshabilitar botones de navegación
    const botonesAnterior = document.querySelectorAll('.pagination button:nth-child(1), .pagination button:nth-child(2)');
    const botonesSiguiente = document.querySelectorAll('.pagination button:nth-child(4), .pagination button:nth-child(5)');
    
    botonesAnterior.forEach(btn => {
        btn.disabled = paginaActual <= 1;
    });
    
    botonesSiguiente.forEach(btn => {
        btn.disabled = paginaActual >= totalPaginas || totalPaginas === 0;
    });
    
    aplicarPaginacion();
}

// Función para ir a la página anterior
function paginaAnterior() {
    if (paginaActual > 1) {
        paginaActual--;
        aplicarPaginacion();
        actualizarPaginacion();
    }
}

// Función para ir a la página siguiente
function paginaSiguiente() {
    if (paginaActual < totalPaginas) {
        paginaActual++;
        aplicarPaginacion();
        actualizarPaginacion();
    }
}

// Función para ir a la primera página
function primeraPagina() {
    paginaActual = 1;
    aplicarPaginacion();
    actualizarPaginacion();
}

// Función para ir a la última página
function ultimaPagina() {
    paginaActual = totalPaginas;
    aplicarPaginacion();
    actualizarPaginacion();
}


// Función para manejar "Seleccionar todos"
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.usuario-checkbox');
    
    checkboxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        if (row.style.display !== 'none') {
            checkbox.checked = selectAllCheckbox.checked;
        }
    });
}

// Función para actualizar el estado del checkbox "Seleccionar todos"
function updateSelectAllCheckbox() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.usuario-checkbox');
    const visibleCheckboxes = Array.from(checkboxes).filter(
        checkbox => checkbox.closest('tr').style.display !== 'none'
    );
    const checkedCheckboxes = Array.from(checkboxes).filter(
        checkbox => checkbox.checked && checkbox.closest('tr').style.display !== 'none'
    );
    
    if (checkedCheckboxes.length === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedCheckboxes.length === visibleCheckboxes.length) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Configurar event listeners para filtros
    document.getElementById('searchInput').addEventListener('input', filtrarUsuarios);
    document.getElementById('estadoFilter').addEventListener('change', filtrarUsuarios);
    document.getElementById('rolFilter').addEventListener('change', filtrarUsuarios);
    
    // Botón de búsqueda
    document.getElementById('btnBuscar').addEventListener('click', filtrarUsuarios);
    
    // Configurar event listeners para paginación
    document.querySelector('.pagination button:nth-child(1)').addEventListener('click', primeraPagina);
    document.querySelector('.pagination button:nth-child(2)').addEventListener('click', paginaAnterior);
    document.querySelector('.pagination button:nth-child(4)').addEventListener('click', paginaSiguiente);
    document.querySelector('.pagination button:nth-child(5)').addEventListener('click', ultimaPagina);
    
    // Checkbox "Seleccionar todos"
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', toggleSelectAll);
    }
    
    // Event listeners para checkboxes individuales
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('usuario-checkbox')) {
            updateSelectAllCheckbox();
        }
    });
    
    // Inicializar estado del checkbox principal
    updateSelectAllCheckbox();
    
    // Menú desplegable de usuario
    const profileUsername = document.getElementById('profileUsername');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileUsername && profileDropdown) {
        profileUsername.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('active');
            console.log("Toggle menú de perfil");
        });
        
        // Cerrar cuando se hace clic fuera
        document.addEventListener('click', function() {
            profileDropdown.classList.remove('active');
        });
        
        // Evitar que se cierre al hacer clic dentro
        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
    
    // Inicializar paginación
    aplicarPaginacion();
    actualizarPaginacion();
    
    // Inicializar efectos interactivos
    setupInteractions();
});

// Funciones para efectos interactivos
function setupInteractions() {
    // Add hover effects to stat cards
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Botones de acción
    const actionButtons = document.querySelectorAll('.btn-action');
    actionButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
}
</script>

<?php require_once 'footer.php'; ?>