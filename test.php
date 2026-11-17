<?php
$new_password = "admin@321";
$hash = password_hash($new_password, PASSWORD_DEFAULT);
echo $hash;
?>