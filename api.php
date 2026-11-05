<?php
// ============================================================
//  api.php — Sistema de Torneos de Tenis
//  Incluye: CRUD general (MySQL) + upload/delete de fotos
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Configuración de base de datos ──────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'u943345234_torneos');
define('DB_USER', 'u943345234_admin');
define('DB_PASS', 'Conejoroger01');

// ── Configuración de carpeta de fotos ───────────────────────
define('UPLOADS_DIR', __DIR__ . '/fotos_jugadores/');
define('UPLOADS_URL', 'fotos_jugadores/');

// ── Conexión PDO ─────────────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        // Tabla principal de datos
        $pdo->exec("CREATE TABLE IF NOT EXISTS torneo_data (
            id VARCHAR(128) PRIMARY KEY,
            data_json LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Tabla de torneos personalizados
        $pdo->exec("CREATE TABLE IF NOT EXISTS custom_tournaments (
            id VARCHAR(128) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    return $pdo;
}

function normalizeBool($value): bool {
    if ($value === true || $value === 1 || $value === '1' || $value === 'true') return true;
    if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === null || $value === '') return false;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}

function normalizeInt($value): ?int {
    if ($value === null || $value === '') return null;
    if (!is_numeric($value)) return null;
    return (int)$value;
}

function normalizeRecord(array $record): array {
    $type = $record['type'] ?? '';

    if ($type === 'match') {
        if (array_key_exists('validated', $record)) {
            $record['validated'] = normalizeBool($record['validated']);
        }
        if (array_key_exists('pending_validation', $record)) {
            $record['pending_validation'] = normalizeBool($record['pending_validation']);
        }
        if (array_key_exists('sets_won', $record)) {
            $record['sets_won'] = normalizeInt($record['sets_won']);
        }
        if (array_key_exists('sets_lost', $record)) {
            $record['sets_lost'] = normalizeInt($record['sets_lost']);
        }
        if (!array_key_exists('validated', $record) && !empty($record['winner'])) {
            $record['validated'] = !normalizeBool($record['pending_validation'] ?? false);
        }
    }

    if ($type === 'ranking_override') {
        foreach (['pos', 'pts', 'pj', 'pg', 'pp', 'sg', 'sp'] as $field) {
            if (isset($record[$field]) && $record[$field] !== '') {
                $record[$field] = (int)$record[$field];
            }
        }
        foreach (['utr', 'ef'] as $field) {
            if (isset($record[$field]) && $record[$field] !== '') {
                $record[$field] = (float)$record[$field];
            }
        }
    }

    return $record;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Subida de foto (jugador o torneo) ────────────────────────
if ($action === 'uploadPhoto' && $method === 'POST') {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No se recibió ningún archivo o hubo un error en la subida']);
        exit;
    }

    $tid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['tournament_id'] ?? '');
    $file = $_FILES['photo'];

    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_types)) {
        echo json_encode(['ok' => false, 'error' => 'Tipo de archivo no permitido: ' . $mime]);
        exit;
    }

    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }

    $extMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $ext = $extMap[$mime] ?? 'jpg';

    if ($tid !== '') {
        $filename = 'torneo_' . $tid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $pattern = UPLOADS_DIR . 'torneo_' . $tid . '_*';
        foreach (glob($pattern) as $old_file) {
            @unlink($old_file);
        }
    } else {
        $filename = 'jugador_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    }

    $dest = UPLOADS_DIR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor']);
        exit;
    }

    $url = UPLOADS_URL . $filename;
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

// ── Eliminar foto ────────────────────────────────────────────
if ($action === 'deletePhoto' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $photo_url = $body['photo_url'] ?? '';

    if ($photo_url) {
        $filename = basename($photo_url);
        $filepath = UPLOADS_DIR . $filename;
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── CRUD general de registros ────────────────────────────────
if ($action === 'getAll' && $method === 'GET') {
    $db = getDB();
    $stmt = $db->query("SELECT data_json FROM torneo_data ORDER BY created_at ASC");
    $rows = $stmt->fetchAll();
    $data = array_map(function ($row) {
        $record = json_decode($row['data_json'], true);
        return normalizeRecord(is_array($record) ? $record : []);
    }, $rows);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

if ($action === 'create' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || !isset($body['id'])) {
        echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
        exit;
    }
    $body = normalizeRecord($body);
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO torneo_data (id, data_json) VALUES (:id, :data)
                          ON DUPLICATE KEY UPDATE data_json = :data2, updated_at = NOW()");
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $stmt->execute([':id' => $body['id'], ':data' => $json, ':data2' => $json]);

    // Si es torneo custom, también lo reflejamos en la tabla dedicada
    if (($body['type'] ?? '') === 'custom_tournament') {
        $tid = $body['tournament_id'] ?? $body['id'];
        $name = $body['name'] ?? 'Torneo';
        $stmt2 = $db->prepare("INSERT IGNORE INTO custom_tournaments (id, name) VALUES (:id, :name)");
        $stmt2->execute([':id' => $tid, ':name' => $name]);
    }

    echo json_encode(['ok' => true, 'id' => $body['id']]);
    exit;
}

if ($action === 'update' && $method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || !isset($body['id'])) {
        echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
        exit;
    }
    $body = normalizeRecord($body);
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO torneo_data (id, data_json) VALUES (:id, :data)
                          ON DUPLICATE KEY UPDATE data_json = :data2, updated_at = NOW()");
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $stmt->execute([':id' => $body['id'], ':data' => $json, ':data2' => $json]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete' && $method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = $body['id'] ?? '';
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM torneo_data WHERE id = :id");
    $stmt->execute([':id' => $id]);
    // Limpieza extra por si era un torneo custom
    $stmt2 = $db->prepare("DELETE FROM custom_tournaments WHERE id = :id");
    $stmt2->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Torneos personalizados ───────────────────────────────────
if ($action === 'getCustomTournaments' && $method === 'GET') {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM custom_tournaments ORDER BY created_at ASC");
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'createTournament' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || !isset($body['id'], $body['name'])) {
        echo json_encode(['ok' => false, 'error' => 'Datos inválidos']); exit;
    }
    $db = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO custom_tournaments (id, name) VALUES (:id, :name)");
    $stmt->execute([':id' => $body['id'], ':name' => $body['name']]);

    // También en torneo_data para que el ranking lo vea con getAll
    $record = [
        'id' => $body['id'],
        'type' => 'custom_tournament',
        'tournament_id' => $body['id'],
        'name' => $body['name'],
        'created_at' => date('c')
    ];
    $stmt3 = $db->prepare("INSERT INTO torneo_data (id, data_json) VALUES (:id, :data)
                           ON DUPLICATE KEY UPDATE data_json = :data2, updated_at = NOW()");
    $json = json_encode($record, JSON_UNESCAPED_UNICODE);
    $stmt3->execute([':id' => $body['id'], ':data' => $json, ':data2' => $json]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'deleteTournament' && $method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = $body['id'] ?? '';
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM custom_tournaments WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $stmt2 = $db->prepare("DELETE FROM torneo_data WHERE id = :id OR JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.tournament_id')) = :id2");
    $stmt2->execute([':id' => $id, ':id2' => $id]);
    $pattern = UPLOADS_DIR . 'torneo_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '_*';
    foreach (glob($pattern) as $f) { @unlink($f); }
    echo json_encode(['ok' => true]);
    exit;
}

// Acción desconocida
echo json_encode(['ok' => false, 'error' => 'Acción no reconocida: ' . $action]);
