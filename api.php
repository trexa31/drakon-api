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

// ============ DDOS KORUMA AYARLARI ============
$RATE_LIMIT = 60;           // Dakikada maksimum istek
$BAN_DURATION = 3600;       // Banlama süresi (saniye) - 1 saat
$WARNING_LIMIT = 30;        // Uyarı limiti (dakikada)

// İstek yapan IP'yi al
$userIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$userIP = explode(',', $userIP)[0]; // Çoklu IP varsa ilkini al
$requestTime = time();
$requestDate = date('Y-m-d H:i:s');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ============ İSTEK LOG TABLOSUNU OLUŞTUR (EĞER YOKSA) ============
    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ip` varchar(45) NOT NULL,
        `method` varchar(50) DEFAULT NULL,
        `user_id` int(11) DEFAULT NULL,
        `request_time` datetime NOT NULL,
        `timestamp` int(11) NOT NULL,
        `user_agent` text DEFAULT NULL,
        `request_data` text DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `ip` (`ip`),
        KEY `timestamp` (`timestamp`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_rate_limit` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ip` varchar(45) NOT NULL,
        `minute_timestamp` int(11) NOT NULL,
        `request_count` int(11) NOT NULL DEFAULT 1,
        `banned_until` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ip_minute` (`ip`, `minute_timestamp`),
        KEY `banned_until` (`banned_until`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_stats` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `stat_date` date NOT NULL,
        `total_requests` int(11) NOT NULL DEFAULT 0,
        `unique_ips` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `stat_date` (`stat_date`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    
    // ============ DDOS KONTROLÜ ============
    $currentMinute = floor($requestTime / 60);
    
    // IP'nin banlı olup olmadığını kontrol et
    $stmt = $pdo->prepare("SELECT banned_until FROM api_rate_limit WHERE ip = :ip AND banned_until > :now LIMIT 1");
    $stmt->execute(['ip' => $userIP, 'now' => $requestTime]);
    $banned = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($banned) {
        $banRemaining = $banned['banned_until'] - $requestTime;
        echo json_encode([
            'status' => 0,
            'error' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'Too many requests. Please try again later.',
            'ban_remaining_seconds' => $banRemaining,
            'ban_remaining_minutes' => ceil($banRemaining / 60)
        ]);
        exit;
    }
    
    // İstatistikleri güncelle
    $pdo->exec("INSERT INTO api_stats (stat_date, total_requests, unique_ips) 
                VALUES (CURDATE(), 1, 1) 
                ON DUPLICATE KEY UPDATE 
                total_requests = total_requests + 1,
                unique_ips = (SELECT COUNT(DISTINCT ip) FROM api_requests WHERE DATE(request_time) = CURDATE())");
    
    // Rate limit kontrolü
    $stmt = $pdo->prepare("INSERT INTO api_rate_limit (ip, minute_timestamp, request_count) 
                           VALUES (:ip, :minute, 1) 
                           ON DUPLICATE KEY UPDATE 
                           request_count = request_count + 1");
    $stmt->execute(['ip' => $userIP, 'minute' => $currentMinute]);
    
    // İstek sayısını al
    $stmt = $pdo->prepare("SELECT request_count FROM api_rate_limit WHERE ip = :ip AND minute_timestamp = :minute");
    $stmt->execute(['ip' => $userIP, 'minute' => $currentMinute]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    $requestCount = $count['request_count'];
    
    // Limit aşıldı mı?
    if ($requestCount > $RATE_LIMIT) {
        // IP'yi banla
        $stmt = $pdo->prepare("UPDATE api_rate_limit SET banned_until = :ban_until WHERE ip = :ip AND minute_timestamp = :minute");
        $stmt->execute(['ip' => $userIP, 'minute' => $currentMinute, 'ban_until' => $requestTime + $BAN_DURATION]);
        
        echo json_encode([
            'status' => 0,
            'error' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'Rate limit exceeded. You have been banned.',
            'ban_duration_minutes' => ($BAN_DURATION / 60)
        ]);
        exit;
    }
    
    // Uyarı seviyesi
    $warning = ($requestCount > $WARNING_LIMIT) ? true : false;
    
    // ============ İSTEĞİ LOGLA ============
    $input = json_decode(file_get_contents('php://input'), true);
    $method = isset($input['method']) ? $input['method'] : '';
    $userId_log = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    
    $stmt = $pdo->prepare("INSERT INTO api_requests (ip, method, user_id, request_time, timestamp, user_agent, request_data) 
                           VALUES (:ip, :method, :user_id, :request_time, :timestamp, :user_agent, :request_data)");
    $stmt->execute([
        'ip' => $userIP,
        'method' => $method,
        'user_id' => $userId_log,
        'request_time' => $requestDate,
        'timestamp' => $requestTime,
        'user_agent' => $userAgent,
        'request_data' => json_encode($input)
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['status' => 0, 'error' => 'DB_CONNECTION_FAILED']);
    exit;
}

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

// ============ İSTATİSTİK BİLGİLERİNİ AL (DEBUG İÇİN) ============
// Sadece GET isteğinde veya özel bir parametrede göster
$showStats = (isset($_GET['stats']) || isset($input['show_stats'])) ? true : false;

if ($showStats) {
    // Toplam istek istatistikleri
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM api_requests");
    $totalRequests = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT ip) as unique_ips FROM api_requests");
    $uniqueIPs = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT ip, COUNT(*) as count FROM api_requests GROUP BY ip ORDER BY count DESC LIMIT 10");
    $topIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT DATE(request_time) as date, COUNT(*) as count FROM api_requests GROUP BY DATE(request_time) ORDER BY date DESC LIMIT 7");
    $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as banned FROM api_rate_limit WHERE banned_until > " . time());
    $bannedIPs = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'stats' => [
            'total_requests' => $totalRequests['total'],
            'unique_ips' => $uniqueIPs['unique_ips'],
            'banned_ips' => $bannedIPs['banned'],
            'warning' => $warning,
            'current_minute_requests' => $requestCount,
            'rate_limit' => $RATE_LIMIT,
            'top_ips' => $topIPs,
            'daily_stats' => $dailyStats,
            'your_ip' => $userIP
        ]
    ]);
    exit;
}

// ============ NORMAL API İŞLEMLERİ ============
try {
    switch ($method) {
        case 'account_details':
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
            if ($betAmount <= 0) {
                echo json_encode(['status' => false, 'error' => 'NO_AMOUNT']);
                break;
            }
            
            $stmt = $pdo->prepare("SELECT id FROM transactions WHERE transaction_id = :tid LIMIT 1");
            $stmt->execute(['tid' => $transactionId]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['status' => true, 'balance' => number_format((float)$user['bakiye'], 2, '.', '')]);
                break;
            }
            
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
            
            $newBalance = (float)$user['bakiye'] - $betAmount;
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
            
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
            if ($winAmount <= 0) {
                echo json_encode(['status' => false, 'error' => 'NO_AMOUNT']);
                break;
            }
            
            $stmt = $pdo->prepare("SELECT id FROM transactions WHERE transaction_id = :tid LIMIT 1");
            $stmt->execute(['tid' => $transactionId]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['status' => true, 'balance' => number_format((float)$user['bakiye'], 2, '.', '')]);
                break;
            }
            
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['status' => false, 'error' => 'INVALID_USER']);
                break;
            }
            
            $newBalance = (float)$user['bakiye'] + $winAmount;
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $newBalance, 'id' => $userId]);
            
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
            if ($betAmount <= 0) {
                echo json_encode(['status' => false, 'error' => 'NO_AMOUNT']);
                break;
            }
            
            $stmt = $pdo->prepare("SELECT id FROM transactions WHERE transaction_id = :tid LIMIT 1");
            $stmt->execute(['tid' => $transactionId]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['status' => true, 'balance' => number_format((float)$user['bakiye'], 2, '.', '')]);
                break;
            }
            
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['status' => false, 'error' => 'INVALID_USER']);
                break;
            }
            
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
