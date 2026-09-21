<?php
class TestController extends BaseAuth
{
  protected $moduleModel;
  protected $lessonModel;
  protected $courseModel;
  protected $testModel;
  protected $uploadPath;

  public function __construct()
  {
    parent::__construct();
    $this->moduleModel = new Module();
    $this->lessonModel = new Lesson();
    $this->courseModel = new Course();
    $this->testModel = new Test();
    $this->uploadPath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/lessons/attachments/';

    // Создаем директорию для загрузок если не существует
    if (!file_exists($this->uploadPath)) {
      mkdir($this->uploadPath, 0755, true);
    }

    error_log("TestController initialized");
  }

  /**
   * Получение теста урока
   */
  public function getTest($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::getTest called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
    return $this->handleLessonRequest($courseId, $moduleId, $lessonId, function ($courseId, $moduleId, $lessonId, $userId) {
      // Получаем тест урока
      $test = $this->testModel->findByLessonId($lessonId);

      if ($test) {
        error_log("Test found for lesson: " . $lessonId);
        return $this->jsonResponse(true, 'Test retrieved successfully', $test);
      } else {
        error_log("Test not found for lesson: " . $lessonId);
        return $this->jsonResponse(false, 'Test not found', null, 404);
      }
    });
  }

  /**
   * Получение теста для студента (публичный доступ)
   */
  public function getTestPublic($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::getTestPublic called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");

    try {
      // Преобразуем ID в числа
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;
      $lessonId = (int)$lessonId;

      if ($courseId <= 0 || $moduleId <= 0 || $lessonId <= 0) {
        error_log("Invalid IDs - courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем, существует ли курс, модуль и урок
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
      if ($module['course_id'] != $courseId || $lesson['module_id'] != $moduleId) {
        error_log("Invalid course-module-lesson relationship");
        return $this->jsonResponse(false, 'Invalid relationship', null, 404);
      }

      // Получаем тест (только вопросы без правильных ответов)
      $test = $this->testModel->findByLessonIdForStudent($lessonId);

      if ($test) {
        error_log("Test found for lesson: " . $lessonId);
        return $this->jsonResponse(true, 'Test retrieved successfully', $test);
      } else {
        error_log("Test not found for lesson: " . $lessonId);
        return $this->jsonResponse(false, 'Test not found', null, 404);
      }
    } catch (Exception $e) {
      error_log("Test get public error: " . $e->getMessage());
      error_log("Stack trace: " . $e->getTraceAsString());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Отправка теста студентом
   */
  public function submitTest($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::submitTest called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");

    try {
      // Преобразуем ID в числа
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;
      $lessonId = (int)$lessonId;

      if ($courseId <= 0 || $moduleId <= 0 || $lessonId <= 0) {
        error_log("Invalid IDs - courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input || !isset($input['answers'])) {
        error_log("Invalid test submission data");
        return $this->jsonResponse(false, 'Invalid test data', null, 400);
      }

      // Проверяем, существует ли курс, модуль и урок
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
      if ($module['course_id'] != $courseId || $lesson['module_id'] != $moduleId) {
        error_log("Invalid course-module-lesson relationship");
        return $this->jsonResponse(false, 'Invalid relationship', null, 404);
      }

      // Получаем ID студента - используем общий метод аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        error_log("User not authenticated for test submission");
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $studentId = $user['id'] ?? null;
      if (!$studentId) {
        error_log("Student ID not found in user data");
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      error_log("Student authenticated with ID: " . $studentId);

      // Получаем тест
      $test = $this->testModel->findByLessonId($lessonId);
      if (!$test) {
        error_log("Test not found for lesson: " . $lessonId);
        return $this->jsonResponse(false, 'Test not found', null, 404);
      }

      // Проверяем ответы
      $result = $this->testModel->checkAnswers($lessonId, $input['answers']);

      if ($result) {
        // Сохраняем результат в базу данных
        $testResultId = $this->saveTestResult([
          'test_id' => $test['id'],
          'student_id' => $studentId,
          'score' => $result['score'],
          'total_questions' => $result['total_questions'],
          'correct_answers' => $result['correct_answers'],
          'time_spent' => $input['time_spent'] ?? 0,
          'started_at' => $input['started_at'] ?? date('Y-m-d H:i:s'),
          'answers_data' => json_encode($input['answers'])
        ]);

        if ($testResultId) {
          $result['test_result_id'] = $testResultId;
          $result['attempt_saved'] = true;

          error_log("Test submitted and saved successfully for lesson: " . $lessonId . ", result ID: " . $testResultId);
          return $this->jsonResponse(true, 'Test submitted successfully', $result);
        } else {
          error_log("Failed to save test result for lesson: " . $lessonId);
          return $this->jsonResponse(false, 'Failed to save test result', null, 500);
        }
      } else {
        error_log("Failed to check test answers for lesson: " . $lessonId);
        return $this->jsonResponse(false, 'Failed to check test answers', null, 500);
      }
    } catch (Exception $e) {
      error_log("Test submit error: " . $e->getMessage());
      error_log("Stack trace: " . $e->getTraceAsString());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Сохранение результата теста
   */
  private function saveTestResult($data)
  {
    try {
      // Создаем экземпляр модели TestResult
      $testResultModel = new TestResult();

      // Получаем следующий номер попытки
      $attemptNumber = $testResultModel->getNextAttemptNumber(
        $data['student_id'],
        $data['test_id']
      );

      // Сохраняем результат
      return $testResultModel->saveResult([
        'test_id' => $data['test_id'],
        'student_id' => $data['student_id'],
        'attempt_number' => $attemptNumber,
        'score' => $data['score'],
        'total_questions' => $data['total_questions'],
        'correct_answers' => $data['correct_answers'],
        'time_spent' => $data['time_spent'],
        'started_at' => $data['started_at'],
        'completed_at' => date('Y-m-d H:i:s'),
        'status' => 'completed'
      ]);
    } catch (Exception $e) {
      error_log("Save test result error: " . $e->getMessage());
      return false;
    }
  }
  /**
   * Получение истории попыток студента по тесту
   */
  /**
   * Получение истории попыток студента по тесту
   */
  public function getTestAttempts($courseId, $moduleId, $lessonId)
  {
    try {
      // Проверка прав доступа и валидация параметров
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $studentId = $user['id'] ?? null;
      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      $test = $this->testModel->findByLessonId($lessonId);
      if (!$test) {
        return $this->jsonResponse(false, 'Test not found', null, 404);
      }

      $testResultModel = new TestResult();
      $attempts = $testResultModel->findByStudentAndTest($studentId, $test['id']);

      return $this->jsonResponse(true, 'Attempts retrieved successfully', $attempts);
    } catch (Exception $e) {
      error_log("Get test attempts error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение лучшего результата студента
   */
  public function getBestResult($courseId, $moduleId, $lessonId)
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $studentId = $user['id'] ?? null;
      if (!$studentId) {
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      $test = $this->testModel->findByLessonId($lessonId);
      if (!$test) {
        return $this->jsonResponse(false, 'Test not found', null, 404);
      }

      $testResultModel = new TestResult();
      $bestResult = $testResultModel->getBestResult($studentId, $test['id']);

      return $this->jsonResponse(true, 'Best result retrieved successfully', $bestResult);
    } catch (Exception $e) {
      error_log("Get best result error: " . $e->getMessage());
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

    error_log("JSON Response: " . json_encode($response));
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
  }
}
