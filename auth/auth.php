<?php
    require_once '../config.php';
    require_once 'jwt_helper.php';

    function loginUser($conn, $email, $password) {
        try {
            if (empty($email) || empty($password)) {
                return ['status' => 'error', 'message' => 'Email and password are required', 'code' => 400];
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['status' => 'error', 'message' => 'Invalid email format', 'code' => 400];
            }

            $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['status' => 'error', 'message' => 'Invalid credentials', 'code' => 401];
            }

            if (!password_verify($password, $user['password'])) {
                logLoginAttempt($conn, $user['id'], false);
                return ['status' => 'error', 'message' => 'Invalid credentials', 'code' => 401];
            }

            if (hasExceededLoginAttempts($conn, $user['id'])) {
                return ['status' => 'error', 'message' => 'Account temporarily locked due to too many failed login attempts', 'code' => 429];
            }

            $payload = [
                'user_id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'iat' => time(),
                'exp' => time() + (60 * 60 * 24) 
            ];
            
            $token = generateJWT($payload);
            
            logLoginAttempt($conn, $user['id'], true);

            unset($user['password']);
            
            return [
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'expires' => date('Y-m-d H:i:s', time() + (60 * 60 * 24))
                ],
                'code' => 200
            ];
            
        } catch (PDOException $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    function logLoginAttempt($conn, $userId, $successful) {
        $stmt = $conn->prepare("INSERT INTO login_attempts (user_id, successful, attempt_time, ip_address) VALUES (?, ?, NOW(), ?)");
        $stmt->execute([$userId, $successful ? 1 : 0, $_SERVER['REMOTE_ADDR']]);
    }

    function hasExceededLoginAttempts($conn, $userId) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts 
                            WHERE user_id = ? AND successful = 0 AND 
                            attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$userId]);
        $failedAttempts = $stmt->fetchColumn();

        return $failedAttempts >= 5;
    }

    function authenticateRequest() {
        $headers = getallheaders();
        $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

        if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: No token provided']);
            exit;
        }
        
        $token = $matches[1];
        
        try {
            $payload = decodeJWT($token);

            if ($payload['exp'] < time()) {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Token expired']);
                exit;
            }
            
            return $payload;
            
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: ' . $e->getMessage()]);
            exit;
        }
    }

    function authorizeRole($payload, $allowedRoles = []) {
        if (empty($allowedRoles) || in_array($payload['role'], $allowedRoles)) {
            return true;
        }
        
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: Insufficient permissions']);
        exit;
    }

    function rateLimiter($conn, $userId = null, $endpoint = null) {
        $identifier = $userId ?? $_SERVER['REMOTE_ADDR'];
        $endpoint = $endpoint ?? $_SERVER['REQUEST_URI'];

        $stmt = $conn->prepare("SELECT COUNT(*) FROM api_requests WHERE identifier = ? AND endpoint = ? AND request_time > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $stmt->execute([$identifier, $endpoint]);
        $requestCount = $stmt->fetchColumn();
 
        $stmt = $conn->prepare("INSERT INTO api_requests (identifier, endpoint, request_time, ip_address) 
                            VALUES (?, ?, NOW(), ?)");
        $stmt->execute([$identifier, $endpoint, $_SERVER['REMOTE_ADDR']]);

        if ($requestCount >= 60) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please try again later.']);
            exit;
        }
        
        return true;
    }
?>