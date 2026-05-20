<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============ VERİTABANI BAĞLANTISI ============
$host = 'sql206.infinityfree.com';
$dbname = 'if0_41958317_c4k';
$dbuser = 'if0_41958317';
$dbpass = 'fMvWLgjJWSf';

// DDOS KORUMA AYARLARI
$RATE_LIMIT = 100;          // Dakikada max istek
$BAN_DURATION = 3600;       // Ban süresi (1 saat)

// İstek yapan IP'yi al
$userIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$userIP = explode(',', $userIP)[0];
$requestTime = time();

// Veritabanı bağlantısı
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ============ GEREKLİ TABLOLARI OLUŞTUR ============
    
    // 1. İstek log tablosu
    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ip` varchar(45) NOT NULL,
        `method` varchar(50) DEFAULT NULL,
        `user_id` int(11) DEFAULT NULL,
        `request_time` datetime NOT NULL,
        `timestamp` int(11) NOT NULL,
        `user_agent` text,
        `request_data` text,
        PRIMARY KEY (`id`),
        KEY `ip` (`ip`),
        KEY `timestamp` (`timestamp`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    
    // 2. Rate limit tablosu
    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_rate_limit` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ip` varchar(45) NOT NULL,
        `minute_timestamp` int(11) NOT NULL,
        `request_count` int(11) NOT NULL DEFAULT 1,
        `banned_until` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ip_minute` (`ip`, `minute_timestamp`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    
    // 3. Günlük istatistik tablosu
    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_stats` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `stat_date` date NOT NULL,
        `total_requests` int(11) NOT NULL DEFAULT 0,
        `unique_ips` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `stat_date` (`stat_date`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    
    // 4. Anlık saldırı tespiti tablosu
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attack_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ip` varchar(45) NOT NULL,
        `attack_type` varchar(50) NOT NULL,
        `attack_time` datetime NOT NULL,
        `details` text,
        PRIMARY KEY (`id`),
        KEY `ip` (`ip`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    
} catch (PDOException $e) {
    // Hata durumunda bile çalışmaya devam et
    echo json_encode([
        'status' => 0, 
        'error' => 'DB_CONNECTION_FAILED',
        'message' => 'Veritabanı bağlantısı kurulamadı',
        'timestamp' => date('Y-m-d H:i:s'),
        'your_ip' => $userIP
    ]);
    exit;
}

// ============ RATE LIMIT KONTROLÜ ============
$currentMinute = floor($requestTime / 60);

// Banlı mı kontrol et
$stmt = $pdo->prepare("SELECT banned_until FROM api_rate_limit WHERE ip = :ip AND banned_until > :now LIMIT 1");
$stmt->execute(['ip' => $userIP, 'now' => $requestTime]);
$banned = $stmt->fetch(PDO::FETCH_ASSOC);

if ($banned) {
    $banRemaining = $banned['banned_until'] - $requestTime;
    echo json_encode([
        'status' => 0,
        'error' => 'RATE_LIMIT_EXCEEDED',
        'message' => 'Çok fazla istek gönderdiniz! ' . ceil($banRemaining / 60) . ' dakika banlandınız.',
        'ban_remaining_seconds' => $banRemaining
    ]);
    exit;
}

// İstatistikleri güncelle
$pdo->exec("INSERT INTO api_stats (stat_date, total_requests, unique_ips) 
            VALUES (CURDATE(), 1, 1) 
            ON DUPLICATE KEY UPDATE 
            total_requests = total_requests + 1");

// Rate limit sayacını artır
$stmt = $pdo->prepare("INSERT INTO api_rate_limit (ip, minute_timestamp, request_count) 
                       VALUES (:ip, :minute, 1) 
                       ON DUPLICATE KEY UPDATE 
                       request_count = request_count + 1");
$stmt->execute(['ip' => $userIP, 'minute' => $currentMinute]);

// İstek sayısını al
$stmt = $pdo->prepare("SELECT request_count FROM api_rate_limit WHERE ip = :ip AND minute_timestamp = :minute");
$stmt->execute(['ip' => $userIP, 'minute' => $currentMinute]);
$requestCount = $stmt->fetch(PDO::FETCH_ASSOC)['request_count'];

// DDOS tespiti
if ($requestCount > $RATE_LIMIT) {
    $stmt = $pdo->prepare("UPDATE api_rate_limit SET banned_until = :ban_until WHERE ip = :ip AND minute_timestamp = :minute");
    $stmt->execute(['ip' => $userIP, 'minute' => $currentMinute, 'ban_until' => $requestTime + $BAN_DURATION]);
    
    // Saldırı log'u
    $stmt = $pdo->prepare("INSERT INTO attack_log (ip, attack_type, attack_time, details) VALUES (:ip, 'DDOS_ATTACK', NOW(), :details)");
    $stmt->execute(['ip' => $userIP, 'details' => "{$requestCount} requests in 1 minute"]);
    
    echo json_encode([
        'status' => 0,
        'error' => 'DDOS_ATTACK_DETECTED',
        'message' => 'DDOS saldırısı tespit edildi! IP adresiniz banlandı.',
        'request_count' => $requestCount,
        'limit' => $RATE_LIMIT
    ]);
    exit;
}

// ============ İSTEĞİ LOGLA ============
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = [];
}

$method = isset($input['method']) ? $input['method'] : '';
$userId_log = isset($input['user_id']) ? (int)$input['user_id'] : 0;

$stmt = $pdo->prepare("INSERT INTO api_requests (ip, method, user_id, request_time, timestamp, user_agent, request_data) 
                       VALUES (:ip, :method, :user_id, NOW(), :timestamp, :user_agent, :request_data)");
$stmt->execute([
    'ip' => $userIP,
    'method' => $method,
    'user_id' => $userId_log,
    'timestamp' => $requestTime,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'request_data' => json_encode($input)
]);

// ============ İSTATİSTİK GÖSTER (GET isteği veya stats parametresi) ============
$showStats = (isset($_GET['stats']) || isset($_GET['dashboard']) || isset($input['show_stats'])) ? true : false;

if ($showStats) {
    // Anlık istatistikler
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM api_requests");
    $totalReq = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT ip) as unique_ips FROM api_requests");
    $uniqueIPs = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Son 1 dakikadaki istekler
    $oneMinuteAgo = time() - 60;
    $stmt = $pdo->prepare("SELECT COUNT(*) as last_minute FROM api_requests WHERE timestamp > :time");
    $stmt->execute(['time' => $oneMinuteAgo]);
    $lastMinute = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Son 1 saatteki istekler
    $oneHourAgo = time() - 3600;
    $stmt = $pdo->prepare("SELECT COUNT(*) as last_hour FROM api_requests WHERE timestamp > :time");
    $stmt->execute(['time' => $oneHourAgo]);
    $lastHour = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // En çok istek atan IP'ler (saldırı şüphelileri)
    $stmt = $pdo->query("SELECT ip, COUNT(*) as count, MIN(request_time) as first_request, MAX(request_time) as last_request 
                         FROM api_requests 
                         GROUP BY ip 
                         ORDER BY count DESC 
                         LIMIT 20");
    $topIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Banlı IP'ler
    $stmt = $pdo->prepare("SELECT ip, banned_until FROM api_rate_limit WHERE banned_until > :now");
    $stmt->execute(['now' => $requestTime]);
    $bannedIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Saldırı logları
    $stmt = $pdo->query("SELECT * FROM attack_log ORDER BY attack_time DESC LIMIT 20");
    $attacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Günlük istatistikler
    $stmt = $pdo->query("SELECT * FROM api_stats ORDER BY stat_date DESC LIMIT 7");
    $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Admin tablosundan toplam bakiye
    $stmt = $pdo->query("SELECT SUM(bakiye) as total_balance, COUNT(*) as total_users FROM admin");
    $adminStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Son 10 işlem
    $stmt = $pdo->query("SELECT * FROM transactions ORDER BY created_at DESC LIMIT 10");
    $lastTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'your_ip' => $userIP,
        'rate_limit_status' => [
            'current_minute_requests' => $requestCount,
            'limit_per_minute' => $RATE_LIMIT,
            'remaining' => max(0, $RATE_LIMIT - $requestCount),
            'warning' => $requestCount > ($RATE_LIMIT * 0.7) ? true : false
        ],
        'summary' => [
            'total_requests_all_time' => $totalReq['total'],
            'unique_ips_all_time' => $uniqueIPs['unique_ips'],
            'requests_last_minute' => $lastMinute['last_minute'],
            'requests_last_hour' => $lastHour['last_hour'],
            'active_bans' => count($bannedIPs)
        ],
        'admin_stats' => [
            'total_users' => $adminStats['total_users'],
            'total_balance' => number_format($adminStats['total_balance'], 2, '.', ''),
            'average_balance' => number_format($adminStats['total_balance'] / max(1, $adminStats['total_users']), 2, '.', '')
        ],
        'top_ips' => $topIPs,
        'banned_ips' => $bannedIPs,
        'attack_logs' => $attacks,
        'daily_stats' => $dailyStats,
        'last_transactions' => $lastTransactions
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ NORMAL API İŞLEMLERİ ============
$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$transactionId = isset($input['transaction_id']) ? $input['transaction_id'] : '';
$sessionId = isset($input['session_id']) ? $input['session_id'] : '';
$roundId = isset($input['round_id']) ? $input['round_id'] : '';
$game = isset($input['game']) ? $input['game'] : '';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// ID Dönüşümü
$idMapping = [1 => 1630, 1555 => 1630, 1629 => 1629, 1628 => 1628];
if (isset($idMapping[$userId])) {
    $userId = $idMapping[$userId];
}

try {
    switch ($method) {
        case 'account_details':
            $stmt = $pdo->prepare("SELECT id, username, email FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                echo json_encode(['email' => $user['email'], 'name_jogador' => $user['username']]);
            } else {
                echo json_encode(['status' => false, 'error' => 'INVALID_USER']);
            }
            break;
            
        case 'user_balance':
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                echo json_encode(['status' => 1, 'balance' => number_format((float)$user['bakiye'], 2, '.', '')]);
            } else {
                echo json_encode(['status' => 0, 'error' => 'INVALID_USER']);
            }
            break;
            
        case 'transaction_bet':
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
                'uid' => $userId, 'tid' => $transactionId, 'sid' => $sessionId,
                'rid' => $roundId, 'game' => $game, 'amount' => $betAmount, 'balance' => $newBalance
            ]);
            
            echo json_encode(['status' => true, 'balance' => number_format($newBalance, 2, '.', '')]);
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
                'uid' => $userId, 'tid' => $transactionId, 'sid' => $sessionId,
                'rid' => $roundId, 'game' => $game, 'amount' => $winAmount, 'balance' => $newBalance
            ]);
            
            echo json_encode(['status' => true, 'balance' => number_format($newBalance, 2, '.', '')]);
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
            
            echo json_encode(['status' => true, 'balance' => number_format($newBalance, 2, '.', '')]);
            break;
            
        default:
            echo json_encode(['status' => false, 'error' => 'INVALID_METHOD']);
            break;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'error' => 'DATABASE_ERROR']);
}
?>
