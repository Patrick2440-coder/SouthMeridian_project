<?php
session_start();
unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_phase'], $_SESSION['user_id'], $_SESSION['role'], $_SESSION['phase']);
header("Location: ../index.php");
exit;
