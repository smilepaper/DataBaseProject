<?php
session_start();

// 檢查是否為管理員
if (!isset($_SESSION['login_session']) || $_SESSION['role'] !== 'MANAGER') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '權限不足']);
    exit();
}

// 檢查必要參數
if (!isset($_POST['res_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '缺少必要參數']);
    exit();
}

$res_id = $_POST['res_id'];

// 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'HOTELRESERVATION');
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '資料庫連線失敗：' . $conn->connect_error]);
    exit();
}

// 開始交易
$conn->begin_transaction();

try {
    // 檢查訂單是否已經確認
    $check_stmt = $conn->prepare("SELECT b_id FROM BILL WHERE res_id = ?");
    $check_stmt->bind_param("i", $res_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        throw new Exception("此訂單已經確認過了");
    }

    // 更新預訂狀態
    $stmt = $conn->prepare("
        UPDATE RESERVATION 
        SET status = '已完成' 
        WHERE res_id = ?
    ");
    $stmt->bind_param("i", $res_id);
    
    if (!$stmt->execute()) {
        throw new Exception("更新預訂狀態失敗：" . $stmt->error);
    }

    // 建立帳單
    $stmt = $conn->prepare("
        INSERT INTO BILL (res_id, r_cost, service_total, r_total) 
        SELECT 
            r.res_id,
            r.days * rm.r_price as r_cost,
            COALESCE(SUM(s.s_price), 0) as service_total,
            (r.days * rm.r_price) + COALESCE(SUM(s.s_price), 0) as r_total
        FROM RESERVATION r
        JOIN RESERVATION_ROOM rr ON r.res_id = rr.res_id
        JOIN ROOM rm ON rr.r_id = rm.r_id
        LEFT JOIN SERVICEDETAIL sd ON sd.res_id = r.res_id
        LEFT JOIN SERVICE s ON sd.s_id = s.s_id
        WHERE r.res_id = ?
        GROUP BY r.res_id, rm.r_price
    ");
    $stmt->bind_param("i", $res_id);
    
    if (!$stmt->execute()) {
        throw new Exception("建立帳單失敗：" . $stmt->error);
    }

    $bill_id = $conn->insert_id;

    // 將服務詳細資訊寫入 SERVICEDETAIL 表格
    if (isset($_SESSION['pending_bill']['selected_services']) && !empty($_SESSION['pending_bill']['selected_services'])) {
        foreach ($_SESSION['pending_bill']['selected_services'] as $s_id_from_session => $s_price) {
            // 假設 quantity 預設為 1，如果需要用戶輸入，則從前端獲取
            $quantity = 1; 

            // 重要的修改：
            // 1. 包含 quantity 和 b_id 欄位
            // 2. 修改 bind_param 的類型： 's' for s_id (char), 'i' for res_id (int), 'i' for quantity (int), 'i' for b_id (int)
            // 3. 使用正確的 $s_id_from_session 和 $bill_id
            $stmt = $conn->prepare("INSERT INTO SERVICEDETAIL (s_id, res_id, quantity, b_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("siii", $s_id_from_session, $res_id, $quantity, $bill_id); 
            
            if (!$stmt->execute()) {
                throw new Exception("寫入服務詳細資訊失敗：" . $stmt->error);
            }
        }
        unset($_SESSION['pending_bill']['selected_services']); // 清除 session 中的服務資訊
    }

    // 提交交易
    $conn->commit();

    // 回傳成功訊息
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => '訂單確認成功',
        'bill_id' => $bill_id
    ]);

} catch (Exception $e) {
    // 回滾交易
    $conn->rollback();
    
    // 回傳錯誤訊息
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// 關閉資料庫連線
$conn->close();
?> 
