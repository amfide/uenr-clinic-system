<?php
require_once __DIR__.'/../../config/config.php';

// Test recording attempts
recordLoginAttempt('testuser', true);
recordLoginAttempt('testuser', false);

// Check attempts
$remaining = MAX_LOGIN_ATTEMPTS - checkLoginAttempts('testuser');
echo "Remaining attempts for testuser: $remaining";
?>