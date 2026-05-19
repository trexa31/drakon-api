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
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'DB Connection Failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'No input data']);
    exit;
}

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// ============ ID DÖNÜŞÜM TABLOSU ============
$idMapping = [
    1 => 1630,
    1555 => 1630,
    1629 => 1629,
    1628 => 1628,
];

if (isset($idMapping[$userId])) {
    $userId = $idMapping[$userId];
}
// ===========================================

$balance = 0;
$realUserId = 0;
$userInfo = null;

try {
    // Kullanıcıyı bul (önce ID ile)
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userInfo) {
            $balance = (float)$userInfo['bakiye'];
            $realUserId = $userInfo['id'];
            error_log("User found by ID: {$realUserId}, Balance: {$balance}");
        }
    }
    
    // ID ile bulunamadıysa username ile ara
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userInfo) {
            $balance = (float)$userInfo['bakiye'];
            $realUserId = $userInfo['id'];
            error_log("User found by username: {$realUserId}, Balance: {$balance}");
        }
    }
    
    // Kullanıcı bulunamadıysa hata döndür
    if ($realUserId == 0) {
        error_log("User not found - ID: {$userId}, Username: {$username}");
        echo json_encode([
            'balance' => '0.00', 
            'currency_code' => $currency,
            'error' => 'User not found'
        ]);
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'Database error']);
    exit;
}

$responseBalance = $balance;

// Bahis işlemi (oyundan bahis alındığında)
if ($method == 'transaction_bet' && $betAmount > 0 && $realUserId > 0) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
            error_log("Bet processed - User: {$realUserId}, Old Balance: {$balance}, Bet: {$betAmount}, New Balance: {$responseBalance}");
        } catch (PDOException $e) {
            error_log("Bet update failed: " . $e->getMessage());
            $responseBalance = $balance; // Hata durumunda eski bakiyeyi gönder
        }
    } else {
        error_log("Insufficient balance - User: {$realUserId}, Balance: {$balance}, Bet: {$betAmount}");
        echo json_encode([
            'balance' => number_format($balance, 2, '.', ''),
            'currency_code' => $currency,
            'error' => 'Insufficient balance'
        ]);
        exit;
    }
}
// Kazanç işlemi (oyun kazanç gönderdiğinde)
elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        error_log("Win processed - User: {$realUserId}, Old Balance: {$balance}, Win: {$winAmount}, New Balance: {$responseBalance}");
    } catch (PDOException $e) {
        error_log("Win update failed: " . $e->getMessage());
        $responseBalance = $balance;
    }
}
// İade işlemi
elseif ($method == 'refund' && $betAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $betAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        error_log("Refund processed - User: {$realUserId}, Old Balance: {$balance}, Refund: {$betAmount}, New Balance: {$responseBalance}");
    } catch (PDOException $e) {
        error_log("Refund update failed: " . $e->getMessage());
        $responseBalance = $balance;
    }
}

// Her durumda güncel bakiyeyi gönder
echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency,
    'user_id' => $realUserId // Debug için user_id de gönder
]);
?>
