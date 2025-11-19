<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
logout();
redirect(BASE_URL . '/admin/login.php');
