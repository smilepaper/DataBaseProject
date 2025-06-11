<?php
session_start();

// 檢查是否登入且為管理員
if (!isset($_SESSION['login_session']) || $_SESSION['role'] !== 'MANAGER') {
    header('Location: index.php');
    exit();
}

// 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'HOTELRESERVATION');
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bill_id']) && isset($_POST['discount'])) {
    $bill_id = $_POST['bill_id'];
    $discount = floatval($_POST['discount']) / 100; // 將百分比轉換為小數
    
    // 檢查訂單狀態
    $check_stmt = $conn->prepare("
        SELECT r.status 
        FROM BILL b 
        JOIN RESERVATION r ON b.res_id = r.res_id 
        WHERE b.b_id = ?
    ");
    $check_stmt->bind_param("i", $bill_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $reservation = $result->fetch_assoc();
    
    if ($reservation['status'] !== '未完成') {
        $_SESSION['discount_update_error'] = "已完成的訂單無法修改優惠";
        header('Location: manager.php#reservations');
        exit();
    }
    
    // 更新優惠
    $update_stmt = $conn->prepare("UPDATE BILL SET discount = ? WHERE b_id = ?");
    $update_stmt->bind_param("di", $discount, $bill_id);
    
    if ($update_stmt->execute()) {
        // 更新總金額
        $update_total_stmt = $conn->prepare("
            UPDATE BILL 
            SET r_total = ROUND((r_cost + service_total) * (1 - COALESCE(discount, 0)), 2)
            WHERE b_id = ?
        ");
        $update_total_stmt->bind_param("i", $bill_id);
        $update_total_stmt->execute();
        
        $_SESSION['discount_update_success'] = true;
    } else {
        $_SESSION['discount_update_error'] = $conn->error;
    }
    
    $check_stmt->close();
    $update_stmt->close();
    $update_total_stmt->close();
}

$conn->close();
header('Location: manager.php#reservations');
exit();
?> 