<?php

class UserProgress extends Model
{
  /**
   * Отметить урок как завершенный
   */
  public function completeLesson($studentId, $lessonId)
{
    try {
        error_log("=== START completeLesson ===");
        error_log("Student ID: " . $studentId . ", Lesson ID: " . $lessonId);
        
        // 1. Проверяем существование записи
        $checkStmt = $this->db->prepare(
            "SELECT id, completed FROM user_progress 
             WHERE student_id = ? AND lesson_id = ?"
        );
        $checkStmt->execute([$studentId, $lessonId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Existing record: " . ($existing ? json_encode($existing) : "NOT FOUND"));
        
        if ($existing) {
            // 2. Если запись существует - обновляем
            if ($existing['completed'] == 1) {
                error_log("Lesson already completed, no update needed");
                return true; // Уже завершен
            }
            
            $stmt = $this->db->prepare(
                "UPDATE user_progress 
                 SET completed = 1, 
                     completed_at = NOW(),
                     updated_at = NOW()
                 WHERE student_id = ? AND lesson_id = ?"
            );
            
            error_log("Updating existing record...");
            $result = $stmt->execute([$studentId, $lessonId]);
            
        } else {
            // 3. Если записи нет - вставляем новую
            $stmt = $this->db->prepare(
                "INSERT INTO user_progress 
                 (student_id, lesson_id, completed, completed_at, created_at, updated_at)
                 VALUES (?, ?, 1, NOW(), NOW(), NOW())"
            );
            
            error_log("Inserting new record...");
            $result = $stmt->execute([$studentId, $lessonId]);
        }
        
        error_log("Execute result: " . ($result ? "TRUE" : "FALSE"));
        
        if ($result) {
            error_log("Rows affected: " . $stmt->rowCount());
        } else {
            // Получите информацию об ошибке
            $errorInfo = $stmt->errorInfo();
            error_log("SQL error: " . json_encode($errorInfo));
        }
        
        error_log("=== END completeLesson ===");
        return $result;
        
    } catch (PDOException $e) {
        error_log("PDOException in completeLesson: " . $e->getMessage());
        error_log("Error code: " . $e->getCode());
        error_log("SQLSTATE: " . $e->errorInfo[0]);
        return false;
    }
}

  /**
   * Проверить, завершен ли урок
   */
  public function isLessonCompleted($studentId, $lessonId)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT completed FROM user_progress 
                WHERE student_id = ? AND lesson_id = ?
            ");
      $stmt->execute([$studentId, $lessonId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$result) {
        return false; // Нет записи о прогрессе
      }

      $isCompleted = !empty($result['completed']) && $result['completed'] == 1;

      return $isCompleted;
    } catch (PDOException $e) {
      error_log("isLessonCompleted PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверить завершение нескольких уроков (пакетный запрос)
   */
  public function areLessonsCompleted($studentId, $lessonIds)
  {
    try {
      if (empty($lessonIds)) {
        return [];
      }

      // Создаем плейсхолдеры для IN условия
      $placeholders = str_repeat('?,', count($lessonIds) - 1) . '?';

      $stmt = $this->db->prepare("
                SELECT lesson_id, completed 
                FROM user_progress 
                WHERE student_id = ? AND lesson_id IN ($placeholders)
            ");

      $params = array_merge([$studentId], $lessonIds);
      $stmt->execute($params);

      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Создаем массив с результатами для всех запрошенных уроков
      $completionMap = [];
      foreach ($lessonIds as $lessonId) {
        $completionMap[$lessonId] = false;
      }

      foreach ($results as $row) {
        if (!empty($row['completed']) && $row['completed'] == 1) {
          $completionMap[$row['lesson_id']] = true;
        }
      }

      return $completionMap;
    } catch (PDOException $e) {
      error_log("areLessonsCompleted PDO error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получить прогресс пользователя по курсу
   */
public function getUserProgress($studentId, $courseId)
{
    try {
        error_log("=== DEBUG getUserProgress ===");
        error_log("Student ID: $studentId, Course ID: $courseId");

        // Проверяем существование курса
        if (!$this->courseExists($courseId)) {
            error_log("Course {$courseId} does not exist");
            return $this->getEmptyProgress($courseId, $studentId);
        }

        // Проверяем существование студента
        if (!$this->studentExists($studentId)) {
            error_log("Student {$studentId} does not exist");
            return $this->getEmptyProgress($courseId, $studentId);
        }

        // ВАЖНО: Добавим проверку модулей в курсе
        $sqlCheckModules = "SELECT COUNT(*) as module_count FROM modules WHERE course_id = ?";
        $stmtModules = $this->db->prepare($sqlCheckModules);
        $stmtModules->execute([$courseId]);
        $modules = $stmtModules->fetch(PDO::FETCH_ASSOC);
        
        error_log("Modules in course: " . print_r($modules, true));

        // Получаем общее количество уроков в курсе (исправленная версия)
        // ВАЖНО: Учитываем только опубликованные уроки, независимо от наличия теста
        $sqlTotal = "
            SELECT COUNT(l.id) as total_lessons 
            FROM modules m 
            LEFT JOIN lessons l ON l.module_id = m.id 
            WHERE m.course_id = ?
        ";
        error_log("SQL Total: " . $sqlTotal . " with param: $courseId");
        
        $stmtTotal = $this->db->prepare($sqlTotal);
        $stmtTotal->execute([$courseId]);
        $total = $stmtTotal->fetch(PDO::FETCH_ASSOC);
        
        error_log("Total lessons query result: " . print_r($total, true));

        // Получаем количество завершенных уроков
        // ВАЖНО: Учитываем все завершенные уроки, независимо от наличия теста
        $sqlCompleted = "
            SELECT COUNT(DISTINCT up.lesson_id) as completed_lessons 
            FROM user_progress up 
            JOIN lessons l ON up.lesson_id = l.id 
            JOIN modules m ON l.module_id = m.id 
            WHERE up.student_id = ? AND m.course_id = ? 
            AND up.completed = 1 
        ";
        error_log("SQL Completed: " . $sqlCompleted . " with params: $studentId, $courseId");
        
        $stmtCompleted = $this->db->prepare($sqlCompleted);
        $stmtCompleted->execute([$studentId, $courseId]);
        $completed = $stmtCompleted->fetch(PDO::FETCH_ASSOC);
        
        error_log("Completed lessons query result: " . print_r($completed, true));

        // Дополнительный запрос для проверки связей
        $sqlDebug = "
            SELECT 
                up.lesson_id,
                l.title,
                m.id as module_id,
                m.course_id
            FROM user_progress up
            JOIN lessons l ON up.lesson_id = l.id
            JOIN modules m ON l.module_id = m.id
            WHERE up.student_id = ? 
                AND up.completed = 1
                AND m.course_id = ?
        ";
        $stmtDebug = $this->db->prepare($sqlDebug);
        $stmtDebug->execute([$studentId, $courseId]);
        $debugResults = $stmtDebug->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Debug completed lessons details: " . print_r($debugResults, true));

        $totalLessons = (int)($total['total_lessons'] ?? 0);
        $completedLessons = (int)($completed['completed_lessons'] ?? 0);

        error_log("Total lessons: $totalLessons, Completed: $completedLessons");

        $progressPercentage = $totalLessons > 0
            ? round(($completedLessons / $totalLessons) * 100, 2)
            : 0;

        // Получаем информацию о прохождении тестов модулей
        // Тесты модулей хранятся в lesson_tests с module_id != NULL
        $sqlModuleTests = "
            SELECT 
                m.id as module_id,
                MAX(tr.score) as module_test_score,
                lt.id as module_test_id
            FROM modules m
            JOIN lesson_tests lt ON m.id = lt.module_id AND lt.deleted_at IS NULL AND lt.module_id IS NOT NULL
            LEFT JOIN test_results tr ON lt.id = tr.test_id AND tr.student_id = ?
            WHERE m.course_id = ?
            GROUP BY m.id, lt.id
        ";
        $stmtModuleTests = $this->db->prepare($sqlModuleTests);
        $stmtModuleTests->execute([$studentId, $courseId]);
        $moduleTests = $stmtModuleTests->fetchAll(PDO::FETCH_ASSOC);
        
        // Формируем массив прохождения тестов модулей
        $moduleTestScores = [];
        foreach ($moduleTests as $mt) {
            if ($mt['module_id'] && $mt['module_test_score'] !== null) {
                $moduleTestScores[$mt['module_id']] = [
                    'score' => (float)$mt['module_test_score'],
                    'test_id' => (int)$mt['module_test_id'],
                    'passed' => (float)$mt['module_test_score'] >= 50
                ];
            }
        }
        
        error_log("Module tests progress: " . json_encode($moduleTestScores));

        // Получаем детальную информацию о прогрессе по каждому уроку
        // ВАЖНО: Используем ТОЛЬКО таблицу user_progress для определения завершения урока
        // best_score берем из student_results как дополнительную информацию (не влияет на completed)
        $sqlProgressDetails = "
            SELECT 
                l.id as lesson_id,
                l.title as lesson_title,
                l.order_index,
                m.id as module_id,
                m.title as module_title,
                up.completed,
                up.completed_at,
                sr.best_score,
                sr.test_id,
                lt.title as test_title
            FROM modules m
            JOIN lessons l ON l.module_id = m.id 
            LEFT JOIN user_progress up ON up.lesson_id = l.id AND up.student_id = ?
            LEFT JOIN (
                SELECT 
                    l2.id as lesson_id,
                    MAX(tr.score) as best_score,
                    tr.test_id
                FROM lessons l2
                JOIN lesson_tests lt2 ON l2.id = lt2.lesson_id AND lt2.deleted_at IS NULL
                JOIN test_results tr ON lt2.id = tr.test_id AND tr.student_id = ?
                GROUP BY l2.id, tr.test_id
            ) sr ON sr.lesson_id = l.id
            LEFT JOIN lesson_tests lt ON lt.id = sr.test_id AND lt.deleted_at IS NULL
            WHERE m.course_id = ?
            ORDER BY m.order_index ASC, l.order_index ASC
        ";
        
        $stmtProgressDetails = $this->db->prepare($sqlProgressDetails);
        $stmtProgressDetails->execute([$studentId, $studentId, $courseId]);
        $progressDetails = $stmtProgressDetails->fetchAll(PDO::FETCH_ASSOC);
        
        // Формируем массив progress для каждого урока
        // ВАЖНО: completed берется ТОЛЬКО из user_progress, не из student_results
        $progressArray = [];
        foreach ($progressDetails as $detail) {
            $progressArray[] = [
                'lesson_id' => (int)$detail['lesson_id'],
                'lesson_title' => $detail['lesson_title'],
                'module_id' => (int)$detail['module_id'],
                'module_title' => $detail['module_title'],
                'order_index' => (int)$detail['order_index'],
                // completed берется ТОЛЬКО из user_progress
                'completed' => (bool)($detail['completed'] == 1 || $detail['completed'] === true || $detail['completed'] === "1"),
                'completed_at' => $detail['completed_at'],
                // best_score - дополнительная информация из student_results (не влияет на completed)
                'best_score' => $detail['best_score'] ? (float)$detail['best_score'] : null,
                'test_id' => $detail['test_id'] ? (int)$detail['test_id'] : null,
                'test_title' => $detail['test_title']
            ];
        }

        // Формируем статистику
        $statistics = [
            'total_lessons' => $totalLessons,
            'completed_lessons' => $completedLessons,
            'completion_rate' => $progressPercentage
        ];

        $result = [
            'total_lessons' => $totalLessons,
            'completed_lessons' => $completedLessons,
            'progress_percentage' => $progressPercentage,
            'course_id' => $courseId,
            'student_id' => $studentId,
            'progress' => $progressArray,
            'statistics' => $statistics,
            'module_tests' => $moduleTestScores, // Информация о прохождении тестов модулей
            'debug' => [
                'modules_count' => $modules['module_count'] ?? 0,
                'completed_details' => $debugResults
            ]
        ];
        
        error_log("Final result: " . print_r($result, true));
        error_log("=== END DEBUG ===");

        return $result;
    } catch (PDOException $e) {
        error_log("getUserProgress PDO error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return $this->getEmptyProgress($courseId, $studentId);
    }
}
  /**
   * Получить все завершенные уроки пользователя
   */
  public function getCompletedLessons($studentId)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT lesson_id FROM user_progress 
                WHERE student_id = ? AND completed = 1
            ");
      $stmt->execute([$studentId]);

      $completedLessons = [];
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $completedLessons[$row['lesson_id']] = true;
      }

      return $completedLessons;
    } catch (PDOException $e) {
      error_log("getCompletedLessons PDO error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получить прогресс по модулю
   */
  public function getModuleProgress($studentId, $moduleId)
  {
    try {
      // Проверяем существование модуля
      if (!$this->moduleExists($moduleId)) {
        error_log("Module {$moduleId} does not exist");
        return $this->getEmptyModuleProgress($moduleId, $studentId);
      }

      // Общее количество уроков в модуле
      $sqlTotal = "
                SELECT COUNT(*) as total_lessons 
                FROM lessons 
                WHERE module_id = ? AND is_published = 1
            ";
      $stmtTotal = $this->db->prepare($sqlTotal);
      $stmtTotal->execute([$moduleId]);
      $total = $stmtTotal->fetch(PDO::FETCH_ASSOC);

      // Завершенные уроки в модуле
      $sqlCompleted = "
                SELECT COUNT(DISTINCT up.lesson_id) as completed_lessons 
                FROM user_progress up 
                JOIN lessons l ON up.lesson_id = l.id 
                WHERE up.student_id = ? AND l.module_id = ? 
                AND up.completed = 1 
            ";
      $stmtCompleted = $this->db->prepare($sqlCompleted);
      $stmtCompleted->execute([$studentId, $moduleId]);
      $completed = $stmtCompleted->fetch(PDO::FETCH_ASSOC);

      $totalLessons = (int)($total['total_lessons'] ?? 0);
      $completedLessons = (int)($completed['completed_lessons'] ?? 0);
      $progressPercentage = $totalLessons > 0
        ? round(($completedLessons / $totalLessons) * 100, 2)
        : 0;

      return [
        'module_id' => $moduleId,
        'student_id' => $studentId,
        'total_lessons' => $totalLessons,
        'completed_lessons' => $completedLessons,
        'progress_percentage' => $progressPercentage
      ];
    } catch (PDOException $e) {
      error_log("getModuleProgress PDO error: " . $e->getMessage());
      return $this->getEmptyModuleProgress($moduleId, $studentId);
    }
  }

  /**
   * Получить детальную информацию о прогрессе
   */
  public function getDetailedProgress($studentId, $courseId)
  {
    try {
      // Проверяем существование курса и студента
      if (!$this->courseExists($courseId) || !$this->studentExists($studentId)) {
        return $this->getEmptyDetailedProgress($courseId, $studentId);
      }

      // Прогресс по модулям
      $sqlModules = "
                SELECT 
                    m.id as module_id,
                    m.title as module_title,
                    m.order_index,
                    COUNT(l.id) as total_lessons,
                    COUNT(DISTINCT up.lesson_id) as completed_lessons,
                    CASE 
                        WHEN COUNT(l.id) > 0 THEN 
                            ROUND((COUNT(DISTINCT up.lesson_id) / COUNT(l.id)) * 100, 2)
                        ELSE 0 
                    END as progress_percentage
                FROM modules m
                LEFT JOIN lessons l ON m.id = l.module_id 
                LEFT JOIN user_progress up ON l.id = up.lesson_id 
                    AND up.student_id = ? 
                    AND up.completed = 1
                WHERE m.course_id = ?
                GROUP BY m.id, m.title, m.order_index
                ORDER BY m.order_index ASC
            ";
      $stmtModules = $this->db->prepare($sqlModules);
      $stmtModules->execute([$studentId, $courseId]);
      $modulesProgress = $stmtModules->fetchAll(PDO::FETCH_ASSOC);

      // Общий прогресс по курсу
      $courseProgress = $this->getUserProgress($studentId, $courseId);

      return [
        'course_progress' => $courseProgress,
        'modules_progress' => $modulesProgress
      ];
    } catch (PDOException $e) {
      error_log("getDetailedProgress PDO error: " . $e->getMessage());
      return $this->getEmptyDetailedProgress($courseId, $studentId);
    }
  }

  /**
   * Сбросить прогресс урока (для повторного прохождения)
   */
  public function resetLessonProgress($studentId, $lessonId)
  {
    try {
      $stmt = $this->db->prepare("
                UPDATE user_progress 
                SET completed = 0, completed_at = NULL, updated_at = NOW()
                WHERE student_id = ? AND lesson_id = ?
            ");
      $result = $stmt->execute([$studentId, $lessonId]);

      if ($result && $stmt->rowCount() > 0) {
        error_log("Progress reset for lesson {$lessonId}, student {$studentId}");
        return true;
      } else {
        error_log("No progress found to reset for lesson {$lessonId}, student {$studentId}");
        return false;
      }
    } catch (PDOException $e) {
      error_log("resetLessonProgress PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получить дату завершения урока
   */
  public function getLessonCompletionDate($studentId, $lessonId)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT completed_at 
                FROM user_progress 
                WHERE student_id = ? AND lesson_id = ? AND completed = 1
            ");
      $stmt->execute([$studentId, $lessonId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result ? $result['completed_at'] : null;
    } catch (PDOException $e) {
      error_log("getLessonCompletionDate PDO error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Получить статистику прогресса
   */
  public function getProgressStats($studentId)
  {
    try {
      // Проверяем существование студента
      if (!$this->studentExists($studentId)) {
        error_log("Student {$studentId} does not exist");
        return $this->getEmptyProgressStats($studentId);
      }

      // Общее количество пройденных уроков
      $sqlTotalCompleted = "
                SELECT COUNT(*) as total_completed 
                FROM user_progress 
                WHERE student_id = ? AND completed = 1
            ";
      $stmtTotalCompleted = $this->db->prepare($sqlTotalCompleted);
      $stmtTotalCompleted->execute([$studentId]);
      $totalCompleted = $stmtTotalCompleted->fetch(PDO::FETCH_ASSOC);

      // Последние завершенные уроки
      $sqlRecentCompleted = "
                SELECT 
                    up.lesson_id, 
                    up.completed_at, 
                    l.title as lesson_title, 
                    c.title as course_title,
                    c.id as course_id,
                    m.title as module_title
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                JOIN courses c ON m.course_id = c.id
                WHERE up.student_id = ? AND up.completed = 1
                ORDER BY up.completed_at DESC
                LIMIT 10
            ";
      $stmtRecentCompleted = $this->db->prepare($sqlRecentCompleted);
      $stmtRecentCompleted->execute([$studentId]);
      $recentCompleted = $stmtRecentCompleted->fetchAll(PDO::FETCH_ASSOC);

      // Количество активных курсов
      $sqlActiveCourses = "
                SELECT COUNT(DISTINCT m.course_id) as active_courses
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE up.student_id = ? AND up.completed = 1
            ";
      $stmtActiveCourses = $this->db->prepare($sqlActiveCourses);
      $stmtActiveCourses->execute([$studentId]);
      $activeCourses = $stmtActiveCourses->fetch(PDO::FETCH_ASSOC);

      return [
        'student_id' => $studentId,
        'total_completed_lessons' => (int)($totalCompleted['total_completed'] ?? 0),
        'active_courses' => (int)($activeCourses['active_courses'] ?? 0),
        'recent_completed_lessons' => $recentCompleted,
        'last_activity' => $this->getLastActivity($studentId)
      ];
    } catch (PDOException $e) {
      error_log("getProgressStats PDO error: " . $e->getMessage());
      return $this->getEmptyProgressStats($studentId);
    }
  }

  /**
   * Проверить, доступен ли урок для прохождения
   * Базовая проверка - можно расширить для проверки prerequisites
   */
  public function isLessonAccessible($studentId, $lessonId)
  {
    try {
      // Проверяем существование урока
      if (!$this->lessonExists($lessonId)) {
        return false;
      }

      // Получаем информацию об уроке (включая module_id, order_index и информацию о модуле)
      $stmt = $this->db->prepare("
                SELECT l.id, l.module_id, l.order_index, m.course_id, m.order_index as module_order_index
                FROM lessons l
                INNER JOIN modules m ON l.module_id = m.id
                WHERE l.id = ? AND l.deleted_at IS NULL AND m.deleted_at IS NULL
            ");
      $stmt->execute([$lessonId]);
      $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$lesson) {
        error_log("Lesson {$lessonId} not found or deleted");
        return false;
      }

      // Если это первый урок в модуле (order_index = 0 или NULL)
      if ($lesson['order_index'] === 0 || $lesson['order_index'] === null) {
        // Проверяем, есть ли предыдущий модуль в курсе
        $prevModuleStmt = $this->db->prepare("
                    SELECT id, order_index
                    FROM modules 
                    WHERE course_id = ? AND order_index < ? AND deleted_at IS NULL
                    ORDER BY order_index DESC
                    LIMIT 1
                ");
        $prevModuleStmt->execute([$lesson['course_id'], $lesson['module_order_index']]);
        $previousModule = $prevModuleStmt->fetch(PDO::FETCH_ASSOC);

        // Если есть предыдущий модуль, проверяем, завершены ли все его уроки
        if ($previousModule) {
          // Получаем все уроки предыдущего модуля
          $prevLessonsStmt = $this->db->prepare("
                        SELECT id 
                        FROM lessons 
                        WHERE module_id = ? AND deleted_at IS NULL
                    ");
          $prevLessonsStmt->execute([$previousModule['id']]);
          $previousLessons = $prevLessonsStmt->fetchAll(PDO::FETCH_ASSOC);

          // Если в предыдущем модуле есть уроки, проверяем, все ли они завершены
          if (!empty($previousLessons)) {
            foreach ($previousLessons as $prevLesson) {
              $isPrevCompleted = $this->isLessonCompleted($studentId, $prevLesson['id']);
              if (!$isPrevCompleted) {
                // Есть незавершенный урок в предыдущем модуле
                error_log("Lesson {$lessonId} is not accessible - lesson {$prevLesson['id']} in previous module {$previousModule['id']} is not completed");
                return false;
              }
            }
          }
        }
        // Если это первый модуль в курсе или все уроки предыдущего модуля завершены
        error_log("Lesson {$lessonId} is first lesson in module {$lesson['module_id']} - accessible");
        return true;
      }

      // Если это не первый урок в модуле, проверяем предыдущий урок в том же модуле
      $prevStmt = $this->db->prepare("
                SELECT id 
                FROM lessons 
                WHERE module_id = ? AND order_index < ? AND deleted_at IS NULL
                ORDER BY order_index DESC
                LIMIT 1
            ");
      $prevStmt->execute([$lesson['module_id'], $lesson['order_index']]);
      $previousLesson = $prevStmt->fetch(PDO::FETCH_ASSOC);

      // Если есть предыдущий урок в том же модуле, проверяем, завершен ли он
      if ($previousLesson) {
        $isPrevCompleted = $this->isLessonCompleted($studentId, $previousLesson['id']);
        if (!$isPrevCompleted) {
          // Предыдущий урок в модуле не завершен, текущий урок недоступен
          error_log("Lesson {$lessonId} is not accessible - previous lesson {$previousLesson['id']} in module {$lesson['module_id']} is not completed");
          return false;
        }
      }

      // Если это первый урок в модуле или все предыдущие уроки в модуле завершены
      error_log("Lesson {$lessonId} is accessible for student {$studentId}");
      return true;
    } catch (PDOException $e) {
      error_log("isLessonAccessible PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Очистить весь прогресс пользователя (для админских целей)
   */
  public function clearAllProgress($studentId)
  {
    try {
      $stmt = $this->db->prepare("
                DELETE FROM user_progress 
                WHERE student_id = ?
            ");
      $result = $stmt->execute([$studentId]);

      if ($result) {
        error_log("All progress cleared for student {$studentId}");
        return true;
      } else {
        error_log("Failed to clear progress for student {$studentId}");
        return false;
      }
    } catch (PDOException $e) {
      error_log("clearAllProgress PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получить статус завершения всех уроков в модуле
   */
  public function getModuleLessonsCompletion($studentId, $moduleId)
  {
    try {
      // Получаем все уроки модуля
      $sqlLessons = "
                SELECT l.id as lesson_id, l.title as lesson_title, l.order_index
                FROM lessons l
                WHERE l.module_id = ? 
                ORDER BY l.order_index ASC
            ";
      $stmtLessons = $this->db->prepare($sqlLessons);
      $stmtLessons->execute([$moduleId]);
      $lessons = $stmtLessons->fetchAll(PDO::FETCH_ASSOC);

      // Получаем завершенные уроки
      $completedLessons = $this->getCompletedLessons($studentId);

      // Формируем результат
      $result = [];
      foreach ($lessons as $lesson) {
        $lessonId = $lesson['lesson_id'];
        $isCompleted = isset($completedLessons[$lessonId]);
        $completionDate = $isCompleted ?
          $this->getLessonCompletionDate($studentId, $lessonId) : null;

        $result[] = [
          'lesson_id' => $lessonId,
          'lesson_title' => $lesson['lesson_title'],
          'order_index' => $lesson['order_index'],
          'is_completed' => $isCompleted,
          'completion_date' => $completionDate
        ];
      }

      return $result;
    } catch (PDOException $e) {
      error_log("getModuleLessonsCompletion PDO error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Проверить, завершены ли все уроки модуля
   */
  public function areAllModuleLessonsCompleted($studentId, $moduleId)
  {
    try {
      // Получаем все уроки модуля
      $sqlLessons = "
        SELECT COUNT(l.id) as total_lessons
        FROM lessons l
        WHERE l.module_id = ? AND l.deleted_at IS NULL
      ";
      $stmtLessons = $this->db->prepare($sqlLessons);
      $stmtLessons->execute([$moduleId]);
      $totalResult = $stmtLessons->fetch(PDO::FETCH_ASSOC);
      $totalLessons = (int)($totalResult['total_lessons'] ?? 0);

      if ($totalLessons === 0) {
        // Если в модуле нет уроков, считаем что все завершено
        return true;
      }

      // Получаем количество завершенных уроков
      $sqlCompleted = "
        SELECT COUNT(DISTINCT up.lesson_id) as completed_lessons
        FROM user_progress up
        JOIN lessons l ON up.lesson_id = l.id
        WHERE up.student_id = ? 
          AND l.module_id = ? 
          AND up.completed = 1
          AND l.deleted_at IS NULL
      ";
      $stmtCompleted = $this->db->prepare($sqlCompleted);
      $stmtCompleted->execute([$studentId, $moduleId]);
      $completedResult = $stmtCompleted->fetch(PDO::FETCH_ASSOC);
      $completedLessons = (int)($completedResult['completed_lessons'] ?? 0);

      error_log("Module {$moduleId} - Total lessons: {$totalLessons}, Completed: {$completedLessons}");

      return $completedLessons >= $totalLessons;
    } catch (PDOException $e) {
      error_log("areAllModuleLessonsCompleted PDO error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка существования студента
   */
  private function studentExists($studentId)
  {
    try {
      $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_blocked = '0'");
      $stmt->execute([$studentId]);
      return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("studentExists error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка существования курса
   */
  private function courseExists($courseId)
  {
    try {
      $stmt = $this->db->prepare("SELECT id FROM courses WHERE id = ?");
      $stmt->execute([$courseId]);
      return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("courseExists error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка существования модуля
   */
  private function moduleExists($moduleId)
  {
    try {
      $stmt = $this->db->prepare("SELECT id FROM modules WHERE id = ?");
      $stmt->execute([$moduleId]);
      return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("moduleExists error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка существования урока
   */
  private function lessonExists($lessonId)
  {
    try {
      $stmt = $this->db->prepare("SELECT id FROM lessons WHERE id = ?");
      $stmt->execute([$lessonId]);
      return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("lessonExists error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка существования студента и урока
   */
  private function validateStudentAndLesson($studentId, $lessonId)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT 1 
                FROM users u
                JOIN lessons l ON l.id = ?
                WHERE u.id = ? AND u.role = 'user' AND u.status = 'active'
            ");
      $stmt->execute([$lessonId, $studentId]);
      return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("validateStudentAndLesson error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Пустой прогресс
   */
  private function getEmptyProgress($courseId = null, $studentId = null)
  {
    return [
      'total_lessons' => 0,
      'completed_lessons' => 0,
      'progress_percentage' => 0,
      'course_id' => $courseId,
      'student_id' => $studentId
    ];
  }

  /**
   * Пустой прогресс модуля
   */
  private function getEmptyModuleProgress($moduleId = null, $studentId = null)
  {
    return [
      'module_id' => $moduleId,
      'student_id' => $studentId,
      'total_lessons' => 0,
      'completed_lessons' => 0,
      'progress_percentage' => 0
    ];
  }

  /**
   * Пустой детальный прогресс
   */
  private function getEmptyDetailedProgress($courseId = null, $studentId = null)
  {
    return [
      'course_progress' => $this->getEmptyProgress($courseId, $studentId),
      'modules_progress' => []
    ];
  }

  /**
   * Пустая статистика прогресса
   */
  private function getEmptyProgressStats($studentId = null)
  {
    return [
      'student_id' => $studentId,
      'total_completed_lessons' => 0,
      'active_courses' => 0,
      'recent_completed_lessons' => [],
      'last_activity' => null
    ];
  }

  /**
   * Получить последнее время активности студента
   */
  private function getLastActivity($studentId)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT MAX(completed_at) as last_activity
                FROM user_progress
                WHERE student_id = ? AND completed = 1
            ");
      $stmt->execute([$studentId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result['last_activity'] ?? null;
    } catch (PDOException $e) {
      error_log("getLastActivity error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Получить время начала изучения курса
   */
  public function getCourseStartDate($studentId, $courseId)
  {
    try {
      $stmt = $this->db->prepare("
                SELECT MIN(up.created_at) as started_at
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE up.student_id = ? AND m.course_id = ?
            ");
      $stmt->execute([$studentId, $courseId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      return $result['started_at'] ?? null;
    } catch (PDOException $e) {
      error_log("getCourseStartDate error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Получить прогресс за определенный период
   */
  public function getProgressByDateRange($studentId, $courseId, $startDate, $endDate)
  {
    try {
      $sql = "
                SELECT 
                    DATE(up.completed_at) as date,
                    COUNT(up.lesson_id) as lessons_completed
                FROM user_progress up
                JOIN lessons l ON up.lesson_id = l.id
                JOIN modules m ON l.module_id = m.id
                WHERE up.student_id = ? 
                AND m.course_id = ?
                AND up.completed = 1
                AND up.completed_at BETWEEN ? AND ?
                GROUP BY DATE(up.completed_at)
                ORDER BY date ASC
            ";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $courseId, $startDate, $endDate]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("getProgressByDateRange error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получить следующую рекомендованную лекцию
   */
  public function getNextRecommendedLesson($studentId, $courseId)
  {
    try {
      // Получаем все уроки курса, которые еще не завершены
      $sql = "
                SELECT l.id, l.title, l.order_index as lesson_order, m.order_index as module_order
                FROM lessons l
                JOIN modules m ON l.module_id = m.id
                WHERE m.course_id = ? 
                
                AND l.id NOT IN (
                    SELECT lesson_id 
                    FROM user_progress 
                    WHERE student_id = ? AND completed = 1
                )
                ORDER BY m.order_index ASC, l.order_index ASC
                LIMIT 1
            ";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$courseId, $studentId]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("getNextRecommendedLesson error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Получить прогресс по всем курсам пользователя
   */
  public function getAllCoursesProgress($studentId)
  {
    try {
      // Получаем все курсы, на которые записан студент
      $sql = "
                SELECT 
                    c.id as course_id,
                    c.title as course_title,
                    COUNT(l.id) as total_lessons,
                    COUNT(DISTINCT up.lesson_id) as completed_lessons,
                    CASE 
                        WHEN COUNT(l.id) > 0 THEN 
                            ROUND((COUNT(DISTINCT up.lesson_id) / COUNT(l.id)) * 100, 2)
                        ELSE 0 
                    END as progress_percentage
                FROM courses c
                JOIN modules m ON c.id = m.course_id
                JOIN lessons l ON m.id = l.module_id 
                LEFT JOIN user_progress up ON l.id = up.lesson_id 
                    AND up.student_id = ? 
                    AND up.completed = 1
                WHERE c.id IN (
                    SELECT course_id 
                    FROM enrollments 
                    WHERE student_id = ? AND status = 'active'
                )
                GROUP BY c.id, c.title
                ORDER BY progress_percentage DESC
            ";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$studentId, $studentId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("getAllCoursesProgress error: " . $e->getMessage());
      return [];
    }
  }
}
