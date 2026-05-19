<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'sql100.infinityfree.com';
$dbname = 'if0_41958317_c4k';
$dbuser = 'if0_41958317';
$dbpass = '3vwx7wgwM7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// DEBUG: Gelen isteği logla
$debug_log = [
    'raw_input' => file_get_contents('php://input'),
    'parsed_input' => $input,
    'timestamp' => date('Y-m-d H:i:s')
];
file_put_contents('api_debug.log', print_r($debug_log, true) . PHP_EOL, FILE_APPEND);

if (!$input) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'debug' => 'No input']);
    exit;
}

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// ID Mapping
$idMapping = [1 => 1630, 1555 => 1630, 1629 => 1629, 1628 => 1628];
if (isset($idMapping[$userId])) {
    $userId = $idMapping[$userId];
}

// Kullanıcı ara
$balance = 0;
$realUserId = 0;
$found_user = null;

try {
    // Önce ID ile ara
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $found_user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Username ile ara
    if (!$found_user && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $found_user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // ID dönüşümü sonrası tekrar dene
    if (!$found_user && $userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $found_user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($found_user) {
        $balance = (float)$found_user['bakiye'];
        $realUserId = $found_user['id'];
        
        // DEBUG: Bulunan kullanıcıyı logla
        file_put_contents('api_debug.log', "FOUND USER: ID={$realUserId}, Balance={$balance}, Username={$found_user['username']}" . PHP_EOL, FILE_APPEND);
    } else {
        file_put_contents('api_debug.log', "USER NOT FOUND: user_id={$userId}, username={$username}" . PHP_EOL, FILE_APPEND);
    }
    
} catch (PDOException $e) {
    file_put_contents('api_debug.log', "DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

$responseBalance = $balance;

// İşlemler
if ($method == 'transaction_bet' && $betAmount > 0 && $realUserId > 0) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id")->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        file_put_contents('api_debug.log', "BET: Old={$balance}, Bet={$betAmount}, New={$responseBalance}" . PHP_EOL, FILE_APPEND);
    }
} elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $winAmount;
    $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id")->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    file_put_contents('api_debug.log', "WIN: Old={$balance}, Win={$winAmount}, New={$responseBalance}" . PHP_EOL, FILE_APPEND);
} elseif ($method == 'refund' && $betAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $betAmount;
    $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id")->execute(['balance' => $responseBalance, 'id' => $realUserId]);
}

echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
