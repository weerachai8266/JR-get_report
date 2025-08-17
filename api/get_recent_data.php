<?php
// ไฟล์สำหรับดึงข้อมูลล่าสุด 20 รายการ
header('Content-Type: application/json');
require_once '../config/db.php';

try {
    // คำสั่ง SQL ดึงข้อมูลล่าสุด 20 รายการ
    $sql = "SELECT `ID`, `Time`, `Voltage`, `Current`, `Frequency`, `Power`, `PF`, `Energy`, `Tem`, `Hum` 
            FROM `room` 
            ORDER BY `Time` DESC 
            LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ส่งข้อมูลกลับเป็น JSON
    echo json_encode($result, JSON_NUMERIC_CHECK);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
