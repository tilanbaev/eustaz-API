<?php
class ProgressController extends BaseAuth
{
  protected $moduleModel;
  protected $lessonModel;
  protected $courseModel;
  protected $enrollmentModel;
  protected $userProgressModel;
  protected $testModel;

  public function __construct()
  {
    parent::__construct();
    $this->moduleModel = new Module();
    $this->lessonModel = new Lesson();
    $this->courseModel = new Course();
    $this->enrollmentModel = new Enrollment();
    $this->userProgressModel = new UserProgress();
    $this->testModel = new Test();

    error_log("ProgressController initialized");
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
    return $this->enrollmentModel->hasAccess($userId, $courseId);
  }

  /**
   * Проверяет доступ пользователя к курсу
   * Возвращает массив с результатом: ['success' => true, 'user' => $user] или ['error' => 'сообщение']
   */
  private function checkCourseAccess($courseId)
  {
    // Проверяем аутентификацию
    $user = $this->getUserFromRequest();
    if (!$user) {
      return ['error' => 'Authentication required', 'code' => 401];
    }

    $userId = $user['id'];
    $userRole = $user['role'];

    // Проверка существования курса
    $course = $this->courseModel->findById($courseId);
    if (!$course) {
      return ['error' => 'Course not found', 'code' => 404];
    }

    // Проверка доступа в зависимости от роли
    if ($userRole === 'user') {
      if (!$this->checkStudentAccess($courseId, $userId)) {
        return ['error' => 'You are not enrolled in this course', 'code' => 403];
      }
    } else if ($userRole === 'teacher') {
      if (!$this->checkTeacherAccess($courseId, $userId)) {
        return ['error' => 'Access denied to this course', 'code' => 403];
      }
    } else if ($userRole !== 'admin') {
      return ['error' => 'Access denied', 'code' => 403];
    }

    // Возвращаем успех и данные пользователя
    return ['success' => true, 'user' => $user];
  }

