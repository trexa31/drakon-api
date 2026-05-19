<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============ YENİ VE DOĞRU VERİTABANI AYARLARI ============
$host = 'sql206.infinityfree.com';      // Yeni host
$dbname = 'if0_41958317_c4k';           // Database adı
$dbuser = 'if0_41958317';               // Kullanıcı adı
$dbpass = 'fMvWLgjJWSf';                // Yeni şifre
// ===========================================================

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage());
    echo json_encode([
        'balance' => '0.00', 
        'currency_code' => 'TRY',
        'error' => 'Database connection failed'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY']);
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

// Kullanıcıyı bul
$balance = 0;
$realUserId = 0;

try {
    // Önce ID ile ara
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
        }
    }
    
    // ID ile bulunamadıysa username ile ara
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
        }
    }
    
    // Kullanıcı hala bulunamadıysa demo kullanıcı oluştur (test için)
    if ($realUserId == 0) {
        // Test amaçlı geçici kullanıcı - silinebilir
        $balance = 1000.00;
        $realUserId = 999;
    }
    
} catch (PDOException $e) {
    error_log("Query Error: " . $e->getMessage());
    $balance = 0;
}

$responseBalance = $balance;

// Bahis işlemi
if ($method == 'transaction_bet' && $betAmount > 0 && $realUserId > 0 && $realUserId != 999) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        } catch (PDOException $e) {
            error_log("Bet Update Error: " . $e->getMessage());
        }
    }
}
// Kazanç işlemi
elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0 && $realUserId != 999) {
    $responseBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {
        error_log("Win Update Error: " . $e->getMessage());
    }
}
// İade işlemi
elseif ($method == 'refund' && $betAmount > 0 && $realUserId > 0 && $realUserId != 999) {
    $responseBalance = $balance + $betAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {
        error_log("Refund Update Error: " . $e->getMessage());
    }
}

// Sonuç döndür
echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
