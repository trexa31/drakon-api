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
    echo json_encode(['status' => 'error', 'balance' => '0.00', 'currency_code' => 'TRY']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'balance' => '0.00', 'currency_code' => 'TRY']);
    exit;
}

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$username = isset($input['username']) ? trim($input['username']) : '';
$method = isset($input['method']) ? $input['method'] : 'user_balance';
$currency = isset($input['currency_code']) ? $input['currency_code'] : 'TRY';
$betAmount = isset($input['bet']) ? (float)$input['bet'] : (isset($input['amount']) ? (float)$input['amount'] : 0);
$winAmount = isset($input['win']) ? (float)$input['win'] : 0;

// ============ TÜM OLASI BAKİYE KOLONLARINI KONTROL ET ============
$balance = 0;
$realUserId = 0;

try {
    // Önce admin tablosundaki kolonları bul
    $stmt = $pdo->query("SHOW COLUMNS FROM admin");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Bakiye olabilecek kolon isimleri (sırayla dene)
    $balanceColumns = ['bakiye', 'balance', 'bakiyesi', 'credit', 'money', 'cash', 'wallet', 'total_balance'];
    
    // Kullanıcıyı bul
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $realUserId = $user['id'];
            // Hangi kolonda bakiye var?
            foreach ($balanceColumns as $col) {
                if (isset($user[$col])) {
                    $balance = (float)$user[$col];
                    break;
                }
            }
        }
    }
    
    // ID ile bulunamadıysa username ile ara
    if ($realUserId == 0 && !empty($username)) {
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $realUserId = $user['id'];
            foreach ($balanceColumns as $col) {
                if (isset($user[$col])) {
                    $balance = (float)$user[$col];
                    break;
                }
            }
        }
    }
    
    // ============ OYUN TABLOSUNU DA GÜNCELLE (ÇOK ÖNEMLİ!) ============
    // Oyun hangi tabloyu kullanıyor? Muhtemel tablolar:
    $gameTables = ['user_balances', 'game_balances', 'players', 'wallet', 'user_wallet'];
    
    foreach ($gameTables as $table) {
        try {
            // Tablo var mı kontrol et
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                // Tablo var, kolonları kontrol et
                $stmt = $pdo->query("SHOW COLUMNS FROM $table");
                $tableColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // user_id veya player_id kolonu var mı?
                $userIdColumn = null;
                if (in_array('user_id', $tableColumns)) $userIdColumn = 'user_id';
                elseif (in_array('player_id', $tableColumns)) $userIdColumn = 'player_id';
                elseif (in_array('uid', $tableColumns)) $userIdColumn = 'uid';
                
                if ($userIdColumn) {
                    // Kullanıcının bu tablodaki kaydını kontrol et
                    $stmt = $pdo->prepare("SELECT * FROM $table WHERE $userIdColumn = :uid");
                    $stmt->execute(['uid' => $realUserId]);
                    $gameUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($gameUser) {
                        // Oyun tablosundaki bakiyeyi admin tablosuyla senkronize et
                        $gameBalanceColumn = null;
                        foreach ($balanceColumns as $col) {
                            if (in_array($col, $tableColumns)) {
                                $gameBalanceColumn = $col;
                                break;
                            }
                        }
                        
                        if ($gameBalanceColumn && $gameUser[$gameBalanceColumn] != $balance) {
                            $stmt = $pdo->prepare("UPDATE $table SET $gameBalanceColumn = :balance WHERE $userIdColumn = :uid");
                            $stmt->execute(['balance' => $balance, 'uid' => $realUserId]);
                        }
                    } else {
                        // Oyun tablosunda kayıt yoksa oluştur
                        $stmt = $pdo->prepare("INSERT INTO $table ($userIdColumn, balance) VALUES (:uid, :balance)");
                        $stmt->execute(['uid' => $realUserId, 'balance' => $balance]);
                    }
                }
            }
        } catch (PDOException $e) {
            // Tablo yoksa veya hata varsa geç
        }
    }
    
} catch (PDOException $e) {
    // Hata olursa devam et
}

$responseBalance = $balance;

// ============ İŞLEMLER ============
if ($method == 'transaction_bet' && $betAmount > 0 && $realUserId > 0) {
    if ($betAmount <= $balance) {
        $responseBalance = $balance - $betAmount;
        try {
            // Admin tablosunu güncelle
            $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
            $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
            
            // Tüm oyun tablolarını da güncelle
            foreach ($gameTables as $table) {
                try {
                    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                    if ($stmt->rowCount() > 0) {
                        $stmt = $pdo->query("SHOW COLUMNS FROM $table");
                        $tableColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        $userIdColumn = null;
                        if (in_array('user_id', $tableColumns)) $userIdColumn = 'user_id';
                        elseif (in_array('player_id', $tableColumns)) $userIdColumn = 'player_id';
                        elseif (in_array('uid', $tableColumns)) $userIdColumn = 'uid';
                        
                        if ($userIdColumn && in_array('balance', $tableColumns)) {
                            $stmt = $pdo->prepare("UPDATE $table SET balance = :balance WHERE $userIdColumn = :uid");
                            $stmt->execute(['balance' => $responseBalance, 'uid' => $realUserId]);
                        }
                    }
                } catch (PDOException $e) {}
            }
        } catch (PDOException $e) {}
    }
}
elseif ($method == 'transaction_win' && $winAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $winAmount;
    try {
        // Admin tablosunu güncelle
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
        
        // Tüm oyun tablolarını da güncelle
        foreach ($gameTables as $table) {
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->query("SHOW COLUMNS FROM $table");
                    $tableColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $userIdColumn = null;
                    if (in_array('user_id', $tableColumns)) $userIdColumn = 'user_id';
                    elseif (in_array('player_id', $tableColumns)) $userIdColumn = 'player_id';
                    elseif (in_array('uid', $tableColumns)) $userIdColumn = 'uid';
                    
                    if ($userIdColumn && in_array('balance', $tableColumns)) {
                        $stmt = $pdo->prepare("UPDATE $table SET balance = :balance WHERE $userIdColumn = :uid");
                        $stmt->execute(['balance' => $responseBalance, 'uid' => $realUserId]);
                    }
                }
            } catch (PDOException $e) {}
        }
    } catch (PDOException $e) {}
}
elseif ($method == 'refund' && $betAmount > 0 && $realUserId > 0) {
    $responseBalance = $balance + $betAmount;
    try {
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $responseBalance, 'id' => $realUserId]);
    } catch (PDOException $e) {}
}

echo json_encode([
    'status' => 'success',
    'balance' => number_format($responseBalance, 2, '.', ''),
    'currency_code' => $currency
]);
?>
