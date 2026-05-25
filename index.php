<?php
// Automatically send anyone who lands on the root site straight to the login page
header("Location: auth/login.php");
exit;
