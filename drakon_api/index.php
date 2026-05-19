<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Veritabanı bağlantı ayarları
$host = 'sql100.infinityfree.com';
$dbname = 'if0_41958317_c4k';
$dbuser = 'if0_41958317';
$dbpass = '3vwx7wgwM7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Gelen veriyi al
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

$method = $input['method'] ?? null;
$userId = $input['user_id'] ?? null;
$currency = $input['currency_code'] ?? 'TRY';

if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'user_id is required']);
    exit;
}

// Kullanıcı bakiyesini al
$stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

$balance = (float) $user['bakiye'];
$response = ['balance' => number_format($balance, 2, '.', ''), 'currency_code' => $currency];

// İşlem tipine göre bakiyeyi güncelle ve KAYDET
switch ($method) {
    case 'transaction_bet':
        $amount = $input['bet'] ?? $input['amount'] ?? 0;
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid bet amount']);
            exit;
        }
        if ($amount > $balance) {
            http_response_code(400);
            echo json_encode(['error' => 'Insufficient balance']);
            exit;
        }
        $newBalance = $balance - $amount;
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
        $response['balance'] = number_format($newBalance, 2, '.', '');
        break;

    case 'transaction_win':
        $amount = $input['win'] ?? $input['amount'] ?? 0;
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid win amount']);
            exit;
        }
        $newBalance = $balance + $amount;
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
        $response['balance'] = number_format($newBalance, 2, '.', '');
        break;

    case 'refund':
        $amount = $input['amount'] ?? 0;
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid refund amount']);
            exit;
        }
        $newBalance = $balance + $amount;
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
        $response['balance'] = number_format($newBalance, 2, '.', '');
        break;

    case 'user_balance':
    default:
        // Sadece bakiye sorgulama, işlem yok
        break;
}

// Başarılı yanıt
http_response_code(200);
echo json_encode($response);
?>
