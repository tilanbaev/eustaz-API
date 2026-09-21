<?php
class Test
{
  protected $db;
  protected $table = 'lesson_tests';

  public function __construct()
  {
    $this->db = $GLOBALS['db'];
  }

  /**
   * Получение теста по ID урока
   */
  public function findByLessonId($lessonId)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
                    WHERE lesson_id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$lessonId]);

      $test = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($test) {
        $test['questions'] = $this->getQuestionsByTestId($test['id']);
      }

      return $test;
    } catch (PDOException $e) {
      error_log("Test findByLessonId error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение теста по ID модуля
   */
  public function findByModuleId($moduleId)
  {
    try {
      $sql = "SELECT * FROM {$this->table} 
                    WHERE module_id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$moduleId]);

      $test = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($test) {
        $test['questions'] = $this->getQuestionsByTestId($test['id']);
      }

      return $test;
    } catch (PDOException $e) {
      error_log("Test findByModuleId error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение теста для студента (без правильных ответов)
   */
  public function findByLessonIdForStudent($lessonId)
  {
    try {
      $sql = "SELECT id, lesson_id, title, description, created_at 
                    FROM {$this->table} 
                    WHERE lesson_id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$lessonId]);

      $test = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($test) {
        $test['questions'] = $this->getQuestionsByTestIdForStudent($test['id']);
      }

      return $test;
    } catch (PDOException $e) {
      error_log("Test findByLessonIdForStudent error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Получение теста модуля для студента (без правильных ответов)
   */
  public function findByModuleIdForStudent($moduleId)
  {
    try {
      $sql = "SELECT id, module_id, title, description, created_at 
                    FROM {$this->table} 
                    WHERE module_id = ? AND deleted_at IS NULL";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$moduleId]);

      $test = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($test) {
        $test['questions'] = $this->getQuestionsByTestIdForStudent($test['id']);
      }

      return $test;
    } catch (PDOException $e) {
      error_log("Test findByModuleIdForStudent error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка, завершен ли тест студентом с минимальным процентом (50%)
   */
  public function isTestCompleted($studentId, $lessonId, $minScore = 50)
  {
    try {
      // Проверяем, есть ли тест для урока
      $test = $this->findByLessonId($lessonId);
      if (!$test) {
        // Если теста нет, считаем что "завершен" (нет требования)
        return ['completed' => true, 'score' => null, 'min_score' => $minScore];
      }

      // Проверяем лучший результат теста для студента
      $sql = "SELECT score, correct_answers, total_questions 
              FROM test_results 
              WHERE test_id = ? AND student_id = ? 
              ORDER BY score DESC 
              LIMIT 1";
      
      $stmt = $this->db->prepare($sql);
      $stmt->execute([$test['id'], $studentId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$result) {
        // Нет результатов теста
        return ['completed' => false, 'score' => null, 'min_score' => $minScore, 'has_attempt' => false];
      }

      $score = (float)$result['score'];
      $isCompleted = $score >= $minScore;

      return [
        'completed' => $isCompleted,
        'score' => $score,
        'correct_answers' => (int)$result['correct_answers'],
        'total_questions' => (int)$result['total_questions'],
        'min_score' => $minScore,
        'has_attempt' => true
      ];
    } catch (PDOException $e) {
      error_log("Test isTestCompleted error: " . $e->getMessage());
      return ['completed' => false, 'score' => null, 'min_score' => $minScore, 'error' => $e->getMessage()];
    }
  }

  /**
   * Проверка, завершен ли тест модуля студентом с минимальным процентом (50%)
   */
  public function isModuleTestCompleted($studentId, $moduleId, $minScore = 50)
  {
    try {
      // Проверяем, есть ли тест для модуля
      $test = $this->findByModuleId($moduleId);
      if (!$test) {
        // Если теста нет, считаем что "завершен" (нет требования)
        return ['completed' => true, 'score' => null, 'min_score' => $minScore];
      }

      // Проверяем лучший результат теста для студента
      $sql = "SELECT score, correct_answers, total_questions 
              FROM test_results 
              WHERE test_id = ? AND student_id = ? 
              ORDER BY score DESC 
              LIMIT 1";
      
      $stmt = $this->db->prepare($sql);
      $stmt->execute([$test['id'], $studentId]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$result) {
        // Нет результатов теста
        return ['completed' => false, 'score' => null, 'min_score' => $minScore, 'has_attempt' => false];
      }

      $score = (float)$result['score'];
      $isCompleted = $score >= $minScore;

      return [
        'completed' => $isCompleted,
        'score' => $score,
        'correct_answers' => (int)$result['correct_answers'],
        'total_questions' => (int)$result['total_questions'],
        'min_score' => $minScore,
        'has_attempt' => true
      ];
    } catch (PDOException $e) {
      error_log("Test isModuleTestCompleted error: " . $e->getMessage());
      return ['completed' => false, 'score' => null, 'min_score' => $minScore, 'error' => $e->getMessage()];
    }
  }

  /**
   * Получение вопросов теста
   */
  private function getQuestionsByTestId($testId)
  {
    try {
      $sql = "SELECT * FROM test_questions 
                WHERE test_id = ? AND deleted_at IS NULL 
                ORDER BY order_index ASC, created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$testId]);

      $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($questions as &$question) {
        if ($question['type'] === 'matching') {
          $question['pairs'] = $this->getMatchingPairsByQuestionId($question['id']);
        } else {
          $question['answers'] = $this->getAnswersByQuestionId($question['id']);
        }
      }

      return $questions;
    } catch (PDOException $e) {
      error_log("Get test questions error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение вопросов теста для студента (без правильных ответов)
   */
  private function getQuestionsByTestIdForStudent($testId)
  {
    try {
      $sql = "SELECT id, test_id, text, type, image_url, order_index, created_at 
                FROM test_questions 
                WHERE test_id = ? AND deleted_at IS NULL 
                ORDER BY order_index ASC, created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$testId]);

      $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($questions as &$question) {
        if ($question['type'] === 'matching') {
          $question['pairs'] = $this->getMatchingPairsByQuestionIdForStudent($question['id']);
        } else {
          $question['answers'] = $this->getAnswersByQuestionIdForStudent($question['id']);
        }
      }

      return $questions;
    } catch (PDOException $e) {
      error_log("Get test questions for student error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение ответов на вопрос
   */
  private function getAnswersByQuestionId($questionId)
  {
    try {
      $sql = "SELECT * FROM test_answers 
                    WHERE question_id = ? AND deleted_at IS NULL 
                    ORDER BY order_index ASC, created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$questionId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Get test answers error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение ответов на вопрос для студента (без флага правильности)
   */
  private function getAnswersByQuestionIdForStudent($questionId)
  {
    try {
      $sql = "SELECT id, question_id, text, order_index, created_at 
                    FROM test_answers 
                    WHERE question_id = ? AND deleted_at IS NULL 
                    ORDER BY order_index ASC, created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$questionId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Get test answers for student error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение пар для вопроса на соответствие
   */
  private function getMatchingPairsByQuestionId($questionId)
  {
    try {
      $sql = "SELECT * FROM test_matching_pairs 
                    WHERE question_id = ? AND deleted_at IS NULL 
                    ORDER BY order_index ASC, created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$questionId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Get matching pairs error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Получение пар для вопроса на соответствие для студента
   */
  private function getMatchingPairsByQuestionIdForStudent($questionId)
  {
    try {
      $sql = "SELECT id, question_id, left_item, right_item, order_index, created_at 
                    FROM test_matching_pairs 
                    WHERE question_id = ? AND deleted_at IS NULL 
                    ORDER BY order_index ASC, created_at ASC";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([$questionId]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Get matching pairs for student error: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Сохранение теста (создание или обновление)
   */
  public function saveTest($lessonId, $data)
  {
    try {
      $this->db->beginTransaction();

      // Проверяем существующий тест
      $existingTest = $this->findByLessonId($lessonId);

      if ($existingTest) {
        // Обновляем существующий тест
        $testId = $existingTest['id'];
        $this->updateTest($testId, $data);
        $this->deleteTestData($testId);
      } else {
        // Создаем новый тест
        $testId = $this->createTest($lessonId, $data);
      }

      // Сохраняем вопросы и ответы
      if ($testId && isset($data['questions'])) {
        foreach ($data['questions'] as $questionIndex => $questionData) {
          $questionId = $this->createQuestion($testId, $questionData, $questionIndex);

          if ($questionId) {
            // Сохраняем данные в зависимости от типа вопроса
            $questionType = $questionData['type'] ?? 'single_choice';

            switch ($questionType) {
              case 'single_choice':
              case 'multiple_choice':
                if (isset($questionData['answers'])) {
                  $this->createAnswers($questionId, $questionData['answers']);
                }
                break;

              case 'true_false':
                // Автоматически создаем ответы для true/false
                $this->createTrueFalseAnswers($questionId, $questionData);
                break;

              case 'matching':
                if (isset($questionData['pairs'])) {
                  $this->createMatchingPairs($questionId, $questionData['pairs']);
                }
                break;
            }
          }
        }
      }

      $this->db->commit();
      return $testId;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("Save test error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Сохранение теста модуля (создание или обновление)
   */
  public function saveModuleTest($moduleId, $data)
  {
    try {
      $this->db->beginTransaction();

      // Проверяем существующий тест
      $existingTest = $this->findByModuleId($moduleId);

      if ($existingTest) {
        // Обновляем существующий тест
        $testId = $existingTest['id'];
        $this->updateTest($testId, $data);
        $this->deleteTestData($testId);
      } else {
        // Создаем новый тест
        $testId = $this->createModuleTest($moduleId, $data);
      }

      // Сохраняем вопросы и ответы
      if ($testId && isset($data['questions'])) {
        foreach ($data['questions'] as $questionIndex => $questionData) {
          $questionId = $this->createQuestion($testId, $questionData, $questionIndex);

          if ($questionId) {
            // Сохраняем данные в зависимости от типа вопроса
            $questionType = $questionData['type'] ?? 'single_choice';

            switch ($questionType) {
              case 'single_choice':
              case 'multiple_choice':
                if (isset($questionData['answers'])) {
                  $this->createAnswers($questionId, $questionData['answers']);
                }
                break;

              case 'true_false':
                // Автоматически создаем ответы для true/false
                $this->createTrueFalseAnswers($questionId, $questionData);
                break;

              case 'matching':
                if (isset($questionData['pairs'])) {
                  $this->createMatchingPairs($questionId, $questionData['pairs']);
                }
                break;
            }
          }
        }
      }

      $this->db->commit();
      return $testId;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("Save module test error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Создание теста
   */
  private function createTest($lessonId, $data)
  {
    try {
      $sql = "INSERT INTO {$this->table} (lesson_id, title, description, created_at) 
                    VALUES (?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);
      $title = $data['title'] ?? 'Тест к уроку';
      $description = $data['description'] ?? null;

      if ($stmt->execute([$lessonId, $title, $description, date('Y-m-d H:i:s')])) {
        return $this->db->lastInsertId();
      }

      return false;
    } catch (PDOException $e) {
      error_log("Create test error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Создание теста модуля
   */
  private function createModuleTest($moduleId, $data)
  {
    try {
      $sql = "INSERT INTO {$this->table} (module_id, title, description, created_at) 
                    VALUES (?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);
      $title = $data['title'] ?? 'Итоговый тест модуля';
      $description = $data['description'] ?? null;

      if ($stmt->execute([$moduleId, $title, $description, date('Y-m-d H:i:s')])) {
        return $this->db->lastInsertId();
      }

      return false;
    } catch (PDOException $e) {
      error_log("Create module test error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Обновление теста
   */
  private function updateTest($testId, $data)
  {
    try {
      $sql = "UPDATE {$this->table} SET title = ?, description = ?, updated_at = ? 
                    WHERE id = ?";

      $stmt = $this->db->prepare($sql);
      $title = $data['title'] ?? 'Тест к уроку';
      $description = $data['description'] ?? null;

      return $stmt->execute([$title, $description, date('Y-m-d H:i:s'), $testId]);
    } catch (PDOException $e) {
      error_log("Update test error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Создание вопроса
   */
  private function createQuestion($testId, $questionData, $orderIndex)
  {
    try {
      $type = $questionData['type'] ?? 'single_choice';
      $imageUrl = $questionData['image_url'] ?? null;

      $sql = "INSERT INTO test_questions (test_id, text, type, image_url, order_index, created_at) 
                VALUES (?, ?, ?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);

      if ($stmt->execute([$testId, $questionData['text'], $type, $imageUrl, $orderIndex, date('Y-m-d H:i:s')])) {
        return $this->db->lastInsertId();
      }

      return false;
    } catch (PDOException $e) {
      error_log("Create question error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Создание ответов
   */
  private function createAnswers($questionId, $answers)
  {
    try {
      $sql = "INSERT INTO test_answers (question_id, text, is_correct, order_index, created_at) 
                    VALUES (?, ?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);

      foreach ($answers as $index => $answerData) {
        $isCorrect = $answerData['is_correct'] ?? false;
        $stmt->execute([
          $questionId,
          $answerData['text'],
          $isCorrect ? 1 : 0,
          $index,
          date('Y-m-d H:i:s')
        ]);
      }

      return true;
    } catch (PDOException $e) {
      error_log("Create answers error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Создание ответов для вопроса верно/неверно
   */
  private function createTrueFalseAnswers($questionId, $questionData)
  {
    try {
      // Для true/false создаем два ответа: Верно и Неверно
      $answers = [
        ['text' => 'Верно', 'is_correct' => true],
        ['text' => 'Неверно', 'is_correct' => false]
      ];

      // Если в данных есть информация о правильном ответе, используем ее
      if (isset($questionData['correct_answer'])) {
        $isTrueCorrect = $questionData['correct_answer'] === 'true';
        $answers[0]['is_correct'] = $isTrueCorrect;
        $answers[1]['is_correct'] = !$isTrueCorrect;
      }

      $sql = "INSERT INTO test_answers (question_id, text, is_correct, order_index, created_at) 
              VALUES (?, ?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);

      foreach ($answers as $index => $answerData) {
        $stmt->execute([
          $questionId,
          $answerData['text'],
          $answerData['is_correct'] ? 1 : 0,
          $index,
          date('Y-m-d H:i:s')
        ]);
      }

      return true;
    } catch (PDOException $e) {
      error_log("Create true/false answers error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Создание пар для вопроса на соответствие
   */
  private function createMatchingPairs($questionId, $pairs)
  {
    try {
      $sql = "INSERT INTO test_matching_pairs (question_id, left_item, right_item, order_index, created_at) 
                    VALUES (?, ?, ?, ?, ?)";

      $stmt = $this->db->prepare($sql);

      foreach ($pairs as $index => $pair) {
        $stmt->execute([
          $questionId,
          $pair['left_item'] ?? $pair['left'], // поддержка обоих форматов
          $pair['right_item'] ?? $pair['right'], // поддержка обоих форматов
          $index,
          date('Y-m-d H:i:s')
        ]);
      }

      return true;
    } catch (PDOException $e) {
      error_log("Create matching pairs error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Удаление данных теста
   */
  private function deleteTestData($testId)
  {
    try {
      // Получаем вопросы для удаления
      $questions = $this->getQuestionsByTestId($testId);

      foreach ($questions as $question) {
        $this->deleteQuestionData($question['id']);
      }

      // Удаляем вопросы
      $sql = "UPDATE test_questions SET deleted_at = ? WHERE test_id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([date('Y-m-d H:i:s'), $testId]);

      return true;
    } catch (PDOException $e) {
      error_log("Delete test data error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Удаление данных вопроса
   */
  private function deleteQuestionData($questionId)
  {
    try {
      // Удаляем ответы
      $sql = "UPDATE test_answers SET deleted_at = ? WHERE question_id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([date('Y-m-d H:i:s'), $questionId]);

      // Удаляем пары соответствия
      $sql = "UPDATE test_matching_pairs SET deleted_at = ? WHERE question_id = ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([date('Y-m-d H:i:s'), $questionId]);

      return true;
    } catch (PDOException $e) {
      error_log("Delete question data error: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Удаление теста по ID урока
   */
  public function deleteByLessonId($lessonId)
  {
    try {
      $test = $this->findByLessonId($lessonId);

      if (!$test) {
        return false;
      }

      $this->db->beginTransaction();

      // Удаляем данные теста
      $this->deleteTestData($test['id']);

      // Удаляем сам тест
      $sql = "UPDATE {$this->table} SET deleted_at = ? WHERE id = ?";
      $stmt = $this->db->prepare($sql);
      $result = $stmt->execute([date('Y-m-d H:i:s'), $test['id']]);

      $this->db->commit();
      return $result;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("Delete test by lesson ID error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Удаление теста по ID модуля
   */
  public function deleteByModuleId($moduleId)
  {
    try {
      $test = $this->findByModuleId($moduleId);

      if (!$test) {
        return false;
      }

      $this->db->beginTransaction();

      // Удаляем данные теста
      $this->deleteTestData($test['id']);

      // Удаляем сам тест
      $sql = "UPDATE {$this->table} SET deleted_at = ? WHERE id = ?";
      $stmt = $this->db->prepare($sql);
      $result = $stmt->execute([date('Y-m-d H:i:s'), $test['id']]);

      $this->db->commit();
      return $result;
    } catch (PDOException $e) {
      $this->db->rollBack();
      error_log("Delete test by module ID error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка ответов студента
   */
  public function checkAnswers($lessonId, $studentAnswers)
  {
    try {
      $test = $this->findByLessonId($lessonId);

      if (!$test || !isset($test['questions'])) {
        return false;
      }

      $results = [
        'total_questions' => count($test['questions']),
        'correct_answers' => 0,
        'score' => 0,
        'details' => []
      ];

      foreach ($test['questions'] as $question) {
        $questionId = $question['id'];
        $studentAnswer = $studentAnswers[$questionId] ?? null;

        $isCorrect = $this->checkQuestionAnswer($question, $studentAnswer);

        if ($isCorrect) {
          $results['correct_answers']++;
        }

        $results['details'][] = [
          'question_id' => $questionId,
          'question_text' => $question['text'],
          'question_type' => $question['type'],
          'is_correct' => $isCorrect,
          'correct_answers' => $this->getCorrectAnswers($question),
          'student_answer' => $studentAnswer
        ];
      }

      $results['score'] = $results['total_questions'] > 0
        ? round(($results['correct_answers'] / $results['total_questions']) * 100, 2)
        : 0;

      return $results;
    } catch (PDOException $e) {
      error_log("Check answers error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Проверка ответов студента для теста модуля
   */
  public function checkModuleAnswers($moduleId, $studentAnswers)
  {
    try {
      $test = $this->findByModuleId($moduleId);

      if (!$test || !isset($test['questions'])) {
        error_log("Module test not found or has no questions for module: {$moduleId}");
        return false;
      }

      $results = [
        'total_questions' => count($test['questions']),
        'correct_answers' => 0,
        'score' => 0,
        'details' => []
      ];

      error_log("Checking module test answers. Total questions: " . count($test['questions']));
      error_log("Student answers received: " . json_encode($studentAnswers));

      foreach ($test['questions'] as $question) {
        $questionId = $question['id'];
        // Пробуем найти ответ по ID как строке и как числу
        $studentAnswer = null;
        if (isset($studentAnswers[$questionId])) {
          $studentAnswer = $studentAnswers[$questionId];
        } elseif (isset($studentAnswers[(string)$questionId])) {
          $studentAnswer = $studentAnswers[(string)$questionId];
        } elseif (isset($studentAnswers[(int)$questionId])) {
          $studentAnswer = $studentAnswers[(int)$questionId];
        }

        error_log("Question ID: {$questionId}, Type: {$question['type']}, Answer found: " . ($studentAnswer !== null ? 'YES' : 'NO'));

        $isCorrect = $this->checkQuestionAnswer($question, $studentAnswer);

        if ($isCorrect) {
          $results['correct_answers']++;
        }

        $results['details'][] = [
          'question_id' => $questionId,
          'question_text' => $question['text'],
          'question_type' => $question['type'],
          'is_correct' => $isCorrect,
          'correct_answers' => $this->getCorrectAnswers($question),
          'student_answer' => $studentAnswer
        ];
      }

      $results['score'] = $results['total_questions'] > 0
        ? round(($results['correct_answers'] / $results['total_questions']) * 100, 2)
        : 0;

      error_log("Module test results: Score: {$results['score']}%, Correct: {$results['correct_answers']}/{$results['total_questions']}");
      return $results;
    } catch (PDOException $e) {
      error_log("Check module answers error: " . $e->getMessage());
      error_log("Stack trace: " . $e->getTraceAsString());
      return false;
    }
  }

  /**
   * Проверка ответа на конкретный вопрос
   */
  private function checkQuestionAnswer($question, $studentAnswer)
  {
    if (!$studentAnswer) {
      return false;
    }

    switch ($question['type']) {
      case 'single_choice':
        return $this->checkSingleChoice($question, $studentAnswer);

      case 'multiple_choice':
        return $this->checkMultipleChoice($question, $studentAnswer);

      case 'matching':
        return $this->checkMatching($question, $studentAnswer);

      case 'true_false':
        return $this->checkTrueFalse($question, $studentAnswer);

      default:
        return false;
    }
  }

  /**
   * Проверка одиночного выбора
   */
  private function checkSingleChoice($question, $studentAnswer)
  {
    $correctAnswers = $this->getCorrectAnswers($question);
    return in_array($studentAnswer, $correctAnswers);
  }

  /**
   * Проверка множественного выбора
   */
  private function checkMultipleChoice($question, $studentAnswer)
  {
    if (!is_array($studentAnswer)) {
      return false;
    }

    $correctAnswers = $this->getCorrectAnswers($question);

    sort($studentAnswer);
    sort($correctAnswers);

    return $studentAnswer == $correctAnswers;
  }

  /**
   * Проверка соответствия
   */
  private function checkMatching($question, $studentAnswer)
  {
    if (!is_array($studentAnswer) || !isset($question['pairs']) || empty($question['pairs'])) {
      error_log("checkMatching: Invalid answer or no pairs. Answer: " . json_encode($studentAnswer));
      return false;
    }

    // Проверяем, что количество пар совпадает
    if (count($studentAnswer) !== count($question['pairs'])) {
      error_log("checkMatching: Answer count mismatch. Expected: " . count($question['pairs']) . ", Got: " . count($studentAnswer));
      return false;
    }

    // В matching вопросах правильный ответ - это когда left_item и right_item из одной пары
    // Каждая пара имеет свой id, и правильное соответствие - это когда left_id пары соответствует right_id той же пары
    // Создаем маппинг правильных соответствий: left_id пары => right_id той же пары
    $correctPairs = [];
    foreach ($question['pairs'] as $index => $pair) {
      $pairId = (int)$pair['id'];
      // Правильное соответствие - пара сама себе (left_id = right_id = id пары)
      $correctPairs[$pairId] = $pairId;
    }

    error_log("checkMatching: Correct pairs mapping: " . json_encode($correctPairs));

    // Проверяем каждое соответствие студента
    foreach ($studentAnswer as $answer) {
      $leftId = isset($answer['left_id']) ? (int)$answer['left_id'] : null;
      $studentRightId = isset($answer['right_id']) ? (int)$answer['right_id'] : null;

      if ($leftId === null || $studentRightId === null) {
        error_log("checkMatching: Missing left_id or right_id in answer: " . json_encode($answer));
        return false;
      }

      // Проверяем, что left_id существует в правильных парах
      if (!isset($correctPairs[$leftId])) {
        error_log("checkMatching: left_id {$leftId} not found in correct pairs. Available: " . implode(', ', array_keys($correctPairs)));
        return false;
      }

      // Правильное соответствие: left_id должен соответствовать right_id с тем же ID
      $correctRightId = $correctPairs[$leftId];
      
      if ($studentRightId != $correctRightId) {
        error_log("checkMatching: Wrong match. Left: {$leftId}, Student right: {$studentRightId}, Expected: {$correctRightId}");
        return false;
      }
    }

    error_log("checkMatching: All pairs matched correctly");
    return true;
  }

  /**
   * Проверка верно/неверно
   */
  private function checkTrueFalse($question, $studentAnswer)
  {
    $correctAnswers = $this->getCorrectAnswers($question);
    $correctAnswer = $correctAnswers[0] ?? null;

    // Приводим к одному типу для сравнения
    $studentAnswer = is_bool($studentAnswer) ? ($studentAnswer ? 'true' : 'false') : $studentAnswer;
    $correctAnswer = is_bool($correctAnswer) ? ($correctAnswer ? 'true' : 'false') : $correctAnswer;

    return $studentAnswer == $correctAnswer;
  }

  /**
   * Получение ID правильных ответов на вопрос
   */
  private function getCorrectAnswers($question)
  {
    $correctAnswers = [];

    if ($question['type'] === 'matching') {
      // Для вопросов на соответствие возвращаем пары
      foreach ($question['pairs'] as $pair) {
        $correctAnswers[$pair['id']] = $pair['right_item'];
      }
    } elseif (isset($question['answers'])) {
      // Для вопросов с выбором
      foreach ($question['answers'] as $answer) {
        if ($answer['is_correct']) {
          // Для true/false возвращаем текст ответа
          if ($question['type'] === 'true_false') {
            $correctAnswers[] = $answer['text'] === 'Верно' ? 'true' : 'false';
          } else {
            $correctAnswers[] = $answer['id'];
          }
        }
      }
    }

    return $correctAnswers;
  }
}
