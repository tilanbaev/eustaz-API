<?php

class Auth
{
  public static function user()
  {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) return null;

    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $stmt = $GLOBALS['db']->prepare("SELECT * FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}
