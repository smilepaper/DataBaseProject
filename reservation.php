<?php
session_start();

// 檢查是否登入
if (!isset($_SESSION['login_session'])) {
    header('Location: index.php');
    exit();
}

// 檢查必要參數
if (!isset($_GET['type']) || !isset($_GET['price'])) {
    header('Location: home.php');
    exit();
}

// 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'hotelreservation'); // 確保資料庫名稱正確
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

// 取得用戶資料
// 根據 fixed_hotel_sql (1).sql 中 user 表與 customer 表的關聯 (customer.c_id = user.u_id)
$stmt = $conn->prepare("
    SELECT c.c_id, c.c_info_name, c.c_info_phone, c.c_info_email 
    FROM CUSTOMER c
    JOIN USER u ON c.c_id = u.u_id
    WHERE u.u_account = ?
");
$stmt->bind_param("s", $_SESSION['login_session']);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    die("無法找到用戶資料");
}

// 儲存用戶ID供後續使用
$customer_id = $customer['c_id'];

// 取得房型資訊
$room_type = $_GET['type'];
$room_price = $_GET['price'];

// 取得房型名稱
$stmt = $conn->prepare("
    SELECT DISTINCT
    CASE 
        WHEN r_type = 1 THEN '標準單人房'
        WHEN r_type = 2 THEN '標準雙人房'
        WHEN r_type = 3 THEN '標準三人房'
        WHEN r_type = 4 AND r_price = 4000 THEN '標準四人房'
        WHEN r_type = 4 AND r_price = 6000 THEN '豪華四人房'
        WHEN r_type = 6 THEN '標準六人房'
    END as type_name,
    r_price
    FROM ROOM 
    WHERE r_type = ? AND r_price = ?
    LIMIT 1
");
$stmt->bind_param("ii", $room_type, $room_price);
$stmt->execute();
$room_info = $stmt->get_result()->fetch_assoc();

// 取得所有可用服務並存儲到 $all_services 陣列中
$services_stmt = $conn->prepare("SELECT s_id, s_type, s_price FROM SERVICE ORDER BY s_id");
$services_stmt->execute();
$services_result = $services_stmt->get_result(); 

$all_services = [];
if ($services_result) { 
    while($service_row = $services_result->fetch_assoc()) {
        $all_services[$service_row['s_id']] = [
            'price' => $service_row['s_price'],
            'type' => $service_row['s_type']
        ];
    }
    $services_result->data_seek(0); // 將指標重置到開頭，以便在 HTML 輸出部分再次遍歷
}

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkin_date = $_POST['checkin_date'];
    $checkout_date = $_POST['checkout_date'];
    $customer_name = $_POST['customer_name'];
    $customer_phone = $_POST['customer_phone'];
    $customer_email = $_POST['customer_email'];
    
    // --- DEBUGGING START ---
    error_log("接收到的 POST 資料: " . print_r($_POST, true)); 
    // --- DEBUGGING END ---

    $selected_services_for_session = []; // 用於 Session 的服務陣列 (s_id => quantity)
    $service_total = 0; // 用於計算總價的服務費用

    if (isset($_POST['services']) && is_array($_POST['services'])) {
        error_log("POST['services'] 已設定且為陣列。"); 
        foreach ($_POST['services'] as $s_id => $quantity_selected) {
            if (isset($all_services[$s_id])) { 
                $service_price = $all_services[$s_id]['price'];
                $selected_services_for_session[$s_id] = (int)$quantity_selected; 
                $service_total += ($service_price * (int)$quantity_selected); 
            } else {
                error_log("從 POST 收到的服務 ID $s_id 在 \$all_services 中未找到。"); 
            }
        }
    } else {
        error_log("POST['services'] 未設定或不是陣列。"); 
    }
    
    // --- DEBUGGING START ---
    error_log("最終的 selected_services_for_session: " . print_r($selected_services_for_session, true));
    error_log("計算出的 service_total: " . $service_total);
    // --- DEBUGGING END ---

    // 驗證日期
    $today = new DateTime();
    $checkin = new DateTime($checkin_date);
    $checkout = new DateTime($checkout_date);
    
    $errors = [];
    
    if ($checkin < $today) {
        $errors[] = "入住日期不能早於今天";
    }
    if ($checkout <= $checkin) {
        $errors[] = "退房日期必須晚於入住日期";
    }

    // 計算預訂天數
    $checkin_dt = new DateTime($checkin_date);
    $checkout_dt = new DateTime($checkout_date);
    $interval = $checkin_dt->diff($checkout_dt);
    $days = $interval->days;
    if ($days == 0) { // 如果是當天入住當天退房，算一天
        $days = 1;
    }

    if (empty($errors)) {
        // 檢查房間是否可用
        $stmt = $conn->prepare("
            SELECT r.r_id, r.r_type, r.r_price
            FROM ROOM r
            WHERE r.r_type = ? 
            AND r.r_price = ?
            AND r.r_id NOT IN (
                SELECT rr.r_id
                FROM RESERVATION_ROOM rr
                JOIN RESERVATION res ON rr.res_id = res.res_id
                WHERE (res.res_checkindate <= ? AND res.res_checkoutdate >= ?)
            )
            ORDER BY r.r_id ASC
            LIMIT 1
        ");

        $stmt->bind_param("iiss", 
            $room_type, 
            $room_price, 
            $checkout_date, 
            $checkin_date   
        );
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $errors[] = "選擇的日期內沒有可用房間";
        } else {
            $available_room = $result->fetch_assoc();
            
            // 開始交易
            $conn->begin_transaction();
            
            try {
                // 建立預訂記錄
                // RESERVATION 表欄位: c_id, res_checkindate, res_checkoutdate, res_date, status, selected_addservice
                // res_date 使用 CURDATE()，所以不需要綁定變數
                $stmt_res = $conn->prepare("
                    INSERT INTO RESERVATION (c_id, res_checkindate, res_checkoutdate, res_date, status, selected_addservice) 
                    VALUES (?, ?, ?, CURDATE(), ?, ?)
                ");
                // `status` 和 `selected_addservice` 在 SQL 中是 `tinyint(1)`
                $status = 0; // 0代表未完成/待確認
                $selected_addservice_flag = 0; // 初始設為0，不代表是否有附加服務，僅為資料庫結構所需
                
                // 修正 bind_param：對應 c_id, res_checkindate, res_checkoutdate, status, selected_addservice
                // 類型：i (int), s (string), s (string), i (int), i (int)
                $stmt_res->bind_param("issii", $customer_id, $checkin_date, $checkout_date, $status, $selected_addservice_flag);
                
                if (!$stmt_res->execute()) {
                    throw new Exception("預訂記錄建立失敗：" . $stmt_res->error);
                }
                
                $res_id = $conn->insert_id; // 獲取自動生成的 res_id

                // 建立房間預訂關聯
                $stmt_rr = $conn->prepare("
                    INSERT INTO RESERVATION_ROOM (r_id, res_id) 
                    VALUES (?, ?)
                ");
                $stmt_rr->bind_param("si", $available_room['r_id'], $res_id);
                
                if (!$stmt_rr->execute()) {
                    throw new Exception("房間預訂關聯建立失敗：" . $stmt_rr->error);
                }
                
                $total_cost = $room_info['r_price'] * $days; // 房間總費用
                $discount_amount = 0.00; // 顧客預訂時沒有折扣，折扣由管理者輸入
                
                // 預先計算 r_total 以便插入到 BILL 表
                $r_total_calculated = $total_cost + $service_total - $discount_amount;
                
                // **重要：將帳單資訊寫入 BILL 表**
                // 根據 fixed_hotel_sql (1).sql，bill 表有 discount (float) 和 r_total (decimal(10,2))
                $stmt_bill = $conn->prepare("
                    INSERT INTO BILL (res_id, r_cost, service_total, discount, r_total)
                    VALUES (?, ?, ?, ?, ?)
                ");
                if ($stmt_bill === false) { 
                    throw new Exception("BILL 預處理失敗: " . $conn->error);
                }
                // ***修正：將 'f' 替換為 'd'***
                // 'iiddd' for res_id (int), r_cost (int), service_total (float->d), discount (float->d), r_total (decimal->d)
                $stmt_bill->bind_param("iiddd", $res_id, $total_cost, $service_total, $discount_amount, $r_total_calculated); 
                if (!$stmt_bill->execute()) {
                    throw new Exception("帳單建立失敗：" . $stmt_bill->error);
                }
                $b_id = $conn->insert_id; // 獲取自動生成的 b_id

                // --- DEBUGGING START ---
                error_log("帳單ID (b_id) 已生成: " . $b_id);
                // --- DEBUGGING END ---

                // **重要：將附加服務細節寫入 SERVICEDETAIL 表**
                // 根據 fixed_hotel_sql (1).sql，servicedetail 表只有 s_id, res_id, quantity
                if (!empty($selected_services_for_session)) {
                    foreach ($selected_services_for_session as $s_id_val => $quantity_val) {
                        $stmt_sd = $conn->prepare("
                            INSERT INTO SERVICEDETAIL (s_id, res_id, quantity)
                            VALUES (?, ?, ?)
                        ");
                        if ($stmt_sd === false) { 
                            throw new Exception("SERVICEDETAIL 預處理失敗 (內層迴圈): " . $conn->error);
                        }
                        // 'sii' for s_id (char/string), res_id (int), quantity (int)
                        $stmt_sd->bind_param("sii", $s_id_val, $res_id, $quantity_val);
                        if (!$stmt_sd->execute()) {
                            throw new Exception("服務細節建立失敗：" . $stmt_sd->error);
                        }
                        $stmt_sd->close(); 
                    }
                    error_log("SERVICEDETAIL 插入完成。");
                } else {
                    error_log("selected_services_for_session 為空，未執行 SERVICEDETAIL 插入。");
                }

                $conn->commit(); // 提交交易
                echo "<script>
                    alert('預訂成功！請等待管理員確認。');
                    window.parent.location.href = 'customer.php';
                    window.parent.document.querySelector('.modal').querySelector('.btn-close').click();
                </script>";
                exit();
                
            } catch (Exception $e) {
                $conn->rollback(); // 發生錯誤時回滾交易
                $errors[] = "預訂失敗：" . $e->getMessage();
                error_log("預訂失敗： " . $e->getMessage()); // 記錄錯誤到日誌
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>預訂房間</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background: none;
            padding: 0;
        }
        .navbar {
            display: none;
        }
        .container {
            padding: 0;
        }
        .card {
            border: none;
            box-shadow: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5><?php echo htmlspecialchars($room_info['type_name']); ?></h5>
                        <p class="text-muted">每晚 NT$ <?php echo number_format($room_info['r_price']); ?></p>
                    </div>
                </div>

                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="customer_name" class="form-label">姓名</label>
                            <input type="text" class="form-control" id="customer_name" name="customer_name" 
                                   value="<?php echo htmlspecialchars($customer['c_info_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="customer_phone" class="form-label">電話</label>
                            <input type="tel" class="form-control" id="customer_phone" name="customer_phone" 
                                   value="<?php echo htmlspecialchars($customer['c_info_phone'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12">
                            <label for="customer_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="customer_email" name="customer_email" 
                                   value="<?php echo htmlspecialchars($customer['c_info_email'] ?? ''); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="checkin_date" class="form-label">入住日期</label>
                            <input type="date" class="form-control" id="checkin_date" name="checkin_date" required>
                        </div>
                        <div class="col-md-6">
                            <label for="checkout_date" class="form-label">退房日期</label>
                            <input type="date" class="form-control" id="checkout_date" name="checkout_date" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">附加服務</label>
                            <div class="row">
                                <?php 
                                if ($services_result) { 
                                    $services_result->data_seek(0); 
                                }
                                while($service = ($services_result ? $services_result->fetch_assoc() : null)): 
                                    if (!$service) break; 
                                ?>
                                <div class="col-md-4 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input service-checkbox" type="checkbox" 
                                               name="services[<?php echo htmlspecialchars($service['s_id']); ?>]" 
                                               value="1" 
                                               id="service<?php echo htmlspecialchars($service['s_id']); ?>"
                                               data-price="<?php echo htmlspecialchars($service['s_price']); ?>">
                                        <label class="form-check-label" for="service<?php echo htmlspecialchars($service['s_id']); ?>">
                                            <?php echo htmlspecialchars($service['s_type']); ?> 
                                            (NT$ <?php echo number_format($service['s_price']); ?>)
                                        </label>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">確認預訂</button>
                            <a href="home.php" class="btn btn-secondary">取消</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 設定日期選擇器的最小值為今天
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('checkin_date').min = today;
        document.getElementById('checkout_date').min = today;
        
        // 當入住日期改變時，更新退房日期的最小值
        document.getElementById('checkin_date').addEventListener('change', function() {
            document.getElementById('checkout_date').min = this.value;
            // 如果退房日期早於新的入住日期，則重置退房日期
            if (document.getElementById('checkout_date').value < this.value) {
                document.getElementById('checkout_date').value = this.value;
            }
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>