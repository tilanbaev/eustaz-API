<?php

class TeachAuth extends BaseAuth
{
  protected $teacherModel;

  public function __construct()
  {
    parent::__construct();
    $this->teacherModel = new Teacher();
  }

  public function sendVerificationCode($userType = 'teacher')
  {
    return parent::sendVerificationCode($userType);
  }

  public function verifyCode($userType = 'teacher')
  {
    return parent::verifyCode($userType);
  }

  public function completeRegistration()
  {
    $data = $this->jsonInput();

    // Дополнительные обязательные поля для учителей
    $requiredFields = ['subject', 'experience', 'education'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field])) {
        return $this->json(['error' => "Поле '$field' обязательно для преподавателя"], 400);
      }
    }

    $additionalData = [
      'subject' => trim($data['subject']),
      'experience' => trim($data['experience']),
      'education' => trim($data['education']),
      'specialization' => isset($data['specialization']) ? trim($data['specialization']) : null,
      'achievements' => isset($data['achievements']) ? trim($data['achievements']) : null
    ];

    return $this->register('teacher', $additionalData);
  }

  public function login($userType = 'teacher')
  {
    return parent::login($userType);
  }

  public function forgotPassword($userType = 'teacher')
  {
    return parent::forgotPassword($userType);
  }

  public function verifyResetCode($userType = 'teacher')
  {
    return parent::verifyResetCode($userType);
  }

  public function resetPassword($userType = 'teacher')
  {
    return parent::resetPassword($userType);
  }

  // Добавьте метод для получения профиля учителя
  public function getProfile()
  {
    $userId = $this->getUserIdFromToken();
    if (!$userId) {
      return $this->json(['error' => 'Неавторизован'], 401);
    }

    $user = $this->teacherModel->findByUserId($userId);

    if (!$user) {
      return $this->json(['error' => 'Пользователь не найден'], 404);
    }

    // Скрываем чувствительные данные
    unset($user['password_hash']);
    unset($user['reset_token']);
    unset($user['password_reset_token']);

    return $this->json(['user' => $user]);
  }

  // Метод для обновления профиля учителя
  public function updateProfile()
  {
    $userId = $this->getUserIdFromToken();
    if (!$userId) {
      return $this->json(['error' => 'Неавторизован'], 401);
    }

    $data = $this->jsonInput();

    // Получаем teacher_id из user_id
    $teacher = $this->teacherModel->findByUserId($userId);
    if (!$teacher) {
      return $this->json(['error' => 'Профиль преподавателя не найден'], 404);
    }

    $allowedFields = ['subject', 'experience', 'education', 'specialization', 'achievements'];
    $updateData = [];

    foreach ($data as $field => $value) {
      if (in_array($field, $allowedFields)) {
        $updateData[$field] = trim($value);
      }
    }

    if (empty($updateData)) {
      return $this->json(['error' => 'Нет данных для обновления'], 400);
    }

    $result = $this->teacherModel->updateTeacherProfile($teacher['id'], $updateData);

    if (!$result) {
      return $this->json(['error' => 'Не удалось обновить профиль'], 500);
    }

    return $this->json(['message' => 'Профиль успешно обновлен']);
  }

  // Метод для дашборда учителя
  public function getDashboard()
  {
    $userId = $this->getUserIdFromToken();
    if (!$userId) {
      return $this->json(['error' => 'Неавторизован'], 401);
    }

    // Получаем teacher_id
    $teacher = $this->teacherModel->findByUserId($userId);
    if (!$teacher) {
      return $this->json(['error' => 'Профиль преподавателя не найден'], 404);
    }

    // Здесь можно добавить логику для получения данных дашборда
    // Например: количество студентов, курсов, расписание и т.д.

    $dashboardData = [
      'total_students' => 0,
      'total_courses' => 0,
      'upcoming_lessons' => [],
      'recent_activity' => [],
      'teacher_info' => [
        'subject' => $teacher['subject'],
        'experience' => $teacher['experience'],
        'status' => $teacher['status'] ?? 'active'
      ]
    ];

    return $this->json(['dashboard' => $dashboardData]);
  }

  /**
   * Получить ID пользователя из JWT токена (альтернативная реализация)
   */
  private function getUserIdFromToken()
  {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);

    if (empty($token)) {
      return null;
    }

    // Используем verifyToken из BaseAuth (который публичный)
    $userId = $this->verifyToken($token);
    return $userId;
  }



  /**
   * Получить входные данные JSON
   */
  private function jsonInput()
  {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
  }
}
