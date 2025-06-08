<?php
session_start();

// 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'HOTELRESERVATION');
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

// 處理篩選條件
$selectedType = isset($_GET['type']) ? $_GET['type'] : '';
$minPrice = isset($_GET['min_price']) ? $_GET['min_price'] : '';
$maxPrice = isset($_GET['max_price']) ? $_GET['max_price'] : '';
$checkin_date = isset($_GET['checkin_date']) ? $_GET['checkin_date'] : '';
$checkout_date = isset($_GET['checkout_date']) ? $_GET['checkout_date'] : '';

// 處理房間查詢
$available_rooms = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_rooms'])) {
    $checkin_date = $_POST['checkin_date'];
    $checkout_date = $_POST['checkout_date'];
    
    // 查詢在指定日期範圍內可用的房間
    $stmt = $conn->prepare("
        SELECT DISTINCT r.r_id, r.r_type, r.r_price,
        CASE 
            WHEN r.r_type = 1 THEN '標準單人房'
            WHEN r.r_type = 2 THEN '標準雙人房'
            WHEN r.r_type = 3 THEN '標準三人房'
            WHEN r.r_type = 4 AND r.r_price = 4000 THEN '標準四人房'
            WHEN r.r_type = 4 AND r.r_price = 6000 THEN '豪華四人房'
            WHEN r.r_type = 6 THEN '標準六人房'
        END as r_type_name
        FROM ROOM r
        WHERE r.r_id NOT IN (
            SELECT rr.r_id
            FROM RESERVATION_ROOM rr
            JOIN RESERVATION res ON rr.res_id = res.res_id
            WHERE (
                (res.res_checkindate <= ? AND res.res_checkoutdate > ?) OR
                (res.res_checkindate < ? AND res.res_checkoutdate >= ?) OR
                (res.res_checkindate >= ? AND res.res_checkoutdate <= ?)
            )
        )
        ORDER BY r.r_type, r.r_id
    ");
    $stmt->bind_param("ssssss", $checkout_date, $checkin_date, $checkout_date, $checkin_date, $checkin_date, $checkout_date);
    $stmt->execute();
    $available_rooms = $stmt->get_result();
}

// 構建查詢條件
$conditions = [];
$params = [];
$types = "";

if ($selectedType !== '') {
    $conditions[] = "r.r_type = ?";
    $params[] = $selectedType;
    $types .= "i";
}
if ($minPrice !== '') {
    $conditions[] = "r.r_price >= ?";
    $params[] = $minPrice;
    $types .= "i";
}
if ($maxPrice !== '') {
    $conditions[] = "r.r_price <= ?";
    $params[] = $maxPrice;
    $types .= "i";
}

// 主要查詢
$query = "SELECT 
    r.r_type,
    r.r_price,
    COUNT(*) as available_rooms,
    CASE 
        WHEN r.r_type = 1 THEN '標準單人房'
        WHEN r.r_type = 2 THEN '標準雙人房'
        WHEN r.r_type = 3 THEN '標準三人房'
        WHEN r.r_type = 4 AND r.r_price = 4000 THEN '標準四人房'
        WHEN r.r_type = 4 AND r.r_price = 6000 THEN '豪華四人房'
        WHEN r.r_type = 6 THEN '標準六人房'
    END as type_name
    FROM ROOM r
    WHERE r.r_id NOT IN (
        SELECT rr.r_id 
        FROM RESERVATION_ROOM rr 
        JOIN RESERVATION res ON rr.res_id = res.res_id 
        WHERE (
            (res.res_checkindate <= ? AND res.res_checkoutdate > ?) OR
            (res.res_checkindate < ? AND res.res_checkoutdate >= ?) OR
            (res.res_checkindate >= ? AND res.res_checkoutdate <= ?)
        )
    )";

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$query .= " GROUP BY r.r_type, r.r_price ORDER BY r.r_type, r.r_price";

// 執行查詢
$stmt = $conn->prepare($query);
$params = array_merge([$checkout_date, $checkin_date, $checkout_date, $checkin_date, $checkin_date, $checkout_date], $params);
$types = "ssssss" . $types;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$room_types = $stmt->get_result();

// 取得所有房型供篩選器使用
$type_query = "SELECT DISTINCT 
    r_type,
    CASE 
        WHEN r_type = 1 THEN '單人房'
        WHEN r_type = 2 THEN '雙人房'
        WHEN r_type = 3 THEN '三人房'
        WHEN r_type = 4 THEN '四人房'
        WHEN r_type = 6 THEN '六人房'
    END as type_name
    FROM ROOM
    ORDER BY r_type";
