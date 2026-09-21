<?php

class CourseMiddleware
{
  public static function check()
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
