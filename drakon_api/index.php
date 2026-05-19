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
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'db_connection']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY']);
    exit;
}

// Gelen veriyi logla
file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - GELEN: " . json_encode($input) . "\n", FILE_APPEND);

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// ============ KULLANICI BULMA STRATEJİSİ ============
$balance = 0;
$realUserId = 0;

try {
    // 1. Önce gelen ID ile ara (direkt)
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
            file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - ID İLE BULUNDU: ID=$realUserId, Bakiye=$balance\n", FILE_APPEND);
        }
    }
    
    // 2. ID ile bulunamadıysa username ile ara
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
            file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - USERNAME İLE BULUNDU: ID=$realUserId, Bakiye=$balance\n", FILE_APPEND);
        }
    }
    
    // 3. Hala bulunamadıysa, admin tablosundaki İLK kullanıcıyı al (TEST AMAÇLI)
    if ($realUserId == 0) {
        $stmt = $pdo->query("SELECT id, bakiye, username FROM admin LIMIT 1");
        $firstUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($firstUser) {
            $balance = (float)$firstUser['bakiye'];
            $realUserId = $firstUser['id'];
            file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - İLK KULLANICI ALINDI: ID=$realUserId, Bakiye=$balance\n", FILE_APPEND);
        }
    }
    
} catch (PDOException $e) {
    file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - DB HATASI: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Kullanıcı bulunamadıysa 0 döndürme, test için sabit bakiye döndür
if ($realUserId == 0) {
    // TEST MODU: Sabit bakiye döndür (10000 TL)
    echo json_encode([
        'balance' => '10000.00',
        'currency_code' => $currency
    ]);
    exit;
}

$responseBalance = $balance;

// İşlemleri yap
if ($method == 'transaction_bet' && $betAmount > 0 && $realUserId > 0) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
            file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - BAHIS: ID=$realUserId, Miktar=$betAmount, YeniBakiye=$responseBalance\n", FILE_APPEND);
        } catch (PDOException $e) {}
    }
}
elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - KAZANC: ID=$realUserId, Miktar=$winAmount, YeniBakiye=$responseBalance\n", FILE_APPEND);
    } catch (PDOException $e) {}
}
elseif ($method == 'refund' && $betAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $betAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {}
}

// Logla
file_put_contents('oyun_log.txt', date('Y-m-d H:i:s') . " - RESPONSE: balance=$responseBalance, method=$method\n", FILE_APPEND);

echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
