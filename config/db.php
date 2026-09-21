<?php

$dsn = 'mysql:host=localhost;dbname=api;charset=utf8';
$user = 'api_admin';
$pass = '123';

try {
  $GLOBALS['db'] = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
} catch (PDOException $e) {
  die("DB connection failed: " . $e->getMessage());
}
