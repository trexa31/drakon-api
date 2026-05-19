<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============ VERİTABANI BİLGİLERİ - BUNLARI KONTROL ET! ============
$host = 'sql206.infinityfree.com';     // InfinityFree'dan kontrol et
$dbname = 'if0_41958317_c4k';          // Doğru mu?
$dbuser = 'if0_41958317';              // Doğru mu?
$dbpass = 'fMvWLgjJWSf';               // ŞİFRENİ KONTROL ET!
// ===================================================================

// Veritabanı bağlantısını dene
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5); // 5 saniye timeout
} catch (PDOException $e) {
    // Hata mesajını logla ama oyuna düzgün format döndür
    error_log("DB Connection Error: " . $e->getMessage());
    
    // Oyun sağlayıcının beklediği FORMATTA hata döndür
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'balance' => '0.00',
        'currency_code' => 'TRY'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid input',
        'balance' => '0.00',
        'currency_code' => 'TRY'
    ]);
    exit;
}

// Log al
@file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " REQ: " . json_encode($input) . "\n", FILE_APPEND);

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// Kullanıcıyı bul
$balance = 0;
$realUserId = 0;

try {
    // Önce ID ile ara
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
        }
    }
    
    // ID ile bulunamadıysa username ile ara
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
        }
    }
    
    // Hala bulunamadıysa ve username varsa yeni kullanıcı oluştur
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("INSERT INTO admin (username, bakiye, created_at) VALUES (:username, 0, NOW())");
        $stmt->execute(['username' => $username]);
        $realUserId = $pdo->lastInsertId();
        $balance = 0;
    }
    
} catch (PDOException $e) {
    @file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database query failed',
        'balance' => '0.00',
        'currency_code' => $currency
    ]);
    exit;
}

// Kullanıcı bulunamadıysa
if ($realUserId == 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'User not found',
        'balance' => '0.00',
        'currency_code' => $currency
    ]);
    exit;
}

$responseBalance = $balance;

// İşlemleri yap
if ($method == 'transaction_bet' && $betAmount > 0) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        } catch (PDOException $e) {
            @file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " BET UPDATE ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Insufficient balance',
            'balance' => number_format($balance, 2, '.', ''),
            'currency_code' => $currency
        ]);
        exit;
    }
} 
elseif ($method == 'transaction_win' && $winAmount > 0) {
    $responseBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {}
} 
elseif ($method == 'refund' && $betAmount > 0) {
    $responseBalance = $balance + $betAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {}
}

// BAŞARILI YANIT - Oyunun beklediği format bu!
echo json_encode([
    'status' => 'success',
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
