<?php
session_start();

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

// 取得顧客的訂房記錄
$stmt = $conn->prepare("
    SELECT r.*, rm.r_id, b.b_id, b.service_total, b.r_cost 
    FROM RESERVATION r 
    LEFT JOIN RESERVATION_ROOM rm ON r.res_id = rm.res_id 
    LEFT JOIN BILL b ON r.res_id = b.res_id 
    WHERE r.c_id = ?
    ORDER BY r.res_date DESC
");
$stmt->bind_param("s", $customer_id);
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
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>顧客專區</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">飯店預訂系統</a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link tab-link" href="#" data-tab="bookings">我的訂房</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link tab-link" href="#" data-tab="profile">個人資料</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="home.php">新增訂房</a>
                    </li>
                </ul>
                <div class="d-flex">
                    <span class="navbar-text me-3">
                        歡迎, <?php echo htmlspecialchars($customer_info['c_info_name']); ?>
                    </span>
                    <a href="logout.php" class="btn btn-outline-light">登出</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- 個人資料區塊 -->
        <div id="profile" class="tab-content">
            <div class="card mb-4">
                <div class="card-header">
                    <h5>個人資料</h5>
                </div>
                <div class="card-body">
                    <div class="row">
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
                </div>
            </div>
        </div>

        <!-- 訂房記錄區塊 -->
        <div id="bookings" class="tab-content active">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>我的訂房記錄</h5>
                </div>
                <div class="card-body">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabLinks = document.querySelectorAll('.tab-link');
        const tabContents = document.querySelectorAll('.tab-content');

        tabLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                // 移除所有 active class
                tabContents.forEach(content => {
                    content.classList.remove('active');
                });
                tabLinks.forEach(link => {
                    link.classList.remove('active');
                });

                // 顯示對應的內容
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
                this.classList.add('active');
            });
        });
    });
    </script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>