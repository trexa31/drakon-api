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

$userId = $data['user_id'] ?? 0;
$currency = $data['currency_code'] ?? 'TRY';
$method = $data['method'] ?? '';
$balance = 100.00;

// ============ VERİTABANI BAĞLANTISI ============
$host = 'sql100.infinityfree.com';
$dbname = 'if0_41734721_trexa';
$user = 'if0_41734721';
$pass = '3vwx7wgwM7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && isset($result['bakiye'])) {
            $balance = (float)$result['bakiye'];
        }
    }
} catch (PDOException $e) {
    // Hata olursa log tut ama çalışmaya devam et
    error_log("DB Error: " . $e->getMessage());
}
// ============================================

// METHOD BAZLI İŞLEMLER
switch ($method) {
    case 'transaction_bet':
        $betAmount = $data['bet'] ?? $data['amount'] ?? 0;
        $newBalance = $balance - $betAmount;
        if ($newBalance < 0) $newBalance = 0;
        
        // Veritabanını güncelle
        if ($userId > 0) {
            try {
                $updateStmt = $pdo->prepare("UPDATE admin SET bakiye = ? WHERE id = ?");
                $updateStmt->execute([$newBalance, $userId]);
            } catch (PDOException $e) {}
        }
        echo json_encode(['balance' => number_format($newBalance, 2, '.', '')]);
        break;
        
    case 'transaction_win':
        $winAmount = $data['win'] ?? $data['amount'] ?? 0;
        $newBalance = $balance + $winAmount;
        
        // Veritabanını güncelle
        if ($userId > 0) {
            try {
                $updateStmt = $pdo->prepare("UPDATE admin SET bakiye = ? WHERE id = ?");
                $updateStmt->execute([$newBalance, $userId]);
            } catch (PDOException $e) {}
        }
        echo json_encode(['balance' => number_format($newBalance, 2, '.', '')]);
        break;
        
    case 'refund':
        $refundAmount = $data['amount'] ?? 0;
        $newBalance = $balance + $refundAmount;
        
        // Veritabanını güncelle
        if ($userId > 0) {
            try {
                $updateStmt = $pdo->prepare("UPDATE admin SET bakiye = ? WHERE id = ?");
                $updateStmt->execute([$newBalance, $userId]);
            } catch (PDOException $e) {}
        }
        echo json_encode(['balance' => number_format($newBalance, 2, '.', '')]);
        break;
        
    default:
        // user_balance - sadece bakiye sorgula
        echo json_encode([
            'balance' => number_format($balance, 2, '.', ''),
            'currency_code' => $currency
        ]);
        break;
}
?>
