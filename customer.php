<?php
session_start();

// 處理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// 檢查是否登入且為顧客
if (!isset($_SESSION['login_session']) || $_SESSION['role'] !== 'CUSTOMER') {
    header('Location: index.php');
    exit();
} 

// 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'HOTELRESERVATION');
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

// 取得顧客資訊
$customer_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM CUSTOMER WHERE c_id = ?");
$stmt->bind_param("s", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$customer_info = $result->fetch_assoc();

// 在查詢預訂記錄之前添加日期範圍條件
$date_condition = "";
$params = [];
$types = "";

if (!empty($_GET['start_date'])) {
    $date_condition .= " AND res.res_checkindate >= ?";
    $params[] = $_GET['start_date'];
    $types .= "s";
}

if (!empty($_GET['end_date'])) {
    $date_condition .= " AND res.res_checkoutdate <= ?";
    $params[] = $_GET['end_date'];
    $types .= "s";
}

// 修改預訂記錄查詢
$stmt = $conn->prepare("
    SELECT res.res_id, res.res_checkindate, res.res_checkoutdate, res.res_date,
           r.r_type, r.r_price, rr.r_id,
           CASE 
               WHEN r.r_type = 1 THEN '標準單人房'
               WHEN r.r_type = 2 THEN '標準雙人房'
               WHEN r.r_type = 3 THEN '標準三人房'
               WHEN r.r_type = 4 AND r.r_price = 4000 THEN '標準四人房'
               WHEN r.r_type = 4 AND r.r_price = 6000 THEN '豪華四人房'
               WHEN r.r_type = 6 THEN '標準六人房'
           END as type_name,
           b.b_id, b.r_cost, b.service_total
    FROM RESERVATION res
    JOIN RESERVATION_ROOM rr ON res.res_id = rr.res_id
    JOIN ROOM r ON rr.r_id = r.r_id
    LEFT JOIN BILL b ON res.res_id = b.res_id
    WHERE res.c_id = ? $date_condition
    ORDER BY res.res_checkindate DESC
");

// 修改參數綁定
$params = array_merge([$customer_id], $params);
$types = "s" . $types;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$reservations = $stmt->get_result();

// 處理刪除訂單請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_reservation'])) {
    $res_id = $_POST['res_id'];
    
    // 檢查是否為該使用者的訂單且日期未過
    $stmt = $conn->prepare("
        SELECT res_id 
        FROM RESERVATION 
        WHERE res_id = ? 
        AND c_id = ? 
        AND res_checkindate > CURDATE()
    ");
    $stmt->bind_param("ss", $res_id, $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // 開始交易
        $conn->begin_transaction();
        
        try {
            // 刪除帳單
            $stmt = $conn->prepare("DELETE FROM BILL WHERE res_id = ?");
            $stmt->bind_param("s", $res_id);
            $stmt->execute();
            
            // 刪除房間預訂關聯
            $stmt = $conn->prepare("DELETE FROM RESERVATION_ROOM WHERE res_id = ?");
            $stmt->bind_param("s", $res_id);
            $stmt->execute();
            
            // 刪除預訂記錄
            $stmt = $conn->prepare("DELETE FROM RESERVATION WHERE res_id = ?");
            $stmt->bind_param("s", $res_id);
            $stmt->execute();
            
            $conn->commit();
            header("Location: customer.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "刪除失敗：" . $e->getMessage();
        }
    }
}

// 處理個人資料更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $birth = $_POST['birth'];

    $stmt = $conn->prepare("UPDATE CUSTOMER SET c_info_name = ?, c_info_email = ?, c_info_phone = ?, c_info_address = ?, c_info_birth = ? WHERE c_id = ?");
    $stmt->bind_param("ssssss", $name, $email, $phone, $address, $birth, $customer_id);
    
    if ($stmt->execute()) {
        // 更新成功，重新獲取顧客資訊
        $stmt = $conn->prepare("SELECT * FROM CUSTOMER WHERE c_id = ?");
        $stmt->bind_param("s", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $customer_info = $result->fetch_assoc();
        $success_message = "個人資料更新成功！";
    } else {
        $error_message = "更新失敗：" . $stmt->error;
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>顧客專區</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="home.php">飯店預訂系統</a>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <div class="d-flex">
                    <span class="navbar-text me-3">
                        歡迎, <?php echo htmlspecialchars($customer_info['c_info_name']); ?>
                    </span>
                    <a href="?logout=1" class="btn btn-outline-light">登出</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <ul class="nav nav-tabs" id="customerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="bookings-tab" data-bs-toggle="tab" 
                        data-bs-target="#bookings-content" type="button" role="tab" 
                        aria-controls="bookings-content" aria-selected="true">
                    我的訂房
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="profile-tab" data-bs-toggle="tab" 
                        data-bs-target="#profile-content" type="button" role="tab" 
                        aria-controls="profile-content" aria-selected="false">
                    個人資料
                </button>
            </li>
        </ul>

        <div class="tab-content mt-3" id="customerTabContent">
            <!-- 訂房記錄區塊 -->
            <div class="tab-pane fade show active" id="bookings-content" role="tabpanel" aria-labelledby="bookings-tab">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>我的訂房記錄</h5>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="reservations" role="tabpanel">
                                <!-- 日期範圍篩選 -->
                                <div class="card mb-4">
                                    <div class="card-body">
                                        <form method="GET" class="row g-3">
                                            <div class="col-md-4">
                                                <label for="start_date" class="form-label">開始日期</label>
                                                <input type="date" class="form-control" id="start_date" name="start_date" 
                                                       value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="end_date" class="form-label">結束日期</label>
                                                <input type="date" class="form-control" id="end_date" name="end_date" 
                                                       value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-4 d-flex align-items-end">
                                                <button type="submit" class="btn btn-primary me-2">篩選</button>
                                                <a href="customer.php" class="btn btn-secondary">重置</a>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>訂房編號</th>
                                                <th>入住日期</th>
                                                <th>退房日期</th>
                                                <th>房間號碼</th>
                                                <th>總金額</th>
                                                <th>狀態</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($reservation = $reservations->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($reservation['res_id']); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['res_checkindate']); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['res_checkoutdate']); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['r_id']); ?></td>
                                                <td>NT$<?php echo number_format($reservation['r_cost'] + $reservation['service_total'], 0); ?></td>
                                                <td>
                                                    <?php 
                                                    $today = new DateTime();
                                                    $checkin = new DateTime($reservation['res_checkindate']);
                                                    if ($today < $checkin) {
                                                        echo '<span class="badge bg-warning">即將入住</span>';
                                                    } else {
                                                        echo '<span class="badge bg-success">已完成</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $today = new DateTime();
                                                    $checkin = new DateTime($reservation['res_checkindate']);
                                                    if ($today < $checkin): ?>
                                                        <form method="POST" onsubmit="return confirm('確定要刪除這筆訂單嗎？');">
                                                            <input type="hidden" name="res_id" value="<?php echo htmlspecialchars($reservation['res_id']); ?>">
                                                            <button type="submit" name="delete_reservation" class="btn btn-danger btn-sm">刪除</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">無法刪除</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 個人資料區塊 -->
            <div class="tab-pane fade" id="profile-content" role="tabpanel" aria-labelledby="profile-tab">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>個人資料</h5>
                        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#editProfileForm">
                            編輯資料
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <p><strong>姓名:</strong> <?php echo htmlspecialchars($customer_info['c_info_name']); ?></p>
                                <p><strong>信箱:</strong> <?php echo htmlspecialchars($customer_info['c_info_email']); ?></p>
                                <p><strong>電話:</strong> <?php echo htmlspecialchars($customer_info['c_info_phone']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>地址:</strong> <?php echo htmlspecialchars($customer_info['c_info_address']); ?></p>
                                <p><strong>生日:</strong> <?php echo htmlspecialchars($customer_info['c_info_birth']); ?></p>
                            </div>
                        </div>

                        <div class="collapse" id="editProfileForm">
                            <div class="card card-body">
                                <form method="POST" action="">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="name" class="form-label">姓名</label>
                                            <input type="text" class="form-control" id="name" name="name" 
                                                   value="<?php echo htmlspecialchars($customer_info['c_info_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="email" class="form-label">信箱</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars($customer_info['c_info_email']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="phone" class="form-label">電話</label>
                                            <input type="tel" class="form-control" id="phone" name="phone" 
                                                   value="<?php echo htmlspecialchars($customer_info['c_info_phone']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="birth" class="form-label">生日</label>
                                            <input type="date" class="form-control" id="birth" name="birth" 
                                                   value="<?php echo htmlspecialchars($customer_info['c_info_birth']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="address" class="form-label">地址</label>
                                        <input type="text" class="form-control" id="address" name="address" 
                                               value="<?php echo htmlspecialchars($customer_info['c_info_address']); ?>" required>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" name="update_profile" class="btn btn-primary">更新資料</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>