<?php
// ==========================================================
// Weekly Course Breakdown API - Single File PHP Version
// Location: src/weekly/api/index.php
// ==========================================================

// -------------------- [HEADERS & CORS] --------------------
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------- [DATABASE CONNECTION] --------------------
class Database {
    private $host = "localhost";           // Your database server address
    private $db_name = "itcs333_course";   // Your database name
    private $username = "root";            // Your database username
    private $password = "";                // Your database password (often empty for local)

    public function getConnection() {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name};charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $this->conn;
        } catch(PDOException $e) {
            echo json_encode(["success" => false, "error" => "Connection failed"]);
            exit;
        }
    }
}

$database = new Database();
$db = $database->getConnection();

// -------------------- [HELPERS] --------------------
function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function sendError($msg, $status = 400) {
    sendResponse(['success' => false, 'error' => $msg], $status);
}

function sanitize($val) {
    return htmlspecialchars(strip_tags(trim($val)));
}

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// -------------------- [INPUT + ROUTE INFO] --------------------
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);
$resource = $_GET['resource'] ?? 'weeks';

// -------------------- [WEEKS FUNCTIONS] --------------------
function getAllWeeks($db) {
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : null;
    $sort = in_array($_GET['sort'] ?? '', ['title', 'start_date', 'created_at']) ? $_GET['sort'] : 'start_date';
    $order = in_array(strtolower($_GET['order'] ?? 'asc'), ['asc', 'desc']) ? strtoupper($_GET['order']) : 'ASC';

    $sql = "SELECT week_id, title, start_date, description, links, created_at FROM weeks";
    if ($search) $sql .= " WHERE title LIKE :search OR description LIKE :search";
    $sql .= " ORDER BY $sort $order";

    $stmt = $db->prepare($sql);
    if ($search) $stmt->bindValue(':search', $search);
    $stmt->execute();

    $weeks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($weeks as &$w) $w['links'] = json_decode($w['links'], true);

    sendResponse(['success' => true, 'data' => $weeks]);
}

function getWeekById($db, $id) {
    if (!$id) sendError("Missing week_id");
    $stmt = $db->prepare("SELECT week_id, title, start_date, description, links, created_at FROM weeks WHERE week_id = ?");
    $stmt->execute([$id]);
    $week = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$week) sendError("Week not found", 404);
    $week['links'] = json_decode($week['links'], true);
    sendResponse(['success' => true, 'data' => $week]);
}

function createWeek($db, $data) {
    foreach (['week_id', 'title', 'start_date', 'description'] as $f) {
        if (empty($data[$f])) sendError("Missing field: $f");
    }

    if (!validateDate($data['start_date'])) sendError("Invalid date format");

    $stmt = $db->prepare("SELECT id FROM weeks WHERE week_id = ?");
    $stmt->execute([$data['week_id']]);
    if ($stmt->rowCount() > 0) sendError("week_id already exists", 409);

    $links = isset($data['links']) && is_array($data['links']) ? json_encode($data['links']) : json_encode([]);

    $stmt = $db->prepare("INSERT INTO weeks (week_id, title, start_date, description, links) VALUES (?, ?, ?, ?, ?)");
    $success = $stmt->execute([
        sanitize($data['week_id']),
        sanitize($data['title']),
        $data['start_date'],
        sanitize($data['description']),
        $links
    ]);

    if ($success) {
        sendResponse(['success' => true, 'message' => 'Week created'], 201);
    } else {
        sendError("Insert failed", 500);
    }
}

