<?php
    require_once '../config.php';
    require_once '../auth/auth_service.php';

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    rateLimiter($conn);

    $requestUri = $_SERVER['REQUEST_URI'];
    $uriSegments = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
    $bookId = isset($uriSegments[count($uriSegments) - 1]) && is_numeric($uriSegments[count($uriSegments) - 1]) 
        ? (int) $uriSegments[count($uriSegments) - 1] 
        : null;

    $data = json_decode(file_get_contents('php://input'), true);
  

    switch ($method) {
        case 'GET':
            if ($bookId) {
                getBook($conn, $bookId);
            } else {
                listBooks($conn);
            }
            break;
        case 'POST':
            $payload = authenticateRequest();
            authorizeRole($payload, ['admin', 'librarian']);
            
            createBook($conn, $data, $payload);
            break;
        case 'PUT':
            if (!$bookId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Book ID is Required!']);
                exit;
            }
            $payload = authenticateRequest();

            authorizeRole($payload, ['admin', 'librarian']);
            
            updateBook($conn, $bookId, $data, $payload);
            break;
        case 'DELETE':
            if (!$bookId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Book ID is Required!']);
                exit;
            }

            $payload = authenticateRequest();

            authorizeRole($payload, ['admin']);
            
            deleteBook($conn, $bookId, $payload);
            break;
        default:
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed!']);
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

    function createBook($conn, $data, $payload) {
        if (!isset($data['title']) || !isset($data['author']) || !isset($data['isbn'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Required Fields: title, author, isbn']);
            return;
        }
        
        try {
            $conn->beginTransaction();
            
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

            $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, details) 
                                VALUES (?, 'create_book', ?)");
            $stmt->execute([
                $payload['user_id'],
                json_encode([
                    'book_id' => $bookId,
                    'title' => $data['title'],
                    'ip' => $_SERVER['REMOTE_ADDR']
                ])
            ]);
            
            $conn->commit();

            $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $stmt->execute([$bookId]);
            $book = $stmt->fetch();
            
            http_response_code(201); 
            echo json_encode(['status' => 'success', 'message' => 'Book Created Successfully!', 'data' => $book]);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function updateBook($conn, $id, $data, $payload) {
        try {
            $checkStmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $checkStmt->execute([$id]);
            $book = $checkStmt->fetch();
            
            if (!$book) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Book Not Found!']);
                return;
            }

            $fields = [];
            $params = [];
            $changes = [];
            
            if (isset($data['title']) && $data['title'] !== $book['title']) {
                $fields[] = "title = ?";
                $params[] = $data['title'];
                $changes['title'] = ['old' => $book['title'], 'new' => $data['title']];
            }
            
            if (isset($data['author']) && $data['author'] !== $book['author']) {
                $fields[] = "author = ?";
                $params[] = $data['author'];
                $changes['author'] = ['old' => $book['author'], 'new' => $data['author']];
            }
            
            if (isset($data['isbn']) && $data['isbn'] !== $book['isbn']) {
                $fields[] = "isbn = ?";
                $params[] = $data['isbn'];
                $changes['isbn'] = ['old' => $book['isbn'], 'new' => $data['isbn']];
            }
            
            if (isset($data['publication_date']) && $data['publication_date'] !== $book['publication_date']) {
                $fields[] = "publication_date = ?";
                $params[] = $data['publication_date'];
                $changes['publication_date'] = ['old' => $book['publication_date'], 'new' => $data['publication_date']];
            }
            
            if (isset($data['category']) && $data['category'] !== $book['category']) {
                $fields[] = "category = ?";
                $params[] = $data['category'];
                $changes['category'] = ['old' => $book['category'], 'new' => $data['category']];
            }
            
            if (isset($data['copies_available']) && $data['copies_available'] !== $book['copies_available']) {
                $fields[] = "copies_available = ?";
                $params[] = $data['copies_available'];
                $changes['copies_available'] = ['old' => $book['copies_available'], 'new' => $data['copies_available']];
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
                return;
            }

            $params[] = $id;
            
            $conn->beginTransaction();
            
            $stmt = $conn->prepare("UPDATE books SET " . implode(", ", $fields) . " WHERE id = ?");
            $stmt->execute($params);

            $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, details) 
                                VALUES (?, 'update_book', ?)");
            $stmt->execute([
                $payload['user_id'],
                json_encode([
                    'book_id' => $id,
                    'changes' => $changes,
                    'ip' => $_SERVER['REMOTE_ADDR']
                ])
            ]);
            
            $conn->commit();

            $stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $stmt->execute([$id]);
            $updatedBook = $stmt->fetch();
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Book Updated Successfully!', 
                'data' => $updatedBook
            ]);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function deleteBook($conn, $id, $payload) {
        try {
            $checkStmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
            $checkStmt->execute([$id]);
            $book = $checkStmt->fetch();
            
            if (!$book) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Book Not Found!']);
                return;
            }
            
            $conn->beginTransaction();
            
            $stmt = $conn->prepare("DELETE FROM books WHERE id = ?");
            $stmt->execute([$id]);
            
            $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, details) 
                                VALUES (?, 'delete_book', ?)");
            $stmt->execute([
                $payload['user_id'],
                json_encode([
                    'book_id' => $id,
                    'book_details' => $book,
                    'ip' => $_SERVER['REMOTE_ADDR']
                ])
            ]);
            
            $conn->commit();
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Book Deleted Successfully!',
                'data' => $book
            ]);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
?>