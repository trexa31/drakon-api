<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'sql206.infinityfree.com';
$dbname = 'if0_41958317_c4k';
$dbuser = 'if0_41958317';
$dbpass = 'fMvWLgjJWSf';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'DB connection failed']);
    exit;
}

// JSON input al
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'Invalid JSON input']);
    exit;
}

// Input değerlerini al
$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

$balance = 0.00;
$realUserId = 0;
$foundUser = null;

try {
    // 1. Önce ID ile admin tablosunda ara (DİREKT ID, mapping yok!)
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username, parabirimi FROM admin WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if ($user) {
            $foundUser = $user;
            $realUserId = (int)$user['id'];
            $bakiyeRaw = $user['bakiye'];
            if ($bakiyeRaw !== null && $bakiyeRaw !== '') {
                $balance = (float) str_replace(',', '.', (string)$bakiyeRaw);
            }
            if (!empty($user['parabirimi'])) {
                $currency = strtoupper(str_replace(['₺', '€', '$'], ['TRY', 'EUR', 'USD'], $user['parabirimi']));
            }
        }
    }
    
    // 2. ID bulunamadıysa username ile ara
    if ($realUserId === 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye, parabirimi FROM admin WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        if ($user) {
            $foundUser = $user;
            $realUserId = (int)$user['id'];
            $bakiyeRaw = $user['bakiye'];
            if ($bakiyeRaw !== null && $bakiyeRaw !== '') {
                $balance = (float) str_replace(',', '.', (string)$bakiyeRaw);
            }
            if (!empty($user['parabirimi'])) {
                $currency = strtoupper(str_replace(['₺', '€', '$'], ['TRY', 'EUR', 'USD'], $user['parabirimi']));
            }
        }
    }
} catch (PDOException $e) {
    echo json_encode(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'Query error: ' . $e->getMessage()]);
    exit;
}

// Kullanıcı bulunamadıysa
if ($realUserId === 0) {
    echo json_encode([
        'balance' => '0.00',
        'currency_code' => $currency,
        'debug' => [
            'status' => 'USER_NOT_FOUND',
            'searched_user_id' => $userId,
            'searched_username' => $username
        ]
    ]);
    exit;
}

$responseBalance = $balance;

// ============ BAHİS İŞLEMİ ============
if ($method === 'transaction_bet' && $betAmount > 0 && $realUserId > 0) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => number_format($responseBalance, 2, '.', ''), 'id' => $realUserId]);
        } catch (PDOException $e) {
            $responseBalance = $balance;
        }
    }
}
// ============ KAZANÇ İŞLEMİ ============
elseif ($method === 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => number_format($responseBalance, 2, '.', ''), 'id' => $realUserId]);
    } catch (PDOException $e) {
        $responseBalance = $balance;
    }
}
// ============ İADE İŞLEMİ ============
elseif ($method === 'refund' && $betAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $betAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => number_format($responseBalance, 2, '.', ''), 'id' => $realUserId]);
    } catch (PDOException $e) {
        $responseBalance = $balance;
    }
}

echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency,
    'debug' => [
        'user_id' => $userId,
        'real_user_id' => $realUserId,
        'username' => $foundUser['username'] ?? null,
        'db_bakiye_raw' => $foundUser['bakiye'] ?? null,
        'parsed_balance' => $balance,
        'method' => $method
    ]
]);
?>
