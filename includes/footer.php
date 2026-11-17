    </div> <!-- Close container-fluid -->
    
    <!-- Footer -->
    <style>
    /* Vendor credit on every printed page. Print-only: on screen the footer
       keeps its existing single line, so nothing about the app changes visually.
       Kept as text rather than a remote logo — browsers routinely skip external
       images when printing, and it has to work offline too. */
    .app-brand-credit { display: none; }
    @media print {
        footer, .app-footer {
            display: block !important;
            background: none !important;
            border: 0 !important;
            border-top: 1px solid #ccc !important;
            margin-top: 14px !important;
            padding: 6px 0 0 0 !important;
            text-align: center;
        }
        .app-brand-credit {
            display: block !important;
            font-size: 9px;
            color: #888;
            letter-spacing: .2px;
            margin-top: 2px;
        }
        .app-brand-credit .brand-name { font-weight: 700; color: #555; letter-spacing: .5px; }
        .app-brand-credit .brand-sep { margin: 0 5px; color: #bbb; }
        footer small { font-size: 9.5px; color: #666 !important; }
    }
    </style>
    <footer class="bg-light text-center py-3 mt-5 border-top app-footer">
        <div class="container">
            <small class="text-muted">
                <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?> &copy; <?php echo date('Y'); ?>
            </small>
            <div class="app-brand-credit">
                <?php _e('developed_by'); ?> <span class="brand-name">Fabonic</span>
                <span class="brand-sep">&middot;</span> www.fabonic.co.tz
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery (for easier DOM manipulation) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
	
	<!-- Offline DB and Sync Manager with cache busting -->
    <?php
    // Cache busting version - change this number when you update the files
    $jsVersion = '3.2.1'; // Sales form: payment-mode-aware Money Account dropdown
    ?>
    <script src="<?php echo BASE_URL; ?>assets/js/offline-db.js?v=<?php echo $jsVersion; ?>"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/sync-manager.js?v=<?php echo $jsVersion; ?>"></script>
    
    <!-- Custom JS -->
    <script src="<?php echo BASE_URL; ?>assets/js/main.js?v=<?php echo time(); ?>"></script>

    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <?php if (isset($customJS)): ?>
        <script src="<?php echo BASE_URL . $customJS; ?>"></script>
    <?php endif; ?>
</body>
</html>
