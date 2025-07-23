<?php
// File: logout.php

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

logoutUser();
header('Location: login.php');
exit;
?>
