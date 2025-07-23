<?php
// File: /includes/footer.php
?>
        </main>
        <footer class="bg-dark text-white py-4 mt-4">
            <div class="container">
                <div class="row">
                    <div class="col-md-6">
                        <h5><?php echo APP_NAME; ?></h5>
                        <p>A smart savings and micro-lending platform for individuals and groups.</p>
                    </div>
                    <div class="col-md-3">
                        <h5>Quick Links</h5>
                        <ul class="list-unstyled">
                            <li><a href="index.php" class="text-white">Home</a></li>
                            <li><a href="about.php" class="text-white">About</a></li>
                            <li><a href="contact.php" class="text-white">Contact</a></li>
                        </ul>
                    </div>
                    <div class="col-md-3">
                        <h5>Connect</h5>
                        <ul class="list-unstyled">
                            <li><a href="#" class="text-white"><i class="bi bi-facebook"></i> Facebook</a></li>
                            <li><a href="#" class="text-white"><i class="bi bi-twitter"></i> Twitter</a></li>
                            <li><a href="#" class="text-white"><i class="bi bi-instagram"></i> Instagram</a></li>
                        </ul>
                    </div>
                </div>
                <hr>
                <div class="text-center">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.</p>
                </div>
            </div>
        </footer>
        <script src="<?php echo JS_PATH; ?>/bootstrap.bundle.min.js"></script>
        <script src="<?php echo JS_PATH; ?>/main.js"></script>
    </body>
</html>
