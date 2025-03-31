<?php
    header('Content-Type: application/json');

    echo json_encode([
        'status' => 'success',
        'message' => 'Welcome to the Library Management System API',
        'endpoints' => [
            'books' => '/api/books',
            'members' => '/api/members',
        ]
    ]);
?>