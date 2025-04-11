<?php
require_once __DIR__ . '/../../config/config.php';
session_start();
unset($_SESSION['show_success_modal']);
echo 'Modal session cleared';
?>