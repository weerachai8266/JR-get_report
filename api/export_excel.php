<?php
// ไฟล์สำหรับ export ข้อมูลเป็น Excel
require_once '../vendor/autoload.php';
require_once '../config/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// รับค่าวันที่เริ่มต้นและวันที่สิ้นสุด
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'hourly';

// ปรับวันที่สิ้นสุดให้เป็นสิ้นสุดของวัน
$end_date_time = $end_date . ' 23:59:59';

try {
    // ดึงข้อมูลทั้งแบบรายชั่วโมงและแบบ 15 นาที (ไม่ว่าจะเลือกโหมดไหนก็ตาม)
    
    // 1. ดึงข้อมูลแบบรายชั่วโมง
    $hourly_sql = "
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
    
    $stmt = $conn->prepare($hourly_sql);
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date_time);
    $stmt->execute();
    
    $hourly_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. ดึงข้อมูลแบบ 15 นาที
    $quarter_sql = "
    WITH quarter_data AS (
        SELECT
            DATE(Time) as date_only,
            HOUR(Time) as hour_only,
            FLOOR(MINUTE(Time) / 15) * 15 as minute_group,
            MAX(Time) as max_time
        FROM
            room
        WHERE
            Time BETWEEN :start_date AND :end_date
        GROUP BY
            DATE(Time),
            HOUR(Time),
            FLOOR(MINUTE(Time) / 15) * 15
    )
    SELECT
        r.ID,
        r.Time,
        r.Energy,
        DATE(r.Time) as date_only,
        HOUR(r.Time) as hour_only,
        FLOOR(MINUTE(r.Time) / 15) * 15 as minute_only
    FROM
        room r
    INNER JOIN
        quarter_data q ON r.Time = q.max_time
    ORDER BY
        date_only, hour_only, minute_only
    ";
    
    $stmt = $conn->prepare($quarter_sql);
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date_time);
    $stmt->execute();
    
    $quarter_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // สร้างรายการวันที่ที่มีข้อมูล
    $dates = [];
    
    // แปลงข้อมูลรายชั่วโมงเป็นรูปแบบ pivot
    $hourly_data = [];
    
    foreach ($hourly_results as $row) {
        $date = $row['date_only'];
        $key = sprintf('%02d', $row['hour_only']);
        $energy = $row['Energy'];
        
        if (!in_array($date, $dates)) {
            $dates[] = $date;
        }
        
        if (!isset($hourly_data[$date])) {
            $hourly_data[$date] = [];
        }
        
        $hourly_data[$date][$key] = $energy;
    }
    
    // แปลงข้อมูลราย 15 นาทีเป็นรูปแบบ pivot
    $quarter_data = [];
    
    foreach ($quarter_results as $row) {
        $date = $row['date_only'];
        $hour = sprintf('%02d', $row['hour_only']);
        
        // ทำให้มั่นใจว่าค่านาทีจะเป็น 00, 15, 30, หรือ 45 เท่านั้น
        $minuteVal = intval($row['minute_only']);
        // ปัดค่านาทีเป็น 0, 15, 30, 45
        $minuteVal = floor($minuteVal / 15) * 15;
        $minute = sprintf('%02d', $minuteVal);
        
        $key = "$hour:$minute";
        $energy = $row['Energy'];
        
        if (!in_array($date, $dates)) {
            $dates[] = $date;
        }
        
        if (!isset($quarter_data[$date])) {
            $quarter_data[$date] = [];
        }
        
        $quarter_data[$date][$key] = $energy;
    }
    
    // เรียงวันที่
    sort($dates);

    // สร้าง spreadsheet
    $spreadsheet = new Spreadsheet();
    
    // ฟังก์ชันสำหรับตั้งค่า style ของหัวตาราง
    $headerStyle = [
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'color' => ['argb' => 'FFE0E0E0'],
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
            ],
        ],
    ];

    // ฟังก์ชันสำหรับสร้างข้อมูลรายชั่วโมง
    function createHourlySheet($spreadsheet, $dates, $energy_data, $start_date, $end_date, $headerStyle) {
        // สร้าง sheet รายชั่วโมง
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('รายชั่วโมง');
        
        // ตั้งค่าหัวตาราง
        $sheet->setCellValue('A1', 'รายงานข้อมูลพลังงาน (รายชั่วโมง)');
        $sheet->mergeCells('A1:' . chr(65 + count($dates)) . '1');
        
        $sheet->setCellValue('A2', 'วันที่: ' . date('d/m/Y', strtotime($start_date)) . ' ถึง ' . date('d/m/Y', strtotime($end_date)));
        $sheet->mergeCells('A2:' . chr(65 + count($dates)) . '2');
        
        $sheet->getStyle('A1:' . chr(65 + count($dates)) . '2')->applyFromArray($headerStyle);
        
        // หัวคอลัมน์
        $sheet->setCellValue('A4', 'เวลา');
        
        // เพิ่มวันที่ในหัวคอลัมน์
        foreach ($dates as $index => $date) {
            $column = chr(66 + $index);
            $sheet->setCellValue($column . '4', date('d/m/Y', strtotime($date)));
        }
        
        // ตั้งค่าสไตล์หัวคอลัมน์
        $sheet->getStyle('A4:' . chr(65 + count($dates)) . '4')->applyFromArray($headerStyle);
        
        // เพิ่มข้อมูล
        $rowIndex = 5;
        
        // สร้างข้อมูลรายชั่วโมง
        for ($hour = 0; $hour < 24; $hour++) {
            $hourKey = sprintf('%02d', $hour);
            
            $sheet->setCellValue('A' . $rowIndex, $hourKey . ':00');
            
            // เพิ่มข้อมูลแต่ละวัน
            foreach ($dates as $index => $date) {
                $column = chr(66 + $index);
                
                if (isset($energy_data[$date][$hourKey])) {
                    $sheet->setCellValue($column . $rowIndex, $energy_data[$date][$hourKey]);
                } else {
                    $sheet->setCellValue($column . $rowIndex, '-');
                }
            }
            
            $rowIndex++;
        }
        
        // ตั้งค่าความกว้างคอลัมน์อัตโนมัติ
        foreach (range('A', chr(65 + count($dates))) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        // ปรับแต่งสไตล์เซลล์ข้อมูล
        $dataRange = 'A5:' . chr(65 + count($dates)) . ($rowIndex - 1);
        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        // ตั้งค่าคอลัมน์เวลาให้อยู่ตรงกลาง
        $sheet->getStyle('A5:A' . ($rowIndex - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // ฟังก์ชันสำหรับสร้างข้อมูลราย 15 นาที
    function createQuarterSheet($spreadsheet, $dates, $energy_data, $start_date, $end_date, $headerStyle) {
        // สร้าง sheet ราย 15 นาที
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('ทุก 15 นาที');
        
        // ตั้งค่าหัวตาราง
        $sheet->setCellValue('A1', 'รายงานข้อมูลพลังงาน (ทุก 15 นาที)');
        $sheet->mergeCells('A1:' . chr(65 + count($dates)) . '1');
        
        $sheet->setCellValue('A2', 'วันที่: ' . date('d/m/Y', strtotime($start_date)) . ' ถึง ' . date('d/m/Y', strtotime($end_date)));
        $sheet->mergeCells('A2:' . chr(65 + count($dates)) . '2');
        
        $sheet->getStyle('A1:' . chr(65 + count($dates)) . '2')->applyFromArray($headerStyle);
        
        // หัวคอลัมน์
        $sheet->setCellValue('A4', 'เวลา');
        
        // เพิ่มวันที่ในหัวคอลัมน์
        foreach ($dates as $index => $date) {
            $column = chr(66 + $index);
            $sheet->setCellValue($column . '4', date('d/m/Y', strtotime($date)));
        }
        
        // ตั้งค่าสไตล์หัวคอลัมน์
        $sheet->getStyle('A4:' . chr(65 + count($dates)) . '4')->applyFromArray($headerStyle);
        
        // เพิ่มข้อมูล
        $rowIndex = 5;
        
        // สร้างข้อมูลราย 15 นาที
        for ($hour = 0; $hour < 24; $hour++) {
            $hourStr = sprintf('%02d', $hour);
            
            for ($minute = 0; $minute < 60; $minute += 15) {
                $minuteStr = sprintf('%02d', $minute);
                $timeKey = "$hourStr:$minuteStr";
                
                $sheet->setCellValue('A' . $rowIndex, $timeKey);
                
                // เพิ่มข้อมูลแต่ละวัน
                foreach ($dates as $index => $date) {
                    $column = chr(66 + $index);
                    
                    if (isset($energy_data[$date][$timeKey])) {
                        $sheet->setCellValue($column . $rowIndex, $energy_data[$date][$timeKey]);
                    } else {
                        $sheet->setCellValue($column . $rowIndex, '-');
                    }
                }
                
                $rowIndex++;
            }
        }
        
        // ตั้งค่าความกว้างคอลัมน์อัตโนมัติ
        foreach (range('A', chr(65 + count($dates))) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        // ปรับแต่งสไตล์เซลล์ข้อมูล
        $dataRange = 'A5:' . chr(65 + count($dates)) . ($rowIndex - 1);
        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        // ตั้งค่าคอลัมน์เวลาให้อยู่ตรงกลาง
        $sheet->getStyle('A5:A' . ($rowIndex - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // สร้าง sheet ทั้งสองแบบ (รายชั่วโมงและราย 15 นาที)
    createHourlySheet($spreadsheet, $dates, $hourly_data, $start_date, $end_date, $headerStyle);
    // createQuarterSheet($spreadsheet, $dates, $quarter_data, $start_date, $end_date, $headerStyle);
    
    // สร้างไฟล์ Excel
    $writer = new Xlsx($spreadsheet);
    
    // ตั้งชื่อไฟล์
    $modeText = ($mode === 'quarter') ? 'ทุก15นาที' : 'รายชั่วโมง';
    $filename = 'energy_report_' . $start_date . '_to_' . $end_date . '_' . $modeText . '.xlsx';
    
    // ส่งไฟล์ไปยังเบราว์เซอร์
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
    
} catch (PDOException $e) {
    // กรณีเกิดข้อผิดพลาด
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
