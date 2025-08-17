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
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// ปรับวันที่สิ้นสุดให้เป็นสิ้นสุดของวัน
$end_date_time = $end_date . ' 23:59:59';

try {
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
    
    // สร้างไฟล์ Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('ประมาณการผลิตไฟฟ้า');
    
    // ตั้งค่ารูปแบบหัวตาราง
    $sheet->setCellValue('A1', 'Huaybong Biotech Co., Ltd.');
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($dates) + 1);
    $sheet->mergeCells('A1:' . $lastCol . '1');

    $sheet->setCellValue('A2', 'รายงานสมดุลการผลิต และใช้ไฟฟ้า');
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($dates) + 1);
    $sheet->mergeCells('A2:' . $lastCol . '2');
    
    $sheet->setCellValue('A3', 'ประมาณการผลิตไฟฟ้า ระหว่างวันที่ ' . $start_date . ' ถึง ' . $end_date);
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($dates) + 1);
    $sheet->mergeCells('A3:' . $lastCol . '3');

    // รูปแบบหัวตาราง
    $headerStyle = [
        'font' => [
            'bold' => true,
            'size' => 14
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ];
    
    $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($dates) + 1) . '3')->applyFromArray($headerStyle);
    
    // เพิ่มหัวตาราง
    $sheet->setCellValue('A5', 'เวลา / วันที่');
    
    // เพิ่มหัวคอลัมน์วันที่
    $colheader = 2;
    
    for ($i = 0; $i < count($dates); $i++) {
        // ตำแหน่งคอลัมน์เริ่มจาก B (คอลัมน์ A เป็นเวลา)
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colheader++);
        // แปลงรูปแบบวันที่เป็น d/m/Y
        $formatted_date = date('d/m/Y', strtotime($dates[$i]));
        $sheet->setCellValue($col . '5', $formatted_date);
        
        // ตั้งค่าความกว้างของคอลัมน์
        $sheet->getColumnDimension($col)->setWidth(12);
    }
    
    // เพิ่มข้อมูล Energy
    for ($hour = 0; $hour < 24; $hour++) {
        $hour_str = sprintf('%02d', $hour);
        $row_num = $hour + 6;
        
        // เพิ่มชั่วโมง
        $sheet->setCellValue('A' . $row_num, $hour_str . ':00');
        
        // เพิ่มค่า Energy สำหรับแต่ละวัน
        $coldata = 2;
        for ($i = 0; $i < count($dates); $i++) {
            $date = $dates[$i];
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($coldata++);

            if (isset($energy_data[$date][$hour_str])) {
                $sheet->setCellValue($col . $row_num, $energy_data[$date][$hour_str]);
            } else {
                $sheet->setCellValue($col . $row_num, '-');
            }
        }
    }
    // sum
    $sheet->setCellValue('A29', 'Total');
    for ($i = 0; $i < count($dates); $i++) {
        $colsum = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 2);
        $sheet->setCellValue($colsum . '29', '=sum(' . $colsum . '6:' . $colsum . '28)');
    }

    // ตั้งค่าความกว้างของคอลัมน์ชั่วโมง
    $sheet->getColumnDimension('A')->setWidth(10);
    
    // ตั้งค่าสไตล์ของตาราง
    $styleArray = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN
            ]
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ];
    
    $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($dates) + 1);
    $lastRow = $hour + 6 - 1; // 6-1 = sum
    $sheet->getStyle('A5:' . $lastColumn . $lastRow)->applyFromArray($styleArray);
    
    // ตั้งค่าสไตล์หัวตาราง
    $headerStyleArray = [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'color' => ['rgb' => 'D9D9D9']
        ],
        'font' => [
            'bold' => true
        ]
    ];
    
    $sheet->getStyle('A5:' . $lastColumn . '5')->applyFromArray($headerStyleArray);
    $sheet->getStyle('A29:' . $lastColumn . '29')->applyFromArray($headerStyleArray);  // sum
    $sheet->getStyle('A5:A28')->applyFromArray($headerStyleArray);

    // สร้าง Sheet ที่สองสำหรับข้อมูลล่าสุด 20 รายการ
    // $spreadsheet->createSheet();
    // $sheet2 = $spreadsheet->getSheet(1);
    // $sheet2->setTitle('ข้อมูลล่าสุด 20 รายการ');
    
    // // คำสั่ง SQL ดึงข้อมูลล่าสุด 20 รายการ
    // $sql = "SELECT `ID`, `Time`, `Voltage`, `Current`, `Frequency`, `Power`, `PF`, `Energy`, `Tem`, `Hum` 
    //         FROM `room` 
    //         ORDER BY `Time` DESC 
    //         LIMIT 20";
    
    // $stmt = $conn->prepare($sql);
    // $stmt->execute();
    // $recent_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // // เพิ่มหัวตาราง
    // $headers = [
    //     'A' => 'ID',
    //     'B' => 'เวลา',
    //     'C' => 'แรงดันไฟฟ้า (V)',
    //     'D' => 'กระแสไฟฟ้า (A)',
    //     'E' => 'ความถี่ (Hz)',
    //     'F' => 'กำลังไฟฟ้า (W)',
    //     'G' => 'Power Factor',
    //     'H' => 'พลังงาน (kWh)',
    //     'I' => 'อุณหภูมิ (°C)',
    //     'J' => 'ความชื้น (%)'
    // ];
    
    // foreach ($headers as $col => $header) {
    //     $sheet2->setCellValue($col . '1', $header);
    //     $sheet2->getColumnDimension($col)->setWidth(15);
    // }
    
    // // ตั้งค่าความกว้างของคอลัมน์เวลา
    // $sheet2->getColumnDimension('B')->setWidth(20);
    
    // // เพิ่มข้อมูล
    // $row = 2;
    // foreach ($recent_data as $data) {
    //     $sheet2->setCellValue('A' . $row, $data['ID']);
    //     $sheet2->setCellValue('B' . $row, $data['Time']);
    //     $sheet2->setCellValue('C' . $row, $data['Voltage']);
    //     $sheet2->setCellValue('D' . $row, $data['Current']);
    //     $sheet2->setCellValue('E' . $row, $data['Frequency']);
    //     $sheet2->setCellValue('F' . $row, $data['Power']);
    //     $sheet2->setCellValue('G' . $row, $data['PF']);
    //     $sheet2->setCellValue('H' . $row, $data['Energy']);
    //     $sheet2->setCellValue('I' . $row, $data['Tem']);
    //     $sheet2->setCellValue('J' . $row, $data['Hum']);
    //     $row++;
    // }
    
    // // ตั้งค่าสไตล์ตาราง
    // $sheet2->getStyle('A1:J1')->applyFromArray($headerStyleArray);
    // $sheet2->getStyle('A1:J' . ($row - 1))->applyFromArray($styleArray);
    
    // // กำหนดชื่อไฟล์
    // $filename = 'รายงานการใช้พลังงาน_' . date('Y-m-d_His') . '.xlsx';
    
    // ดาวน์โหลดไฟล์
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="ประมาณการผลิตไฟฟ้า ' . $start_date . '_' . $end_date . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
} catch (PDOException $e) {
    echo 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
