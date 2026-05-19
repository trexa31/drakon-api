<?php
/**
 * Casino Wallet API
 * Güvenli, idempotent, race-condition korumalı versiyon
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Signature');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =======================================================
// YAPILANDIRMA - Bu değerleri .env veya config dosyasına taşı
// =======================================================
define('DB_HOST',     'sql206.infinityfree.com');
define('DB_NAME',     'if0_41958317_c4k');
define('DB_USER',     'if0_41958317');
define('DB_PASS',     'fMvWLgjJWSf');
define('DB_CHARSET',  'utf8mb4');

// Sağlayıcıdan gelen istekleri doğrulamak için shared secret
if (!defined('SHARED_SECRET')) {
    define('Az1SoO4yj23TZISfOa027i6q56qM3Nyg', '');  // Sağlayıcı key varsa buraya yaz, yoksa boş bırak
}

// =======================================================
// YARDIMCI: JSON çıktısı verip çık
// =======================================================
function respond(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// =======================================================
// OPSİYONEL İMZA DOĞRULAMA
// =======================================================
if (!empty(SHARED_SECRET)) {
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $rawBody    = file_get_contents('php://input');
    $expected   = hash_hmac('sha256', $rawBody, SHARED_SECRET);
    if (!hash_equals($expected, strtolower($signature))) {
        respond(['error' => 'Unauthorized', 'balance' => '0.00', 'currency_code' => 'TRY'], 401);
    }
    $input = json_decode($rawBody, true);
} else {
    $input = json_decode(file_get_contents('php://input'), true);
}

// =======================================================
// GİRİŞ DOĞRULAMA
// =======================================================
if (!is_array($input)) {
    respond(['error' => 'Invalid JSON body', 'balance' => '0.00', 'currency_code' => 'TRY'], 400);
}

$rawUserId     = isset($input['user_id'])       ? trim((string)$input['user_id']) : '';
$username      = isset($input['username'])      ? trim($input['username'])        : '';
$method        = isset($input['method'])        ? trim($input['method'])          : 'user_balance';
$currency      = isset($input['currency_code']) ? trim($input['currency_code'])   : 'TRY';
$transactionId = isset($input['transaction_id'])? trim((string)$input['transaction_id']) : '';

$betAmount = 0.0;
$winAmount = 0.0;

if (isset($input['bet']))    $betAmount = (float)$input['bet'];
if (isset($input['amount'])) $betAmount = (float)$input['amount'];   // bazı sağlayıcılar 'amount' gönderir
if (isset($input['win']))    $winAmount = (float)$input['win'];

// =======================================================
// VERİTABANI BAĞLANTISI
// =======================================================
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Gerçek hata mesajını dışarıya sızdırma
    error_log('[WalletAPI] DB bağlantı hatası: ' . $e->getMessage());
    respond(['error' => 'Database connection failed', 'balance' => '0.00', 'currency_code' => $currency], 500);
}

// =======================================================
// KULLANICI BULMA
// =======================================================
$user = null;

try {
    // Önce sayısal ID ile ara
    if (is_numeric($rawUserId) && (int)$rawUserId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int)$rawUserId]);
        $user = $stmt->fetch();
    }

    // Bulunamadıysa kullanıcı adı ile ara
    if (!$user) {
        $searchName = !empty($username) ? $username : $rawUserId;
        if (!empty($searchName)) {
            $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE username = :username LIMIT 1");
            $stmt->execute(['username' => $searchName]);
            $user = $stmt->fetch();
        }
    }
} catch (PDOException $e) {
    error_log('[WalletAPI] Kullanıcı sorgulama hatası: ' . $e->getMessage());
    respond(['error' => 'Query error', 'balance' => '0.00', 'currency_code' => $currency], 500);
}

if (!$user) {
    respond(['error' => 'User not found', 'balance' => '0.00', 'currency_code' => $currency, 'status' => 'USER_NOT_FOUND'], 404);
}

$realUserId = (int)$user['id'];

// =======================================================
// SADECE BAKİYE SORGULAMA (user_balance)
// =======================================================
if ($method === 'user_balance') {
    respond([
        'balance'       => number_format((float)$user['bakiye'], 2, '.', ''),
        'currency_code' => $currency,
    ]);
}

// =======================================================
// İŞLEM TABLOSU YOKSA OTOMATİK OLUŞTUR
// (İlk kurulumda çalışır, sonra devre dışı bırakabilirsin)
// =======================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wallet_transactions (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(255)   NOT NULL,
            user_id        INT UNSIGNED   NOT NULL,
            method         VARCHAR(50)    NOT NULL,
            bet_amount     DECIMAL(18,2)  NOT NULL DEFAULT 0.00,
            win_amount     DECIMAL(18,2)  NOT NULL DEFAULT 0.00,
            balance_before DECIMAL(18,2)  NOT NULL,
            balance_after  DECIMAL(18,2)  NOT NULL,
            currency       VARCHAR(10)    NOT NULL DEFAULT 'TRY',
            created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_transaction (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    error_log('[WalletAPI] Tablo oluşturma hatası: ' . $e->getMessage());
}

// =======================================================
// İDEMPOTENSİ KONTROLÜ
// Aynı transaction_id daha önce işlendiyse aynı yanıtı dön
// =======================================================
if (!empty($transactionId)) {
    try {
        $stmt = $pdo->prepare(
            "SELECT balance_after, currency FROM wallet_transactions
             WHERE transaction_id = :tid AND user_id = :uid LIMIT 1"
        );
        $stmt->execute(['tid' => $transactionId, 'uid' => $realUserId]);
        $existing = $stmt->fetch();
        if ($existing) {
            respond([
                'balance'       => number_format((float)$existing['balance_after'], 2, '.', ''),
                'currency_code' => $existing['currency'],
                'status'        => 'ALREADY_PROCESSED',
            ]);
        }
    } catch (PDOException $e) {
        error_log('[WalletAPI] İdempotens sorgu hatası: ' . $e->getMessage());
    }
}

// =======================================================
// BAKİYE GÜNCELLEME — RACE CONDITION KORUMALILR (FOR UPDATE)
// =======================================================
try {
    $pdo->beginTransaction();

    // Satırı kilitle, başka istek aynı anda okuyamasın
    $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $realUserId]);
    $locked = $stmt->fetch();

    if (!$locked) {
        $pdo->rollBack();
        respond(['error' => 'User lock failed', 'balance' => '0.00', 'currency_code' => $currency], 500);
    }

    $balanceBefore = (float)$locked['bakiye'];
    $balanceAfter  = $balanceBefore;
    $processed     = false;

    // --- BET ---
    if (in_array($method, ['transaction_bet', 'bet', 'debit']) && $betAmount > 0) {
        if ($balanceBefore < $betAmount) {
            $pdo->rollBack();
            respond([
                'error'         => 'Insufficient balance',
                'balance'       => number_format($balanceBefore, 2, '.', ''),
                'currency_code' => $currency,
                'status'        => 'INSUFFICIENT_BALANCE',
            ], 402);
        }
        $balanceAfter = $balanceBefore - $betAmount;
        $processed    = true;
    }

    // --- WIN ---
    elseif (in_array($method, ['transaction_win', 'win', 'credit']) && $winAmount > 0) {
        $balanceAfter = $balanceBefore + $winAmount;
        $processed    = true;
    }

    // --- REFUND / ROLLBACK ---
    elseif (in_array($method, ['refund', 'rollback', 'cancel']) && $betAmount > 0) {
        $balanceAfter = $balanceBefore + $betAmount;
        $processed    = true;
    }

    // --- BET + WIN aynı istekte (bazı sağlayıcılar) ---
    elseif (in_array($method, ['transaction', 'round', 'play'])) {
        if ($betAmount > 0) {
            if ($balanceBefore < $betAmount) {
                $pdo->rollBack();
                respond([
                    'error'         => 'Insufficient balance',
                    'balance'       => number_format($balanceBefore, 2, '.', ''),
                    'currency_code' => $currency,
                    'status'        => 'INSUFFICIENT_BALANCE',
                ], 402);
            }
            $balanceAfter -= $betAmount;
        }
        if ($winAmount > 0) {
            $balanceAfter += $winAmount;
        }
        $processed = ($betAmount > 0 || $winAmount > 0);
    }

    if ($processed) {
        // Bakiyeyi güncelle
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $balanceAfter, 'id' => $realUserId]);

        // Log kaydı
        if (!empty($transactionId)) {
            $stmt = $pdo->prepare("
                INSERT INTO wallet_transactions
                    (transaction_id, user_id, method, bet_amount, win_amount,
                     balance_before, balance_after, currency)
                VALUES
                    (:tid, :uid, :method, :bet, :win, :before, :after, :cur)
            ");
            $stmt->execute([
                'tid'    => $transactionId,
                'uid'    => $realUserId,
                'method' => $method,
                'bet'    => $betAmount,
                'win'    => $winAmount,
                'before' => $balanceBefore,
                'after'  => $balanceAfter,
                'cur'    => $currency,
            ]);
        }
    }

    $pdo->commit();

    respond([
        'balance'       => number_format($balanceAfter, 2, '.', ''),
        'currency_code' => $currency,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[WalletAPI] İşlem hatası: ' . $e->getMessage());
    respond(['error' => 'Transaction failed', 'balance' => '0.00', 'currency_code' => $currency], 500);
}
