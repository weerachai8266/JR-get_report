document.addEventListener('DOMContentLoaded', function() {
    // ตั้งค่าปีปัจจุบันในส่วน footer
    document.getElementById('current-year').textContent = new Date().getFullYear();
    
    // ตั้งค่า DatePicker
    const today = new Date();
    const oneWeekAgo = new Date();
    oneWeekAgo.setDate(today.getDate() - 7);
    const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    
    // สร้างตัวเลือก datepicker พร้อมลูกเล่น
    flatpickr("#start-date", {
        dateFormat: "Y-m-d",
        locale: "th",
        defaultDate: firstDayOfMonth,
        animate: true,
        allowInput: true,
        disableMobile: true,
        monthSelectorType: 'static',
        onClose: function(selectedDates, dateStr, instance) {
            // ตรวจสอบว่าวันที่เริ่มต้นไม่เกินวันที่สิ้นสุด
            const endDatePicker = document.querySelector("#end-date")._flatpickr;
            if (selectedDates[0] > endDatePicker.selectedDates[0]) {
                endDatePicker.setDate(selectedDates[0]);
            }
        }
    });
    
    flatpickr("#end-date", {
        dateFormat: "Y-m-d",
        locale: "th",
        defaultDate: today,
        animate: true,
        allowInput: true,
        disableMobile: true,
        monthSelectorType: 'static',
        onClose: function(selectedDates, dateStr, instance) {
            // ตรวจสอบว่าวันที่สิ้นสุดไม่น้อยกว่าวันที่เริ่มต้น
            const startDatePicker = document.querySelector("#start-date")._flatpickr;
            if (selectedDates[0] < startDatePicker.selectedDates[0]) {
                startDatePicker.setDate(selectedDates[0]);
            }
        }
    });
    
    // แสดงการอัพเดทเวลาล่าสุด
    updateLastUpdatedTime();
    
    // อัพเดทข้อความตามโหมดการแสดงผล
    updateDisplayMode();
    
    // โหลดข้อมูลเริ่มต้น
    loadData();
    
    // เมื่อคลิกปุ่มค้นหา
    document.getElementById('search-btn').addEventListener('click', function() {
        loadData();
    });
    
    // เมื่อเปลี่ยนโหมดการแสดงผล
    document.querySelectorAll('input[name="displayMode"]').forEach(radio => {
        radio.addEventListener('change', function() {
            updateDisplayMode();
            loadData();
        });
    });
    
    // เมื่อคลิกปุ่ม Export Excel
    document.getElementById('export-excel').addEventListener('click', function() {
        exportToExcel();
    });
    
    // เพิ่มการกด Enter ในช่องวันที่
    document.querySelectorAll('#start-date, #end-date').forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                loadData();
            }
        });
    });
});

// แสดงเวลาอัปเดตล่าสุด
function updateLastUpdatedTime() {
    const now = new Date();
    const options = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    };
    const formattedDate = now.toLocaleString('th-TH', options);
    document.getElementById('last-updated').textContent = formattedDate;
}

// อัพเดทข้อความตามโหมดการแสดงผล
function updateDisplayMode() {
    const modeElement = document.querySelector('input[name="displayMode"]:checked');
    const mode = modeElement ? modeElement.value : 'hourly';
    const dataDescription = document.querySelector('.card-header p.text-muted');
    
    if (dataDescription) {
        if (mode === 'hourly') {
            dataDescription.textContent = 'ค่าพลังงานล่าสุดในแต่ละชั่วโมง (kWh)';
        } else if (mode === 'quarter') {
            dataDescription.textContent = 'ค่าพลังงานล่าสุดทุก 15 นาที (kWh)';
        }
    }
}

// แสดง loading
function showLoading() {
    document.getElementById('loading').classList.remove('d-none');
}

// ซ่อน loading
function hideLoading() {
    document.getElementById('loading').classList.add('d-none');
}

