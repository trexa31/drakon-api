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
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'No input data']);
    exit;
}

// Log al (sorun çözülünce kaldır veya yorum satırı yap)
file_put_contents('api_log.txt', date('Y-m-d H:i:s') . " - INPUT: " . json_encode($input) . "\n", FILE_APPEND);

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// ============ ID DÖNÜŞÜMÜNÜ KALDIRIYORUZ - TÜM KULLANICILAR İÇİN ============
// Artık sadece gelen ID'nin admin tablosunda olup olmadığını kontrol et
// Eğer yoksa, username ile ara veya yeni kullanıcı oluştur
// ============================================================================

// Kullanıcıyı bul (önce ID ile, sonra username ile)
$balance = 0;
$realUserId = 0;
$userExists = false;

try {
    // 1. Önce ID ile ara
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username, email FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
            $userExists = true;
        }
    }
    
    // 2. ID ile bulunamadıysa username ile ara
    if (!$userExists && !empty($username)) {
        $stmt = $pdo->prepare("SELECT id, bakiye, username, email FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $balance = (float)$user['bakiye'];
            $realUserId = $user['id'];
            $userExists = true;
        }
    }
    
    // 3. Kullanıcı hala bulunamadıysa ve username varsa YENİ KULLANICI OLUŞTUR
    if (!$userExists && !empty($username)) {
        $stmt = $pdo->prepare("INSERT INTO admin (username, bakiye, email, created_at) VALUES (:username, 0, :email, NOW())");
        $stmt->execute([
            'username' => $username,
            'email' => $username . '@temp.com'
        ]);
        $realUserId = $pdo->lastInsertId();
        $balance = 0;
        $userExists = true;
        
        file_put_contents('api_log.txt', date('Y-m-d H:i:s') . " - YENİ KULLANICI OLUŞTURULDU: ID=$realUserId, Username=$username\n", FILE_APPEND);
    }
    
} catch (PDOException $e) {
    file_put_contents('api_log.txt', date('Y-m-d H:i:s') . " - DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    exit;
}

// Kullanıcı bulunamadıysa hata döndür
if (!$userExists) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'User not found',
        'provided_user_id' => $userId,
        'provided_username' => $username
    ]);
    exit;
}

$responseBalance = $balance;

// ============ İŞLEM TİPLERİ ============
switch ($method) {
    case 'user_balance':
        // Sadece bakiye sorgulama
        echo json_encode([
            'status' => 'success',
            'balance' => number_format($responseBalance, 2, '.', ''),
            'currency_code' => $currency,
            'user_id' => $realUserId
        ]);
        break;
        
    case 'transaction_bet':
        if ($betAmount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid bet amount']);
            break;
        }
        
        if ($betAmount > $balance) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Insufficient balance',
                'balance' => number_format($balance, 2, '.', '')
            ]);
            break;
        }
        
        $responseBalance = $balance - $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
            
            file_put_contents('api_log.txt', date('Y-m-d H:i:s') . " - BET: User=$realUserId, Amount=$betAmount, Old=$balance, New=$responseBalance\n", FILE_APPEND);
            
            echo json_encode([
                'status' => 'success',
                'balance' => number_format($responseBalance, 2, '.', ''),
                'currency_code' => $currency
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database update failed']);
        }
        break;
        
    case 'transaction_win':
        if ($winAmount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid win amount']);
            break;
        }
        
        $responseBalance = $balance + $winAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
            
            file_put_contents('api_log.txt', date('Y-m-d H:i:s') . " - WIN: User=$realUserId, Amount=$winAmount, Old=$balance, New=$responseBalance\n", FILE_APPEND);
            
            echo json_encode([
                'status' => 'success',
                'balance' => number_format($responseBalance, 2, '.', ''),
                'currency_code' => $currency
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database update failed']);
        }
        break;
        
    case 'refund':
        if ($betAmount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid refund amount']);
            break;
        }
        
        $responseBalance = $balance + $betAmount;
        try {
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
            
            file_put_contents('api_log.txt', date('Y-m-d H:i:s') . " - REFUND: User=$realUserId, Amount=$betAmount, Old=$balance, New=$responseBalance\n", FILE_APPEND);
            
            echo json_encode([
                'status' => 'success',
                'balance' => number_format($responseBalance, 2, '.', ''),
                'currency_code' => $currency
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database update failed']);
        }
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Unknown method: ' . $method
        ]);
        break;
}
?>