$type_result = $conn->query($type_query);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>飯店預約系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/home.css">
</head>
<body>
    <!-- 導航欄 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">我也不知道叫啥飯店</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <?php if (isset($_SESSION['login_session'])): ?>
                    <a href="customer.php" class="btn btn-outline-light">我的訂房</a>
                <?php else: ?>
                    <a href="index.php" class="btn btn-outline-light">登入/註冊</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- 主視覺區域 -->
    <section class="main-section text-center">
        <div class="container">
            <h1 class="display-4">123123</h1>
            <p class="lead">超讚</p>
            <a href="#rooms" class="btn btn-primary btn-lg">立即訂房</a>
        </div>
    </section>

    <!-- 房型展示區 -->
    <section id="rooms" class="py-5">
        <div class="container">
            <div class="row">
                <!-- 左側篩選欄 -->
                <div class="col-md-3">
                    <div class="card sticky-top" style="top: 20px;">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-funnel"></i> 篩選條件</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-12">
                                    <label for="checkin_date" class="form-label">入住日期</label>
                                    <input type="date" class="form-control" id="checkin_date" name="checkin_date" 
                                           min="<?php echo date('Y-m-d'); ?>" 
                                           value="<?php echo htmlspecialchars($checkin_date); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label for="checkout_date" class="form-label">退房日期</label>
                                    <input type="date" class="form-control" id="checkout_date" name="checkout_date" 
                                           min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                                           value="<?php echo htmlspecialchars($checkout_date); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label for="type" class="form-label">房型</label>
                                    <select name="type" id="type" class="form-select">
                                        <option value="">全部房型</option>
                                        <?php while($type = $type_result->fetch_assoc()): ?>
                                            <option value="<?php echo $type['r_type']; ?>" 
                                                <?php echo $selectedType == $type['r_type'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type['type_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="min_price" class="form-label">最低價格</label>
                                    <input type="number" class="form-control" id="min_price" name="min_price" 
                                           value="<?php echo htmlspecialchars($minPrice); ?>">
                                </div>
                                <div class="col-12">
                                    <label for="max_price" class="form-label">最高價格</label>
                                    <input type="number" class="form-control" id="max_price" name="max_price"
                                           value="<?php echo htmlspecialchars($maxPrice); ?>">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary w-100">套用篩選</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- 右側房間列表 -->
                <div class="col-md-9">
                    <div class="row">
                        <?php 
                        $count = 0;
                        // 確保資料指標在開始位置
                        $room_types->data_seek(0);
                        while($room = $room_types->fetch_assoc()): 
                            $count++;
                        ?>
                            <div class="col-md-6 mb-4">
                                <div class="card room-card">
                                    <img src="images/room-<?php echo $room['r_type']; ?>.jpg" 
                                         class="card-img-top" style="height: 200px; object-fit: cover;"
                                         alt="<?php echo htmlspecialchars($room['type_name']); ?>">
                                    <div class="card-body">
                                        <h5 class="card-title"><?php echo htmlspecialchars($room['type_name']); ?></h5>
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span class="h5 mb-0">NT$ <?php echo number_format($room['r_price']); ?></span>
                                            <span class="badge bg-<?php echo $room['available_rooms'] > 0 ? 'success' : 'danger'; ?>">
                                                可預訂：<?php echo $room['available_rooms']; ?> 間
                                            </span>
                                        </div>
                                        <?php if($room['available_rooms'] > 0): ?>
                                            <button type="button" class="btn btn-primary w-100" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#reservationModal<?php echo $room['r_type']; ?>">
                                                立即預訂
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary w-100" disabled>已無空房</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- 預訂表單 Modal -->
                            <div class="modal fade" id="reservationModal<?php echo $room['r_type']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">預訂 <?php echo htmlspecialchars($room['type_name']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <iframe src="reservation.php?type=<?php echo $room['r_type']; ?>&price=<?php echo $room['r_price']; ?>&checkin=<?php echo urlencode($checkin_date); ?>&checkout=<?php echo urlencode($checkout_date); ?>" 
                                                    style="width: 100%; height: 600px; border: none;"></iframe>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        
                        <?php if ($count === 0): ?>
                            <div class="col-12">
                                <div class="alert alert-info">
                                    目前沒有可用房間
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="col-12">
                                <p class="text-muted">共顯示 <?php echo $count; ?> 個房型</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="bg-dark text-light py-4">
        <div class="container text-center">
            <p>&copy; 頁尾^_^.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>