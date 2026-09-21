<?php
class TeacherController extends BaseAuth
{
  protected $teacherResultsModel;
  protected $courseModel;
  protected $moduleModel;
  protected $lessonModel;

  public function __construct()
  {
    parent::__construct();
    $this->teacherResultsModel = new TeacherResults();
    $this->courseModel = new Course();
    $this->moduleModel = new Module();
    $this->lessonModel = new Lesson();
  }

  /**
   * Получение результатов по курсу
   */
  public function getCourseResults($course_id)
  {
    try {
      $teacherId = $this->getCurrentTeacherId();
      if (!$teacherId) {
        return $this->jsonResponse(false, 'Teacher not authenticated', null, 401);
      }

      // Проверяем, что курс принадлежит учителю
      $course = $this->courseModel->findById($course_id);
      if (!$course || $course['user_id'] != $teacherId) {
        return $this->jsonResponse(false, 'Course not found or access denied', null, 404);
      }

      $results = $this->teacherResultsModel->getCourseResults($teacherId, $course_id);
      $statistics = $this->teacherResultsModel->getCourseStatistics($teacherId, $course_id);

      return $this->jsonResponse(true, 'Course results retrieved successfully', [
        'results' => $results,
        'statistics' => $statistics,
        'course' => $course
      ]);
    } catch (Exception $e) {
      error_log("Get course results error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение результатов по уроку
   */
  public function getLessonResults($course_id, $module_id, $lesson_id)
  {
    try {
      $teacherId = $this->getCurrentTeacherId();
      if (!$teacherId) {
        return $this->jsonResponse(false, 'Teacher not authenticated', null, 401); 
      }

      // Проверяем принадлежность уроков и курса
      $course = $this->courseModel->findById($course_id);
      $module = $this->moduleModel->findById($module_id);
      $lesson = $this->lessonModel->findById($lesson_id);

      if (!$course || $course['user_id'] != $teacherId) {
        return $this->jsonResponse(false, 'Course not found or access denied', null, 404);
      }

      if (!$module || $module['course_id'] != $course_id) {
        return $this->jsonResponse(false, 'Module not found', null, 404); 
      }

      if (!$lesson || $lesson['module_id'] != $module_id) {
        return $this->jsonResponse(false, 'Lesson not found', null, 404);
      }

      $results = $this->teacherResultsModel->getLessonResults($teacherId, $lesson_id);
      $statistics = $this->teacherResultsModel->getLessonStatistics($teacherId, $lesson_id);

      return $this->jsonResponse(true, 'Lesson results retrieved successfully', [
        'results' => $results,
        'statistics' => $statistics,
        'lesson' => $lesson,
        'module' => $module,
        'course' => $course
      ]);
    } catch (Exception $e) {
      error_log("Get lesson results error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение прогресса студентов по курсу
   */
  public function getStudentsProgress($course_id)
  {
    try {
      $teacherId = $this->getCurrentTeacherId();
      if (!$teacherId) {
        return $this->jsonResponse(false, 'Teacher not authenticated', null, 401);
      }

      // Проверяем, что курс принадлежит учителю
      $course = $this->courseModel->findById($course_id);
      if (!$course || $course['user_id'] != $teacherId) {
        return $this->jsonResponse(false, 'Course not found or access denied', null, 404);
      }

      $progress = $this->teacherResultsModel->getStudentsProgress($teacherId, $course_id);

      return $this->jsonResponse(true, 'Students progress retrieved successfully', [
        'progress' => $progress,
        'course' => $course
      ]);
    } catch (Exception $e) {
      error_log("Get students progress error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение детальной информации о попытке
   */
  public function getAttemptDetails($attempt_id)
  {
    try {
      $teacherId = $this->getCurrentTeacherId();
      if (!$teacherId) {
        return $this->jsonResponse(false, 'Teacher not authenticated', null, 401);
      }

      $attempt = $this->teacherResultsModel->getAttemptDetails($teacherId, $attempt_id);

      if (!$attempt) {
        return $this->jsonResponse(false, 'Attempt not found or access denied', null, 404);
      }

      return $this->jsonResponse(true, 'Attempt details retrieved successfully', $attempt);
    } catch (Exception $e) {
      error_log("Get attempt details error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Поиск результатов по студенту
   */
  public function searchStudentResults()
  {
    try {
      $teacherId = $this->getCurrentTeacherId();
      if (!$teacherId) {
        return $this->jsonResponse(false, 'Teacher not authenticated', null, 401);
      }

      $input = json_decode(file_get_contents('php://input'), true);
      $studentName = $input['name'] ?? '';
      $course_id = $input['course_id'] ?? null;

      if (empty($studentName)) {
        return $this->jsonResponse(false, 'Student name is required', null, 400);
      }

      $results = $this->teacherResultsModel->searchStudentResults($teacherId, $studentName, $course_id);

      return $this->jsonResponse(true, 'Search results retrieved successfully', $results);
    } catch (Exception $e) {
      error_log("Search student results error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }
/**
 * Экспорт результатов в CSV
 */
public function exportCourseResults($course_id)
{
    try {
        $teacherId = $this->getCurrentTeacherId();
        if (!$teacherId) {
            return $this->jsonResponse(false, 'Teacher not authenticated', null, 401);
        }

        // Проверяем, что курс принадлежит учителю
        $course = $this->courseModel->findById($course_id);
        if (!$course || $course['user_id'] != $teacherId) {
            return $this->jsonResponse(false, 'Course not found or access denied', null, 404);
        }

        $results = $this->teacherResultsModel->getCourseResults($teacherId, $course_id);
        
        // Проверяем, что results - это массив
        if ($results === false || !is_array($results)) {
            return $this->jsonResponse(false, 'No data available for export', null, 404);
        }
        
        // Проверяем, что массив не пустой
        if (empty($results)) {
            return $this->jsonResponse(false, 'No results found for this course', null, 404);
        }

        // Генерируем CSV
        $csvData = $this->generateCSV($results, $course);

        // Возвращаем CSV как файл
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="results_' . $course['title'] . '_' . date('Y-m-d') . '.csv"');

        echo $csvData;
        exit;
    } catch (Exception $e) {
        error_log("Export course results error: " . $e->getMessage());
        return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
}

/**
 * Генерация CSV данных
 */
private function generateCSV($results, $course)
{
    // Создаем временный буфер для записи CSV
    $output = fopen('php://temp', 'w+');
    
    if ($output === false) {
        throw new Exception('Failed to open temporary stream for CSV generation');
    }

    // Заголовки CSV
    fputcsv($output, [
        'Student Name',
        'Email',
        'Lesson',
        'Test',
        'Score',
        'Correct Answers',
        'Total Questions',
        'Time Spent (min)',
        'Completed At'
    ], ';');

    // Данные
    foreach ($results as $result) {
        // Проверяем структуру каждого результата
        if (!is_array($result)) {
            continue;
        }
        
        fputcsv($output, [
            ($result['name'] ?? '') . ' ' . ($result['last_name'] ?? ''),
            $result['email'] ?? '',
            $result['lesson_title'] ?? '',
            $result['test_title'] ?? '',
            ($result['score'] ?? 0) . '%',
            $result['correct_answers'] ?? 0,
            $result['total_questions'] ?? 0,
            round(($result['time_spent'] ?? 0) / 60, 2),
            $result['completed_at'] ?? ''
        ], ';');
    }

    // Возвращаем содержимое буфера
    rewind($output);
    $csvData = stream_get_contents($output);
    fclose($output);

    return $csvData;
}

  /**
 * Получение ID текущего учителя
 */
private function getCurrentTeacherId()
{
    try {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $authHeader);

        if (empty($token)) {
            return null; // Возвращаем null вместо JSON
        }

        $user = $this->getUserFromToken($token);
        if (!$user) {
            return null; // Возвращаем null вместо JSON
        }

        // Возвращаем ID пользователя (учителя)
        return $user['id'] ?? null;
        
    } catch (Exception $e) {
        error_log("Get current teacher id error: " . $e->getMessage());
        return null;
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
