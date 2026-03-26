<?php
//session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Buffer de salida para permitir redirecciones después de output
ob_start();

require_once 'header.php';

function actualizarFechaFinContrato($conn, $contrato_id) {
   // Obtener la fecha de vencimiento de la última factura pagada como total
   $stmt = $conn->prepare("
       SELECT f.fecha_vencimiento
       FROM facturas f
       WHERE f.contrato_id = ?
       AND f.estado = 'pagada'
       AND EXISTS (
           SELECT 1 
           FROM pagos p 
           WHERE p.factura_id = f.id 
           AND p.tipo_pago = 'total'
           AND p.estado = 'procesado'
       )
       ORDER BY f.fecha_vencimiento DESC
       LIMIT 1
   ");
   
   $stmt->execute([$contrato_id]);
   $resultado = $stmt->fetch();
   
   if ($resultado) {
       $stmt = $conn->prepare("
           UPDATE contratos 
           SET fecha_fin = ? 
           WHERE id = ?
       ");
       $stmt->execute([$resultado['fecha_vencimiento'], $contrato_id]);
   }
}


if (!isset($_GET['factura_id'])) {
   ob_end_clean(); // Limpiar buffer
   header('Location: facturacion.php');
   exit();
}

$factura_id = (int)$_GET['factura_id'];

// Obtener datos de la factura y validar estado
$stmt = $conn->prepare("
   SELECT f.*,
          c.numero_contrato,
          cl.nombre as cliente_nombre,
          cl.apellidos as cliente_apellidos,
          (SELECT COUNT(*) 
           FROM facturas f2 
           WHERE f2.contrato_id = c.id 
           AND f2.estado = 'incompleta'
           AND f2.id < f.id) as facturas_incompletas_previas,
          (SELECT SUM(p.monto)
           FROM pagos p
           WHERE p.factura_id = f.id
           AND p.estado = 'procesado') as total_abonado  -- Removido el filtro de tipo_pago='abono'
   FROM facturas f
   JOIN contratos c ON f.contrato_id = c.id
   JOIN clientes cl ON c.cliente_id = cl.id
   WHERE f.id = ? 
");

$stmt->execute([$factura_id]);
$factura = $stmt->fetch();

if (!$factura) {
   header('Location: facturacion.php');
   exit();
}

// Calcular monto pendiente
$montoPendiente = max(0, $factura['monto'] - ($factura['total_abonado'] ?? 0));  // Asegurar que no sea negativo

// Obtener historial de pagos
$stmt = $conn->prepare("
   SELECT p.*, co.nombre_completo as cobrador_nombre 
   FROM pagos p
   LEFT JOIN cobradores co ON p.cobrador_id = co.id
   WHERE p.factura_id = ?
   ORDER BY p.fecha_pago DESC
");
$stmt->execute([$factura_id]);
$pagos_previos = $stmt->fetchAll();

// Mostrar mensaje toast si viene de un pago exitoso
if (isset($_GET['mensaje']) && isset($_GET['tipo'])) {
   echo "<script>
       document.addEventListener('DOMContentLoaded', function() {
           mostrarToast('" . htmlspecialchars($_GET['mensaje']) . "', '" . htmlspecialchars($_GET['tipo']) . "');
       });
   </script>";
}

// Verificar si hay facturas incompletas previas
if ($factura['estado'] === 'pendiente' && $factura['facturas_incompletas_previas'] > 0) {
   header('Location: facturacion.php?error=facturas_incompletas');
   exit();
}

// Si la factura está pagada, mostrar mensaje toast
if ($factura['estado'] === 'pagada') {
   echo "<script>
       document.addEventListener('DOMContentLoaded', function() {
           mostrarToast('Esta factura ya ha sido pagada', 'error');
       });
   </script>";
}

// Procesar el formulario de pago solo si la factura permite pagos
if ($_SERVER['REQUEST_METHOD'] == 'POST' && in_array($factura['estado'], ['pendiente', 'vencida', 'incompleta'])) {
   try {
       $conn->beginTransaction();

       $monto_pago = floatval($_POST['monto']);
       $tipo_pago = $monto_pago >= $montoPendiente ? 'total' : 'abono';
       $nuevo_estado = $tipo_pago === 'total' ? 'pagada' : 'incompleta';

       // Validar monto mínimo
       if ($monto_pago <= 0) {
           throw new Exception("El monto debe ser mayor a 0");
       }

       // Validar que no exceda el monto pendiente
       if ($monto_pago > $montoPendiente) {
           throw new Exception("El monto no puede ser mayor al monto pendiente");
       }

       // Registrar el pago
       $stmt = $conn->prepare("
           INSERT INTO pagos (
               factura_id, monto, fecha_pago, 
               metodo_pago, referencia_pago, 
               cobrador_id, estado, tipo_pago, notas
           ) VALUES (?, ?, NOW(), ?, ?, 
               (SELECT id FROM cobradores WHERE estado = 'activo' LIMIT 1), 
               'procesado', ?, ?)
       ");
       $stmt->execute([
           $factura_id,
           $monto_pago,
           $_POST['metodo_pago'],
           $_POST['referencia_pago'],
           $tipo_pago,
           $_POST['notas']
       ]);
       
       // Actualizar estado y monto pendiente de la factura
       $nuevo_monto_pendiente = $montoPendiente - $monto_pago;
       
       $stmt = $conn->prepare("
           UPDATE facturas 
           SET estado = ?,
               monto_pendiente = ?
           WHERE id = ?
       ");
       $stmt->execute([
           $nuevo_estado,
           $nuevo_monto_pendiente,
           $factura_id
       ]);
       
       
       // Si es un pago total, actualizar la fecha_fin del contrato
       if ($tipo_pago === 'total') {
           $stmt = $conn->prepare("
               SELECT c.id as contrato_id
               FROM facturas f
               JOIN contratos c ON f.contrato_id = c.id
               WHERE f.id = ?
           ");
           $stmt->execute([$factura_id]);
           $contrato = $stmt->fetch();
           
           if ($contrato) {
               actualizarFechaFinContrato($conn, $contrato['contrato_id']);
           }
       }

       $conn->commit();
       
       // Redireccionar con mensaje según el tipo de pago
       $tipo_toast = $tipo_pago === 'total' ? 'success' : 'warning';
       $mensaje_toast = $tipo_pago === 'total' ? 'Pago total registrado exitosamente' : 'Abono registrado exitosamente';
       
       header("Location: registrar_pago.php?factura_id=$factura_id&mensaje=$mensaje_toast&tipo=$tipo_toast");
       exit();

   } catch(Exception $e) {
       $conn->rollBack();
       $error = $e->getMessage();
   }
}
?>

<div class="registro-pago">
   <div class="card">
       <div class="card-header">
           <div class="header-content">
               <h2>Registrar Pago</h2>
               <a href="facturacion.php" class="btn btn-primary">
                   <i class="fas fa-arrow-left"></i> Volver
               </a>
           </div>
       </div>

       <?php if (isset($error)): ?>
           <div class="alert alert-danger">
               <?php echo $error; ?>
           </div>
       <?php endif; ?>

       <div class="factura-resumen">
          <h3>Detalles de la Factura</h3>
          <div class="info-grid">
            <!-- Primera fila -->
            <div class="info-row">
                <div class="info-group">
                    <span class="label">Factura:</span>
                    <span class="value"><?php echo htmlspecialchars($factura['numero_factura']); ?></span>
                </div>
                <div class="info-group">
                    <span class="label">Contrato:</span>
                    <span class="value"><?php echo htmlspecialchars($factura['numero_contrato']); ?></span>
                </div>
                <div class="info-group">
                    <span class="label">Mes:</span>
                    <span class="value">
                        <?php
                        $mes_anio = explode('/', $factura['mes_factura']);
                        $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
                        if (count($mes_anio) == 2) {
                            $mes = intval($mes_anio[0]);
                            $anio = $mes_anio[1];
                            echo $meses[$mes - 1] . '/' . $anio;
                        } else {
                            echo htmlspecialchars($factura['mes_factura']);
                        }
                        ?>
                    </span>
                </div>
                <div class="info-group">
                    <span class="label">Cuota:</span>
                    <span class="value">RD$<?php echo number_format($factura['monto'], 2); ?></span>
                </div>
            </div>
            
            <!-- Segunda fila -->
            <div class="info-row">
                <div class="info-group">
                    <span class="label">Estado Actual:</span>
                    <span class="badge badge-<?php 
                        echo $factura['estado'] === 'pagada' ? 'success' : 
                             ($factura['estado'] === 'pendiente' ? 'warning' : 
                             ($factura['estado'] === 'incompleta' ? 'info' : 
                             ($factura['estado'] === 'vencida' ? 'danger' : 'secondary'))); 
                    ?>">
                        <?php echo ucfirst($factura['estado']); ?>
                    </span>
                </div>
                <div class="info-group">
                    <span class="label">Monto Total:</span>
                    <span class="value">RD$<?php echo number_format($factura['monto'], 2); ?></span>
                </div>
                <div class="info-group">
                    <span class="label">Monto Pendiente:</span>
                    <span class="value warning-text">RD$<?php echo number_format($montoPendiente, 2); ?></span>
                </div>
            </div>
        
            <!-- Tercera fila -->
            <div class="info-row">
                <div class="info-group full-width">
                    <span class="label">Cliente:</span>
                    <span class="value">
                        <?php echo htmlspecialchars($factura['cliente_nombre'] . ' ' . $factura['cliente_apellidos']); ?>
                    </span>
                </div>
            </div>
        </div>
          
          <?php if (in_array($factura['estado'], ['pendiente', 'vencida', 'incompleta'])): ?>
            <form method="POST" class="pago-form" id="formPago">
                <div class="form-row">
                    <!-- Monto a Pagar -->
                    <div class="form-group">
                        <label for="monto">Monto a Pagar <span class="required">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">RD$</span>
                            </div>
                            <input type="number" id="monto" name="monto" class="form-control" 
                                   step="0.01" min="1" max="<?php echo $montoPendiente; ?>" 
                                   value="<?php echo $montoPendiente; ?>" required>
                        </div>
                        <small class="form-text text-muted">
                            Monto máximo: RD$<?php echo number_format($montoPendiente, 2); ?>
                        </small>
                    </div>
        
                    <!-- Método de Pago -->
                    <div class="form-group">
                        <label for="metodo_pago">Método de Pago <span class="required">*</span></label>
                        <select id="metodo_pago" name="metodo_pago" class="form-control" required>
                            <option value="">Seleccione método de pago</option>
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                </div>
        
                <!-- Referencia (en su propia fila) -->
                <div class="form-group" id="referenciaGroup" style="display: none;">
                    <label for="referencia_pago">Referencia de Pago <span class="required">*</span></label>
                    <input type="text" id="referencia_pago" name="referencia_pago" 
                           class="form-control" placeholder="Número de referencia o cheque"
                           maxlength="20">
                    <small class="form-text text-muted">
                        Máximo 20 caracteres
                    </small>
                </div>
        
                <!-- Notas (en su propia fila) -->
                <div class="form-group">
                    <label for="notas">Notas</label>
                    <textarea id="notas" name="notas" class="form-control" 
                              placeholder="Observaciones adicionales"></textarea>
                </div>
        
                <!-- Información de pago (en su propia fila) -->
                <div class="pago-info alert alert-info" style="display: none;">
                    <p><strong>Tipo de Pago:</strong> <span id="tipoPagoText"></span></p>
                    <p><strong>Restante:</strong> RD$<span id="montoRestante">0.00</span></p>
                </div>
        
                <!-- Botones de acción -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Registrar Pago
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </form>
        <?php endif; ?>
          
          <?php if (!empty($pagos_previos)): ?>
              <div class="pagos-historial">
                  <br>
                  <h4>Historial de Pagos</h4>
                  <div class="table-responsive">
                      <table class="table">
                          <thead>
                              <tr>
                                  <th>Fecha</th>
                                  <th>Monto</th>
                                  <th>Tipo</th>
                                  <th>Método</th>
                                  <th>Referencia</th>
                                  <th>Cobrador</th>
                                  <th>Estado</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php foreach ($pagos_previos as $pago): ?>
                                  <tr>
                                      <td><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></td>
                                      <td>RD$<?php echo number_format($pago['monto'], 2); ?></td>
                                      <td>
                                          <span class="badge badge-<?php echo $pago['tipo_pago'] === 'total' ? 'success' : 'info'; ?>">
                                              <?php echo ucfirst($pago['tipo_pago']); ?>
                                          </span>
                                      </td>
                                      <td><?php echo ucfirst($pago['metodo_pago']); ?></td>
                                      <td><?php echo htmlspecialchars($pago['referencia_pago'] ?? '-'); ?></td>
                                      <td><?php echo htmlspecialchars($pago['cobrador_nombre']); ?></td>
                                      <td>
                                          <span class="badge badge-<?php echo $pago['estado'] === 'procesado' ? 'success' : 'danger'; ?>">
                                              <?php echo ucfirst($pago['estado']); ?>
                                          </span>
                                      </td>
                                  </tr>
                                  <?php if ($pago['notas']): ?>
                                      <tr class="notas-row">
                                          <td colspan="7">
                                              <small class="text-muted">
                                                  <strong>Notas:</strong> <?php echo htmlspecialchars($pago['notas']); ?>
                                              </small>
                                          </td>
                                      </tr>
                                  <?php endif; ?>
                              <?php endforeach; ?>
                          </tbody>
                      </table>
                  </div>
               </div>
          <?php endif; ?>
       </div>

       <!-- Modal de Confirmación de Pago -->
       <div class="modal" id="modalConfirmarPago">
           <div class="modal-dialog">
               <div class="modal-content">
                   <div class="modal-header">
                       <h5 class="modal-title">Confirmar Pago</h5>
                       <button type="button" class="close" onclick="cerrarModalConfirmarPago()">
                           <span>&times;</span>
                       </button>
                   </div>
                   <div class="modal-body">
                       <div class="info-pago alert alert-info">
                           <div id="detalles_pago"></div>
                       </div>
                       <div id="mensaje_confirmacion" class="alert alert-warning"></div>
                   </div>
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" onclick="cerrarModalConfirmarPago()">
                           Cancelar
                       </button>
                       <button type="button" class="btn btn-primary" onclick="confirmarPago()">
                           Confirmar
                       </button>
                   </div>
               </div>
           </div>
       </div>
       
       <style>
   .registro-pago {
       max-width: 1300px;
       margin: 0 auto;
       padding: 1rem;
   }
   
   .header-content {
       display: flex;
       justify-content: space-between;
       align-items: center;
   }
   
   .factura-resumen {
       background-color: #f8f9fa;
       padding: 1.5rem;
       border-radius: 8px;
       margin-bottom: 1.5rem;
   }
   
   .info-grid {
       display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1.0rem;
   }
   
   .info-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 0.5rem;
    }
   
   .info-group {
       display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: white;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
   }
   
   .info-group.full-width {
        grid-column: 1 / -1;
    }
   
   .label {
       font-weight: 600;
       color: #4a5568;
   }
   
   .value {
       color: #2d3748;
   }
   
   .success-text {
       color: #2f855a;
   }
   
   .warning-text {
       color: #c05621;
   }
   
   .pago-form {
       background: white;
       padding: 1.5rem;
       border-radius: 8px;
       box-shadow: 0 1px 3px rgba(0,0,0,0.1);
       display: grid;
       gap: 0.5rem;
   }
   
   
   .pago-info {
        padding: 0.5rem;
        border-radius: 8px;
        margin-top: 0.5rem;
        margin-bottom: 0.5rem;
    }
    
    .pago-info.alert-success {
        background-color: #dcfce7;
        border-color: #16a34a;
        color: #166534;
    }
    
    .pago-info.alert-warning {
        background-color: #fef3c7;
        border-color: #d97706;
        color: #92400e;
    }
    
    .modal-dialog {
        max-width: 800px;  /* Modificado para ser más ancho */
    }
   
   .form-group {
       margin-bottom: 0.1rem;
   }
   
   .required {
       color: #e53e3e;
   }
   
   /* Estilos para el modal */
   .modal {
       display: none;
       position: fixed;
       top: 0;
       left: 0;
       width: 100%;
       height: 100%;
       background-color: rgba(0, 0, 0, 0.5);
       z-index: 1050;
   }

   .modal.show {
       display: flex !important;
       align-items: center;
       justify-content: center;
   }
   
   .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end; /* Alinea los botones a la derecha */
        margin-top: 1.5rem;
    }

   .detalle-item {
       display: flex;
       margin-bottom: 0.75rem;
       line-height: 1.5;
   }

   .detalle-item:last-child {
       margin-bottom: 0;
   }

   .detalle-item strong {
       min-width: 130px;
       color: #495057;
   }

   .detalle-item span {
       color: #212529;
       flex: 1;
   }

   .texto-verde {
       color: #198754;
       font-weight: 600;
   }
   
   
   .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        margin-bottom: 0; /* Para evitar espaciado extra */
    }

   #mensaje_confirmacion {
       text-align: center;
       font-weight: 500;
       font-size: 1.1rem;
       margin-bottom: 0;
   }

   .modal-footer .btn-primary {
       background-color: #0066cc;
       border-color: #0066cc;
       padding: 0.5rem 1.5rem;
   }

   .modal-footer .btn-secondary {
       background-color: #6c757d;
       border-color: #6c757d;
       padding: 0.5rem 1.5rem;
   }

   .modal-footer .btn:hover {
       opacity: 0.9;
   }
   
   @media (max-width: 768px) {
       .info-grid {
           grid-template-columns: 1fr;
       }
   
       .form-actions {
           flex-direction: column;
       }
   
       .form-actions button {
           width: 100%;
       }
       
       .form-row {
            grid-template-columns: 1fr;
        }
        
        .info-row {
            grid-template-columns: 1fr;
        }
        
        .modal-dialog {
            max-width: 95%;
            margin: 1rem auto;
        }
   }
</style>

<script>
// Función para formatear números como moneda
const formatoMoneda = (numero) => {
   return new Intl.NumberFormat('es-DO', {
       minimumFractionDigits: 2,
       maximumFractionDigits: 2
   }).format(numero);
};

// Función para validar el formulario
function validarFormularioPago() {
   const monto = parseFloat(document.getElementById('monto').value);
   const montoPendiente = <?php echo $montoPendiente; ?>;
   const metodoPago = document.getElementById('metodo_pago').value;
   const referencia = document.getElementById('referencia_pago').value;
   const errores = [];

   if (!monto || isNaN(monto)) {
       errores.push('El monto es requerido y debe ser un número válido');
   } else {
       if (monto <= 0) {
           errores.push('El monto debe ser mayor a 0');
       }
       if (monto > montoPendiente) {
           errores.push('El monto no puede ser mayor al monto pendiente');
       }
   }

   if (!metodoPago) {
       errores.push('Debe seleccionar un método de pago');
   }

   if (metodoPago !== 'efectivo') {
       if (!referencia || !referencia.trim()) {
           errores.push('Debe ingresar una referencia para este método de pago');
       }
       if (referencia.length > 20) {
           errores.push('La referencia no puede exceder los 20 caracteres');
       }
   }

   return errores;
}

// Event listener único para el formulario
document.getElementById('formPago').addEventListener('submit', function(e) {
   e.preventDefault();
   
   const errores = validarFormularioPago();
   if (errores.length > 0) {
       errores.forEach(error => mostrarToast(error, 'error'));
       return;
   }

   mostrarModalConfirmarPago();
});

function mostrarModalConfirmarPago() {
   const monto = parseFloat(document.getElementById('monto').value);
   const montoPendiente = <?php echo $montoPendiente; ?>;
   const metodoPago = document.getElementById('metodo_pago').value;
   const referencia = document.getElementById('referencia_pago').value || '-';
   const notas = document.getElementById('notas').value || '-';
   const esPagoTotal = monto >= montoPendiente;

   const metodoPagoFormateado = metodoPago.charAt(0).toUpperCase() + metodoPago.slice(1);

   document.getElementById('detalles_pago').innerHTML = `
       <div class="detalle-item">
           <strong>Contrato:</strong> 
           <span><?php echo str_pad($factura['numero_contrato'], 5, '0', STR_PAD_LEFT); ?></span>
       </div>
       <div class="detalle-item">
           <strong>Factura:</strong> 
           <span><?php echo htmlspecialchars($factura['numero_factura']); ?></span>
       </div>
       <div class="detalle-item">
           <strong>Cliente:</strong> 
           <span><?php echo htmlspecialchars($factura['cliente_nombre'] . ' ' . $factura['cliente_apellidos']); ?></span>
       </div>
       <div class="detalle-item">
           <strong>Monto a Pagar:</strong> 
           <span class="texto-verde">RD$${monto.toFixed(2)}</span>
       </div>
       <div class="detalle-item">
           <strong>Método de Pago:</strong> 
           <span>${metodoPagoFormateado}</span>
       </div>
       ${metodoPago !== 'efectivo' ? `
       <div class="detalle-item">
           <strong>Referencia:</strong> 
           <span>${referencia}</span>
       </div>` : ''}
       ${notas.trim() !== '' ? `
       <div class="detalle-item">
           <strong>Notas:</strong> 
           <span>${notas}</span>
       </div>` : ''}
   `;

   document.getElementById('mensaje_confirmacion').innerHTML = esPagoTotal ? 
       '¿Está seguro de registrar el pago total?' : 
       '¿Está seguro de registrar este abono?';

   document.getElementById('modalConfirmarPago').classList.add('show');
   document.body.style.overflow = 'hidden';
}

function cerrarModalConfirmarPago() {
   document.getElementById('modalConfirmarPago').classList.remove('show');
   document.body.style.overflow = '';
}

function confirmarPago() {
   document.getElementById('formPago').submit();
}

// Manejar visibilidad del campo de referencia
document.getElementById('metodo_pago').addEventListener('change', function() {
   const referenciaGroup = document.getElementById('referenciaGroup');
   const referenciaInput = document.getElementById('referencia_pago');
   
   if (this.value === 'efectivo') {
       referenciaGroup.style.display = 'none';
       referenciaInput.removeAttribute('required');
   } else {
       referenciaGroup.style.display = 'block';
       referenciaInput.setAttribute('required', 'required');
   }
});

// Función para actualizar información del pago
function actualizarInfoPago() {
   const monto = parseFloat(document.getElementById('monto').value) || 0;
   const montoPendiente = <?php echo $montoPendiente; ?>;
   const pagoInfo = document.querySelector('.pago-info');
   const tipoPagoText = document.getElementById('tipoPagoText');
   const montoRestante = document.getElementById('montoRestante');

   if (monto > 0) {
       const restante = montoPendiente - monto;
       const esPagoTotal = monto >= montoPendiente;

       tipoPagoText.textContent = esPagoTotal ? 'Pago Total' : 'Abono';
       montoRestante.textContent = formatoMoneda(Math.max(0, restante));
       
       pagoInfo.style.display = 'block';
       pagoInfo.className = 'pago-info alert ' + 
           (esPagoTotal ? 'alert-success' : 'alert-warning');
   } else {
       pagoInfo.style.display = 'none';
   }
}

// Formatear monto al perder el foco
document.getElementById('monto').addEventListener('blur', function() {
   if (this.value) {
       this.value = parseFloat(this.value).toFixed(2);
   }
});

// Actualizar información cuando cambia el monto
document.getElementById('monto').addEventListener('input', actualizarInfoPago);

// Cerrar modal con Escape
document.addEventListener('keydown', function(e) {
   if (e.key === 'Escape') {
       cerrarModalConfirmarPago();
   }
});

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
   actualizarInfoPago();
});
</script>

<?php require_once 'footer.php'; ?>