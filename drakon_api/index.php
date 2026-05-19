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
$dbpass = '3vwx7wgwM7';

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
    
    // *** EK: Oyun tablosunda da kullanıcının kaydı var mı kontrol et ***
    if ($realUserId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM user_balances WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $realUserId]);
        $gameUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$gameUser) {
            // Oyun tablosunda yoksa oluştur
            $stmt = $pdo->prepare("INSERT INTO user_balances (user_id, balance, currency_code) VALUES (:user_id, :balance, :currency)");
            $stmt->execute([
                'user_id' => $realUserId,
                'balance' => $balance,
                'currency' => $currency
            ]);
        } else {
            // Oyun tablosundaki bakiyeyi admin tablosuyla senkronize et
            $stmt = $pdo->prepare("SELECT balance FROM user_balances WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $realUserId]);
            $gameBalance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($gameBalance && (float)$gameBalance['balance'] != $balance) {
                // Senkronizasyon: oyun bakiyesini admin bakiyesine eşitle
                $stmt = $pdo->prepare("UPDATE user_balances SET balance = :balance WHERE user_id = :user_id");
                $stmt->execute(['balance' => $balance, 'user_id' => $realUserId]);
            }
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
            // 1. Admin tablosunu güncelle
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
            
            // 2. Oyun tablosunu güncelle (BURASI ÖNEMLİ!)
            $stmt = $pdo->prepare("UPDATE user_balances SET balance = :balance WHERE user_id = :user_id");
            $stmt->execute(['balance' => $responseBalance, 'user_id' => $realUserId]);
            
            // 3. İşlem log'u ekle (opsiyonel)
            $stmt = $pdo->prepare("INSERT INTO transaction_logs (user_id, type, amount, balance_before, balance_after, created_at) VALUES (:user_id, 'bet', :amount, :balance_before, :balance_after, NOW())");
            $stmt->execute([
                'user_id' => $realUserId,
                'amount' => $betAmount,
                'balance_before' => $balance,
                'balance_after' => $responseBalance
            ]);
            
        } catch (PDOException $e) {
            // Hata durumunda geri al
            $responseBalance = $balance;
        }
    } else {
        // Yetersiz bakiye
        echo json_encode([
            'balance' => number_format($balance, 2, '.', ''),
            'currency_code' => $currency,
            'error' => 'Insufficient balance'
        ]);
        exit;
    }
}
// Kazanç işlemi
elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $winAmount;
    try {
        // 1. Admin tablosunu güncelle
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        
        // 2. Oyun tablosunu güncelle (BURASI ÖNEMLİ!)
        $stmt = $pdo->prepare("UPDATE user_balances SET balance = :balance WHERE user_id = :user_id");
        $stmt->execute(['balance' => $responseBalance, 'user_id' => $realUserId]);
        
        // 3. İşlem log'u ekle
        $stmt = $pdo->prepare("INSERT INTO transaction_logs (user_id, type, amount, balance_before, balance_after, created_at) VALUES (:user_id, 'win', :amount, :balance_before, :balance_after, NOW())");
        $stmt->execute([
            'user_id' => $realUserId,
            'amount' => $winAmount,
            'balance_before' => $balance,
            'balance_after' => $responseBalance
        ]);
        
    } catch (PDOException $e) {}
}
// İade işlemi
elseif ($method == 'refund' && $betAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $betAmount;
    try {
        // 1. Admin tablosunu güncelle
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        
        // 2. Oyun tablosunu güncelle (BURASI ÖNEMLİ!)
        $stmt = $pdo->prepare("UPDATE user_balances SET balance = :balance WHERE user_id = :user_id");
        $stmt->execute(['balance' => $responseBalance, 'user_id' => $realUserId]);
        
    } catch (PDOException $e) {}
}

// Eğer sadece bakiye sorgusu ise (method = user_balance)
if ($method == 'user_balance' && $realUserId > 0) {
    // Oyun tablosundan güncel bakiyeyi al
    try {
        $stmt = $pdo->prepare("SELECT balance FROM user_balances WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $realUserId]);
        $gameBalance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($gameBalance) {
            $responseBalance = (float)$gameBalance['balance'];
        } else {
            // Oyun tablosunda kayıt yoksa oluştur
            $stmt = $pdo->prepare("INSERT INTO user_balances (user_id, balance, currency_code) VALUES (:user_id, :balance, :currency)");
            $stmt->execute([
                'user_id' => $realUserId,
                'balance' => $balance,
                'currency' => $currency
            ]);
            $responseBalance = $balance;
        }
    } catch (PDOException $e) {
        $responseBalance = $balance;
    }
}

echo json_encode([
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
