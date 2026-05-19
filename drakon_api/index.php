<?php
/**
 * Casino Wallet API - Trexa Entegrasyonu
 * Güvenli, idempotent, race-condition korumalı
 */

// Hata çıktısını kapat — JSON bozulmasın
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Signature, X-Agent-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =======================================================
// YAPILANDIRMA
// =======================================================
$config = [
    'db_host'      => 'sql206.infinityfree.com',
    'db_name'      => 'if0_41958317_c4k',
    'db_user'      => 'if0_41958317',
    'db_pass'      => 'fMvWLgjJWSf',
    'db_charset'   => 'utf8mb4',

    // Trexa API bilgileri
    'agent_code'   => 'trexa',
    'agent_token'  => 'DD7rYRIt5bug1Kxqi01NlX39RV1YsJPl',
    'secret_key'   => 'Az1SoO4yj23TZISfOa027i6q56qM3Nyg',
];

// =======================================================
// YARDIMCI: JSON çıktısı verip çık
// =======================================================
function respond(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// =======================================================
// BODY OKU
// =======================================================
$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);

if (!is_array($input)) {
    respond(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'Invalid JSON body'], 400);
}

// =======================================================
// İMZA DOĞRULAMA (Trexa secret_key ile)
// Trexa genellikle agent_token veya HMAC gönderir.
// Header'da X-Signature varsa doğrula, yoksa geç.
// =======================================================
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if (!empty($signature)) {
    $expected = hash_hmac('sha256', $rawBody, $config['secret_key']);
    if (!hash_equals($expected, strtolower($signature))) {
        respond(['balance' => '0.00', 'currency_code' => 'TRY', 'error' => 'Unauthorized'], 401);
    }
}

// =======================================================
// GİRİŞ PARAMETRELERİ
// =======================================================
$rawUserId     = isset($input['user_id'])        ? trim((string)$input['user_id']) : '';
$username      = isset($input['username'])       ? trim($input['username'])        : '';
$method        = isset($input['method'])         ? trim($input['method'])          : 'user_balance';
$currency      = isset($input['currency_code'])  ? trim($input['currency_code'])   : 'TRY';
$transactionId = isset($input['transaction_id']) ? trim((string)$input['transaction_id']) : '';

$betAmount = 0.0;
$winAmount = 0.0;

if (isset($input['bet']))    $betAmount = (float)$input['bet'];
if (isset($input['amount'])) $betAmount = (float)$input['amount'];
if (isset($input['win']))    $winAmount = (float)$input['win'];

// =======================================================
// VERİTABANI BAĞLANTISI
// =======================================================
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('[WalletAPI] DB bağlantı hatası: ' . $e->getMessage());
    respond(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'Database connection failed'], 500);
}

// =======================================================
// KULLANICI BULMA
// =======================================================
$user = null;

try {
    if (is_numeric($rawUserId) && (int)$rawUserId > 0) {
        $stmt = $pdo->prepare("SELECT id, bakiye FROM admin WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int)$rawUserId]);
        $user = $stmt->fetch();
    }

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
    respond(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'Query error'], 500);
}

if (!$user) {
    respond(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'User not found'], 404);
}

$realUserId = (int)$user['id'];

// =======================================================
// SADECE BAKİYE SORGULAMA
// =======================================================
if ($method === 'user_balance') {
    respond([
        'balance'       => number_format((float)$user['bakiye'], 2, '.', ''),
        'currency_code' => $currency,
    ]);
}

// =======================================================
// İŞLEM TABLOSU OTOMATİK OLUŞTUR
// =======================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wallet_transactions (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(255)  NOT NULL,
            user_id        INT UNSIGNED  NOT NULL,
            method         VARCHAR(50)   NOT NULL,
            bet_amount     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            win_amount     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            balance_before DECIMAL(18,2) NOT NULL,
            balance_after  DECIMAL(18,2) NOT NULL,
            currency       VARCHAR(10)   NOT NULL DEFAULT 'TRY',
            created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_transaction (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    error_log('[WalletAPI] Tablo oluşturma hatası: ' . $e->getMessage());
}

// =======================================================
// İDEMPOTENSİ — Aynı transaction tekrar gelirse aynı yanıtı dön
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
            ]);
        }
    } catch (PDOException $e) {
        error_log('[WalletAPI] İdempotens hatası: ' . $e->getMessage());
    }
}

// =======================================================
// BAKİYE GÜNCELLEME — KİLİTLİ TRANSACTION
// =======================================================
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT bakiye FROM admin WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $realUserId]);
    $locked = $stmt->fetch();

    if (!$locked) {
        $pdo->rollBack();
        respond(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'User lock failed'], 500);
    }

    $balanceBefore = (float)$locked['bakiye'];
    $balanceAfter  = $balanceBefore;
    $processed     = false;

    // BET
    if (in_array($method, ['transaction_bet', 'bet', 'debit']) && $betAmount > 0) {
        if ($balanceBefore < $betAmount) {
            $pdo->rollBack();
            respond([
                'balance'       => number_format($balanceBefore, 2, '.', ''),
                'currency_code' => $currency,
                'error'         => 'Insufficient balance',
            ], 402);
        }
        $balanceAfter = $balanceBefore - $betAmount;
        $processed    = true;
    }

    // WIN
    elseif (in_array($method, ['transaction_win', 'win', 'credit']) && $winAmount > 0) {
        $balanceAfter = $balanceBefore + $winAmount;
        $processed    = true;
    }

    // REFUND / ROLLBACK
    elseif (in_array($method, ['refund', 'rollback', 'cancel']) && $betAmount > 0) {
        $balanceAfter = $balanceBefore + $betAmount;
        $processed    = true;
    }

    // BET + WIN aynı istekte
    elseif (in_array($method, ['transaction', 'round', 'play'])) {
        if ($betAmount > 0) {
            if ($balanceBefore < $betAmount) {
                $pdo->rollBack();
                respond([
                    'balance'       => number_format($balanceBefore, 2, '.', ''),
                    'currency_code' => $currency,
                    'error'         => 'Insufficient balance',
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
        $stmt = $pdo->prepare("UPDATE admin SET bakiye = :balance WHERE id = :id");
        $stmt->execute(['balance' => $balanceAfter, 'id' => $realUserId]);

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
    respond(['balance' => '0.00', 'currency_code' => $currency, 'error' => 'Transaction failed'], 500);
}
