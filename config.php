<?php

    $host = 'localhost';
    $dbname = 'library_managementdb';
    $username = 'root';
    $password = 'Sheamar@442211';

    try {
        $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        die(json_encode([
            'status' => 'error',
            'message' => 'Connection failed: ' . $e->getMessage()
        ]));
    }
?>