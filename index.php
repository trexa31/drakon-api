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

$host = 'sql100.infinityfree.com';
$dbname = 'if0_41734721_trexa';
$user = 'if0_41734721';
$pass = '3vwx7wgwM7';

if ($userId > 0) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && isset($result['bakiye'])) {
            $balance = (float)$result['bakiye'];
        }
    } catch (Exception $e) {}
}

// SAĞLAYICININ BEKLEDİĞİ FORMAT (İngilizce)
switch ($method) {
    case 'transaction_bet':
        $betAmount = $data['bet'] ?? $data['amount'] ?? 0;
        $balance = max(0, $balance - $betAmount);
        echo json_encode(['balance' => number_format($balance, 2, '.', '')]);
        break;
    case 'transaction_win':
        $winAmount = $data['win'] ?? $data['amount'] ?? 0;
        $balance = $balance + $winAmount;
        echo json_encode(['balance' => number_format($balance, 2, '.', '')]);
        break;
    case 'refund':
        $refundAmount = $data['amount'] ?? 0;
        $balance = $balance + $refundAmount;
        echo json_encode(['balance' => number_format($balance, 2, '.', '')]);
        break;
    default:
        // user_balance için beklenen format
        echo json_encode([
            'balance' => number_format($balance, 2, '.', ''),
            'currency_code' => $currency
        ]);
        break;
}
?>