// โหลดข้อมูล
function loadData() {
    showLoading();
    const startDate = document.getElementById('start-date').value;
    const endDate = document.getElementById('end-date').value;
    const modeElement = document.querySelector('input[name="displayMode"]:checked');
    const mode = modeElement ? modeElement.value : 'hourly';
    
    // ตรวจสอบว่าวันที่ถูกกรอกไหม
    if (!startDate || !endDate) {
        hideLoading();
        showNotification('กรุณาเลือกวันที่ให้ครบถ้วน', 'warning');
        return;
    }
    
    // ดึงข้อมูล Energy แบบ pivot
    fetch(`api/get_energy_pivot.php?start_date=${startDate}&end_date=${endDate}&mode=${mode}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            renderEnergyPivot(data);
            updateLastUpdatedTime();
            hideLoading();
            showNotification('โหลดข้อมูลเรียบร้อย', 'success');
        })
        .catch(error => {
            console.error('Error:', error);
            hideLoading();
            showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล: ' + error.message, 'danger');
        });
        
    // ดึงข้อมูลล่าสุด 20 รายการ (ถ้ามีการใช้งาน)
    // fetch(`api/get_recent_data.php`)
    //     .then(response => response.json())
    //     .then(data => {
    //         renderRecentData(data);
    //     })
    //     .catch(error => {
    //         console.error('Error:', error);
    //     });
}

// แสดงข้อความแจ้งเตือน
function showNotification(message, type = 'info') {
    // ยกเลิก timer เก่า (ถ้ามี)
    if (window.notificationTimer) {
        clearTimeout(window.notificationTimer);
    }
    
    // ถ้ามี element แจ้งเตือนอยู่แล้ว ให้ลบออกก่อน
    const existingAlert = document.querySelector('.alert-notification');
    if (existingAlert) {
        existingAlert.classList.remove('show');
        setTimeout(() => {
            existingAlert.remove();
            createNewNotification();
        }, 300);
    } else {
        createNewNotification();
    }
    
    // ฟังก์ชันสร้างการแจ้งเตือนใหม่
    function createNewNotification() {
        // สร้าง element แจ้งเตือน
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show alert-notification animate__animated animate__fadeInRight`;
        alert.style.position = 'fixed';
        alert.style.top = '20px';
        alert.style.right = '20px';
        alert.style.zIndex = '9999';
        alert.style.maxWidth = '400px';
        alert.style.boxShadow = '0 4px 8px rgba(0,0,0,0.2)';
        alert.style.borderLeft = type === 'success' ? '5px solid #28a745' :
                                 type === 'warning' ? '5px solid #ffc107' :
                                 type === 'danger' ? '5px solid #dc3545' : '5px solid #17a2b8';
        
        // กำหนด icon ตามประเภทของแจ้งเตือน
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        if (type === 'danger') icon = 'exclamation-circle';
        
        alert.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-${icon} me-2"></i>
                <div>${message}</div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        // เพิ่มเข้าไปใน body
        document.body.appendChild(alert);
        
        // เพิ่มความสามารถในการปิดการแจ้งเตือนด้วยการคลิก
        alert.addEventListener('click', () => {
            alert.classList.remove('show');
            alert.classList.add('animate__fadeOutRight');
            setTimeout(() => {
                alert.remove();
            }, 300);
        });
        
        // หลังจาก 5 วินาที ให้ลบออกอัตโนมัติ
        window.notificationTimer = setTimeout(() => {
            alert.classList.remove('show');
            alert.classList.add('animate__fadeOutRight');
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    }
}

function renderEnergyPivot(data) {
    const dateHeaderRow = document.getElementById('date-header-row');
    const energyData = document.getElementById('energy-data');
    const modeElement = document.querySelector('input[name="displayMode"]:checked');
    const mode = modeElement ? modeElement.value : 'hourly';
    
    // ล้างข้อมูลเก่า
    while (dateHeaderRow.childElementCount > 1) {
        dateHeaderRow.removeChild(dateHeaderRow.lastChild);
    }
    energyData.innerHTML = '';
    
    // ตรวจสอบว่ามีข้อมูลไหม
    if (!data.dates || data.dates.length === 0) {
        const noDataRow = document.createElement('tr');
        const noDataCell = document.createElement('td');
        noDataCell.colSpan = 2;
        noDataCell.className = 'text-center p-5';
        noDataCell.innerHTML = `
            <div class="py-5">
                <i class="fas fa-exclamation-circle fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">ไม่พบข้อมูลในช่วงเวลาที่เลือก</h5>
                <p class="text-muted">โปรดลองเลือกช่วงวันที่อื่น</p>
            </div>
        `;
        noDataRow.appendChild(noDataCell);
        energyData.appendChild(noDataRow);
        return;
    }
    
    // เพิ่มส่วนหัวของตาราง (วันที่)
    const dates = data.dates;
    dates.forEach(date => {
        const th = document.createElement('th');
        const displayDate = new Date(date).toLocaleDateString('th-TH', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
        th.innerHTML = `<i class="fas fa-calendar-day me-1"></i>${displayDate}`;
        th.className = 'date-header';
        dateHeaderRow.appendChild(th);
    });
    
    if (mode === 'hourly') {
        // สร้างแถวสำหรับแต่ละชั่วโมง
        for (let hour = 0; hour < 24; hour++) {
            const tr = document.createElement('tr');
            
            // สร้างเซลล์แสดงชั่วโมง
            const hourCell = document.createElement('td');
            hourCell.textContent = `${hour.toString().padStart(2, '0')}:00`;
            hourCell.className = 'hour-cell';
            tr.appendChild(hourCell);
            
            // สร้างเซลล์สำหรับแต่ละวัน
            dates.forEach(date => {
                const td = document.createElement('td');
                const hourKey = hour.toString().padStart(2, '0');
                
                if (data.energy[date] && data.energy[date][hourKey] !== undefined) {
                    td.textContent = parseFloat(data.energy[date][hourKey]).toFixed(2);
                } else {
                    td.textContent = '-';
                }
                
                td.className = 'value-cell';
                tr.appendChild(td);
            });
            
            energyData.appendChild(tr);
        }
    } else if (mode === 'quarter') {
        // สร้างแถวสำหรับทุก 15 นาที
        for (let hour = 0; hour < 24; hour++) {
            const hourStr = hour.toString().padStart(2, '0');
            
            for (let minute = 0; minute < 60; minute += 15) {
                const tr = document.createElement('tr');
                const minuteStr = minute.toString().padStart(2, '0');
                
                // สร้างเซลล์แสดงเวลา (HH:MM)
                const timeCell = document.createElement('td');
                timeCell.textContent = `${hourStr}:${minuteStr}`;
                timeCell.className = minute === 0 ? 'hour-cell' : 'time-cell';
                tr.appendChild(timeCell);
                
                // สร้างเซลล์สำหรับแต่ละวัน
                dates.forEach(date => {
                    const td = document.createElement('td');
                    // รูปแบบ key ที่ต้องตรงกับที่ API ส่งกลับมา
                    const timeKey = `${hourStr}:${minuteStr}`;
                    
                    if (data.energy[date] && data.energy[date][timeKey] !== undefined) {
                        td.textContent = parseFloat(data.energy[date][timeKey]).toFixed(2);
                    } else {
                        td.textContent = '-';
                    }
                    
                    td.className = 'value-cell';
                    tr.appendChild(td);
                });
                
                energyData.appendChild(tr);
            }
        }
    }
}

function renderRecentData(data) {
    const recentDataBody = document.getElementById('recent-data-body');
    recentDataBody.innerHTML = '';
    
    data.forEach(item => {
        const tr = document.createElement('tr');
        
        tr.innerHTML = `
            <td>${item.ID}</td>
            <td>${formatDateTime(item.Time)}</td>
            <td>${parseFloat(item.Voltage).toFixed(1)}</td>
            <td>${parseFloat(item.Current).toFixed(2)}</td>
            <td>${parseFloat(item.Frequency).toFixed(1)}</td>
            <td>${parseFloat(item.Power).toFixed(0)}</td>
            <td>${parseFloat(item.PF).toFixed(2)}</td>
            <td>${parseFloat(item.Energy).toFixed(2)}</td>
            <td>${parseFloat(item.Tem).toFixed(1)}</td>
            <td>${parseFloat(item.Hum).toFixed(1)}</td>
        `;
        
        recentDataBody.appendChild(tr);
    });
    
    // ตั้งค่า DataTable
    if ($.fn.DataTable.isDataTable('#recent-data')) {
        $('#recent-data').DataTable().destroy();
    }
    
    $('#recent-data').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json'
        },
        pageLength: 10
    });
}

