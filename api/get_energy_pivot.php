<?php
// ไฟล์สำหรับดึงข้อมูล Energy แบบ pivot
header('Content-Type: application/json');
require_once '../config/db.php';

try {
    // รับค่าวันที่เริ่มต้นและวันที่สิ้นสุด
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    
    // ปรับวันที่สิ้นสุดให้เป็นสิ้นสุดของวัน
    $end_date_time = $end_date . ' 23:59:59';
    
    // คำสั่ง SQL เพื่อดึงข้อมูล Energy ล่าสุดในแต่ละชั่วโมงของแต่ละวันในช่วงที่กำหนด
    $sql = "
    WITH hourly_data AS (
        SELECT
            DATE(Time) as date_only,
            HOUR(Time) as hour_only,
            MAX(Time) as max_time
        FROM
            room
        WHERE
            Time BETWEEN :start_date AND :end_date
        GROUP BY
            DATE(Time),
            HOUR(Time)
    )
    SELECT
        r.ID,
        r.Time,
        r.Energy,
        DATE(r.Time) as date_only,
        HOUR(r.Time) as hour_only
    FROM
        room r
    INNER JOIN
        hourly_data h ON r.Time = h.max_time
    ORDER BY
        date_only, hour_only
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date_time);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // แปลงข้อมูลเป็นรูปแบบ pivot
    $dates = [];
    $energy_data = [];
    
    foreach ($results as $row) {
        $date = $row['date_only'];
        $hour = sprintf('%02d', $row['hour_only']);
        $energy = $row['Energy'];
        
        if (!in_array($date, $dates)) {
            $dates[] = $date;
        }
        
        if (!isset($energy_data[$date])) {
            $energy_data[$date] = [];
        }
        
        $energy_data[$date][$hour] = $energy;
    }
    
    // เรียงวันที่
    sort($dates);
    
    // ส่งข้อมูลกลับเป็น JSON
    echo json_encode([
        'dates' => $dates,
        'energy' => $energy_data
    ], JSON_NUMERIC_CHECK);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
