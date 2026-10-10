<?php
require_once __DIR__ . '/auth.php';
auth_clear_cookie();
header('Location: login.php');
exit;
