<?php
require_once '../helpers.php';
start_session_30d();
session_unset();
session_destroy();
header('Location: login.php');
exit;
