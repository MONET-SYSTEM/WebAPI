<?php
    require_once '../../config.php';
    require_once '../../auth/auth_service.php';

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    $payload = authenticateRequest();

    $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, details) VALUES (?, 'logout', ?)");
    $stmt->execute([$payload['user_id'], json_encode(['ip' => $_SERVER['REMOTE_ADDR']])]);

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Logout successful']);
    ?>
