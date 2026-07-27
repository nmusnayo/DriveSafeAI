<?php
include "db.php";
require_login();

$user = current_user();

redirect_to_role_dashboard($user);
?>
