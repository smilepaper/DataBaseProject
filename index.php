<?php
session_start();
$err = ''; // 初始化錯誤訊息變數

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['Username'];
    $password = $_POST['Password'];

    $db = new mysqli('localhost', 'root', '', 'HOTELRESERVATION');
    if ($db->connect_error) {
        // 在實際應用中，不應直接顯示資料庫連線錯誤給用戶，而是記錄錯誤並顯示友善訊息
        die("資料庫連線失敗：" . $db->connect_error);
    }

    // 使用預處理語句防止 SQL 注入
    $stmt = $db->prepare("SELECT u_id, role FROM USER WHERE u_account = ? AND u_password = ?");
    $stmt->bind_param("ss", $username, $password); 
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $_SESSION['login_session'] = $username;
        $_SESSION['user_id'] = $user['u_id'];
        $_SESSION['role'] = $user['role'];

        if ($user['role'] === 'MANAGER') {
            header("Location: manager.php");
        } else {
            header("Location: home.php");
        }
        exit(); // 重定向後應立即終止腳本執行
    } else {
        // 帳號或密碼不正確時設定錯誤訊息
        $err = "帳號或密碼錯誤。";
    }

    $stmt->close();
    $db->close();
}
?>

<!doctype html>
<html lang="en">
  <head>
    <title>Login</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <link href="https://fonts.googleapis.com/css?family=Lato:300,400,700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
    
    <link rel="stylesheet" href="css/style.css">

    </head>
    <body class="img js-fullheight" style="background-image: url(images/bg.jpg);">
    <section class="ftco-section">
        <div class="container" style="max-width: 400px;margin-top: 250px;">
            <div class="row justify-content-center">
                <div class="col-md-11 col-lg-9" style="margin-top: 20px;">
                    <div class="login-wrap p-0">
                        <h3 class="mb-4 text-center">已經擁有帳戶?</h3>

                        <?php if (!empty($err)): ?>
                            <p style="color: red; text-align: center;"><?php echo htmlspecialchars($err); ?></p>
                        <?php endif; ?>

                        <form action="index.php" method="post" class="signin-form">
                            <div class="form-group">
                                <input type="text" name="Username" class="form-control" placeholder="帳號" required>
                            </div>
                            <div class="form-group">
                                <input id="password-field" type="password" name="Password" class="form-control" placeholder="密碼" required>
                                <span toggle="#password-field" class="fa fa-fw fa-eye field-icon toggle-password"></span>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="form-control btn btn-primary submit px-3">登錄</button>
                            </div>
                            <div class="form-group d-md-flex">
                                <div class="w-50">
                                    <label class="checkbox-wrap checkbox-primary">記住我
                                        <input type="checkbox" checked>
                                        <span class="checkmark"></span>
                                    </label>
                                </div>
                                <div class="w-50 text-md-right">
                                    <a href="#" style="color: #fff">忘記密碼</a>
                                </div>
                                
                            </div>
                            <div class="text-center mt-3">
                                <p style="color: white;">還沒有帳號嗎? &nbsp;&nbsp;&nbsp;<a href="signup.php" style="color:   #fff;">按此註冊</a></p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
  
  <script src="js/jquery.min.js"></script>
  <script src="js/popper.js"></script>
  <script src="js/bootstrap.min.js"></script>
  <script src="js/main.js"></script>

    </body>
</html>
