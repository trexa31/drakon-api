<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Veritabanı bağlantısı
$host = 'sql100.infinityfree.com';
$dbname = 'if0_41958317_c4k';
$dbuser = 'if0_41958317';
$dbpass = '3vwx7wgwM7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY']);
    exit;
}

// Gelen veriyi al
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY']);
    exit;
}

$userId = $input['user_id'] ?? 0;
$username = $input['username'] ?? '';
$method = $input['method'] ?? 'user_balance';
$currency = $input['currency_code'] ?? 'TRY';
$betAmount = $input['bet'] ?? $input['amount'] ?? 0;
$winAmount = $input['win'] ?? $input['amount'] ?? 0;

// Kullanıcıyı BUL (ID veya kullanıcı adı ile)
$balance = 0;
$realUserId = 0;

try {
    // Önce ID ile ara
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float) $user['bakiye'];
            $realUserId = $user['id'];
        }
    }
    
    // ID ile bulunamadıysa kullanıcı adı ile ara
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE username = :username OR kullanici_adi = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float) $user['bakiye'];
            $realUserId = $user['id'];
        }
    }
    
    // Hala bulunamadıysa betsapi'yi dene (test için)
    if ($realUserId == 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE username = 'betsapi'");
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float) $user['bakiye'];
            $realUserId = $user['id'];
        }
    }
} catch (PDOException $e) {
    $balance = 0;
}

// İşlemleri gerçekleştir
$response = ['balance' => number_format($balance, 2, '.', ''), 'currency_code' => $currency];

if ($method == 'transaction_bet' && $betAmount > 0 && $realUserId > 0) {
    if ($betAmount <= $balance) {
        $newBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $newBalance, 'id' => $realUserId]);
            $response['balance'] = number_format($newBalance, 2, '.', '');
        } catch (PDOException $e) {}
    }
} 
elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $newBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $newBalance, 'id' => $realUserId]);
        $response['balance'] = number_format($newBalance, 2, '.', '');
    } catch (PDOException $e) {}
}

echo json_encode($response);
?>
