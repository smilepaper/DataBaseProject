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
$conn = new mysqli('localhost', 'root', '', 'HOTELRESERVATION');
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

// 取得用戶資料 - 通過 USER 表關聯查詢，使用正確的欄位名稱
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

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkin_date = $_POST['checkin_date'];
    $checkout_date = $_POST['checkout_date'];
    $customer_name = $_POST['customer_name'];
    $customer_phone = $_POST['customer_phone'];
    $customer_email = $_POST['customer_email'];
    
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

    // ... 其他驗證邏輯 ...

    if (empty($errors)) {
        // 檢查房間是否可用 - 修改查詢以確保房間存在且可用
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
                // 建立預訂記錄 - 使用 RES 格式的 ID
                $res_id = generateReservationId($conn);
                $stmt = $conn->prepare("
                    INSERT INTO RESERVATION (res_id, c_id, res_checkindate, res_checkoutdate, res_date) 
                    VALUES (?, ?, ?, ?, CURDATE())
                ");
                $stmt->bind_param("ssss", $res_id, $customer_id, $checkin_date, $checkout_date);
                $stmt->execute();

                // 建立房間預訂關聯
                $stmt = $conn->prepare("
                    INSERT INTO RESERVATION_ROOM (r_id, res_id) 
                    VALUES (?, ?)
                ");
                $stmt->bind_param("ss", $available_room['r_id'], $res_id);
                $stmt->execute();

                // 建立帳單 - 使用 BILL 格式的 ID
                $stmt = $conn->prepare("
                    SELECT b_id 
                    FROM BILL 
                    WHERE b_id LIKE 'BILL%' 
                    ORDER BY b_id DESC 
                    LIMIT 1
                ");
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $lastId = intval(substr($row['b_id'], 4));
                    $newId = $lastId + 1;
                } else {
                    $newId = 1;
                }
                $bill_id = 'BILL' . str_pad($newId, 5, '0', STR_PAD_LEFT);

                $days = $checkin->diff($checkout)->days;
                $total_cost = $room_info['r_price'] * $days;
                
                $stmt = $conn->prepare("
                    INSERT INTO BILL (b_id, res_id, r_cost, service_total) 
                    VALUES (?, ?, ?, 0)
                ");
                $stmt->bind_param("ssi", $bill_id, $res_id, $total_cost);
                $stmt->execute();

                $conn->commit();
                header("Location: customer.php");
                exit();
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "預訂失敗：" . $e->getMessage();
            }
        }
    }
}

function generateReservationId($conn) {
    $stmt = $conn->prepare("
        SELECT res_id 
        FROM RESERVATION 
        WHERE res_id LIKE 'RES%' 
        ORDER BY res_id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $lastId = intval(substr($row['res_id'], 3));
        $newId = $lastId + 1;
    } else {
        $newId = 1;
    }
    
    return 'RES' . str_pad($newId, 5, '0', STR_PAD_LEFT);
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="home.php">返回首頁</a>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">預訂房間</h4>
                    </div>
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
                                <!-- 個人資料欄位 -->
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

                                <!-- 日期選擇 -->
                                <div class="col-md-6">
                                    <label for="checkin_date" class="form-label">入住日期</label>
                                    <input type="date" class="form-control" id="checkin_date" name="checkin_date" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="checkout_date" class="form-label">退房日期</label>
                                    <input type="date" class="form-control" id="checkout_date" name="checkout_date" required>
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
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>