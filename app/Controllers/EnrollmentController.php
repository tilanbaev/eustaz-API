<?php
class EnrollmentController extends BaseAuth
{
  protected $enrollmentModel;
  protected $courseModel;
  protected $userModel;

  public function __construct()
  {
    parent::__construct();
    $this->enrollmentModel = new Enrollment();
    $this->courseModel = new Course();
    $this->userModel = new User();
  }

  /**
   * Запись на курс
   */
  public function enroll($courseId)
  {
    try {
      // Получаем пользователя из токена
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;
      if (!$userId) {
        return $this->jsonResponse(false, 'User ID not found', null, 401);
      }

      // Проверяем валидность courseId
      if (!is_numeric($courseId) || $courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Проверяем существование курса
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем существующую запись
      $existingEnrollment = $this->enrollmentModel->findByUserAndCourse($userId, $courseId);

      if ($existingEnrollment) {
        // Если запись уже подтверждена
        if ($existingEnrollment['status'] === 'approved') {
          return $this->jsonResponse(false, 'Вы уже записаны на этот курс', null, 400);
        }

        // Если запись ожидает рассмотрения
        if ($existingEnrollment['status'] === 'pending') {
          return $this->jsonResponse(false, 'Ваша заявка на запись на этот курс уже находится на рассмотрении', null, 400);
        }

        // Если запись была отклонена - обновляем существующую запись
        if ($existingEnrollment['status'] === 'rejected') {
          // Определяем данные для обновления
          $updateData = [
            'status' => 'pending',
            'enrolled_at' => date('Y-m-d H:i:s'),
            'rejected_at' => null,
            'rejected_by' => null
          ];

          // Для платных курсов добавляем статус оплаты
          if (!$course['is_free'] && $course['price'] > 0) {
            $updateData['payment_status'] = 'claimed';
          } else {
            // Для бесплатных курсов автоматически подтверждаем
            $updateData['status'] = 'approved';
            $updateData['approved_at'] = date('Y-m-d H:i:s');
          }

          // Обновляем существующую запись
          $result = $this->enrollmentModel->update($existingEnrollment['id'], $updateData);

          if ($result) {
            $enrollment = $this->enrollmentModel->findById($existingEnrollment['id']);
            $message = $updateData['status'] === 'approved'
              ? 'Successfully enrolled in the course'
              : 'Enrollment request sent. Please wait for teacher approval.';
            return $this->jsonResponse(true, $message, $enrollment, 200);
          } else {
            return $this->jsonResponse(false, 'Failed to re-enroll in course', null, 500);
          }
        }
      }

      // Создаем новую запись (если предыдущей не было)
      $enrollmentData = [
        'user_id' => $userId,
        'course_id' => $courseId,
        'enrolled_at' => date('Y-m-d H:i:s')
      ];

      if ($course['is_free'] || $course['price'] == 0) {
        // Бесплатный курс - автоматически подтверждаем запись
        $enrollmentData['status'] = 'approved';
        $enrollmentData['approved_at'] = date('Y-m-d H:i:s');
        $message = 'Successfully enrolled in the course';
      } else {
        // Платный курс - ожидание подтверждения от учителя
        $enrollmentData['status'] = 'pending';
        $enrollmentData['payment_status'] = 'claimed'; // Пользователь утверждает, что оплатил
        $message = 'Enrollment request sent. Please wait for teacher approval.';
      }

      // Создаем запись о записи на курс
      $enrollmentId = $this->enrollmentModel->create($enrollmentData);

      if ($enrollmentId) {
        $enrollment = $this->enrollmentModel->findById($enrollmentId);
        return $this->jsonResponse(true, $message, $enrollment, 201);
      } else {
        return $this->jsonResponse(false, 'Failed to enroll in course', null, 500);
      }
    } catch (Exception $e) {
      error_log("Course enrollment error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Подтверждение записи на курс (для учителя/админа)
   */
  public function approveEnrollment($enrollmentId)
  {
    try {
      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $currentUserId = $user['id'] ?? null;
      $userRole = $user['role'] ?? 'user';

      // Проверяем валидность enrollmentId
      if (!is_numeric($enrollmentId) || $enrollmentId <= 0) {
        return $this->jsonResponse(false, 'Invalid enrollment ID', null, 400);
      }

      // Получаем запись о записи
      $enrollment = $this->enrollmentModel->findById($enrollmentId);
      if (!$enrollment) {
        return $this->jsonResponse(false, 'Enrollment not found', null, 404);
      }

      // Получаем курс
      $course = $this->courseModel->findById($enrollment['course_id']);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем права доступа
      if ($userRole !== 'admin' && $course['user_id'] != $currentUserId) {
        return $this->jsonResponse(false, 'Access denied. Only course teacher or admin can approve enrollments.', null, 403);
      }

      // Проверяем, что запись еще не подтверждена
      if ($enrollment['status'] === 'approved') {
        return $this->jsonResponse(false, 'Enrollment is already approved', null, 400);
      }

      // Обновляем статус записи
      $updateData = [
        'status' => 'approved',
        'approved_at' => date('Y-m-d H:i:s'),
        'approved_by' => $currentUserId
      ];

      $result = $this->enrollmentModel->update($enrollmentId, $updateData);

      if ($result) {
        $updatedEnrollment = $this->enrollmentModel->findById($enrollmentId);
        return $this->jsonResponse(true, 'Enrollment approved successfully', $updatedEnrollment);
      } else {
        return $this->jsonResponse(false, 'Failed to approve enrollment', null, 500);
      }
    } catch (Exception $e) {
      error_log("Enrollment approval error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Отклонение записи на курс (для учителя/админа)
   */
  public function rejectEnrollment($enrollmentId)
  {
    try {
      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $currentUserId = $user['id'] ?? null;
      $userRole = $user['role'] ?? 'user';

      // Проверяем валидность enrollmentId
      if (!is_numeric($enrollmentId) || $enrollmentId <= 0) {
        return $this->jsonResponse(false, 'Invalid enrollment ID', null, 400);
      }

      // Получаем запись о записи
      $enrollment = $this->enrollmentModel->findById($enrollmentId);
      if (!$enrollment) {
        return $this->jsonResponse(false, 'Enrollment not found', null, 404);
      }

      // Получаем курс
      $course = $this->courseModel->findById($enrollment['course_id']);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем права доступа
      if ($userRole !== 'admin' && $course['user_id'] != $currentUserId) {
        return $this->jsonResponse(false, 'Access denied. Only course teacher or admin can reject enrollments.', null, 403);
      }

      // Обновляем статус записи
      $updateData = [
        'status' => 'rejected',
        'rejected_at' => date('Y-m-d H:i:s'),
        'rejected_by' => $currentUserId
      ];

      $result = $this->enrollmentModel->update($enrollmentId, $updateData);

      if ($result) {
        $updatedEnrollment = $this->enrollmentModel->findById($enrollmentId);
        return $this->jsonResponse(true, 'Enrollment rejected successfully', $updatedEnrollment);
      } else {
        return $this->jsonResponse(false, 'Failed to reject enrollment', null, 500);
      }
    } catch (Exception $e) {
      error_log("Enrollment rejection error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Отмена записи на курс пользователем
   */
  public function cancelEnrollment($courseId)
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;

      if (!is_numeric($courseId) || $courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Находим существующую запись
      $existingEnrollment = $this->enrollmentModel->findByUserAndCourse($userId, $courseId);

      if (!$existingEnrollment) {
        return $this->jsonResponse(false, 'Enrollment not found', null, 404);
      }

      // Удаляем запись (или можно изменить статус на 'cancelled' если хотите сохранять историю)
      $result = $this->enrollmentModel->delete($existingEnrollment['id']);

      if ($result) {
        return $this->jsonResponse(true, 'Enrollment cancelled successfully');
      } else {
        return $this->jsonResponse(false, 'Failed to cancel enrollment', null, 500);
      }
    } catch (Exception $e) {
      error_log("Cancel enrollment error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение записей на курс (для учителя)
   */
  public function getCourseEnrollments($courseId)
  {
    try {
      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $currentUserId = $user['id'] ?? null;
      $userRole = $user['role'] ?? 'user';

      // Проверяем валидность courseId
      if (!is_numeric($courseId) || $courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Получаем курс
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем права доступа
      if ($userRole !== 'admin' && $course['user_id'] != $currentUserId) {
        return $this->jsonResponse(false, 'Access denied. Only course teacher or admin can view enrollments.', null, 403);
      }

      // Получаем записи на курс
      $enrollments = $this->enrollmentModel->findByCourseId($courseId);

      // Добавляем информацию о пользователях
      foreach ($enrollments as &$enrollment) {
        $userInfo = $this->userModel->findById($enrollment['user_id']);
        if ($userInfo) {
          $enrollment['user'] = [
            'id' => $userInfo['id'],
            'name' => $userInfo['name'] ?? $userInfo['username'] ?? 'Unknown',
            'email' => $userInfo['email'] ?? ''
          ];
        }
      }

      return $this->jsonResponse(true, 'Enrollments retrieved successfully', $enrollments);
    } catch (Exception $e) {
      error_log("Get course enrollments error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение курсов, на которые записан пользователь
   */
  public function myEnrollments()
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;

      $enrollments = $this->enrollmentModel->findByUserId($userId);

      // Добавляем информацию о курсах
      foreach ($enrollments as &$enrollment) {
        $course = $this->courseModel->findById($enrollment['course_id']);
        if ($course) {
          $enrollment['course'] = $course;
          if (!empty($course['preview_image'])) {
            $enrollment['course']['preview_image_url'] = $this->getFullImageUrl($course['preview_image']);
          }
        }
      }

      return $this->jsonResponse(true, 'User enrollments retrieved successfully', $enrollments);
    } catch (Exception $e) {
      error_log("My enrollments error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Проверка, записан ли пользователь на курс
   */
  public function checkEnrollment($courseId)
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;

      if (!is_numeric($courseId) || $courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      $enrollment = $this->enrollmentModel->findByUserAndCourse($userId, $courseId);

      if ($enrollment) {
        return $this->jsonResponse(true, 'Enrollment found', [
          'is_enrolled' => $enrollment['status'] === 'approved',
          'enrollment_status' => $enrollment['status'],
          'enrollment' => $enrollment,
          'can_re_enroll' => $enrollment['status'] === 'rejected' // Добавляем флаг возможности повторной записи
        ]);
      } else {
        return $this->jsonResponse(true, 'Not enrolled', [
          'is_enrolled' => false,
          'enrollment_status' => 'not_enrolled',
          'can_re_enroll' => true
        ]);
      }
    } catch (Exception $e) {
      error_log("Check enrollment error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение пользователя из запроса
   */
  private function getUserFromRequest()
  {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);

    if (empty($token)) {
      return null;
    }

    return $this->getUserFromToken($token);
  }

  /**
   * Получение полного URL для изображения
   */
  private function getFullImageUrl($imagePath)
  {
    if (empty($imagePath)) {
      return null;
    }

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];

    return $protocol . '://' . $host . $imagePath;
  }

  /**
   * Вспомогательный метод для JSON ответов
   */
  protected function jsonResponse($success, $message, $data = null, $statusCode = 200)
  {
    http_response_code($statusCode);
    header('Content-Type: application/json');

    $response = [
      'success' => $success,
      'message' => $message
    ];

    if ($data !== null) {
      $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
  }
}
