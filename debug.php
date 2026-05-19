<?php
header('Content-Type: application/json; charset=utf-8');
$host = 'sql206.infinityfree.com';
$dbname = 'if0_41958317_c4k';
$dbuser = 'if0_41958317';
$dbpass = 'fMvWLgjJWSf';

$debug = [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $debug['db_connection'] = '✅ BAŞARILI';
} catch (PDOException $e) {
    $debug['db_connection'] = '❌ HATA: ' . $e->getMessage();
    echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Tüm tablolar
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug['tables'] = $tables;
} catch (PDOException $e) {
    $debug['tables'] = 'HATA: ' . $e->getMessage();
}

// 2. Admin tablosu sütunları
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM admin");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debug['admin_columns'] = array_column($columns, 'Field');
} catch (PDOException $e) {
    $debug['admin_columns'] = 'HATA: ' . $e->getMessage();
}

// 3. Kullanıcı 1630 (Emre) - Tüm veriler
try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE id = 1630");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $debug['user_1630'] = $user ?: 'BULUNAMADI';
} catch (PDOException $e) {
    $debug['user_1630'] = 'HATA: ' . $e->getMessage();
}

// 4. Kullanıcı 1629 (Yiğit)
try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE id = 1629");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $debug['user_1629'] = $user ?: 'BULUNAMADI';
} catch (PDOException $e) {
    $debug['user_1629'] = 'HATA: ' . $e->getMessage();
}

// 5. Kullanıcı 1628 (Mert)
try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE id = 1628");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $debug['user_1628'] = $user ?: 'BULUNAMADI';
} catch (PDOException $e) {
    $debug['user_1628'] = 'HATA: ' . $e->getMessage();
}

// 6. Eğer admin'de yoksa diğer tablolarda ara
if (isset($debug['user_1630']) && $debug['user_1630'] === 'BULUNAMADI') {
    foreach ($tables as $table) {
        if ($table === 'admin') continue;
        try {
            $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = 1630 LIMIT 1");
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $debug["user_1630_in_$table"] = $user;
                break;
            }
        } catch (PDOException $e) {
            // Tabloda id sütunu yoksa atla
        }
    }
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
