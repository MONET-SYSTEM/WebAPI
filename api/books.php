<?php
    require_once '../config.php';

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    $data = json_decode(file_get_contents('php://input'), true);

    $requestUri = $_SERVER['REQUEST_URI'];
    $uriSegments = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
    $bookId = isset($uriSegments[count($uriSegments) - 1]) && is_numeric($uriSegments[count($uriSegments) - 1]) 
        ? (int) $uriSegments[count($uriSegments) - 1] 
        : null;

    switch ($method) {
        case 'GET':
            if ($bookId) {
                getBook($conn, $bookId);
            } else {
                listBooks($conn);
            }
            break;
        case 'POST':
            createBook($conn, $data);
            break;
        case 'PUT':
            if (!$bookId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Book ID is required']);
                exit;
            }
            updateBook($conn, $bookId, $data);
            break;
        case 'DELETE':
            if (!$bookId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Book ID is required']);
                exit;
            }
            deleteBook($conn, $bookId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
            break;
    }

    function listBooks($conn) {
        try {
            $query = "SELECT * FROM books";
            $params = [];

            if (isset($_GET['title'])) {
                $query .= " WHERE title LIKE ?";
                $params[] = '%' . $_GET['title'] . '%';
            }

            $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            $query .= " LIMIT $limit OFFSET $offset";
            
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $books = $stmt->fetchAll();
            
            $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM books" . 
                (isset($_GET['title']) ? " WHERE title LIKE ?" : ""));
            $countParams = isset($_GET['title']) ? ['%' . $_GET['title'] . '%'] : [];
            $countStmt->execute($countParams);
            $totalCount = $countStmt->fetch()['total'];
            
            echo json_encode([
                'status' => 'success',
                'data' => $books,
                'meta' => [
                    'total' => $totalCount,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($totalCount / $limit)
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function getBook($conn, $id) {
        try {
            $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $stmt->execute([$id]);
            $book = $stmt->fetch();
            
            if ($book) {
                echo json_encode(['status' => 'success', 'data' => $book]);
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Book not found']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function createBook($conn, $data) {
        if (!isset($data['title']) || !isset($data['author']) || !isset($data['isbn'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Required fields: title, author, isbn']);
            return;
        }
        
        try {
            $stmt = $conn->prepare("INSERT INTO books (title, author, isbn, publication_date, category, copies_available) 
                                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['title'],
                $data['author'],
                $data['isbn'],
                $data['publication_date'] ?? null,
                $data['category'] ?? null,
                $data['copies_available'] ?? 1
            ]);
            
            $bookId = $conn->lastInsertId();

            $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $stmt->execute([$bookId]);
            $book = $stmt->fetch();
            
            http_response_code(201);
            echo json_encode(['status' => 'success', 'message' => 'Book created successfully', 'data' => $book]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function updateBook($conn, $id, $data) {
        try {
            $checkStmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $checkStmt->execute([$id]);
            $book = $checkStmt->fetch();
            
            if (!$book) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Book not found']);
                return;
            }

            $fields = [];
            $params = [];
            
            if (isset($data['title'])) {
                $fields[] = "title = ?";
                $params[] = $data['title'];
            }
            
            if (isset($data['author'])) {
                $fields[] = "author = ?";
                $params[] = $data['author'];
            }
            
            if (isset($data['isbn'])) {
                $fields[] = "isbn = ?";
                $params[] = $data['isbn'];
            }
            
            if (isset($data['publication_date'])) {
                $fields[] = "publication_date = ?";
                $params[] = $data['publication_date'];
            }
            
            if (isset($data['category'])) {
                $fields[] = "category = ?";
                $params[] = $data['category'];
            }
            
            if (isset($data['copies_available'])) {
                $fields[] = "copies_available = ?";
                $params[] = $data['copies_available'];
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
                return;
            }

            $params[] = $id;
            
            $stmt = $conn->prepare("UPDATE books SET " . implode(", ", $fields) . " WHERE id = ?");
            $stmt->execute($params);
  
            $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $stmt->execute([$id]);
            $updatedBook = $stmt->fetch();
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Book updated successfully', 
                'data' => $updatedBook
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function deleteBook($conn, $id) {
        try {
            $checkStmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $checkStmt->execute([$id]);
            $book = $checkStmt->fetch();
            
            if (!$book) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Book not found']);
                return;
            }
            
            $stmt = $conn->prepare("DELETE FROM books WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Book deleted successfully',
                'data' => $book
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
?>