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

// Gelen veriyi al (her durumda çalışsın)
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Eğer JSON geçersizse veya boşsa, sadece bakiye sorgula
if (!$input || !isset($input['user_id'])) {
    // Test amaçlı varsayılan kullanıcı
    $userId = 1555;
    $method = 'user_balance';
    $currency = 'TRY';
    $betAmount = 0;
    $winAmount = 0;
    $refundAmount = 0;
} else {
    $userId = $input['user_id'] ?? 1555;
    $method = $input['method'] ?? 'user_balance';
    $currency = $input['currency_code'] ?? 'TRY';
    $betAmount = $input['bet'] ?? $input['amount'] ?? 0;
    $winAmount = $input['win'] ?? $input['amount'] ?? 0;
    $refundAmount = $input['amount'] ?? 0;
}

// Kullanıcı bakiyesini al
$balance = 0;
try {
    $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $balance = (float) $user['bakiye'];
    }
} catch (PDOException $e) {
    $balance = 0;
}

// İşlemleri gerçekleştir
$newBalance = $balance;
$response = ['balance' => number_format($balance, 2, '.', ''), 'currency_code' => $currency];

if ($method == 'transaction_bet' && $betAmount > 0) {
    if ($betAmount <= $balance) {
        $newBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
            $response['balance'] = number_format($newBalance, 2, '.', '');
        } catch (PDOException $e) {}
    }
} 
elseif ($method == 'transaction_win' && $winAmount > 0) {
    $newBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
        $response['balance'] = number_format($newBalance, 2, '.', '');
    } catch (PDOException $e) {}
}
elseif ($method == 'refund' && $refundAmount > 0) {
    $newBalance = $balance + $refundAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
        $response['balance'] = number_format($newBalance, 2, '.', '');
    } catch (PDOException $e) {}
}

echo json_encode($response);
?>
