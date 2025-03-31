<?php
    require_once '../config.php';
    require_once '../auth/auth.php';

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);

    rateLimiter($conn, null, $path);

    $data = json_decode(file_get_contents('php://input'), true);

    if (strpos($path, '/api/auth/login') !== false && $method === 'POST') {
        if (!isset($data['email']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
            exit;
        }
        
        $result = loginUser($conn, $data['email'], $data['password']);
        http_response_code($result['code']);
        unset($result['code']);
        echo json_encode($result);
        exit;
    }

    if (strpos($path, '/api/auth/logout') !== false && $method === 'POST') {
        $payload = authenticateRequest();
        
        $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, details) VALUES (?, 'logout', ?)");
        $stmt->execute([$payload['user_id'], json_encode(['ip' => $_SERVER['REMOTE_ADDR']])]);
        
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Logout successful']);
        exit;
    }

    if (strpos($path, '/api/auth/me') !== false && $method === 'GET') {
        $payload = authenticateRequest();
 
        $stmt = $conn->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();
        
        http_response_code(200);
        echo json_encode(['status' => 'success', 'data' => $user]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Endpoint not found']);
?>