<?php
class StudentResults
{
  protected $db;
  protected $table = 'test_results';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Получение полного прогресса по уроку
   */
  public function getLessonProgress($studentId, $lessonId)
  {
    try {
      // Получаем базовую информацию об уроке
      $sql = "SELECT 
                l.*,
                m.title as module_title,
                c.title as course_title,
                lp.progress_percent,
                lp.time_spent as total_time_spent,
                lp.is_completed,
                lp.completed_at,
                lp.last_activity_at
              FROM lessons l
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              LEFT JOIN lesson_progress lp ON l.id = lp.lesson_id AND lp.student_id = ?
              WHERE l.id = ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $lessonId]);
      $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$lesson) {
        return false;
      }

      // Получаем статусы всех элементов урока
      $lesson['items'] = $this->getLessonItemsStatus($studentId, $lessonId);

      // Получаем попытки тестов
      $lesson['test_attempts'] = $this->getStudentAttempts($studentId, $lessonId);

      // Получаем лучший результат теста
      $lesson['best_test_result'] = $this->getStudentBestResult($studentId, $lessonId);

      // Рассчитываем общий прогресс
      $lesson['calculated_progress'] = $this->calculateLessonProgress($lesson['items']);

      return $lesson;
    } catch (PDOException $e) {
      error_log("StudentResults getLessonProgress error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение статусов элементов урока
   */
  public function getLessonItemsStatus($studentId, $lessonId)
  {
    try {
      $sql = "SELECT 
                lis.*,
                CASE 
                  WHEN lis.item_type = 'material' THEN m.title
                  WHEN lis.item_type = 'test' THEN lt.title
                  WHEN lis.item_type = 'assignment' THEN a.title
                END as item_title,
                CASE 
                  WHEN lis.item_type = 'material' THEN m.content_type
                  WHEN lis.item_type = 'test' THEN 'test'
                  WHEN lis.item_type = 'assignment' THEN 'assignment'
                END as content_type
              FROM lesson_item_status lis
              LEFT JOIN lesson_materials m ON lis.item_id = m.id AND lis.item_type = 'material'
              LEFT JOIN lesson_tests lt ON lis.item_id = lt.id AND lis.item_type = 'test'
              LEFT JOIN assignments a ON lis.item_id = a.id AND lis.item_type = 'assignment'
              WHERE lis.student_id = ? AND lis.lesson_id = ?
              ORDER BY 
                CASE lis.item_type
                  WHEN 'material' THEN 1
                  WHEN 'test' THEN 2
                  WHEN 'assignment' THEN 3
                END,
                lis.created_at";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $lessonId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("StudentResults getLessonItemsStatus error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Расчет прогресса урока
   */
  public function calculateLessonProgress($items)
  {
    if (empty($items)) {
      return 0;
    }

    $completedItems = 0;
    $totalItems = count($items);

    foreach ($items as $item) {
      if ($item['status'] === 'completed') {
        $completedItems++;
      }
    }

    return $totalItems > 0 ? round(($completedItems / $totalItems) * 100, 2) : 0;
  }

  /**
   * Обновление прогресса урока
   */
  public function updateLessonProgress($studentId, $lessonId, $data)
  {
    try {
      $this->db->beginTransaction();

      $currentTime = date('Y-m-d H:i:s');

      // Проверяем существующую запись
      $sql = "SELECT id FROM lesson_progress 
              WHERE student_id = ? AND lesson_id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $lessonId]);
      $existing = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($existing) {
        // Обновляем существующую запись
        $sql = "UPDATE lesson_progress 
                SET progress_percent = ?, time_spent = ?, 
                    is_completed = ?, completed_at = ?,
                    last_activity_at = ?, updated_at = ?
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
          $data['progress_percent'],
          $data['time_spent'],
          $data['is_completed'] ? 1 : 0,
          $data['is_completed'] ? $currentTime : null,
          $currentTime,
          $currentTime,
          $existing['id']
        ]);
      } else {
        // Создаем новую запись
        $courseId = $this->getCourseIdByLesson($lessonId);

        $sql = "INSERT INTO lesson_progress 
                (student_id, lesson_id, course_id, progress_percent, 
                 time_spent, is_completed, completed_at, 
                 last_activity_at, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
          $studentId,
          $lessonId,
          $courseId,
          $data['progress_percent'],
          $data['time_spent'],
          $data['is_completed'] ? 1 : 0,
          $data['is_completed'] ? $currentTime : null,
          $currentTime,
          $currentTime,
          $currentTime
        ]);
      }

      $this->db->commit();
      return true;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("StudentResults updateLessonProgress error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Обновление статуса элемента урока
   */
  public function updateItemStatus($studentId, $lessonId, $itemType, $itemId, $status, $score = null)
  {
    try {
      $this->db->beginTransaction();

      $currentTime = date('Y-m-d H:i:s');

      // Проверяем существующую запись
      $sql = "SELECT id FROM lesson_item_status 
              WHERE student_id = ? AND lesson_id = ? 
              AND item_type = ? AND item_id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $lessonId, $itemType, $itemId]);
      $existing = $stmt->fetch(PDO::FETCH_ASSOC);

      $attempts = 1;
      $completedAt = $status === 'completed' ? $currentTime : null;

      if ($existing) {
        // Получаем текущее количество попыток
        $sqlAttempts = "SELECT attempts FROM lesson_item_status WHERE id = ?";
        $stmtAttempts = $this->db->prepare($sqlAttempts);
        $stmtAttempts->execute([$existing['id']]);
        $current = $stmtAttempts->fetch(PDO::FETCH_ASSOC);
        $attempts = $current['attempts'] + 1;

        // Обновляем существующую запись
        $sql = "UPDATE lesson_item_status 
                SET status = ?, score = ?, attempts = ?,
                    last_attempt_at = ?, completed_at = ?, updated_at = ?
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
          $status,
          $score,
          $attempts,
          $currentTime,
          $completedAt,
          $currentTime,
          $existing['id']
        ]);
      } else {
        // Создаем новую запись
        $sql = "INSERT INTO lesson_item_status 
                (student_id, lesson_id, item_type, item_id, status, 
                 score, attempts, last_attempt_at, completed_at, 
                 created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
          $studentId,
          $lessonId,
          $itemType,
          $itemId,
          $status,
          $score,
          $attempts,
          $currentTime,
          $completedAt,
          $currentTime,
          $currentTime
        ]);
      }

      $this->db->commit();
      return true;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("StudentResults updateItemStatus error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение ID курса по уроку
   */
  private function getCourseIdByLesson($lessonId)
  {
    try {
      $sql = "SELECT c.id 
              FROM courses c
              INNER JOIN modules m ON c.id = m.course_id
              INNER JOIN lessons l ON m.id = l.module_id
              WHERE l.id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([$lessonId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result ? $result['id'] : null;
    } catch (PDOException $e) {
      error_log("StudentResults getCourseIdByLesson error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Получение общего прогресса по курсу с детализацией
   */
  public function getDetailedCourseProgress($studentId, $courseId)
  {
    try {
      // Получаем все уроки курса
      $sql = "SELECT 
                l.id, l.title, l.description, l.order_index,
                m.title as module_title, m.order_index as module_order,
                lp.progress_percent, lp.is_completed, lp.completed_at,
                lp.time_spent, lp.last_activity_at
              FROM lessons l
              INNER JOIN modules m ON l.module_id = m.id
              LEFT JOIN lesson_progress lp ON l.id = lp.lesson_id AND lp.student_id = ?
              WHERE m.course_id = ?
              ORDER BY m.order_index, l.order_index";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $courseId]);
      $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Добавляем детали по каждому уроку
      foreach ($lessons as &$lesson) {
        $lesson['items'] = $this->getLessonItemsStatus($studentId, $lesson['id']);
        $lesson['test_attempts'] = $this->getStudentAttempts($studentId, $lesson['id']);
        $lesson['calculated_progress'] = $this->calculateLessonProgress($lesson['items']);
      }

      return $lessons;
    } catch (PDOException $e) {
      error_log("StudentResults getDetailedCourseProgress error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение попыток студента по уроку
   */
  public function getStudentAttempts($studentId, $lessonId)
  {
    try {
      $sql = "SELECT 
                tr.*,
                lt.title as test_title,
                l.title as lesson_title,
                m.title as module_title,
                c.title as course_title
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              WHERE tr.student_id = ? AND l.id = ?
              ORDER BY tr.attempt_number DESC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $lessonId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("StudentResults getStudentAttempts error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение лучшего результата студента по уроку
   */
  public function getStudentBestResult($studentId, $lessonId)
  {
    try {
      $sql = "SELECT 
                tr.*,
                lt.title as test_title,
                l.title as lesson_title,
                m.title as module_title,
                c.title as course_title
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              WHERE tr.student_id = ? AND l.id = ?
              ORDER BY tr.score DESC, tr.time_spent ASC
              LIMIT 1";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $lessonId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("StudentResults getStudentBestResult error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение прогресса студента по курсу
   * ВАЖНО: completed берется ТОЛЬКО из user_progress, не из результатов тестов
   */
  public function getStudentCourseProgress($studentId, $courseId)
  {
    try {
      $sql = "SELECT 
                l.id as lesson_id,
                l.title as lesson_title,
                m.title as module_title,
                lt.id as test_id,
                lt.title as test_title,
                tr.score,
                tr.attempt_number,
                tr.correct_answers,
                tr.total_questions,
                tr.completed_at,
                tr.status,
                up.completed as lesson_completed,
                up.completed_at as lesson_completed_at
              FROM lessons l
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              LEFT JOIN lesson_tests lt ON l.id = lt.lesson_id AND lt.deleted_at IS NULL
              LEFT JOIN {$this->table} tr ON lt.id = tr.test_id AND tr.student_id = ?
              LEFT JOIN user_progress up ON l.id = up.lesson_id AND up.student_id = ?
              WHERE c.id = ? 
              ORDER BY m.order_index, l.order_index, tr.attempt_number DESC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $studentId, $courseId]);

      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Группируем по урокам
      $progress = [];
      foreach ($results as $result) {
        $lessonId = $result['lesson_id'];

        if (!isset($progress[$lessonId])) {
          // ВАЖНО: completed берется ТОЛЬКО из user_progress
          $isCompleted = !empty($result['lesson_completed']) && $result['lesson_completed'] == 1;
          
          $progress[$lessonId] = [
            'lesson_id' => $result['lesson_id'],
            'lesson_title' => $result['lesson_title'],
            'module_title' => $result['module_title'],
            'test_id' => $result['test_id'],
            'test_title' => $result['test_title'],
            'attempts' => [],
            'best_score' => 0,
            'completed' => $isCompleted,
            'completed_at' => $result['lesson_completed_at'],
            'last_attempt' => null
          ];
        }

        // Добавляем попытки тестов (если есть)
        if ($result['score'] !== null) {
          $progress[$lessonId]['attempts'][] = [
            'score' => $result['score'],
            'attempt_number' => $result['attempt_number'],
            'correct_answers' => $result['correct_answers'],
            'total_questions' => $result['total_questions'],
            'completed_at' => $result['completed_at'],
            'status' => $result['status']
          ];

          // Обновляем лучший результат
          if ($result['score'] > $progress[$lessonId]['best_score']) {
            $progress[$lessonId]['best_score'] = $result['score'];
          }

          // Обновляем последнюю попытку (но не меняем completed - он берется из user_progress)
          if (!$progress[$lessonId]['last_attempt'] || $result['completed_at'] > $progress[$lessonId]['last_attempt']) {
            $progress[$lessonId]['last_attempt'] = $result['completed_at'];
          }
        }
      }

      return array_values($progress);
    } catch (PDOException $e) {
      error_log("StudentResults getStudentCourseProgress error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение статистики студента по курсу
   * ВАЖНО: completed_lessons берется ТОЛЬКО из user_progress, не из результатов тестов
   */
  public function getStudentCourseStatistics($studentId, $courseId)
  {
    try {
      // Получаем общее количество уроков в курсе
      $sqlTotal = "SELECT COUNT(*) as total_lessons
                   FROM lessons l
                   INNER JOIN modules m ON l.module_id = m.id
                   INNER JOIN courses c ON m.course_id = c.id
                   WHERE c.id = ?";

      $stmtTotal = $this->db->prepare($sqlTotal);
      $stmtTotal->execute([$courseId]);
      $total = $stmtTotal->fetch(PDO::FETCH_ASSOC);
      $totalLessons = (int)($total['total_lessons'] ?? 0);

      // Получаем количество завершенных уроков из user_progress
      $sqlCompleted = "SELECT COUNT(DISTINCT up.lesson_id) as completed_lessons
                       FROM user_progress up
                       INNER JOIN lessons l ON up.lesson_id = l.id
                       INNER JOIN modules m ON l.module_id = m.id
                       INNER JOIN courses c ON m.course_id = c.id
                       WHERE up.student_id = ? AND c.id = ? 
                       AND up.completed = 1";

      $stmtCompleted = $this->db->prepare($sqlCompleted);
      $stmtCompleted->execute([$studentId, $courseId]);
      $completed = $stmtCompleted->fetch(PDO::FETCH_ASSOC);
      $completedLessons = (int)($completed['completed_lessons'] ?? 0);

      // Получаем статистику по попыткам тестов (для информации, не для расчета прогресса)
      $sqlAttempts = "SELECT 
                        COUNT(tr.id) as total_attempts,
                        AVG(tr.score) as average_score,
                        MAX(tr.score) as best_score,
                        SUM(tr.time_spent) as total_time_spent
                      FROM {$this->table} tr
                      INNER JOIN lesson_tests lt ON tr.test_id = lt.id AND lt.deleted_at IS NULL
                      INNER JOIN lessons l ON lt.lesson_id = l.id
                      INNER JOIN modules m ON l.module_id = m.id
                      INNER JOIN courses c ON m.course_id = c.id
                      WHERE tr.student_id = ? AND c.id = ?";

      $stmtAttempts = $this->db->prepare($sqlAttempts);
      $stmtAttempts->execute([$studentId, $courseId]);
      $attemptsStats = $stmtAttempts->fetch(PDO::FETCH_ASSOC);

      $statistics = [
        'total_lessons' => $totalLessons,
        'completed_lessons' => $completedLessons,
        'completion_rate' => $totalLessons > 0
          ? round(($completedLessons / $totalLessons) * 100, 2)
          : 0,
        'total_attempts' => (int)($attemptsStats['total_attempts'] ?? 0),
        'average_score' => $attemptsStats['average_score'] ? (float)$attemptsStats['average_score'] : 0,
        'best_score' => $attemptsStats['best_score'] ? (float)$attemptsStats['best_score'] : 0,
        'total_time_spent' => (int)($attemptsStats['total_time_spent'] ?? 0)
      ];

      return $statistics;
    } catch (PDOException $e) {
      error_log("StudentResults getStudentCourseStatistics error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение детальной информации о попытке студента
   */
  public function getStudentAttemptDetails($studentId, $attemptId)
  {
    try {
      $sql = "SELECT 
                tr.*,
                lt.title as test_title,
                l.title as lesson_title,
                m.title as module_title,
                c.title as course_title
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              WHERE tr.student_id = ? AND tr.id = ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $attemptId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("StudentResults getStudentAttemptDetails error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение последних активностей студента
   */
  public function getStudentRecentActivity($studentId, $limit = 10)
  {
    try {
      $sql = "SELECT 
                tr.*,
                lt.title as test_title,
                l.title as lesson_title,
                m.title as module_title,
                c.title as course_title
              FROM {$this->table} tr
              INNER JOIN lesson_tests lt ON tr.test_id = lt.id
              INNER JOIN lessons l ON lt.lesson_id = l.id
              INNER JOIN modules m ON l.module_id = m.id
              INNER JOIN courses c ON m.course_id = c.id
              WHERE tr.student_id = ?
              ORDER BY tr.completed_at DESC
              LIMIT ?";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $limit]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("StudentResults getStudentRecentActivity error: " . $e->getMessage());
      return false;
    }
  }
}
