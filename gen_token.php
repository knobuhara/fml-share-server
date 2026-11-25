<?php
require_once __DIR__ . '/lib/JwtHandler.php';

$jwt = new JwtHandler();
$token = $jwt->generate([
    'user_id' => 1,
    'username' => 'test_user',
    'role' => 'user'
]);

echo "Token: $token\n";
