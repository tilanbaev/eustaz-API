<?php
class StudentResultsController extends BaseAuth
{
  protected $studentResultsModel;
  protected $courseModel;
  protected $moduleModel;
  protected $lessonModel;
  protected $enrollment;
  protected $enrollmentController;

  public function __construct()
  {
    parent::__construct();
    $this->studentResultsModel = new StudentResults();
    $this->courseModel = new Course();
    $this->moduleModel = new Module();
    $this->lessonModel = new Lesson();
    $this->enrollment = new Enrollment();
    $this->enrollmentController = new EnrollmentController();
  }

  /**
   * Получение пользователя из запроса
   */
  private function getUserFromRequest()
  {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);

    if (empty($token)) {
      error_log("No authorization token found");
      return null;
    }

    error_log("Authorization token found, length: " . strlen($token));
    return $this->getUserFromToken($token);
  }

  /**
   * Проверка доступа преподавателя к курсу
   */
  private function checkTeacherAccess($courseId, $userId)
  {
    $course = $this->courseModel->findById($courseId);
    if (!$course) {
      error_log("Course not found: " . $courseId);
      return false;
    }

    if ($course['user_id'] != $userId) {
      error_log("User {$userId} is not owner of course {$courseId}");
      return false;
    }

    return true;
  }

  /**
   * Проверка доступа студента к курсу через Enrollment
   */
  private function checkStudentAccess($courseId, $userId)
  {
    return $this->enrollment->hasAccess($userId, $courseId);
  }

  /**
   * Проверяет доступ пользователя к курсу
   */
  private function checkCourseAccess($courseId, $requiredRole = null)
  {
    // Проверяем аутентификацию
    $user = $this->getUserFromRequest();
    if (!$user) {
      return $this->jsonResponse(false, 'Authentication required', null, 401);
    }

    $userId = $user['id'];
    $userRole = $user['role'];

    // Если указана требуемая роль, проверяем ее
    if ($requiredRole && $userRole !== $requiredRole) {
      return $this->jsonResponse(false, 'Insufficient permissions', null, 403);
    }

    // Проверка доступа в зависимости от роли
    if ($userRole === 'user') {
      if (!$this->checkStudentAccess($courseId, $userId)) {
        return $this->jsonResponse(false, 'You are not enrolled in this course', null, 403);
      }
    } else if ($userRole === 'teacher') {
      if (!$this->checkTeacherAccess($courseId, $userId)) {
        return $this->jsonResponse(false, 'Access denied to this course', null, 403);
      }
    } else if ($userRole !== 'admin') {
      return $this->jsonResponse(false, 'Access denied', null, 403);
    }

    // Возвращаем данные пользователя при успешной проверке
    return $user;
  }

  /**
   * Получение попыток студента по уроку
   */
  public function getMyAttempts($course_id, $module_id, $lesson_id)
  {
    try {
      // Проверяем доступ к курсу
      $user = $this->checkCourseAccess($course_id);
      if (!is_array($user)) {
        return $user; // Возвращаем ошибку из checkCourseAccess
      }

      // Проверяем существование урока
      $lesson = $this->lessonModel->findById($lesson_id);
      if (!$lesson) {
        return $this->jsonResponse(false, 'Lesson not found', null, 404);
      }

      $attempts = $this->studentResultsModel->getStudentAttempts($user['id'], $lesson_id);
      $bestResult = $this->studentResultsModel->getStudentBestResult($user['id'], $lesson_id);

      return $this->jsonResponse(true, 'Attempts retrieved successfully', [
        'attempts' => $attempts,
        'best_result' => $bestResult,
        'lesson' => $lesson
      ]);
    } catch (Exception $e) {
      error_log("Get my attempts error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение лучшего результата по уроку
   */
  public function getMyBestResult($course_id, $module_id, $lesson_id)
  {
    try {
      // Проверяем доступ к курсу
      $user = $this->checkCourseAccess($course_id);
      if (!is_array($user)) {
        return $user;
      }

      $bestResult = $this->studentResultsModel->getStudentBestResult($user['id'], $lesson_id);

      if (!$bestResult) {
        return $this->jsonResponse(false, 'No attempts found for this lesson', null, 404);
      }

      return $this->jsonResponse(true, 'Best result retrieved successfully', $bestResult);
    } catch (Exception $e) {
      error_log("Get my best result error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение прогресса по курсу
   */
  public function getMyCourseProgress($course_id)
  {
    try {
      // Проверяем доступ к курсу
      $user = $this->checkCourseAccess($course_id);
      if (!is_array($user)) {
        return $user;
      }

      $progress = $this->studentResultsModel->getStudentCourseProgress($user['id'], $course_id);
      $statistics = $this->studentResultsModel->getStudentCourseStatistics($user['id'], $course_id);
      $course = $this->courseModel->findById($course_id);

      return $this->jsonResponse(true, 'Course progress retrieved successfully', [
        'progress' => $progress,
        'statistics' => $statistics,
        'course' => $course
      ]);
    } catch (Exception $e) {
      error_log("Get my course progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение детальной информации о попытке
   */
  public function getMyAttemptDetails($attempt_id)
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'Authentication required', null, 401);
      }

      $attempt = $this->studentResultsModel->getStudentAttemptDetails($user['id'], $attempt_id);

      if (!$attempt) {
        return $this->jsonResponse(false, 'Attempt not found', null, 404);
      }

      return $this->jsonResponse(true, 'Attempt details retrieved successfully', $attempt);
    } catch (Exception $e) {
      error_log("Get my attempt details error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение последних активностей
   */
  public function getMyRecentActivity()
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'Authentication required', null, 401);
      }

      $input = json_decode(file_get_contents('php://input'), true);
      $limit = $input['limit'] ?? 10;

      $activity = $this->studentResultsModel->getStudentRecentActivity($user['id'], $limit);

      return $this->jsonResponse(true, 'Recent activity retrieved successfully', [
        'activity' => $activity,
        'total' => count($activity)
      ]);
    } catch (Exception $e) {
      error_log("Get my recent activity error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение общей статистики студента
   */
  public function getMyOverallStatistics()
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'Authentication required', null, 401);
      }

      // Получаем все курсы студента
      $enrollments = $this->enrollmentController->myEnrollments($user['id']);

      $overallStats = [
        'total_courses' => count($enrollments),
        'completed_courses' => 0,
        'total_attempts' => 0,
        'average_score' => 0,
        'total_time_spent' => 0
      ];

      $totalScore = 0;
      $coursesWithStats = 0;

      foreach ($enrollments as $enrollment) {
        $courseStats = $this->studentResultsModel->getStudentCourseStatistics($user['id'], $enrollment['course_id']);

        if ($courseStats) {
          $overallStats['total_attempts'] += $courseStats['total_attempts'];
          $overallStats['total_time_spent'] += $courseStats['total_time_spent'];

          if ($courseStats['average_score'] > 0) {
            $totalScore += $courseStats['average_score'];
            $coursesWithStats++;
          }

          if ($courseStats['completion_rate'] >= 100) {
            $overallStats['completed_courses']++;
          }
        }
      }

      $overallStats['average_score'] = $coursesWithStats > 0
        ? round($totalScore / $coursesWithStats, 2)
        : 0;

      return $this->jsonResponse(true, 'Overall statistics retrieved successfully', $overallStats);
    } catch (Exception $e) {
      error_log("Get my overall statistics error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение полного прогресса по уроку
   */
  public function getMyLessonProgress($course_id, $module_id, $lesson_id)
  {
    try {
      // Проверяем доступ к курсу
      $user = $this->checkCourseAccess($course_id);
      if (!is_array($user)) {
        return $user;
      }

      $progress = $this->studentResultsModel->getLessonProgress($user['id'], $lesson_id);

      if (!$progress) {
        return $this->jsonResponse(false, 'Lesson not found', null, 404);
      }

      return $this->jsonResponse(true, 'Lesson progress retrieved successfully', $progress);
    } catch (Exception $e) {
      error_log("Get my lesson progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Обновление прогресса элемента урока
   */
  public function updateMyProgress()
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'Authentication required', null, 401);
      }

      $input = json_decode(file_get_contents('php://input'), true);

      $required = ['lesson_id', 'item_type', 'item_id', 'status'];
      foreach ($required as $field) {
        if (!isset($input[$field])) {
          return $this->jsonResponse(false, "Missing required field: $field", null, 400);
        }
      }

      // Обновляем статус элемента
      $success = $this->studentResultsModel->updateItemStatus(
        $user['id'],
        $input['lesson_id'],
        $input['item_type'],
        $input['item_id'],
        $input['status'],
        $input['score'] ?? null
      );

      if ($success) {
        // Пересчитываем общий прогресс урока
        $this->recalculateLessonProgress($user['id'], $input['lesson_id']);

        return $this->jsonResponse(true, 'Progress updated successfully');
      } else {
        return $this->jsonResponse(false, 'Failed to update progress', null, 500);
      }
    } catch (Exception $e) {
      error_log("Update my progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение детального прогресса по курсу
   */
  public function getMyDetailedCourseProgress($course_id)
  {
    try {
      // Проверяем доступ к курсу
      $user = $this->checkCourseAccess($course_id);
      if (!is_array($user)) {
        return $user;
      }

      $progress = $this->studentResultsModel->getDetailedCourseProgress($user['id'], $course_id);
      $statistics = $this->studentResultsModel->getStudentCourseStatistics($user['id'], $course_id);
      $course = $this->courseModel->findById($course_id);

      return $this->jsonResponse(true, 'Detailed course progress retrieved successfully', [
        'progress' => $progress,
        'statistics' => $statistics,
        'course' => $course
      ]);
    } catch (Exception $e) {
      error_log("Get my detailed course progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Пересчет прогресса урока
   */
  private function recalculateLessonProgress($studentId, $lessonId)
  {
    try {
      $items = $this->studentResultsModel->getLessonItemsStatus($studentId, $lessonId);
      $progress = $this->studentResultsModel->calculateLessonProgress($items);

      // Считаем общее время
      $totalTime = 0;
      $isCompleted = true;

      foreach ($items as $item) {
        if ($item['status'] !== 'completed') {
          $isCompleted = false;
        }
      }

      // Обновляем общий прогресс урока
      $this->studentResultsModel->updateLessonProgress($studentId, $lessonId, [
        'progress_percent' => $progress,
        'time_spent' => $totalTime,
        'is_completed' => $isCompleted
      ]);
    } catch (Exception $e) {
      error_log("Recalculate lesson progress error: " . $e->getMessage());
    }
  }

  /**
   * JSON Response helper
   */
  private function jsonResponse($success, $message, $data = null, $statusCode = 200)
  {
    http_response_code($statusCode);
    header('Content-Type: application/json');

    echo json_encode([
      'success' => $success,
      'message' => $message,
      'data' => $data
    ]);
    exit;
  }
}
