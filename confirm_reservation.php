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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['res_id'])) {
    $res_id = $_POST['res_id'];
    $discount = isset($_POST['discount']) ? floatval($_POST['discount']) / 100 : 0; // 將百分比轉換為小數
    
    // 開始交易
    $conn->begin_transaction();
    
    try {
        // 1. 獲取訂單資訊
        $res_stmt = $conn->prepare("
            SELECT 
                r.*,
                GROUP_CONCAT(DISTINCT rm.r_id) as room_ids,
                SUM(rm.r_price * r.days) as total_room_cost,
                r.days
            FROM RESERVATION r
            JOIN RESERVATION_ROOM rr ON r.res_id = rr.res_id
            JOIN ROOM rm ON rr.r_id = rm.r_id
            WHERE r.res_id = ?
            GROUP BY r.res_id
        ");
        $res_stmt->bind_param("i", $res_id);
        $res_stmt->execute();
        $reservation = $res_stmt->get_result()->fetch_assoc();
        
        if (!$reservation) {
            throw new Exception("找不到訂單");
        }

        // 2. 計算服務總額
        $service_total = 0;
        $service_stmt = $conn->prepare("
            SELECT 
                s.s_id,
                s.s_price,
                sd.quantity
            FROM SERVICEDETAIL sd
            JOIN SERVICE s ON sd.s_id = s.s_id
            WHERE sd.res_id = ?
        ");
        $service_stmt->bind_param("i", $res_id);
        $service_stmt->execute();
        $service_result = $service_stmt->get_result();
        
        while ($service = $service_result->fetch_assoc()) {
            $service_total += ($service['s_price'] * $service['quantity']);
        }
        
        // 3. 創建帳單
        $total_cost = $reservation['total_room_cost'];
        $total_amount = $total_cost + $service_total;
        $discounted_amount = $total_amount * (1 - $discount);

        $bill_stmt = $conn->prepare("
            INSERT INTO BILL (res_id, r_cost, service_total, discount, r_total)
            VALUES (?, ?, ?, ?, ?)
        ");
        $bill_stmt->bind_param("idddd", 
            $res_id, 
            $total_cost,
            $service_total,
            $discount,
            $discounted_amount
        );
        
        if (!$bill_stmt->execute()) {
            throw new Exception("帳單建立失敗：" . $bill_stmt->error);
        }
        
        // 4. 更新訂單狀態
        $update_stmt = $conn->prepare("
            UPDATE RESERVATION 
            SET status = '未完成'
            WHERE res_id = ?
        ");
        $update_stmt->bind_param("i", $res_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("訂單狀態更新失敗：" . $update_stmt->error);
        }
        
        // 提交交易
        $conn->commit();
        
        $_SESSION['discount_update_success'] = true;
        header('Location: manager.php#reservations');
        exit();
    } catch (Exception $e) {
        // 回滾交易
        $conn->rollback();
        $_SESSION['discount_update_error'] = $e->getMessage();
        header('Location: manager.php#reservations');
        exit();
    }
    
    $res_stmt->close();
    $bill_stmt->close();
    $update_stmt->close();
    if (isset($service_stmt)) {
        $service_stmt->close();
    }
} else {
    $_SESSION['discount_update_error'] = '無效的請求';
    header('Location: manager.php#reservations');
    exit();
}

$conn->close();
?> 
