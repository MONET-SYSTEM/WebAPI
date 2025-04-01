<?php
    // Adjust the path if needed: this example assumes that the "config.php" 
    // and "auth_service.php" files are located two directories up from this file.
    require_once '../../config.php';
    require_once '../../auth/auth_service.php';

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['email']) || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
        exit;
    }

    $result = loginUser($conn, $data['email'], $data['password']);
    http_response_code($result['code']);
    unset($result['code']);
    echo json_encode($result);
?>