function updateWeek($db, $data) {
    if (empty($data['week_id'])) sendError("Missing week_id");

    $stmt = $db->prepare("SELECT id FROM weeks WHERE week_id = ?");
    $stmt->execute([$data['week_id']]);
    if ($stmt->rowCount() === 0) sendError("Week not found", 404);

    $fields = [];
    $values = [];

    if (!empty($data['title'])) {
        $fields[] = "title = ?";
        $values[] = sanitize($data['title']);
    }
    if (!empty($data['start_date'])) {
        if (!validateDate($data['start_date'])) sendError("Invalid date");
        $fields[] = "start_date = ?";
        $values[] = $data['start_date'];
    }
    if (!empty($data['description'])) {
        $fields[] = "description = ?";
        $values[] = sanitize($data['description']);
    }
    if (isset($data['links']) && is_array($data['links'])) {
        $fields[] = "links = ?";
        $values[] = json_encode($data['links']);
    }

    if (empty($fields)) sendError("Nothing to update");

    $fields[] = "updated_at = CURRENT_TIMESTAMP";
    $sql = "UPDATE weeks SET " . implode(", ", $fields) . " WHERE week_id = ?";
    $values[] = $data['week_id'];

    $stmt = $db->prepare($sql);
    $stmt->execute($values);

    sendResponse(['success' => true, 'message' => 'Week updated']);
}

function deleteWeek($db, $id) {
    if (!$id) sendError("Missing week_id");

    $stmt = $db->prepare("SELECT id FROM weeks WHERE week_id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) sendError("Week not found", 404);

    $db->prepare("DELETE FROM comments WHERE week_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM weeks WHERE week_id = ?")->execute([$id]);

    sendResponse(['success' => true, 'message' => 'Week and related comments deleted']);
}

// -------------------- [COMMENTS FUNCTIONS] --------------------
function getCommentsByWeek($db, $weekId) {
    if (!$weekId) sendError("Missing week_id");

    $stmt = $db->prepare("SELECT id, week_id, author, text, created_at FROM comments WHERE week_id = ? ORDER BY created_at ASC");
    $stmt->execute([$weekId]);

    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendResponse(['success' => true, 'data' => $comments]);
}

function createComment($db, $data) {
    foreach (['week_id', 'author', 'text'] as $f) {
        if (empty($data[$f])) sendError("Missing field: $f");
    }

    $stmt = $db->prepare("SELECT id FROM weeks WHERE week_id = ?");
    $stmt->execute([$data['week_id']]);
    if ($stmt->rowCount() === 0) sendError("Week not found", 404);

    $stmt = $db->prepare("INSERT INTO comments (week_id, author, text) VALUES (?, ?, ?)");
    $success = $stmt->execute([
        sanitize($data['week_id']),
        sanitize($data['author']),
        sanitize($data['text'])
    ]);

    if ($success) {
        sendResponse(['success' => true, 'message' => 'Comment added'], 201);
    } else {
        sendError("Insert failed", 500);
    }
}

function deleteComment($db, $id) {
    if (!$id) sendError("Missing comment id");

    $stmt = $db->prepare("SELECT id FROM comments WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) sendError("Comment not found", 404);

    $db->prepare("DELETE FROM comments WHERE id = ?")->execute([$id]);

    sendResponse(['success' => true, 'message' => 'Comment deleted']);
}

// -------------------- [ROUTING] --------------------
try {
    if ($resource === 'weeks') {
        if ($method === 'GET') {
            isset($_GET['week_id']) ? getWeekById($db, $_GET['week_id']) : getAllWeeks($db);
        } elseif ($method === 'POST') {
            createWeek($db, $input);
        } elseif ($method === 'PUT') {
            updateWeek($db, $input);
        } elseif ($method === 'DELETE') {
            $id = $_GET['week_id'] ?? ($input['week_id'] ?? null);
            deleteWeek($db, $id);
        } else {
            sendError("Method not allowed", 405);
        }
    } elseif ($resource === 'comments') {
        if ($method === 'GET') {
            getCommentsByWeek($db, $_GET['week_id'] ?? null);
        } elseif ($method === 'POST') {
            createComment($db, $input);
        } elseif ($method === 'DELETE') {
            $id = $_GET['id'] ?? ($input['id'] ?? null);
            deleteComment($db, $id);
        } else {
            sendError("Method not allowed", 405);
        }
    } else {
        sendError("Invalid resource: use 'weeks' or 'comments'", 400);
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    sendError("Unexpected error occurred", 500);
}
