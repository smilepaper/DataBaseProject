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

    // 4. 自動產生 u_id
    $uid = uniqid("U"); // 例如：U662ff3e13d4c8
    $role = "CUSTOMER";

    // ✅ 5. 不加密密碼，直接使用明文
    // $hashed_password = password_hash($password, PASSWORD_DEFAULT); // ⛔這行不要用

    // 6. 寫入 USER 和 CUSTOMER（同時使用 transaction）
    $conn->begin_transaction();
    try {
        // 6.1 插入 USER（直接用 $password）
        $stmt_user = $conn->prepare("INSERT INTO USER (u_id, u_account, u_password, role) VALUES (?, ?, ?, ?)");
        $stmt_user->bind_param("ssss", $uid, $account, $password, $role);
        $stmt_user->execute();

        // 6.2 插入 CUSTOMER
        $stmt_customer = $conn->prepare("INSERT INTO CUSTOMER (c_id, c_info_name, c_info_birth, c_info_email, c_info_address, c_info_phone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_customer->bind_param("ssssss", $uid, $name, $birth, $email, $address, $phone);
        $stmt_customer->execute();

        $conn->commit();
        echo "<script>alert('註冊成功！'); window.location.href='login.html';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "註冊失敗：" . $e->getMessage();
    }

    // 關閉連線
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
    <section class="container" >
      <header>Registration Form</header>
      <form action="signup.php" method="POST" class="form">
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