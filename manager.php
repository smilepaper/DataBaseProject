<?php
session_start();

// 處理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

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

//管理員資訊
$manager_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM MANAGER WHERE m_id = ?");
$stmt->bind_param("s", $manager_id);
$stmt->execute();
$result = $stmt->get_result();
$manager_info = $result->fetch_assoc();

// 獲取訂單資料
$reservations_stmt = $conn->prepare("
    SELECT 
        r.res_id, 
        c.c_info_name, 
        r.res_checkindate, 
        r.res_checkoutdate, 
        GROUP_CONCAT(
            CASE 
                WHEN rm.r_type = 1 THEN '標準單人房'
                WHEN rm.r_type = 2 THEN '標準雙人房'
                WHEN rm.r_type = 3 THEN '標準三人房'
                WHEN rm.r_type = 4 AND rm.r_price = 4000 THEN '標準四人房'
                WHEN rm.r_type = 4 AND rm.r_price = 6000 THEN '豪華四人房'
                WHEN rm.r_type = 6 THEN '標準六人房'
            END
        ) as room_types,
        b.b_id,
        b.discount,
        b.service_total,
        b.r_cost,
        r.days,
        r.status
    FROM RESERVATION r
    JOIN CUSTOMER c ON r.c_id = c.c_id
    JOIN RESERVATION_ROOM rr ON r.res_id = rr.res_id
    JOIN ROOM rm ON rr.r_id = rm.r_id
    LEFT JOIN BILL b ON r.res_id = b.res_id
    GROUP BY r.res_id, c.c_info_name, r.res_checkindate, r.res_checkoutdate, b.b_id, b.discount, b.service_total, b.r_cost, r.days, r.status
    ORDER BY r.res_id DESC
");
$reservations_stmt->execute();
$reservations_result = $reservations_stmt->get_result();

// 獲取房間資料
$rooms_stmt = $conn->prepare("
    SELECT 
        r.r_id,
        r.r_type,
        r.r_price,
        CASE 
            WHEN r.r_type = 1 THEN '標準單人房'
            WHEN r.r_type = 2 THEN '標準雙人房'
            WHEN r.r_type = 3 THEN '標準三人房'
            WHEN r.r_type = 4 AND r.r_price = 4000 THEN '標準四人房'
            WHEN r.r_type = 4 AND r.r_price = 6000 THEN '豪華四人房'
            WHEN r.r_type = 6 THEN '標準六人房'
        END as type_name,
        CASE 
            WHEN r.r_id IN (
                SELECT rr.r_id 
                FROM RESERVATION_ROOM rr 
                JOIN RESERVATION res ON rr.res_id = res.res_id 
                WHERE res.res_checkoutdate >= CURDATE()
            ) THEN '已預訂'
            ELSE '可預訂'
        END as status
    FROM ROOM r
    ORDER BY r.r_type, r.r_price, r.r_id
");
$rooms_stmt->execute();
$rooms_result = $rooms_stmt->get_result();

// 處理房型價格更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_room_type'])) {
    $room_type = $_POST['room_type'];
    $room_price = $_POST['room_price'];
    
    $update_stmt = $conn->prepare("UPDATE ROOM SET r_price = ? WHERE r_type = ?");
    $update_stmt->bind_param("ii", $room_price, $room_type);
    
    if ($update_stmt->execute()) {
        echo "<script>alert('房型價格更新成功！'); window.location.reload();</script>";
    } else {
        echo "<script>alert('更新失敗：" . $conn->error . "');</script>";
    }
    $update_stmt->close();
}

// 處理服務更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service'])) {
    $service_id = $_POST['service_id'];
    $service_type = $_POST['service_type'];
    $service_price = $_POST['service_price'];
    
    $update_stmt = $conn->prepare("UPDATE SERVICE SET s_type = ?, s_price = ? WHERE s_id = ?");
    $update_stmt->bind_param("sii", $service_type, $service_price, $service_id);
    
    if ($update_stmt->execute()) {
        echo "<script>alert('服務更新成功！'); window.location.reload();</script>";
    } else {
        echo "<script>alert('更新失敗：" . $conn->error . "');</script>";
    }
    $update_stmt->close();
}