function formatDateTime(dateTimeStr) {
    const date = new Date(dateTimeStr);
    return date.toLocaleString('th-TH');
}

function exportToExcel() {
    const startDate = document.getElementById('start-date').value;
    const endDate = document.getElementById('end-date').value;
    const modeElement = document.querySelector('input[name="displayMode"]:checked');
    const mode = modeElement ? modeElement.value : 'hourly';
    
    // ตรวจสอบว่าวันที่ถูกกรอกไหม
    if (!startDate || !endDate) {
        showNotification('กรุณาเลือกวันที่ให้ครบถ้วนก่อนส่งออกข้อมูล', 'warning');
        return;
    }
    
    showLoading();
    document.querySelector('.loading-message').textContent = 'กำลังเตรียมข้อมูลสำหรับส่งออก...';
    
    // ตรวจสอบการเชื่อมต่อก่อนส่งออก
    fetch(`api/check_data.php?start_date=${startDate}&end_date=${endDate}&mode=${mode}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('ไม่สามารถเชื่อมต่อกับระบบได้');
            }
            return response.json();
        })
        .then(data => {
            if (data && data.hasData) {
                // ดำเนินการส่งออกเมื่อมีข้อมูล
                window.location.href = `api/export_excel.php?start_date=${startDate}&end_date=${endDate}&mode=${mode}`;
                showNotification('เริ่มดาวน์โหลดไฟล์ Excel แล้ว', 'success');
            } else {
                // แจ้งเตือนเมื่อไม่มีข้อมูล
                showNotification('ไม่พบข้อมูลในช่วงวันที่ที่เลือก', 'warning');
            }
            
            // ซ่อนสถานะการโหลดหลังจากเริ่มดาวน์โหลด
            setTimeout(() => {
                hideLoading();
            }, 1500);
        })
        .catch(error => {
            console.error('Error:', error);
            hideLoading();
            showNotification('เกิดข้อผิดพลาดในการส่งออกข้อมูล: ' + error.message, 'danger');
        });
}
