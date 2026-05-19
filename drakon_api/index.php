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
    echo json_encode(['status' => 0, 'error' => 'DB_CONNECTION_FAILED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 0, 'error' => 'INVALID_INPUT']);
    exit;
}

$method = isset($input['method']) ? $input['method'] : '';
$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';

// İşlem ID'leri (idempotent kontrolü için)
$transactionId = isset($input['transaction_id']) ? $input['transaction_id'] : '';
$sessionId = isset($input['session_id']) ? $input['session_id'] : '';
$roundId = isset($input['round_id']) ? $input['round_id'] : '';
$game = isset($input['game']) ? $input['game'] : '';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// ============ ID DÖNÜŞÜM TABLOSU (ÇOK ÖNEMLİ!) ============
// Test ID'lerini gerçek admin ID'lerine çevir
$idMapping = [
    1 => 1630,      // Test ID'si 1 -> Emre Aydemir (ID:1630)
    1555 => 1630,   // Test ID'si 1555 -> Emre Aydemir
    1629 => 1629,   // Yiğit can Gündüz
    1628 => 1628,   // mert aydogan
];

if (isset($idMapping[$userId])) {
    $userId = $idMapping[$userId];
}

// ============ İŞLEMLER ============
try {
    switch ($method) {
        case 'account_details':
            // Kullanıcı detaylarını döndür
            $stmt = $pdo->prepare("SELECT id, username, email FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                echo json_encode([
                    'email' => $user['email'],
                    'name_jogador' => $user['username']
                ]);
            } else {
                echo json_encode(['status' => false, 'error' => 'INVALID_USER']);
            }
            break;
            
        case 'user_balance':
            // Kullanıcı bakiyesini döndür (DOKÜMANTA GÖRE: status ve balance)
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                echo json_encode([
                    'status' => 1,
                    'balance' => number_format((float)$user['bakiye'], 2, '.', '')
                ]);
            } else {
                echo json_encode(['status' => 0, 'error' => 'INVALID_USER']);
            }
            break;
            
        case 'transaction_bet':
            // Bahis işlemi
            if ($betAmount <= 0) {
                echo json_encode(['status' => false, 'error' => 'NO_AMOUNT']);
                break;
            }
            
            // İdempotent kontrol - aynı transaction_id daha önce işlendi mi?
            $stmt = $pdo->prepare("SELECT id FROM transactions WHERE transaction_id = :tid LIMIT 1");
            $stmt->execute(['tid' => $transactionId]);
            if ($stmt->rowCount() > 0) {
                // Daha önce işlenmiş, sadece bakiyeyi döndür
                $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['status' => true, 'balance' => number_format((float)$user['bakiye'], 2, '.', '')]);
                break;
            }
            
            // Bakiyeyi kontrol et
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['status' => false, 'error' => 'INVALID_USER']);
                break;
            }
            
            if ((float)$user['bakiye'] < $betAmount) {
                echo json_encode(['status' => false, 'error' => 'NO_BALANCE']);
                break;
            }
            
            // Bakiyeyi düş
            $newBalance = (float)$user['bakiye'] - $betAmount;
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
            
            // İşlemi logla (idempotent için)
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, transaction_id, session_id, round_id, game, type, amount, balance_after, created_at) VALUES (:uid, :tid, :sid, :rid, :game, 'bet', :amount, :balance, NOW())");
            $stmt->execute([
                'uid' => $userId,
                'tid' => $transactionId,
                'sid' => $sessionId,
                'rid' => $roundId,
                'game' => $game,
                'amount' => $betAmount,
                'balance' => $newBalance
            ]);
            
            echo json_encode([
                'status' => true,
                'balance' => number_format($newBalance, 2, '.', '')
            ]);
            break;
            
        case 'transaction_win':
            // Kazanç işlemi
            if ($winAmount <= 0) {
                echo json_encode(['status' => false, 'error' => 'NO_AMOUNT']);
                break;
            }
            
            // İdempotent kontrol
            $stmt = $pdo->prepare("SELECT id FROM transactions WHERE transaction_id = :tid LIMIT 1");
            $stmt->execute(['tid' => $transactionId]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['status' => true, 'balance' => number_format((float)$user['bakiye'], 2, '.', '')]);
                break;
            }
            
            // Bakiyeyi bul
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['status' => false, 'error' => 'INVALID_USER']);
                break;
            }
            
            // Bakiyeyi artır
            $newBalance = (float)$user['bakiye'] + $winAmount;
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
            
            // İşlemi logla
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, transaction_id, session_id, round_id, game, type, amount, balance_after, created_at) VALUES (:uid, :tid, :sid, :rid, :game, 'win', :amount, :balance, NOW())");
            $stmt->execute([
                'uid' => $userId,
                'tid' => $transactionId,
                'sid' => $sessionId,
                'rid' => $roundId,
                'game' => $game,
                'amount' => $winAmount,
                'balance' => $newBalance
            ]);
            
            echo json_encode([
                'status' => true,
                'balance' => number_format($newBalance, 2, '.', '')
            ]);
            break;
            
        case 'refund':
            // İade işlemi
            if ($betAmount <= 0) {
                echo json_encode(['status' => false, 'error' => 'NO_AMOUNT']);
                break;
            }
            
            // İdempotent kontrol
            $stmt = $pdo->prepare("SELECT id FROM transactions WHERE transaction_id = :tid LIMIT 1");
            $stmt->execute(['tid' => $transactionId]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['status' => true, 'balance' => number_format((float)$user['bakiye'], 2, '.', '')]);
                break;
            }
            
            // Bakiyeyi bul
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['status' => false, 'error' => 'INVALID_USER']);
                break;
            }
            
            // Bakiyeyi geri ekle
            $newBalance = (float)$user['bakiye'] + $betAmount;
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
            
            echo json_encode([
                'status' => true,
                'balance' => number_format($newBalance, 2, '.', '')
            ]);
            break;
            
        default:
            echo json_encode(['status' => false, 'error' => 'INVALID_METHOD']);
            break;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'error' => 'DATABASE_ERROR']);
}
?>
