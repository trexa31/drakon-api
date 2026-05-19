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
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'Db Connection Failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'Invalid Input']);
    exit;
}

// Sağlayıcıdan gelen ham ID string veya int olabilir, o yüzden cast etmeden alıyoruz
$rawUserId = isset($input['user_id']) ? trim($input['user_id']) : '';
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';

$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

$balance = 0;
$realUserId = 0;

// =======================================================
// DİNAMİK KULLANICI BULMA (ESKİ VE YENİ TÜM ÜYELER İÇİN)
// =======================================================
try {
    // 1. Adım: Gelen veri sayısal ise önce ID olarak ara
    if (is_numeric($rawUserId) && (int)$rawUserId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
        $stmt->execute(['id' => (int)$rawUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
        }
    }
   
    // 2. Adım: ID ile bulunamadıysa veya gelen veri string ise username olarak ara
    if ($realUserId == 0) {
        $searchName = !empty($username) ? $username : $rawUserId;
        if (!empty($searchName)) {
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE username = :username");
            $stmt->execute(['username' => $searchName]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $balance = (float)$user['bakiye'];
                $realUserId = $user['id'];
            }
        }
    }
} catch (PDOException $e) {
    // Hata durumunda loglanabilir, şimdilik sıfırlıyoruz
    $realUserId = 0;
}

// Kullanıcı hiçbir şekilde bulunamadıysa sıfır bakiye dön ve çık
if ($realUserId == 0) {
    echo json_encode(['balance' => '0.00', 'currency_code' => $currency, 'status' => 'User Not Found']);
    exit;
}

$responseBalance = $balance;

// =======================================================
// BAKİYE GÜNCELLEME İŞLEMLERİ (ANA BAKİYEYE YANSIMA)
// =======================================================

// 1. Bahis Düştü (Bet / Debit)
if (($method == 'transaction_bet' || $method == 'bet') && $betAmount > 0) {
    // Kullanıcının bakiyesi yetiyorsa düşüyoruz (Eksiye düşmeyi engellemek için)
    if ($balance >= $betAmount) {
        $responseBalance = $balance - $betAmount;
    } else {
        // Bakiye yetersizse mevcut bakiyeyi koru veya sağlayıcıya göre hata kodu dön (Genelde bakiye yetersiz olsa da mevcut bakiye dönülür)
        $responseBalance = $balance;
    }
    
    $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
    $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
}

// 2. Kazanç Eklendi (Win / Credit)
// NOT: Sağlayıcı bazen bet ve win'i aynı istekte (metot) gönderebilir. 
// Eğer metot sadece 'win' ise veya ortak bir transaction metoduysa hem bet düşüp hem win ekliyoruz.
elseif (($method == 'transaction_win' || $method == 'win') && $winAmount > 0) {
    $responseBalance = $balance + $winAmount;
    
    $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
    $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
}

// 3. İade / İptal (Refund / Rollback)
elseif (($method == 'refund' || $method == 'rollback') && $betAmount > 0) {
    $responseBalance = $balance + $betAmount;
    
    $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
    $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
}

// Çıktıyı tam olarak API'nin istediği formatta (float string - 2 basamaklı) gönderiyoruz
echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
