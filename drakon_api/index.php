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
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY']);
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
// Oyun sağlayıcının gönderdiği ID'yi, admin tablosundaki doğru ID'ye çevir
$idMapping = [
    1 => 1630, // Eğer gelen ID 1 ise, Emre Aydemir (ID:1630) olarak işlem yap
    1555 => 1630, // Eğer gelen ID 1555 ise, yine Emre'ye çevir
    1629 => 1629, // Yiğit can Gündüz
    1628 => 1628, // mert aydogan
];
// ID dönüşümü uygula
if (isset($idMapping[$userId])) {
    $userId = $idMapping[$userId];
}
// ===========================================
// Kullanıcıyı bul (önce ID ile)
$balance = 0;
$realUserId = 0;
try {
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
        $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
        }
    }
} catch (PDOException $e) {
    $balance = 0;
}
$responseBalance = $balance;
// Bahis işlemi
if ($method == 'transaction_bet' && $betAmount > 0 && $realUserId > 0) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        } catch (PDOException $e) {}
    }
}
// Kazanç işlemi
elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $winAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {}
}
// İade işlemi
elseif ($method == 'refund' && $betAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $betAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {}
}
echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