// 獲取所有服務
$services_stmt = $conn->prepare("SELECT * FROM SERVICE ORDER BY s_id");
$services_stmt->execute();
$services_result = $services_stmt->get_result();

// 獲取指定月份的收入統計
if (isset($_GET['month'])) {
    $month = $_GET['month'];
} else {
    $month = date('Y-m');
}

$revenue_stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(r.res_checkindate, '%Y-%m') as month,
        COALESCE(SUM(b.r_cost), 0) as room_revenue,
        COALESCE(SUM(b.service_total), 0) as service_revenue,
        COALESCE(SUM(b.r_cost + b.service_total), 0) as total_revenue,
        COUNT(DISTINCT r.res_id) as reservation_count
    FROM RESERVATION r
    LEFT JOIN BILL b ON r.res_id = b.res_id
    WHERE DATE_FORMAT(r.res_checkindate, '%Y-%m') >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 6 MONTH), '%Y-%m')
    GROUP BY DATE_FORMAT(r.res_checkindate, '%Y-%m')
    ORDER BY month DESC
");
$revenue_stmt->execute();
$revenue_result = $revenue_stmt->get_result();

// 獲取預訂次數最多的客戶
$top_customers_stmt = $conn->prepare("
    SELECT 
        c.c_id,
        c.c_info_name,
        COUNT(r.res_id) as reservation_count,
        COALESCE(SUM(b.r_cost + b.service_total), 0) as total_spent,
        COALESCE(AVG(b.r_cost + b.service_total), 0) as avg_spent
    FROM CUSTOMER c
    JOIN RESERVATION r ON c.c_id = r.c_id
    LEFT JOIN BILL b ON r.res_id = b.res_id
    GROUP BY c.c_id, c.c_info_name
    ORDER BY reservation_count DESC, total_spent DESC
    LIMIT 5
");
$top_customers_stmt->execute();
$top_customers_result = $top_customers_stmt->get_result();

// 獲取房間使用率分析
$room_occupancy_stmt = $conn->prepare("
    SELECT 
        r.r_type,
        CASE 
            WHEN r.r_type = 1 THEN '標準單人房'
            WHEN r.r_type = 2 THEN '標準雙人房'
            WHEN r.r_type = 3 THEN '標準三人房'
            WHEN r.r_type = 4 AND r.r_price = 4000 THEN '標準四人房'
            WHEN r.r_type = 4 AND r.r_price = 6000 THEN '豪華四人房'
            WHEN r.r_type = 6 THEN '標準六人房'
        END as type_name,
        DATE_FORMAT(res.res_checkindate, '%Y-%m') as month,
        COUNT(DISTINCT CASE WHEN res.res_id IS NOT NULL THEN r.r_id END) as booked_rooms,
        COUNT(DISTINCT r.r_id) as total_rooms,
        ROUND((COUNT(DISTINCT CASE WHEN res.res_id IS NOT NULL THEN r.r_id END) / COUNT(DISTINCT r.r_id)) * 100, 2) as occupancy_rate
    FROM ROOM r
    LEFT JOIN RESERVATION_ROOM rr ON r.r_id = rr.r_id
    LEFT JOIN RESERVATION res ON rr.res_id = res.res_id 
        AND res.res_checkindate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY r.r_type, type_name, month
    HAVING occupancy_rate > 0
    ORDER BY month DESC, occupancy_rate DESC
");

$room_occupancy_stmt->execute();
$room_occupancy_result = $room_occupancy_stmt->get_result();

// 獲取最常被預訂的房型
$popular_rooms_stmt = $conn->prepare("
    SELECT 
        r.r_type,
        CASE 
            WHEN r.r_type = 1 THEN '標準單人房'
            WHEN r.r_type = 2 THEN '標準雙人房'
            WHEN r.r_type = 3 THEN '標準三人房'
            WHEN r.r_type = 4 AND r.r_price = 4000 THEN '標準四人房'
            WHEN r.r_type = 4 AND r.r_price = 6000 THEN '豪華四人房'
            WHEN r.r_type = 6 THEN '標準六人房'
        END as type_name,
        COUNT(rr.res_id) as reservation_count,
        COALESCE(AVG(b.r_cost), 0) as avg_room_cost,
        COALESCE(AVG(b.service_total), 0) as avg_service_cost
    FROM ROOM r
    JOIN RESERVATION_ROOM rr ON r.r_id = rr.r_id
    JOIN RESERVATION res ON rr.res_id = res.res_id
    LEFT JOIN BILL b ON res.res_id = b.res_id
    WHERE res.res_checkindate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY r.r_type, type_name
    ORDER BY reservation_count DESC
");
$popular_rooms_stmt->execute();
$popular_rooms_result = $popular_rooms_stmt->get_result();

// 獲取當前月份的收入統計 (針對卡片顯示)
$current_month_revenue_stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(b.r_cost), 0) as room_revenue,
        COALESCE(SUM(b.service_total), 0) as service_revenue,
        COALESCE(SUM(b.r_cost + b.service_total), 0) as total_revenue
    FROM RESERVATION r
    LEFT JOIN BILL b ON r.res_id = b.res_id
    WHERE DATE_FORMAT(r.res_checkindate, '%Y-%m') = ?
");
$current_month_revenue_stmt->bind_param("s", $month);
$current_month_revenue_stmt->execute();
$current_month_revenue_result = $current_month_revenue_stmt->get_result();
$revenue_data = $current_month_revenue_result->fetch_assoc();

// 如果沒有資料，則將收入資料初始化為 0
if (!$revenue_data) {
    $revenue_data = [
        'room_revenue' => 0,
        'service_revenue' => 0,
        'total_revenue' => 0
    ];
}

// 獲取過去6個月的總預訂次數
$total_bookings_6_months_stmt = $conn->prepare("
    SELECT COUNT(res_id) as total_count
    FROM RESERVATION
    WHERE res_checkindate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
");
$total_bookings_6_months_stmt->execute();
$total_bookings_6_months_result = $total_bookings_6_months_stmt->get_result();
$total_bookings_row = $total_bookings_6_months_result->fetch_assoc();
$total_reservations_6_months = $total_bookings_row['total_count'];

// 獲取過去6個月房間預訂佔比
$room_booking_share_stmt = $conn->prepare("
    SELECT
        r.r_type,
        CASE
            WHEN r.r_type = 1 THEN '標準單人房'
            WHEN r.r_type = 2 THEN '標準雙人房'
            WHEN r.r_type = 3 THEN '標準三人房'
            WHEN r.r_type = 4 AND r.r_price = 4000 THEN '標準四人房'
            WHEN r.r_type = 4 AND r.r_price = 6000 THEN '豪華四人房'
            WHEN r.r_type = 6 THEN '標準六人房'
        END as type_name,
        COUNT(rr.res_id) as reservation_count_by_type_6_months
    FROM ROOM r
    JOIN RESERVATION_ROOM rr ON r.r_id = rr.r_id
    JOIN RESERVATION res ON rr.res_id = res.res_id
    WHERE res.res_checkindate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY r.r_type, type_name
    ORDER BY reservation_count_by_type_6_months DESC
");
$room_booking_share_stmt->execute();
$room_booking_share_result = $room_booking_share_stmt->get_result();

?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理員控制台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">飯店管理系統</a>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <div class="d-flex">
                    <span class="navbar-text me-3">
                        歡迎, <?php echo htmlspecialchars($manager_info['m_info_name']); ?>
                    </span>
                    <a href="?logout=1" class="btn btn-outline-light">登出</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <ul class="nav nav-tabs" id="managerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="rooms-tab" data-bs-toggle="tab" 
                        data-bs-target="#rooms" type="button" role="tab">
                    房間管理
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reservations-tab" data-bs-toggle="tab" 
                        data-bs-target="#reservations" type="button" role="tab">
                    訂單檢視
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="services-tab" data-bs-toggle="tab" 
                        data-bs-target="#services" type="button" role="tab">
                    服務管理
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="analytics-tab" data-bs-toggle="tab" 
                        data-bs-target="#analytics" type="button" role="tab">
                    營運分析
                </button>
            </li>
        </ul>

        <div class="tab-content mt-3" id="managerTabContent">
            <!-- 房間管理 Tab -->
            <div class="tab-pane fade show active" id="rooms" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">房間管理</h5>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>房間編號</th>
                                        <th>房型</th>
                                        <th>價格</th>
                                        <th>狀態</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_type = '';
                                    while($room = $rooms_result->fetch_assoc()): 
                                        if ($current_type !== $room['type_name']):
                                            $current_type = $room['type_name'];
                                    ?>
                                        <tr class="table-secondary">
                                            <td colspan="5" class="fw-bold">
                                                <?php echo htmlspecialchars($current_type); ?>
                                                <button class="btn btn-sm btn-primary float-end" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editRoomTypeModal<?php echo $room['r_type']; ?>">
                                                    編輯房型價格
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($room['r_id']); ?></td>
                                        <td><?php echo htmlspecialchars($room['type_name']); ?></td>
                                        <td>NT$ <?php echo number_format($room['r_price'], 0); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $room['status'] === '可預訂' ? 'success' : 'warning'; ?>">
                                                <?php echo htmlspecialchars($room['status']); ?>
                                            </span>
                                        </td>
                                        <td></td>
                                    </tr>

                                    <!-- 編輯房型價格 Modal -->
                                    <div class="modal fade" id="editRoomTypeModal<?php echo $room['r_type']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">編輯房型價格</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="POST">
                                                        <input type="hidden" name="room_type" value="<?php echo $room['r_type']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">房型</label>
                                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($room['type_name']); ?>" readonly>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">價格</label>
                                                            <input type="number" class="form-control" name="room_price" 
                                                                   value="<?php echo htmlspecialchars($room['r_price']); ?>" required>
                                                        </div>
                                                        <div class="text-end">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                                            <button type="submit" name="update_room_type" class="btn btn-primary">更新</button>
                                                        </div>
                                                    </form>
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

            <!-- 訂單檢視 Tab -->
            <div class="tab-pane fade" id="reservations" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">訂單檢視</h5>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>訂單編號</th>
                                        <th>客戶名稱</th>
                                        <th>入住日期</th>
                                        <th>退房日期</th>
                                        <th>房型</th>
                                        <th>狀態</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($reservation = $reservations_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reservation['res_id']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['c_info_name']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['res_checkindate']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['res_checkoutdate']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['room_types']); ?></td>
                                        <td>
                                            <?php if (!isset($reservation['b_id'])): ?>
                                                <span class="badge bg-warning">待確認</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">已確認</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <?php if (!isset($reservation['b_id'])): ?>
                                                    <button class="btn btn-sm btn-success" 
                                                            onclick="confirmReservation('<?php echo $reservation['res_id']; ?>')">
                                                        確認訂單
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#billModal<?php echo htmlspecialchars($reservation['res_id']); ?>">
                                                    詳情
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- 帳單詳情 Modal -->
                                    <div class="modal fade" id="billModal<?php echo htmlspecialchars($reservation['res_id']); ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">訂單詳情</h5>
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
                                                                <label class="form-label fw-bold">客戶姓名</label>
                                                                <p><?php echo htmlspecialchars($reservation['c_info_name']); ?></p>
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
                                                                <p><?php echo htmlspecialchars($reservation['room_types']); ?></p>
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

            <!-- 服務管理 Tab -->
            <div class="tab-pane fade" id="services" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">服務管理</h5>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>服務編號</th>
                                        <th>服務名稱</th>
                                        <th>價格</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $services_result->data_seek(0);
                                    while($service = $services_result->fetch_assoc()): 
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($service['s_id']); ?></td>
                                        <td><?php echo htmlspecialchars($service['s_type']); ?></td>
                                        <td>NT$ <?php echo number_format($service['s_price'], 0); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editServiceModal<?php echo $service['s_id']; ?>">
                                                編輯
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- 編輯服務 Modal -->
                                    <div class="modal fade" id="editServiceModal<?php echo $service['s_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">編輯服務</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="POST">
                                                        <input type="hidden" name="service_id" value="<?php echo $service['s_id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">服務名稱</label>
                                                            <input type="text" class="form-control" name="service_type" 
                                                                   value="<?php echo htmlspecialchars($service['s_type']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">價格</label>
                                                            <input type="number" class="form-control" name="service_price" 
                                                                   value="<?php echo htmlspecialchars($service['s_price']); ?>" required>
                                                        </div>
                                                        <div class="text-end">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                                            <button type="submit" name="update_service" class="btn btn-primary">更新</button>
                                                        </div>
                                                    </form>
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

            <!-- 營運分析 Tab -->
            <div class="tab-pane fade" id="analytics" role="tabpanel">
                <div class="row">
                    <!-- 營收統計 -->
                    <div class="col-md-12 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">收入統計</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="monthSelector" class="form-label">選擇月份</label>
                                    <input type="month" id="monthSelector" class="form-control w-auto" value="<?php echo $month; ?>">
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="card bg-light mb-3">
                                            <div class="card-body">
                                                <h6 class="card-title text-muted">房間收入</h6>
                                                <h3 class="card-text">NT$ <?php echo number_format($revenue_data['room_revenue']); ?></h3>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-light mb-3">
                                            <div class="card-body">
                                                <h6 class="card-title text-muted">服務收入</h6>
                                                <h3 class="card-text">NT$ <?php echo number_format($revenue_data['service_revenue']); ?></h3>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-light mb-3">
                                            <div class="card-body">
                                                <h6 class="card-title text-muted">總收入</h6>
                                                <h3 class="card-text">NT$ <?php echo number_format($revenue_data['total_revenue']); ?></h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 顧客分析 -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">忠實客戶分析</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th style="cursor: pointer;" onclick="sortTable(0, 'customer')">客戶名稱 <i class="bi bi-arrow-down-up"></i></th>
                                                <th style="cursor: pointer;" onclick="sortTable(1, 'customer')">預訂次數 <i class="bi bi-arrow-down-up"></i></th>
                                                <th style="cursor: pointer;" onclick="sortTable(2, 'customer')">消費總額 <i class="bi bi-arrow-down-up"></i></th>
                                            </tr>
                                        </thead>
                                        <tbody id="customerTable">
                                            <?php while($row = $top_customers_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo $row['c_info_name']; ?></td>
                                                <td><?php echo $row['reservation_count']; ?></td>
                                                <td>NT$ <?php echo number_format($row['total_spent']); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 熱門房型分析 -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">熱門房型分析</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th style="cursor: pointer;" onclick="sortTable(0, 'popular')">房型 <i class="bi bi-arrow-down-up"></i></th>
                                                <th style="cursor: pointer;" onclick="sortTable(1, 'popular')">預訂次數 <i class="bi bi-arrow-down-up"></i></th>
                                                <th style="cursor: pointer;" onclick="sortTable(2, 'popular')">平均房費 <i class="bi bi-arrow-down-up"></i></th>
                                                <th style="cursor: pointer;" onclick="sortTable(3, 'popular')">平均服務費 <i class="bi bi-arrow-down-up"></i></th>
                                            </tr>
                                        </thead>
                                        <tbody id="popularTable">
                                            <?php while($row = $popular_rooms_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo $row['type_name']; ?></td>
                                                <td><?php echo $row['reservation_count']; ?></td>
                                                <td>NT$ <?php echo number_format($row['avg_room_cost'], 0); ?></td>
                                                <td>NT$ <?php echo number_format($row['avg_service_cost'], 0); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 房間使用率分析 -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">房間預訂佔比分析 ( 近6個月 ) </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th style="cursor: pointer;" onclick="sortTable(0, 'occupancy')">房型 <i class="bi bi-arrow-down-up"></i></th>
                                                <th style="cursor: pointer;" onclick="sortTable(1, 'occupancy')">預訂次數 <i class="bi bi-arrow-down-up"></i></th>
                                                <th style="cursor: pointer;" onclick="sortTable(2, 'occupancy')">佔總預訂比率 <i class="bi bi-arrow-down-up"></i></th>
                                            </tr>
                                        </thead>
                                        <tbody id="occupancyTable">
                                            <?php while($row = $room_booking_share_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['type_name']); ?></td>
                                                <td><?php echo htmlspecialchars($row['reservation_count_by_type_6_months']); ?></td>
                                                <td>
                                                    <?php
                                                        $booking_share_rate = 0;
                                                        if ($total_reservations_6_months > 0) {
                                                            $booking_share_rate = ($row['reservation_count_by_type_6_months'] / $total_reservations_6_months) * 100;
                                                        }
                                                        echo number_format($booking_share_rate, 1) . '%';
                                                    ?>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('monthSelector').addEventListener('change', function() {
        const selectedMonth = this.value;
        const url = new URL(window.location.href);
        url.searchParams.set('month', selectedMonth);
        url.hash = 'analytics'; // 確保切換月份後仍停留在營運分析頁籤
        window.location.href = url.toString();
    });

    // 排序功能
    function sortTable(columnIndex, tableId) {
        const table = document.getElementById(tableId + 'Table');
        const rows = Array.from(table.getElementsByTagName('tr'));
        const headerRow = table.previousElementSibling.querySelector('thead tr');
        
        let currentDirection = table.getAttribute('data-sort-direction');
        if (currentDirection === null || currentDirection === 'desc') {
            currentDirection = 'asc';
        } else {
            currentDirection = 'desc';
        }
        table.setAttribute('data-sort-direction', currentDirection);
        
        rows.sort((a, b) => {
            const aValue = a.cells[columnIndex].textContent.trim();
            const bValue = b.cells[columnIndex].textContent.trim();
            
            const aNum = parseFloat(aValue.replace(/[^0-9.-]+/g, ''));
            const bNum = parseFloat(bValue.replace(/[^0-9.-]+/g, ''));
            
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return currentDirection === 'asc' ? aNum - bNum : bNum - aNum;
            }
            
            return currentDirection === 'asc' 
                ? aValue.localeCompare(bValue, 'zh-TW')
                : bValue.localeCompare(aValue, 'zh-TW');
        });
        
        rows.forEach(row => table.appendChild(row));
        
        const headers = headerRow.getElementsByTagName('th');
        for (let i = 0; i < headers.length; i++) {
            const icon = headers[i].querySelector('i');
            if (icon) icon.className = 'bi bi-arrow-down-up';
        }
        const currentIcon = headers[columnIndex].querySelector('i');
        if (currentIcon) {
            currentIcon.className = 
                currentDirection === 'asc' ? 'bi bi-arrow-up' : 'bi bi-arrow-down';
        }
    }

    function confirmReservation(resId) {
        if (confirm('確定要確認此訂單嗎？確認後將生成帳單。')) {
            fetch('confirm_reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `res_id=${resId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('訂單已確認！');
                    location.reload();
                } else {
                    alert('錯誤：' + data.message);
                }
            })
            .catch(error => {
                alert('發生錯誤：' + error);
            });
        }
    }
</script>
</body>
</html>
<?php
$stmt->close();
$reservations_stmt->close();
$rooms_stmt->close();
$services_stmt->close();
$conn->close();
$current_month_revenue_stmt->close();
$total_bookings_6_months_stmt->close();
$room_booking_share_stmt->close();
?>