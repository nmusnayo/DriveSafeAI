<?php
session_start();

if (!empty($_SESSION["user"])) {
    include "db.php";
    redirect_to_role_dashboard($_SESSION["user"]);
} else {
    header("Location: login.php");
}

exit;
?>
