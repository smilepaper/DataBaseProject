<?php
session_start();

// 處理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// 檢查是否登入且為客戶
if (!isset($_SESSION['login_session']) || $_SESSION['role'] !== 'CUSTOMER') {
    header('Location: index.php');
    exit();
}

// 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'HOTELRESERVATION');
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

// 獲取客戶資訊
$customer_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM CUSTOMER WHERE c_id = ?");
$stmt->bind_param("s", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$customer_info = $result->fetch_assoc();

// 獲取客戶的預訂記錄
$reservations_stmt = $conn->prepare("
    SELECT 
        r.res_id, 
        r.res_checkindate, 
        r.res_checkoutdate, 
        rm.r_type,
        rm.r_price,
        b.b_id,
        b.discount,
        b.service_total,
        b.r_cost
    FROM RESERVATION r
    JOIN RESERVATION_ROOM rr ON r.res_id = rr.res_id
    JOIN ROOM rm ON rr.r_id = rm.r_id
    LEFT JOIN BILL b ON r.res_id = b.res_id
    WHERE r.c_id = ?
    ORDER BY r.res_checkindate DESC
");
$reservations_stmt->bind_param("s", $customer_id);
$reservations_stmt->execute();
$reservations_result = $reservations_stmt->get_result();

// 獲取客戶的服務記錄
$services_stmt = $conn->prepare("
    SELECT 
        b.b_id,
        s.s_type,
        s.s_price,
        sd.quantity,
        b.res_id
    FROM SERVICEDETAIL sd
    JOIN BILL b ON sd.b_id = b.b_id
    JOIN SERVICE s ON sd.s_id = s.s_id
    JOIN RESERVATION r ON b.res_id = r.res_id
    WHERE r.c_id = ?
    ORDER BY b.b_id DESC
");
$services_stmt->bind_param("s", $customer_id);
$services_stmt->execute();
$services_result = $services_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客戶中心</title>
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
                    訂房記錄
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
                    <div class="card-header">
                        <h5 class="mb-0">訂房記錄</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>訂單編號</th>
                                        <th>入住日期</th>
                                        <th>退房日期</th>
                                        <th>房型</th>
                                        <th>狀態</th>
                                        <th>總金額</th>
                                        <th>帳單</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($reservation = $reservations_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reservation['res_id']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['res_checkindate']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['res_checkoutdate']); ?></td>
                                        <td>
                                            <?php
                                            switch($reservation['r_type']) {
                                                case 1: echo '標準單人房'; break;
                                                case 2: echo '標準雙人房'; break;
                                                case 3: echo '標準三人房'; break;
                                                case 4: echo ($reservation['r_price'] == 4000) ? '標準四人房' : '豪華四人房'; break;
                                                case 6: echo '標準六人房'; break;
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if (!isset($reservation['b_id'])): ?>
                                                <span class="badge bg-warning">待確認</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">已確認</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (isset($reservation['b_id'])): ?>
                                                NT$ <?php 
                                                    $total = $reservation['r_cost'] + $reservation['service_total'];
                                                    if ($reservation['discount']) {
                                                        $total = $total * (1 - $reservation['discount']);
                                                    }
                                                    echo number_format($total, 0);
                                                ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#billModal<?php echo htmlspecialchars($reservation['res_id']); ?>">
                                                詳情
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- 帳單詳情 Modal -->
                                    <div class="modal fade" id="billModal<?php echo htmlspecialchars($reservation['res_id']); ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">帳單詳情</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="row">
                                                        <div class="col-12">
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">訂單編號</label>
                                                                <p><?php echo htmlspecialchars($reservation['res_id']); ?></p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">入住日期</label>
                                                                <p><?php echo htmlspecialchars($reservation['res_checkindate']); ?></p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">退房日期</label>
                                                                <p><?php echo htmlspecialchars($reservation['res_checkoutdate']); ?></p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">房型</label>
                                                                <p>
                                                                    <?php
                                                                    switch($reservation['r_type']) {
                                                                        case 1: echo '標準單人房'; break;
                                                                        case 2: echo '標準雙人房'; break;
                                                                        case 3: echo '標準三人房'; break;
                                                                        case 4: echo ($reservation['r_price'] == 4000) ? '標準四人房' : '豪華四人房'; break;
                                                                        case 6: echo '標準六人房'; break;
                                                                    }
                                                                    ?>
                                                                </p>
                                                            </div>
                                                            <?php if (isset($reservation['b_id'])): ?>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">帳單編號</label>
                                                                    <p><?php echo htmlspecialchars($reservation['b_id']); ?></p>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">房間費用</label>
                                                                    <p>NT$ <?php echo number_format($reservation['r_cost'], 0); ?></p>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">服務費用</label>
                                                                    <p>NT$ <?php echo number_format($reservation['service_total'], 0); ?></p>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">折扣</label>
                                                                    <p><?php echo $reservation['discount'] ? number_format($reservation['discount'] * 100, 0) . '%' : '無'; ?></p>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">總金額</label>
                                                                    <p>NT$ <?php 
                                                                        $total = $reservation['r_cost'] + $reservation['service_total'];
                                                                        if ($reservation['discount']) {
                                                                            $total = $total * (1 - $reservation['discount']);
                                                                        }
                                                                        echo number_format($total, 0);
                                                                    ?></p>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="alert alert-warning">
                                                                    此訂單尚未確認，無法顯示帳單資訊
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 個人資料區塊 -->
            <div class="tab-pane fade" id="profile-content" role="tabpanel" aria-labelledby="profile-tab">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">個人資料</h5>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                            編輯資料
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">姓名</label>
                                    <p><?php echo htmlspecialchars($customer_info['c_info_name']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">電話</label>
                                    <p><?php echo htmlspecialchars($customer_info['c_info_phone']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">電子郵件</label>
                                    <p><?php echo htmlspecialchars($customer_info['c_info_email']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">地址</label>
                                    <p><?php echo htmlspecialchars($customer_info['c_info_address']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 編輯個人資料 Modal -->
    <div class="modal fade" id="editProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">編輯個人資料</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="update_profile.php">
                        <div class="mb-3">
                            <label class="form-label">姓名</label>
                            <input type="text" class="form-control" name="name" 
                                   value="<?php echo htmlspecialchars($customer_info['c_info_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">電話</label>
                            <input type="tel" class="form-control" name="phone" 
                                   value="<?php echo htmlspecialchars($customer_info['c_info_phone']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">電子郵件</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($customer_info['c_info_email']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">地址</label>
                            <input type="text" class="form-control" name="address" 
                                   value="<?php echo htmlspecialchars($customer_info['c_info_address']); ?>" required>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">更新</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化所有 Bootstrap 元件
            var triggerTabList = [].slice.call(document.querySelectorAll('#customerTabs button'))
            triggerTabList.forEach(function(triggerEl) {
                var tabTrigger = new bootstrap.Tab(triggerEl)
                triggerEl.addEventListener('click', function(event) {
                    event.preventDefault()
                    tabTrigger.show()
                })
            })
        });
    </script>
</body>
</html>
<?php
$stmt->close();
$reservations_stmt->close();
$services_stmt->close();
$conn->close();
?>