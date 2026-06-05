<?php
require_once __DIR__ . '/../app/function.php';

if (file_exists(APP_ROOT . '/config/install.lock')) {
  exit(header("Location:/../index.php"));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  exit(header("Location:index.php"));
}

easyimage_require_csrf();

if (!easyimage_verify_install_token(isset($_POST['install_token']) ? $_POST['install_token'] : '')) {
  exit('<script>window.alert("安装 Token 错误, 请查看 Docker 日志后重试!");location.href="./index.php";</script>');
}

if (isset($_POST['password'])) {
  if ($_POST['password'] === $_POST['repassword'] && strlen($_POST['password']) >= 8 && strlen($_POST['password']) <= 18) {

    $config['password'] = easyimage_hash_password($_POST['password']);
    $config['user'] = $_POST['user'];
  } else {

    exit('<script>window.alert("密码长度需为8~18位, 且两次输入必须一致!");location.href="./index.php";</script>');
  }
} else {
  exit(header("Location:index.php"));
}

if (isset($_POST['domain'])) {
  $config['domain'] = $_POST['domain'];
}

if (isset($_POST['imgurl'])) {
  $config['imgurl'] = $_POST['imgurl'];
}

$config_file = APP_ROOT . '/config/config.php';
cache_write($config_file, $config);

// 创建安装程序锁
file_put_contents(APP_ROOT . '/config/install.lock', '安装程序锁定文件。');
@unlink(APP_ROOT . '/config/install.token');

// 删除安装目录
if (isset($_POST['del_install'])) {
  if ($_POST['del_install'] === "del") {
    try {
      @deldir(APP_ROOT . "/install");
    } catch (Exception $e) {
      echo $e->getMessage();
    }
  }
}

// 删除多余文件.whitesource
if (isset($_POST['del_extra_files'])) {
  if ($_POST['del_extra_files'] === "del") {
    try {
      @unlink(APP_ROOT . '/LICENSE');
      @unlink(APP_ROOT . '/README.md');
      @unlink(APP_ROOT . "/SECURITY.md");
      @unlink(APP_ROOT . '/.whitesource');
      @unlink(APP_ROOT . '/CODE_OF_CONDUCT.md');
      @unlink(APP_ROOT . '/config/EasyIamge.lock');
      @deldir(APP_ROOT . "/.github");
      @deldir(APP_ROOT . "/.git");
      @deldir(APP_ROOT . "/docs");
    } catch (Exception $e) {
      echo $e->getMessage();
    }
  }
}
?>
<!-- 跳转主页 -->

<script>
  window.alert("安装成功,即将为您跳转到登陆界面!");
  location.href = "../admin/index.php";
</script>
