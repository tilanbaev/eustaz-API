<?php

class AuthMiddleware
{
  public static function handle()
  {
    $user = Auth::user();
    if (!$user) {
      http_response_code(401);
      echo json_encode(['error' => 'Unauthorized']);
      exit;
    }
    return $user;
  }
}
