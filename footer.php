<?php
/*
 * footer.php
 * Cierra .page-content, agrega el pie de página, scripts globales
 * y cierra .main-wrapper, </body>, </html>.
 * Se incluye al final de cada módulo con: require_once 'footer.php';
 */
?>

    </div>
    <!-- /page-content -->

    <!-- ============================================================
         PIE DE PÁGINA
         ============================================================ -->
    <footer class="page-footer">
        <span>
            &copy; <?php echo date('Y'); ?>
            <strong style="color:var(--accent);">ORTHIIS</strong>
            — Servicios Funerarios. Todos los derechos reservados.
        </span>
        <span style="color:var(--gray-400);">
            Diseñado por <strong>MM Lab Studio</strong>
        </span>
    </footer>

</div>
<!-- /main-wrapper -->


<!-- ============================================================
     SCRIPTS GLOBALES
     ============================================================ -->
<script src="scripts.js"></script>

<script>
/* ============================================================
   SIDEBAR TOGGLE — mobile
   ============================================================ */
(function () {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar       = document.getElementById('sidebar');
    const mainWrapper   = document.getElementById('mainWrapper');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });

        // Cerrar sidebar al hacer clic fuera (mobile)
        document.addEventListener('click', function (e) {
            if (
                window.innerWidth <= 768 &&
                !sidebar.contains(e.target) &&
                !sidebarToggle.contains(e.target)
            ) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Responsive: colapsar sidebar si pantalla pequeña al cargar
    function checkResponsive() {
        if (window.innerWidth <= 768) {
            sidebar && sidebar.classList.remove('open');
        }
    }
    checkResponsive();
    window.addEventListener('resize', checkResponsive);
})();


/* ============================================================
   DROPDOWN USUARIO (topbar)
   ============================================================ */
(function () {
    const topbarUser  = document.getElementById('topbarUser');
    const userDropdown = document.getElementById('userDropdown');

    if (topbarUser && userDropdown) {
        topbarUser.addEventListener('click', function (e) {
            e.stopPropagation();
            userDropdown.classList.toggle('active');
        });

        // Cerrar al hacer clic fuera
        document.addEventListener('click', function () {
            userDropdown.classList.remove('active');
        });

        // Evitar que se cierre al hacer clic dentro del dropdown
        userDropdown.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }
})();


/* ============================================================
   FUNCIÓN GLOBAL: mostrarToast
   (disponible en todos los módulos)
   ============================================================ */
function mostrarToast(mensaje, tipo = 'info', duracion = 4000) {
    const colores = {
        success : 'linear-gradient(135deg, #2E7D32, #388E3C)',
        error   : 'linear-gradient(135deg, #C62828, #D32F2F)',
        warning : 'linear-gradient(135deg, #F57F17, #F9A825)',
        info    : 'linear-gradient(135deg, #1565C0, #2196F3)'
    };

    Toastify({
        text        : mensaje,
        duration    : duracion,
        close       : true,
        gravity     : 'top',
        position    : 'right',
        style       : { background: colores[tipo] || colores.info },
        stopOnFocus : true
    }).showToast();
}


/* ============================================================
   CERRAR MODALES CON TECLA ESCAPE
   ============================================================ */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        // Modales propios del sistema (clase modal-overlay con clase open)
        document.querySelectorAll('.modal-overlay.open').forEach(function (modal) {
            modal.classList.remove('open');
        });
    }
});


/* ============================================================
   CERRAR MODALES PROPIOS AL HACER CLIC EN EL OVERLAY
   ============================================================ */
document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) {
            overlay.classList.remove('open');
        }
    });
});
</script>

</body>
</html>