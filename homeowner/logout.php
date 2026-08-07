<?php
session_start();
unset($_SESSION['homeowner_id'], $_SESSION['homeowner_role'], $_SESSION['homeowner_phase'], $_SESSION['role'], $_SESSION['phase']);
header("Location: ../index.php");
exit;
