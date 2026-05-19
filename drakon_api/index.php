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
$dbpass = '3vwx7wgwM7'; // UYARI: Bu şifreyi mutlaka değiştir kanka!

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'db_connection']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'invalid_input']);
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

// 1. ADIM: Kullanıcının ID'sini netleştir
$realUserId = 0;
try {
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $realUserId = $user['id'];
        }
    }
    
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $realUserId = $user['id'];
        }
    }
} catch (PDOException $e) {
    $realUserId = 0;
}

// Kullanıcı bulunamadıysa direkt 0 bakiye dön
if ($realUserId == 0) {
    echo json_encode(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'user_not_found']);
    exit;
}

// 2. ADIM: Bakiyeyi güncelleme işlemlerini (Matematiksel olarak) yap
try {
    // Önce kullanıcının mevcut bakiyesini çekelim (Kontrol için)
    $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
    $stmt->execute(['id' => $realUserId]);
    $currentBalance = (float)$stmt->fetchColumn();

    // Bahis işlemi
    if ($method == 'transaction_bet' && $betAmount > 0) {
        if ($currentBalance >= $betAmount) {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = bakiye - :bet WHERE id = :id");
            $stmt->execute(['bet' => $betAmount, 'id' => $realUserId]);
        }
    }
    // Kazanç işlemi
    elseif ($method == 'transaction_win' && $winAmount > 0) {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = bakiye + :win WHERE id = :id");
        $stmt->execute(['win' => $winAmount, 'id' => $realUserId]);
    }
    // İade işlemi
    elseif ($method == 'refund' && $betAmount > 0) {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = bakiye + :refund WHERE id = :id");
        $stmt->execute(['refund' => $betAmount, 'id' => $realUserId]);
    }
} catch (PDOException $e) {
    // Güncelleme sırasında hata olursa buraya düşer
}

// 3. ADIM: EN GÜNCEL BAKİYEYİ VERİTABANINDAN ÇEK VE API'YE GÖNDER
// En kritik yer burası. Yukarıdaki işlemlerden sonra veritabanında bakiye ne olduysa onu okuyoruz.
$finalBalance = 0.00;
try {
    $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
    $stmt->execute(['id' => $realUserId]);
    $finalBalance = (float)$stmt->fetchColumn();
} catch (PDOException $e) {
    $finalBalance = 0.00;
}

echo json_encode([
    'balance' => number_format($finalBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
