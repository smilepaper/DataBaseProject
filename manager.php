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

//管理員資訊
$manager_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM MANAGER WHERE m_id = ?");
$stmt->bind_param("s", $manager_id);
$stmt->execute();
$result = $stmt->get_result();
$manager_info = $result->fetch_assoc();
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
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#rooms">房間管理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#reservations">訂房管理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#services">服務管理</a>
                    </li>
                </ul>
                <div class="d-flex">
                    <span class="navbar-text me-3">
                        歡迎, <?php echo htmlspecialchars($manager_info['m_info_name']); ?>
                    </span>
                    <a href="logout.php" class="btn btn-outline-light">登出</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">房間管理</h5>
                        <p class="card-text">管理飯店房間和使用狀態</p>
                        <a href="#" class="btn btn-primary">查看房間</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">訂房管理</h5>
                        <p class="card-text">查看和管理訂房資訊</p>
                        <a href="#" class="btn btn-primary">查看訂房</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">服務管理</h5>
                        <p class="card-text">管理飯店服務和定價</p>
                        <a href="#" class="btn btn-primary">查看服務</a>
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