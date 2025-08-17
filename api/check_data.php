<?php
/**
 * check_data.php
 * ไฟล์สำหรับตรวจสอบว่ามีข้อมูลในช่วงวันที่ที่เลือกหรือไม่
 */

header('Content-Type: application/json');

// รับค่าวันที่จาก URL parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;

// ตรวจสอบว่ามีการระบุวันที่หรือไม่
if (!$start_date || !$end_date) {
    echo json_encode(['error' => 'กรุณาระบุวันที่เริ่มต้นและวันที่สิ้นสุด', 'hasData' => false]);
    exit;
}

// เชื่อมต่อกับฐานข้อมูล
require_once '../config/db.php';

try {
    // เตรียม query เพื่อตรวจสอบข้อมูล
    $sql = "SELECT COUNT(*) as record_count 
            FROM energy_data 
            WHERE DATE(timestamp) BETWEEN :start_date AND :end_date";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
    $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ตรวจสอบว่ามีข้อมูลหรือไม่
    $hasData = ($result['record_count'] > 0);
    
    echo json_encode([
        'hasData' => $hasData,
        'count' => $result['record_count'],
        'period' => [
            'start' => $start_date,
            'end' => $end_date
        ]
    ]);
    
} catch (PDOException $e) {
    // กรณีเกิด error
    echo json_encode([
        'error' => 'เกิดข้อผิดพลาดในการเชื่อมต่อกับฐานข้อมูล: ' . $e->getMessage(),
        'hasData' => false
    ]);
}
