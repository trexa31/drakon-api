<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

$userId = $data['user_id'] ?? $data['id'] ?? 0;
$method = $data['method'] ?? '';
$currency = $data['currency_code'] ?? 'TRY';

// Veritabanı bağlantısı - C4KBET veritabanı
$host = 'sql100.infinityfree.com';
$dbname = 'if0_41958317_c4k';
$user = 'if0_41958317';
$pass = '3vwx7wgwM7';

$balance = 0;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($userId > 0) {
        // admin tablosunda ara (tüm kullanıcılar burada)
        $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $balance = (float)$result['bakiye'];
        }
    }
} catch (PDOException $e) {
    // Hata olursa bakiye 0 döndür
    $balance = 0;
}

// İşlem varsa güncelle
if ($userId > 0 && ($method == 'transaction_bet' || $method == 'transaction_win' || $method == 'refund')) {
    
    if ($method == 'transaction_bet') {
        $betAmount = $data['bet'] ?? $data['amount'] ?? 0;
        $balance = max(0, $balance - $betAmount);
    } elseif ($method == 'transaction_win') {
        $winAmount = $data['win'] ?? $data['amount'] ?? 0;
        $balance = $balance + $winAmount;
    } elseif ($method == 'refund') {
        $refundAmount = $data['amount'] ?? 0;
        $balance = $balance + $refundAmount;
    }
    
    try {
        $updateStmt = $pdo->prepare("UPDATE admin SET bakiye = ? WHERE id = ?");
        $updateStmt->execute([$balance, $userId]);
    } catch (PDOException $e) {
        // Güncelleme hatası olursa sessiz geç
    }
}

echo json_encode([
    'balance' => number_format($balance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
