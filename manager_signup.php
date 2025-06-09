<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // 1. 接收表單資料
    $account  = $_POST["email"];
    $password = $_POST["password"];
    $name     = $_POST["name"];
    $birth    = $_POST["birth"];
    $email    = $_POST["email"];
    $address  = $_POST["address"];
    $phone    = $_POST["phone"];

    // 2. 建立連線
    $conn = new mysqli("localhost", "root", "", "HOTELRESERVATION");
    if ($conn->connect_error) {
        die("資料庫連線失敗：" . $conn->connect_error);
    }

    // 3. 檢查帳號是否已存在
    $check_stmt = $conn->prepare("SELECT * FROM USER WHERE u_account = ?");
    $check_stmt->bind_param("s", $account);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        echo "<script>alert('帳號已存在！請使用其他 Email'); history.back();</script>";
        exit;
    }

    $role = "MANAGER";

    // 4. 使用 transaction 保護多步驟寫入
    $conn->begin_transaction();

    try {
        // 5. 插入 USER，u_id 由資料庫自動產生
        $stmt_user = $conn->prepare("INSERT INTO USER (u_account, u_password, role) VALUES (?, ?, ?)");
        $stmt_user->bind_param("sss", $account, $password, $role);
        $stmt_user->execute();

        // 6. 取得自動產生的 u_id
        $uid = $conn->insert_id;

        // 7. 插入 CUSTOMER，c_id 使用剛取得的 u_id
        $stmt_manager = $conn->prepare("INSERT INTO MANAGER (m_id, m_info_name, m_info_birth, m_info_email, m_info_address, m_info_phone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_manager->bind_param("isssss", $uid, $name, $birth, $email, $address, $phone);
        $stmt_manager->execute();

        // 8. 提交交易
        $conn->commit();

        echo "<script>alert('註冊成功！'); window.location.href='index.php';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "註冊失敗：" . $e->getMessage();
    }

    // 9. 關閉資源
    $stmt_user->close();
    $stmt_customer->close();
    $check_stmt->close();
    $conn->close();
}
?>



<!DOCTYPE html>
<!---Coding By CodingLab | www.codinglabweb.com--->
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <!--<title>Registration Form in HTML CSS</title>-->
    <!---Custom CSS File--->
    <link rel="stylesheet" href="css/signupstyle.css" />
  </head>
  <body>
    <script>
    window.onload = function() {
        const correctPassword = "123456789"; // 你設定的內部密碼
        let input = prompt("請輸入公司內部密碼以進入註冊頁面：");

        if (input !== correctPassword) {
        alert("密碼錯誤，無法進入註冊頁面！");
        window.location.href = "index.php"; // 或用 history.back();
        }
    };
    </script>
    <section class="container" >
      <header>Manager Registration Form</header>
      <form action="manager_signup.php" method="POST" class="form">
        <div class="input-box">
          <label>Full Name</label>
          <input type="text" name="name" placeholder="Enter full name" required />
        </div>
        <div class="input-box">
          <label>Email address (Account)</label>
          <input type="email" name="email" placeholder="Enter email address" required />
        </div>
        <div class="input-box">
          <label>Password</label>
          <input type="password" name="password" placeholder="Enter password" required />
          <span toggle="#password-field" class="fa fa-fw fa-eye field-icon toggle-password"></span>
        </div>
        <div class="column">
          <div class="input-box">
            <label>Phone Number</label>
            <input type="text" name="phone" placeholder="Enter phone number" required />
          </div>
          <div class="input-box">
            <label>Birth Date</label>
            <input type="date" name="birth" placeholder="Enter birth date" required />
          </div>
        </div>
        <div class="input-box address">
          <label>Address</label>
          <input type="text" name="address" placeholder="Enter street address" required />
        </div>
        <button type="submit">Submit</button>
      </form>
    </section>
  </body>
</html>