  /**
 * Отметить урок как завершенный
 */
public function completeLesson($courseId, $moduleId, $lessonId)
{
    try {
        // Преобразуем ID в числа
        $courseId = (int)$courseId;
        $moduleId = (int)$moduleId;
        $lessonId = (int)$lessonId;

        if ($courseId <= 0 || $moduleId <= 0 || $lessonId <= 0) {
            return $this->jsonResponse(false, 'Invalid IDs', null, 400);
        }

        // Проверяем существование курса, модуля и урока
        $course = $this->courseModel->findById($courseId);
        if (!$course) {
            return $this->jsonResponse(false, 'Course not found', null, 404);
        }

        $module = $this->moduleModel->findById($moduleId);
        if (!$module) {
            return $this->jsonResponse(false, 'Module not found', null, 404);
        }

        $lesson = $this->lessonModel->findById($lessonId);
        if (!$lesson) {
            return $this->jsonResponse(false, 'Lesson not found', null, 404);
        }

        // Проверяем принадлежность
        if ($module['course_id'] != $courseId) {
            return $this->jsonResponse(false, 'Module does not belong to this course', null, 404);
        }

        if ($lesson['module_id'] != $moduleId) {
            return $this->jsonResponse(false, 'Lesson does not belong to this module', null, 404);
        }

        // Проверяем доступ к курсу
        $accessResult = $this->checkCourseAccess($courseId);

        if (isset($accessResult['error'])) {
            return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
        }

        // Получаем пользователя из результата проверки
        $user = $accessResult['user'];
        $studentId = $user['id'];

        if (!$studentId) {
            return $this->jsonResponse(false, 'Student not authenticated', null, 401);
        }

        // Проверяем, не завершен ли уже урок
        $isCompleted = $this->userProgressModel->isLessonCompleted($studentId, $lessonId);

        if ($isCompleted) {
            return $this->jsonResponse(true, 'Lesson already completed', [
                'lesson_id' => $lessonId,
                'completed' => true,
                'already_completed' => true
            ]);
        }

        // Проверяем, завершен ли тест (если тест есть) с минимальным процентом 50%
        $testCompletion = $this->testModel->isTestCompleted($studentId, $lessonId, 50);
        if (!$testCompletion['completed']) {
            $message = 'Нельзя завершить урок. ';
            if (!$testCompletion['has_attempt']) {
                $message .= 'Сначала необходимо пройти тест.';
            } else {
                $currentScore = $testCompletion['score'] ?? 0;
                $message .= "Необходимо набрать минимум {$testCompletion['min_score']}% правильных ответов. Ваш результат: " . round($currentScore, 1) . "%. Пожалуйста, пройдите тест заново.";
            }
            
            return $this->jsonResponse(false, $message, [
                'lesson_id' => $lessonId,
                'test_required' => true,
                'test_completion' => $testCompletion
            ], 403);
        }

        // Отмечаем урок как завершенный
        $result = $this->userProgressModel->completeLesson($studentId, $lessonId);

        if ($result) {
            // Получаем обновленный прогресс
            $progress = $this->userProgressModel->getUserProgress($studentId, $courseId);

            return $this->jsonResponse(true, 'Lesson marked as completed', [
                'lesson_id' => $lessonId,
                'completed' => true,
                'progress' => $progress,
                'already_completed' => false
            ]);
        } else {
            return $this->jsonResponse(false, 'Failed to complete lesson', null, 500);
        }
    } catch (Exception $e) {
        return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
}

  /**
   * Проверка завершения конкретного урока
   */
  public function checkLessonCompletion($courseId, $moduleId, $lessonId)
  {
    error_log("ProgressController::checkLessonCompletion called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");

    try {
      // Преобразуем ID в числа
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;
      $lessonId = (int)$lessonId;

      error_log("Converted IDs - courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");

      if ($courseId <= 0 || $moduleId <= 0 || $lessonId <= 0) {
        error_log("Invalid IDs - courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем существование курса, модуля и урока
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        error_log("Course not found: " . $courseId);
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      $module = $this->moduleModel->findById($moduleId);
      if (!$module) {
        error_log("Module not found: " . $moduleId);
        return $this->jsonResponse(false, 'Module not found', null, 404);
      }

      $lesson = $this->lessonModel->findById($lessonId);
      if (!$lesson) {
        error_log("Lesson not found: " . $lessonId);
        return $this->jsonResponse(false, 'Lesson not found', null, 404);
      }

      // Проверяем принадлежность
      if ($module['course_id'] != $courseId) {
        error_log("Module {$moduleId} does not belong to course {$courseId}. Module course_id: {$module['course_id']}");
        return $this->jsonResponse(false, 'Module does not belong to this course', null, 404);
      }

      if ($lesson['module_id'] != $moduleId) {
        error_log("Lesson {$lessonId} does not belong to module {$moduleId}. Lesson module_id: {$lesson['module_id']}");
        return $this->jsonResponse(false, 'Lesson does not belong to this module', null, 404);
      }

      // Проверяем доступ к курсу
      error_log("Checking course access for user...");
      $accessResult = $this->checkCourseAccess($courseId);

      if (isset($accessResult['error'])) {
        error_log("Access denied: " . $accessResult['error']);
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя из результата проверки
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        error_log("Student ID not found in user data");
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      error_log("Checking completion status for lesson {$lessonId}, student {$studentId}");

      // Проверяем, завершен ли урок
      $isCompleted = $this->userProgressModel->isLessonCompleted($studentId, $lessonId);

      // Получаем дополнительную информацию о завершении
      $completionDate = null;
      if ($isCompleted) {
        $completionDate = $this->userProgressModel->getLessonCompletionDate($studentId, $lessonId);
      }

      // Проверяем доступность урока
      $isAccessible = $this->userProgressModel->isLessonAccessible($studentId, $lessonId);

      // Проверяем, завершен ли тест (если тест есть) с минимальным процентом 50%
      $testCompletion = $this->testModel->isTestCompleted($studentId, $lessonId, 50);

      return $this->jsonResponse(true, 'Lesson completion status retrieved', [
        'lesson_id' => $lessonId,
        'module_id' => $moduleId,
        'course_id' => $courseId,
        'is_test_completed' => $testCompletion['completed'],
        'test_score' => $testCompletion['score'],
        'test_min_score' => $testCompletion['min_score'],
        'is_completed' => $isCompleted,
        'completion_date' => $completionDate,
        'is_accessible' => $isAccessible,
        'student_id' => $studentId,
        'lesson_title' => $lesson['title'] ?? null,
        'module_title' => $module['title'] ?? null,
        'course_title' => $course['title'] ?? null
      ]);
    } catch (Exception $e) {
      error_log("Check lesson completion error: " . $e->getMessage());
      error_log("Stack trace: " . $e->getTraceAsString());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Проверка завершения нескольких уроков (пакетный запрос)
   */
  public function checkMultipleLessonsCompletion($courseId)
  {
    error_log("ProgressController::checkMultipleLessonsCompletion called with courseId: {$courseId}");

    try {
      $courseId = (int)$courseId;
      if ($courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Получаем список lesson_id из запроса
      $input = json_decode(file_get_contents('php://input'), true);
      $lessonIds = $input['lesson_ids'] ?? [];

      if (empty($lessonIds) || !is_array($lessonIds)) {
        return $this->jsonResponse(false, 'Lesson IDs are required as an array', null, 400);
      }

      // Преобразуем все ID в числа
      $lessonIds = array_map('intval', $lessonIds);
      $lessonIds = array_filter($lessonIds, function ($id) {
        return $id > 0;
      });

      if (empty($lessonIds)) {
        return $this->jsonResponse(false, 'No valid lesson IDs provided', null, 400);
      }

      // Проверяем существование курса
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем доступ к курсу
      $accessResult = $this->checkCourseAccess($courseId);
      if (isset($accessResult['error'])) {
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      error_log("Checking completion status for " . count($lessonIds) . " lessons, student {$studentId}");

      // Собираем информацию о завершении каждого урока
      $completionStatus = [];
      $completedLessons = $this->userProgressModel->getCompletedLessons($studentId);

      foreach ($lessonIds as $lessonId) {
        // Проверяем существование урока
        $lesson = $this->lessonModel->findById($lessonId);
        if (!$lesson) {
          $completionStatus[$lessonId] = [
            'lesson_id' => $lessonId,
            'exists' => false,
            'is_completed' => false,
            'error' => 'Lesson not found'
          ];
          continue;
        }

        // Проверяем принадлежность урока к курсу через модуль
        $module = $this->moduleModel->findById($lesson['module_id']);
        if (!$module || $module['course_id'] != $courseId) {
          $completionStatus[$lessonId] = [
            'lesson_id' => $lessonId,
            'exists' => true,
            'is_completed' => false,
            'error' => 'Lesson does not belong to this course'
          ];
          continue;
        }

        // Проверяем завершение
        $isCompleted = isset($completedLessons[$lessonId]);
        $completionDate = $isCompleted ?
          $this->userProgressModel->getLessonCompletionDate($studentId, $lessonId) : null;

        $completionStatus[$lessonId] = [
          'lesson_id' => $lessonId,
          'exists' => true,
          'is_completed' => $isCompleted,
          'completion_date' => $completionDate,
          'lesson_title' => $lesson['title'] ?? null,
          'module_id' => $lesson['module_id'],
          'module_title' => $module['title'] ?? null
        ];
      }

      return $this->jsonResponse(true, 'Multiple lessons completion status retrieved', [
        'course_id' => $courseId,
        'student_id' => $studentId,
        'total_lessons_checked' => count($lessonIds),
        'completed_count' => count(array_filter($completionStatus, function ($status) {
          return $status['exists'] && $status['is_completed'];
        })),
        'completion_status' => $completionStatus
      ]);
    } catch (Exception $e) {
      error_log("Check multiple lessons completion error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение статуса доступности и завершения урока
   */
  public function getLessonStatus($courseId, $moduleId, $lessonId)
  {
    error_log("ProgressController::getLessonStatus called");

    try {
      // Используем существующий метод проверки завершения
      $completionResult = $this->checkLessonCompletion($courseId, $moduleId, $lessonId);

      // Если есть ошибка, возвращаем ее
      if (!$completionResult['success'] ?? false) {
        return $completionResult;
      }

      $data = $completionResult['data'] ?? [];

      // Добавляем дополнительную информацию о модуле и курсе
      $nextLesson = $this->userProgressModel->getNextRecommendedLesson($data['student_id'], $courseId);

      // Получаем прогресс по модулю
      $moduleProgress = $this->userProgressModel->getModuleProgress($data['student_id'], $moduleId);

      // Получаем прогресс по курсу
      $courseProgress = $this->userProgressModel->getUserProgress($data['student_id'], $courseId);

      $data['next_recommended_lesson'] = $nextLesson;
      $data['module_progress'] = $moduleProgress;
      $data['course_progress'] = $courseProgress;

      return $this->jsonResponse(true, 'Lesson status retrieved successfully', $data);
    } catch (Exception $e) {
      error_log("Get lesson status error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Сброс прогресса урока (для повторного прохождения)
   */
  public function resetLessonProgress($courseId, $moduleId, $lessonId)
  {
    error_log("ProgressController::resetLessonProgress called");

    try {
      // Проверяем параметры
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;
      $lessonId = (int)$lessonId;

      if ($courseId <= 0 || $moduleId <= 0 || $lessonId <= 0) {
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем существование и принадлежность
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      $module = $this->moduleModel->findById($moduleId);
      if (!$module) {
        return $this->jsonResponse(false, 'Module not found', null, 404);
      }

      if ($module['course_id'] != $courseId) {
        return $this->jsonResponse(false, 'Module does not belong to this course', null, 404);
      }

      $lesson = $this->lessonModel->findById($lessonId);
      if (!$lesson) {
        return $this->jsonResponse(false, 'Lesson not found', null, 404);
      }

      if ($lesson['module_id'] != $moduleId) {
        return $this->jsonResponse(false, 'Lesson does not belong to this module', null, 404);
      }

      // Проверяем доступ к курсу
      $accessResult = $this->checkCourseAccess($courseId);
      if (isset($accessResult['error'])) {
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      // Сбрасываем прогресс
      $result = $this->userProgressModel->resetLessonProgress($studentId, $lessonId);

      if ($result) {
        // Получаем обновленный статус
        $isCompleted = $this->userProgressModel->isLessonCompleted($studentId, $lessonId);

        return $this->jsonResponse(true, 'Lesson progress reset successfully', [
          'lesson_id' => $lessonId,
          'is_completed' => $isCompleted,
          'reset_successful' => true,
          'message' => 'You can now retake this lesson'
        ]);
      } else {
        return $this->jsonResponse(false, 'Failed to reset lesson progress', null, 500);
      }
    } catch (Exception $e) {
      error_log("Reset lesson progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение прогресса по курсу
   */
  public function getCourseProgress($courseId)
  {
    error_log("ProgressController::getCourseProgress called with courseId: " . $courseId);

    try {
      $courseId = (int)$courseId;
      if ($courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Проверяем существование курса
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем доступ к курсу
      $accessResult = $this->checkCourseAccess($courseId);
      if (isset($accessResult['error'])) {
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя из результата проверки
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      // Получаем прогресс
      $progress = $this->userProgressModel->getUserProgress($studentId, $courseId);

      return $this->jsonResponse(true, 'Course progress retrieved successfully', $progress);
    } catch (Exception $e) {
      error_log("Get course progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение детального прогресса по модулям
   */
  public function getDetailedProgress($courseId)
  {
    error_log("ProgressController::getDetailedProgress called with courseId: " . $courseId);

    try {
      $courseId = (int)$courseId;
      if ($courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Проверяем существование курса
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем доступ к курсу
      $accessResult = $this->checkCourseAccess($courseId);
      if (isset($accessResult['error'])) {
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя из результата проверки
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      // Получаем детальный прогресс
      $detailedProgress = $this->userProgressModel->getDetailedProgress($studentId, $courseId);

      return $this->jsonResponse(true, 'Detailed progress retrieved successfully', $detailedProgress);
    } catch (Exception $e) {
      error_log("Get detailed progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение статистики прогресса студента
   */
  public function getProgressStats($courseId)
  {
    error_log("ProgressController::getProgressStats called with courseId: " . $courseId);

    try {
      $courseId = (int)$courseId;
      if ($courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Проверяем доступ к курсу
      $accessResult = $this->checkCourseAccess($courseId);
      if (isset($accessResult['error'])) {
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя из результата проверки
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      // Получаем статистику прогресса
      $progressStats = $this->userProgressModel->getProgressStats($studentId);

      return $this->jsonResponse(true, 'Progress stats retrieved successfully', $progressStats);
    } catch (Exception $e) {
      error_log("Get progress stats error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение прогресса по модулю
   */
  public function getModuleProgress($courseId, $moduleId)
  {
    error_log("ProgressController::getModuleProgress called with courseId: {$courseId}, moduleId: {$moduleId}");

    try {
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;

      if ($courseId <= 0 || $moduleId <= 0) {
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем существование курса
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем существование модуля и принадлежность курсу
      $module = $this->moduleModel->findById($moduleId);
      if (!$module) {
        return $this->jsonResponse(false, 'Module not found', null, 404);
      }

      if ($module['course_id'] != $courseId) {
        return $this->jsonResponse(false, 'Module does not belong to this course', null, 404);
      }

      // Проверяем доступ к курсу
      $accessResult = $this->checkCourseAccess($courseId);
      if (isset($accessResult['error'])) {
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      } 

      // Получаем прогресс по модулю
      $moduleProgress = $this->userProgressModel->getModuleProgress($studentId, $moduleId);

      return $this->jsonResponse(true, 'Module progress retrieved successfully', $moduleProgress);
    } catch (Exception $e) {
      error_log("Get module progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение следующей рекомендованной лекции
   */
  public function getNextRecommendedLesson($courseId)
  {
    error_log("ProgressController::getNextRecommendedLesson called with courseId: " . $courseId);

    try {
      $courseId = (int)$courseId;
      if ($courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Проверяем существование курса
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем доступ к курсу
      $accessResult = $this->checkCourseAccess($courseId);
      if (isset($accessResult['error'])) {
        return $this->jsonResponse(false, $accessResult['error'], null, $accessResult['code'] ?? 403);
      }

      // Получаем пользователя
      $user = $accessResult['user'];
      $studentId = $user['id'];

      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      // Получаем следующую рекомендованную лекцию
      $nextLesson = $this->userProgressModel->getNextRecommendedLesson($studentId, $courseId);

      if ($nextLesson) {
        return $this->jsonResponse(true, 'Next recommended lesson found', $nextLesson);
      } else {
        return $this->jsonResponse(true, 'No more lessons to complete', [
          'message' => 'All lessons in this course are completed',
          'all_completed' => true
        ]);
      }
    } catch (Exception $e) {
      error_log("Get next recommended lesson error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
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

    error_log("ProgressController JSON Response - Success: {$success}, Message: {$message}, Status: {$statusCode}");

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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

    error_log("Authorization token found");
    return $this->getUserFromToken($token);
  }

  /**
   * Проверка существования курса (вспомогательный метод)
   */
  private function validateCourseExists($courseId)
  {
    $course = $this->courseModel->findById($courseId);
    if (!$course) {
      return $this->jsonResponse(false, 'Course not found', null, 404);
    }
    return $course;
  }

  /**
   * Проверка существования модуля и его принадлежности курсу
   */
  private function validateModuleExists($moduleId, $courseId)
  {
    $module = $this->moduleModel->findById($moduleId);
    if (!$module) {
      return $this->jsonResponse(false, 'Module not found', null, 404);
    }

    if ($module['course_id'] != $courseId) {
      return $this->jsonResponse(false, 'Module does not belong to this course', null, 404);
    }

    return $module;
  }

  /**
   * Проверка существования урока и его принадлежности модулю
   */
  private function validateLessonExists($lessonId, $moduleId)
  {
    $lesson = $this->lessonModel->findById($lessonId);
    if (!$lesson) {
      return $this->jsonResponse(false, 'Lesson not found', null, 404);
    }

    if ($lesson['module_id'] != $moduleId) {
      return $this->jsonResponse(false, 'Lesson does not belong to this module', null, 404);
    }

    return $lesson;
  }
}
