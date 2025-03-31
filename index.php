<?php
    header('Content-Type: application/json');

    echo json_encode([
        'status' => 'success',
        'message' => 'Welcome to the Library Management System API',
        'endpoints' => [
            'books' => '/api/books',
            'register' => '/api/register',
            'login' => '/api/auth/login',
            'logout' => '/api/auth/logout',   
            'user_info' => '/api/auth/me',
        ]
    ]);
?>