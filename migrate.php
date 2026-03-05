<?php
require_once __DIR__ . '/includes/db.php';

$migrations = [
    "ALTER TABLE appointments ADD service_id INT(11) DEFAULT NULL AFTER vehicle_id",
    "ALTER TABLE appointments ADD notes TEXT DEFAULT NULL AFTER status",
    "ALTER TABLE orders ADD receipt_image VARCHAR(255) DEFAULT NULL AFTER payment_method",
    "ALTER TABLE assignments ADD appointment_id INT(11) DEFAULT NULL AFTER assignment_id",
    "ALTER TABLE notifications ADD type VARCHAR(50) DEFAULT 'general' AFTER message",
    "UPDATE orders SET payment_method = 'Cash' WHERE payment_method IN ('Card', 'Bank Transfer')",
    "UPDATE payments SET payment_method = 'Cash' WHERE payment_method IN ('Card', 'Bank Transfer')",
    "ALTER TABLE orders MODIFY payment_method ENUM('Cash','GCash','Maya') DEFAULT NULL",
    "ALTER TABLE payments MODIFY payment_method ENUM('Cash','GCash','Maya') DEFAULT NULL",
];

foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $sql\n";
    } catch (Exception $e) {
        echo "SKIP: " . $e->getMessage() . "\n";
    }
}
echo "\nMigration completed!\n";
