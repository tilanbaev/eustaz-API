<?php
class ModuleController extends BaseAuth
{
  protected $moduleModel;
  protected $lessonModel;
  protected $courseModel;
  protected $testModel;
  protected $uploadPath;
  protected $pdfUploadPath;
  protected $enrollmentModel;
  protected $userProgressModel;
  public function __construct()
  {
    parent::__construct();
    $this->moduleModel = new Module();
    $this->lessonModel = new Lesson();
    $this->courseModel = new Course();
    $this->testModel = new Test();
    $this->enrollmentModel = new Enrollment();
    $this->userProgressModel = new UserProgress();
    $this->uploadPath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/lessons/attachments/';
    $this->pdfUploadPath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/lessons/pdfs/';

    // Создаем директории для загрузок если не существуют
    if (!file_exists($this->uploadPath)) {
      mkdir($this->uploadPath, 0755, true);
    }
    if (!file_exists($this->pdfUploadPath)) {
      mkdir($this->pdfUploadPath, 0755, true);
    }

    error_log("ModuleController initialized");
  }

  /**
   * Получение модулей курса (для преподавателей)
   */
  public function index($courseId)
  {
    error_log("ModuleController::index called with courseId: " . $courseId);
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) {
      error_log("Fetching modules for course: " . $courseId);

      // Получаем модули с уроками и тестами
      $modules = $this->moduleModel->findByCourseId($courseId);
      error_log("Found " . count($modules) . " modules for course: " . $courseId);

      return $this->jsonResponse(true, 'Modules retrieved successfully', $modules);
    });
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
   * Определение роли пользователя
   */
  private function getUserRole()
  {
    $user = $this->getUserFromRequest();
    return $user['role'] ?? null;
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
   * Получение модулей курса для студентов (публичный доступ)
   */
  public function indexPublic($courseId)
  {
    error_log("ModuleController::indexPublic called with courseId: " . $courseId);

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

      // Проверяем доступ
      $this->checkCourseAccess($courseId);

      // Получаем модули
      $modules = $this->moduleModel->findByCourseId($courseId);
      return $this->jsonResponse(true, 'Modules retrieved successfully', $modules);
    } catch (Exception $e) {
      error_log("Modules index public error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }
  /**
   * Получение уроков модуля (для преподавателей)
   */
  public function getLessons($courseId, $moduleId)
  {
    error_log("ModuleController::getLessons called with courseId: {$courseId}, moduleId: {$moduleId}");
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) use ($moduleId) {
      // Преобразуем moduleId в число
      $moduleId = (int)$moduleId;
      if ($moduleId <= 0) {
        error_log("Invalid module ID: " . $moduleId);
        return $this->jsonResponse(false, 'Invalid module ID', null, 400);
      }

      // Проверяем принадлежность модуля
      if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
        error_log("User {$userId} does not own module {$moduleId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      // Получаем уроки модуля с тестами и вложениями
      $lessons = $this->moduleModel->getLessonsByModuleId($moduleId);
      error_log("Found " . count($lessons) . " lessons for module: " . $moduleId);

      return $this->jsonResponse(true, 'Lessons retrieved successfully', $lessons);
    });
  }
  /**
   * Получение конкретного модуля для студентов
   */
  public function showPublic($courseId, $moduleId)
  {
    error_log("ModuleController::showPublic called with courseId: {$courseId}, moduleId: {$moduleId}");

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

      $this->checkCourseAccess($courseId);

      // Получаем модуль
      $module = $this->moduleModel->findByIdWithLessons($moduleId);
      if (!$module) {
        return $this->jsonResponse(false, 'Module not found', null, 404);
      }

      // Проверяем принадлежность модуля курсу
      if ($module['course_id'] != $courseId) {
        return $this->jsonResponse(false, 'Module not found in this course', null, 404);
      }

      return $this->jsonResponse(true, 'Module retrieved successfully', $module);
    } catch (Exception $e) {
      error_log("Module show public error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение уроков модуля для студентов
   */
  public function indexLessonsPublic($courseId, $moduleId)
  {
    error_log("ModuleController::indexLessonsPublic called with courseId: {$courseId}, moduleId: {$moduleId}");

    try {
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;

      if ($courseId <= 0 || $moduleId <= 0) {
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем существование курса и модуля
      $course = $this->courseModel->findById($courseId);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      $module = $this->moduleModel->findById($moduleId);
      if (!$module) {
        return $this->jsonResponse(false, 'Module not found', null, 404);
      }

      $this->checkCourseAccess($courseId);


      // Проверяем принадлежность модуля курсу
      if ($module['course_id'] != $courseId) {
        return $this->jsonResponse(false, 'Module not found in this course', null, 404);
      }

      // Получаем уроки
      $lessons = $this->moduleModel->getLessonsByModuleId($moduleId);
      return $this->jsonResponse(true, 'Lessons retrieved successfully', $lessons);
    } catch (Exception $e) {
      error_log("Lessons index public error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение конкретного урока для студентов
   */
  public function showLessonPublic($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::showLessonPublic called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");

    try {
      // Преобразуем ID в числа
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;
      $lessonId = (int)$lessonId;

      if ($courseId <= 0 || $moduleId <= 0 || $lessonId <= 0) {
        error_log("Invalid IDs - courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем, существует ли курс и модуль
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

      // Проверяем, принадлежит ли модуль курсу
      if ($module['course_id'] != $courseId) {
        error_log("Module {$moduleId} does not belong to course {$courseId}");
        return $this->jsonResponse(false, 'Module not found in this course', null, 404);
      }
      $this->checkCourseAccess($courseId);

      // Получаем урок с тестом
      $lesson = $this->lessonModel->findByIdWithTest($lessonId);
      if (!$lesson) {
        error_log("Lesson not found: " . $lessonId);
        return $this->jsonResponse(false, 'Lesson not found', null, 404);
      }

      // Проверяем, принадлежит ли урок модулю
      if ($lesson['module_id'] != $moduleId) {
        error_log("Lesson {$lessonId} does not belong to module {$moduleId}");
        return $this->jsonResponse(false, 'Lesson not found in this module', null, 404);
      }

      error_log("Successfully retrieved lesson: " . $lessonId);
      return $this->jsonResponse(true, 'Lesson retrieved successfully', $lesson);
    } catch (Exception $e) {
      error_log("Lesson show public error: " . $e->getMessage());
      error_log("Stack trace: " . $e->getTraceAsString());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Создание модуля
   */
  public function create($courseId)
  {
    error_log("ModuleController::create called with courseId: " . $courseId);
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) {
      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) {
        error_log("Invalid JSON data received");
        return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
      }

      error_log("Creating module with data: " . json_encode($input));

      // Добавляем course_id к данным модуля
      $input['course_id'] = $courseId;

      // Валидация
      $errors = $this->validateModuleData($input);
      if (!empty($errors)) {
        error_log("Module validation errors: " . json_encode($errors));
        return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
      }

      // Создание модуля
      $moduleId = $this->moduleModel->create($input);

      if ($moduleId) {
        $module = $this->moduleModel->findById($moduleId);
        error_log("Module created successfully with ID: " . $moduleId);
        return $this->jsonResponse(true, 'Module created successfully', [
          'id' => $moduleId,
          'module' => $module
        ], 201);
      } else {
        error_log("Failed to create module in database");
        return $this->jsonResponse(false, 'Failed to create module', null, 500);
      }
    });
  }

  /**
   * Обновление модуля
   */
  public function update($courseId, $moduleId)
  {
    error_log("ModuleController::update called with courseId: {$courseId}, moduleId: {$moduleId}");
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) use ($moduleId) {
      // Преобразуем moduleId в число
      $moduleId = (int)$moduleId;
      if ($moduleId <= 0) {
        error_log("Invalid module ID: " . $moduleId);
        return $this->jsonResponse(false, 'Invalid module ID', null, 400);
      }

      // Проверяем принадлежность
      if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
        error_log("User {$userId} does not own module {$moduleId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) {
        error_log("Invalid JSON data received");
        return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
      }

      error_log("Updating module {$moduleId} with data: " . json_encode($input));

      // Валидация
      $errors = $this->validateModuleData($input);
      if (!empty($errors)) {
        error_log("Module validation errors: " . json_encode($errors));
        return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
      }

      // Обновление модуля
      $result = $this->moduleModel->update($moduleId, $input);

      if ($result) {
        $updatedModule = $this->moduleModel->findById($moduleId);
        error_log("Module updated successfully: " . $moduleId);
        return $this->jsonResponse(true, 'Module updated successfully', $updatedModule);
      } else {
        error_log("Failed to update module in database: " . $moduleId);
        return $this->jsonResponse(false, 'Failed to update module', null, 500);
      }
    });
  }

  /**
   * Удаление модуля
   */
  public function delete($courseId, $moduleId)
  {
    error_log("ModuleController::delete called with courseId: {$courseId}, moduleId: {$moduleId}");
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) use ($moduleId) {
      // Преобразуем moduleId в число
      $moduleId = (int)$moduleId;
      if ($moduleId <= 0) {
        error_log("Invalid module ID: " . $moduleId);
        return $this->jsonResponse(false, 'Invalid module ID', null, 400);
      }

      // Проверяем принадлежность
      if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
        error_log("User {$userId} does not own module {$moduleId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      $result = $this->moduleModel->delete($moduleId);

      if ($result) {
        error_log("Module deleted successfully: " . $moduleId);
        return $this->jsonResponse(true, 'Module deleted successfully');
      } else {
        error_log("Module not found or already deleted: " . $moduleId);
        return $this->jsonResponse(false, 'Module not found or already deleted', null, 404);
      }
    });
  }

  /**
   * Создание урока
   */
  public function createLesson($courseId, $moduleId)
  {
    error_log("ModuleController::createLesson called with courseId: {$courseId}, moduleId: {$moduleId}");
    return $this->handleLessonRequest($courseId, $moduleId, null, function ($courseId, $moduleId, $lessonId, $userId) {
      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) {
        error_log("Invalid JSON data received");
        return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
      }

      error_log("Creating lesson for module {$moduleId} with data: " . json_encode($input));
      $this->checkCourseAccess($courseId);

      // Добавляем module_id к данным урока
      $input['module_id'] = $moduleId;

      // Обработка вложений
      $attachments = [];
      if (!empty($input['attachments'])) {
        error_log("Processing " . count($input['attachments']) . " attachments");
        $attachments = $this->processAttachments($input['attachments']);
        $input['attachments'] = $attachments;
      }

      // Валидация (создание нового урока)
      $errors = $this->validateLessonData($input, false);
      if (!empty($errors)) {
        error_log("Lesson validation errors: " . json_encode($errors));
        // Удаляем загруженные файлы в случае ошибки
        foreach ($attachments as $attachment) {
          $this->deleteFile($attachment['path']);
        }
        return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
      }

      // Создание урока
      $lessonId = $this->lessonModel->create($input);

      if ($lessonId) {
        $lesson = $this->lessonModel->findById($lessonId);
        error_log("Lesson created successfully with ID: " . $lessonId);
        return $this->jsonResponse(true, 'Lesson created successfully', [
          'id' => $lessonId,
          'lesson' => $lesson
        ], 201);
      } else {
        error_log("Failed to create lesson in database");
        // Удаляем загруженные файлы в случае ошибки
        foreach ($attachments as $attachment) {
          $this->deleteFile($attachment['path']);
        }
        return $this->jsonResponse(false, 'Failed to create lesson', null, 500);
      }
    });
  }

  /**
   * Обновление урока
   */
  public function updateLesson($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::updateLesson called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
    return $this->handleLessonRequest($courseId, $moduleId, $lessonId, function ($courseId, $moduleId, $lessonId, $userId) {
      // Преобразуем lessonId в число
      $lessonId = (int)$lessonId;
      if ($lessonId <= 0) {
        error_log("Invalid lesson ID: " . $lessonId);
        return $this->jsonResponse(false, 'Invalid lesson ID', null, 400);
      }

      // Проверяем принадлежность
      if (!$this->lessonModel->isOwnedByUser($lessonId, $userId)) {
        error_log("User {$userId} does not own lesson {$lessonId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) {
        error_log("Invalid JSON data received");
        return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
      }

      error_log("Updating lesson {$lessonId} with data: " . json_encode($input));

      // Получаем старый урок для проверки старого PDF
      $oldLesson = $this->lessonModel->findById($lessonId);
      $oldPdfUrl = $oldLesson['pdf_url'] ?? null;

      // Если pdf_url не передан, сохраняем старый (не обновляем)
      if (empty($input['pdf_url']) && $oldPdfUrl) {
        $input['pdf_url'] = $oldPdfUrl;
      }

      // Если pdf_url изменился, удаляем старый PDF
      if (!empty($input['pdf_url']) && $oldPdfUrl && $input['pdf_url'] !== $oldPdfUrl) {
        $oldPdfPath = $_SERVER['DOCUMENT_ROOT'] . $oldPdfUrl;
        if (file_exists($oldPdfPath) && is_file($oldPdfPath)) {
          unlink($oldPdfPath);
          error_log("Old PDF deleted during update: " . $oldPdfUrl);
        }
      }

      // Обработка вложений
      $attachments = [];
      if (!empty($input['attachments'])) {
        error_log("Processing " . count($input['attachments']) . " attachments");
        $attachments = $this->processAttachments($input['attachments']);
        $input['attachments'] = $attachments;
      }

      // Валидация (обновление урока)
      $errors = $this->validateLessonData($input, true);
      if (!empty($errors)) {
        error_log("Lesson validation errors: " . json_encode($errors));
        // Удаляем загруженные файлы в случае ошибки
        foreach ($attachments as $attachment) {
          $this->deleteFile($attachment['path']);
        }
        return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
      }

      // Обновление урока
      $result = $this->lessonModel->update($lessonId, $input);

      if ($result) {
        $updatedLesson = $this->lessonModel->findById($lessonId);
        error_log("Lesson updated successfully: " . $lessonId);
        return $this->jsonResponse(true, 'Lesson updated successfully', $updatedLesson);
      } else {
        error_log("Failed to update lesson in database: " . $lessonId);
        // Удаляем загруженные файлы в случае ошибки
        foreach ($attachments as $attachment) {
          $this->deleteFile($attachment['path']);
        }
        return $this->jsonResponse(false, 'Failed to update lesson', null, 500);
      }
    });
  }

  /**
   * Удаление урока
   */
  public function deleteLesson($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::deleteLesson called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
    return $this->handleLessonRequest($courseId, $moduleId, $lessonId, function ($courseId, $moduleId, $lessonId, $userId) {
      // Преобразуем lessonId в число
      $lessonId = (int)$lessonId;
      if ($lessonId <= 0) {
        error_log("Invalid lesson ID: " . $lessonId);
        return $this->jsonResponse(false, 'Invalid lesson ID', null, 400);
      }

      // Проверяем принадлежность
      if (!$this->lessonModel->isOwnedByUser($lessonId, $userId)) {
        error_log("User {$userId} does not own lesson {$lessonId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      $result = $this->lessonModel->delete($lessonId);

      if ($result) {
        error_log("Lesson deleted successfully: " . $lessonId);
        return $this->jsonResponse(true, 'Lesson deleted successfully');
      } else {
        error_log("Lesson not found or already deleted: " . $lessonId);
        return $this->jsonResponse(false, 'Lesson not found or already deleted', null, 404);
      }
    });
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
      $this->checkCourseAccess($courseId);

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
   * Получение теста модуля
   */
  public function getModuleTest($courseId, $moduleId)
  {
    error_log("ModuleController::getModuleTest called with courseId: {$courseId}, moduleId: {$moduleId}");
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) use ($moduleId) {
      // Преобразуем moduleId в число
      $moduleId = (int)$moduleId;
      if ($moduleId <= 0) {
        error_log("Invalid module ID: " . $moduleId);
        return $this->jsonResponse(false, 'Invalid module ID', null, 400);
      }

      // Проверяем принадлежность модуля
      if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
        error_log("User {$userId} does not own module {$moduleId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      // Получаем тест модуля
      $test = $this->testModel->findByModuleId($moduleId);

      if ($test) {
        error_log("Test found for module: " . $moduleId);
        return $this->jsonResponse(true, 'Module test retrieved successfully', $test);
      } else {
        error_log("Test not found for module: " . $moduleId);
        return $this->jsonResponse(false, 'Module test not found', null, 404);
      }
    });
  }

  /**
   * Создание или обновление теста урока
   */
  public function saveTest($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::saveTest called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
    return $this->handleLessonRequest($courseId, $moduleId, $lessonId, function ($courseId, $moduleId, $lessonId, $userId) {
      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) {
        error_log("Invalid JSON data received for test");
        return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
      }

      error_log("Saving test for lesson {$lessonId} with data: " . json_encode($input));
      $this->checkCourseAccess($courseId);

      // Устанавливаем заголовок по умолчанию если не указан
      if (empty($input['title'])) {
        $input['title'] = 'Тест к уроку';
      }

      // Валидация данных теста
      $errors = $this->validateTestData($input);
      if (!empty($errors)) {
        error_log("Test validation errors: " . json_encode($errors));
        return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
      }

      // Добавляем lesson_id к данным теста
      $input['lesson_id'] = $lessonId;

      // Сохраняем тест
      $result = $this->testModel->saveTest($lessonId, $input);

      if ($result) {
        $test = $this->testModel->findByLessonId($lessonId);
        error_log("Test saved successfully for lesson: " . $lessonId);
        return $this->jsonResponse(true, 'Test saved successfully', $test);
      } else {
        error_log("Failed to save test for lesson: " . $lessonId);
        return $this->jsonResponse(false, 'Failed to save test', null, 500);
      }
    });
  }

  /**
   * Создание или обновление теста модуля
   */
  public function saveModuleTest($courseId, $moduleId)
  {
    error_log("ModuleController::saveModuleTest called with courseId: {$courseId}, moduleId: {$moduleId}");
    
    try {
      return $this->handleModuleRequest($courseId, function ($courseId, $userId) use ($moduleId) {
        // Преобразуем moduleId в число
        $moduleId = (int)$moduleId;
        if ($moduleId <= 0) {
          error_log("Invalid module ID: " . $moduleId);
          return $this->jsonResponse(false, 'Invalid module ID', null, 400);
        }

        // Проверяем принадлежность модуля
        if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
          error_log("User {$userId} does not own module {$moduleId}");
          return $this->jsonResponse(false, 'Access denied', null, 403);
        }

        // Получение данных
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
          error_log("Invalid JSON data received for module test");
          return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
        }

        error_log("Saving test for module {$moduleId} with data: " . json_encode($input));

        // Устанавливаем заголовок по умолчанию если не указан
        if (empty($input['title'])) {
          $input['title'] = 'Итоговый тест модуля';
        }

        // Валидация данных теста
        $errors = $this->validateTestData($input);
        if (!empty($errors)) {
          error_log("Module test validation errors: " . json_encode($errors));
          return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
        }

        // Сохраняем тест модуля
        $result = $this->testModel->saveModuleTest($moduleId, $input);

        if ($result) {
          $test = $this->testModel->findByModuleId($moduleId);
          error_log("Module test saved successfully for module: " . $moduleId);
          return $this->jsonResponse(true, 'Module test saved successfully', $test);
        } else {
          error_log("Failed to save module test for module: " . $moduleId);
          return $this->jsonResponse(false, 'Failed to save module test', null, 500);
        }
      });
    } catch (Exception $e) {
      error_log("Exception in saveModuleTest: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error: ' . $e->getMessage(), null, 500);
    }
  }

  /**
   * Удаление теста урока
   */
  public function deleteTest($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::deleteTest called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
    return $this->handleLessonRequest($courseId, $moduleId, $lessonId, function ($courseId, $moduleId, $lessonId, $userId) {
      // Удаляем тест
      $result = $this->testModel->deleteByLessonId($lessonId);

      if ($result) {
        error_log("Test deleted successfully for lesson: " . $lessonId);
        return $this->jsonResponse(true, 'Test deleted successfully');
      } else {
        error_log("Test not found or already deleted for lesson: " . $lessonId);
        return $this->jsonResponse(false, 'Test not found or already deleted', null, 404);
      }
    });
  }

  /**
   * Удаление теста модуля
   */
  public function deleteModuleTest($courseId, $moduleId)
  {
    error_log("ModuleController::deleteModuleTest called with courseId: {$courseId}, moduleId: {$moduleId}");
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) use ($moduleId) {
      // Преобразуем moduleId в число
      $moduleId = (int)$moduleId;
      if ($moduleId <= 0) {
        error_log("Invalid module ID: " . $moduleId);
        return $this->jsonResponse(false, 'Invalid module ID', null, 400);
      }

      // Проверяем принадлежность модуля
      if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
        error_log("User {$userId} does not own module {$moduleId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      // Удаляем тест модуля
      $result = $this->testModel->deleteByModuleId($moduleId);

      if ($result) {
        error_log("Module test deleted successfully for module: " . $moduleId);
        return $this->jsonResponse(true, 'Module test deleted successfully');
      } else {
        error_log("Module test not found or already deleted for module: " . $moduleId);
        return $this->jsonResponse(false, 'Module test not found or already deleted', null, 404);
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
      $this->checkCourseAccess($courseId);

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
   * Получение теста модуля для студента (публичный доступ)
   */
  public function getModuleTestPublic($courseId, $moduleId)
  {
    error_log("ModuleController::getModuleTestPublic called with courseId: {$courseId}, moduleId: {$moduleId}");

    try {
      // Преобразуем ID в числа
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;

      if ($courseId <= 0 || $moduleId <= 0) {
        error_log("Invalid IDs - courseId: {$courseId}, moduleId: {$moduleId}");
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем, существует ли курс и модуль
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

      $user = $this->checkCourseAccess($courseId);
      if (!$user || !is_array($user)) {
        return $this->jsonResponse(false, 'Authentication required', null, 401);
      }

      $userId = $user['id'];

      // Проверяем принадлежность
      if ($module['course_id'] != $courseId) {
        error_log("Invalid course-module relationship");
        return $this->jsonResponse(false, 'Invalid relationship', null, 404);
      }

      // Проверяем, завершены ли все уроки модуля
      if (!$this->userProgressModel->areAllModuleLessonsCompleted($userId, $moduleId)) {
        error_log("Student {$userId} has not completed all lessons in module {$moduleId}");
        return $this->jsonResponse(false, 'Барлық сабақтарды аяқтау керек', null, 403);
      }

      // Получаем тест модуля (только вопросы без правильных ответов)
      $test = $this->testModel->findByModuleIdForStudent($moduleId);

      if ($test) {
        error_log("Module test found for module: " . $moduleId);
        return $this->jsonResponse(true, 'Module test retrieved successfully', $test);
      } else {
        error_log("Module test not found for module: " . $moduleId);
        return $this->jsonResponse(false, 'Module test not found', null, 404);
      }
    } catch (Exception $e) {
      error_log("Module test get public error: " . $e->getMessage());
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
      $this->checkCourseAccess($courseId);

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
   * Отправка теста модуля студентом
   */
  public function submitModuleTest($courseId, $moduleId)
  {
    error_log("ModuleController::submitModuleTest called with courseId: {$courseId}, moduleId: {$moduleId}");

    try {
      // Преобразуем ID в числа
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;

      if ($courseId <= 0 || $moduleId <= 0) {
        error_log("Invalid IDs - courseId: {$courseId}, moduleId: {$moduleId}");
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input || !isset($input['answers'])) {
        error_log("Invalid module test submission data");
        return $this->jsonResponse(false, 'Invalid test data', null, 400);
      }

      // Проверяем, существует ли курс и модуль
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

      $user = $this->checkCourseAccess($courseId);
      if (!$user || !is_array($user)) {
        return $this->jsonResponse(false, 'Authentication required', null, 401);
      }

      $studentId = $user['id'] ?? null;
      if (!$studentId) {
        error_log("Student ID not found in user data");
        return $this->jsonResponse(false, 'Student not authenticated', null, 401);
      }

      error_log("Student authenticated with ID: " . $studentId);

      // Проверяем принадлежность
      if ($module['course_id'] != $courseId) {
        error_log("Invalid course-module relationship");
        return $this->jsonResponse(false, 'Invalid relationship', null, 404);
      }

      // Проверяем, завершены ли все уроки модуля
      if (!$this->userProgressModel->areAllModuleLessonsCompleted($studentId, $moduleId)) {
        error_log("Student {$studentId} has not completed all lessons in module {$moduleId}");
        return $this->jsonResponse(false, 'Барлық сабақтарды аяқтау керек', null, 403);
      }

      // Получаем тест модуля
      $test = $this->testModel->findByModuleId($moduleId);
      if (!$test) {
        error_log("Module test not found for module: " . $moduleId);
        return $this->jsonResponse(false, 'Module test not found', null, 404);
      }

      // Проверяем ответы
      $result = $this->testModel->checkModuleAnswers($moduleId, $input['answers']);

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

          // Если тест пройден успешно (>= 50%), открываем доступ к следующему модулю
          $minScore = 50;
          if ($result['score'] >= $minScore) {
            // Обновляем прогресс модуля - отмечаем что модуль пройден
            // Это важно для расчета общего прогресса курса
            error_log("Module test passed with score: {$result['score']}%, updating module progress");
            
            // Прогресс модуля автоматически обновится через test_results таблицу
            // getUserProgress учитывает завершенные уроки, а тест модуля - это дополнительная проверка
            // Инвалидируем кеш прогресса, чтобы обновить данные
            error_log("Module test completed - progress will be recalculated on next getUserProgress call");
            
            // Получаем следующий модуль в курсе
            $nextModule = $this->getNextModule($courseId, $moduleId);
            if ($nextModule) {
              $result['next_module'] = $nextModule;
              $result['module_completed'] = true;
              error_log("Module {$moduleId} completed successfully, next module: {$nextModule['id']}");
            } else {
              $result['course_completed'] = true;
              error_log("Module {$moduleId} completed successfully, course completed");
            }
          }

          error_log("Module test submitted and saved successfully for module: " . $moduleId . ", result ID: " . $testResultId);
          return $this->jsonResponse(true, 'Module test submitted successfully', $result);
        } else {
          error_log("Failed to save module test result for module: " . $moduleId);
          return $this->jsonResponse(false, 'Failed to save module test result', null, 500);
        }
      } else {
        error_log("Failed to check module test answers for module: " . $moduleId);
        return $this->jsonResponse(false, 'Failed to check module test answers', null, 500);
      }
    } catch (Exception $e) {
      error_log("Module test submit error: " . $e->getMessage());
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
  public function getTestAttempts($courseId, $moduleId, $lessonId)
  {
    try {
      // Проверка прав доступа и валидация параметров
      $user = $this->getUserFromRequest();


      $studentId = $user['id'] ?? null;

      $this->checkCourseAccess($courseId);

      $test = $this->testModel->findByLessonId($lessonId);
      if (!$test) {
        return $this->jsonResponse(false, 'Test not found', null, 404);
      }
      $this->checkCourseAccess($courseId);

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
      $this->checkCourseAccess($courseId);


      $testResultModel = new TestResult();
      $bestResult = $testResultModel->getBestResult($studentId, $test['id']);

      return $this->jsonResponse(true, 'Best result retrieved successfully', $bestResult);
    } catch (Exception $e) {
      error_log("Get best result error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }
  public function uploadQuestionImage($courseId, $moduleId, $lessonId)
  {
    $uploadDir = '/uploads/test-questions/';
    $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $uploadDir;

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
      return $this->jsonResponse(false, 'Файл не загружен или ошибка загрузки', null, 400);
    }

    $uploadedFile = $_FILES['image'];

    // Проверка типа файла
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($uploadedFile['tmp_name']);

    if (!in_array($fileType, $allowedTypes)) {
      return $this->jsonResponse(false, 'Допустимы только JPEG, PNG, GIF, WebP', null, 400);
    }

    // Проверка размера файла (макс. 5MB)
    if ($uploadedFile['size'] > 5 * 1024 * 1024) {
      return $this->jsonResponse(false, 'Файл не должен превышать 5MB', null, 400);
    }

    // Создаем директорию если не существует
    if (!is_dir($absolutePath)) {
      if (!mkdir($absolutePath, 0755, true)) {
        return $this->jsonResponse(false, 'Ошибка создания директории', null, 500);
      }
    }

    // Проверяем права на запись
    if (!is_writable($absolutePath)) {
      if (!chmod($absolutePath, 0755)) {
        return $this->jsonResponse(false, 'Директория недоступна для записи', null, 500);
      }
    }

    // Генерируем уникальное имя файла
    $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    $fileName = uniqid('test_img_') . '.' . $fileExtension;
    $filePath = $absolutePath . $fileName;

    // Перемещаем загруженный файл
    if (move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
      if (file_exists($filePath)) {
        $imageUrl = $uploadDir . $fileName;

        return $this->jsonResponse(true, 'Изображение загружено', [
          'image_url' => $imageUrl,
          'file_name' => $fileName,
          'file_size' => filesize($filePath)
        ]);
      } else {
        return $this->jsonResponse(false, 'Ошибка сохранения файла', null, 500);
      }
    } else {
      return $this->jsonResponse(false, 'Ошибка сохранения файла', null, 500);
    }
  }

  /**
   * Загрузка PDF файла для урока (при создании нового урока)
   */
  public function uploadPdf($courseId, $moduleId)
  {
    error_log("ModuleController::uploadPdf called with courseId: {$courseId}, moduleId: {$moduleId}");
    return $this->handleModuleRequest($courseId, function ($courseId, $userId) use ($moduleId) {
      $moduleId = (int)$moduleId;
      if ($moduleId <= 0) {
        return $this->jsonResponse(false, 'Invalid module ID', null, 400);
      }

      // Проверяем принадлежность модуля
      if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        return $this->jsonResponse(false, 'PDF файл не загружен или ошибка загрузки', null, 400);
      }

      $uploadedFile = $_FILES['pdf'];

      // Проверка типа файла
      $allowedTypes = ['application/pdf'];
      $fileType = mime_content_type($uploadedFile['tmp_name']);

      if (!in_array($fileType, $allowedTypes)) {
        return $this->jsonResponse(false, 'Допустимы только PDF файлы', null, 400);
      }

      // Проверка размера файла (макс. 10MB)
      if ($uploadedFile['size'] > 10 * 1024 * 1024) {
        return $this->jsonResponse(false, 'PDF файл не должен превышать 10MB', null, 400);
      }

      // Проверяем права на запись
      if (!is_writable($this->pdfUploadPath)) {
        if (!chmod($this->pdfUploadPath, 0755)) {
          return $this->jsonResponse(false, 'Директория недоступна для записи', null, 500);
        }
      }

      // Генерируем уникальное имя файла
      $fileExtension = 'pdf';
      $fileName = uniqid('lesson_pdf_') . '.' . $fileExtension;
      $filePath = $this->pdfUploadPath . $fileName;

      // Перемещаем загруженный файл
      if (move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
        if (file_exists($filePath)) {
          $pdfUrl = '/uploads/lessons/pdfs/' . $fileName;

          error_log("PDF uploaded successfully: " . $pdfUrl);
          return $this->jsonResponse(true, 'PDF файл успешно загружен', [
            'pdf_url' => $pdfUrl,
            'file_name' => $fileName,
            'file_size' => filesize($filePath)
          ]);
        } else {
          return $this->jsonResponse(false, 'Ошибка сохранения файла', null, 500);
        }
      } else {
        return $this->jsonResponse(false, 'Ошибка сохранения файла', null, 500);
      }
    });
  }

  /**
   * Загрузка PDF файла для существующего урока
   */
  public function uploadPdfForLesson($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::uploadPdfForLesson called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");
    return $this->handleLessonRequest($courseId, $moduleId, $lessonId, function ($courseId, $moduleId, $lessonId, $userId) {
      $lessonId = (int)$lessonId;
      if ($lessonId <= 0) {
        return $this->jsonResponse(false, 'Invalid lesson ID', null, 400);
      }

      // Проверяем принадлежность урока
      if (!$this->lessonModel->isOwnedByUser($lessonId, $userId)) {
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        return $this->jsonResponse(false, 'PDF файл не загружен или ошибка загрузки', null, 400);
      }

      $uploadedFile = $_FILES['pdf'];

      // Проверка типа файла
      $allowedTypes = ['application/pdf'];
      $fileType = mime_content_type($uploadedFile['tmp_name']);

      if (!in_array($fileType, $allowedTypes)) {
        return $this->jsonResponse(false, 'Допустимы только PDF файлы', null, 400);
      }

      // Проверка размера файла (макс. 10MB)
      if ($uploadedFile['size'] > 10 * 1024 * 1024) {
        return $this->jsonResponse(false, 'PDF файл не должен превышать 10MB', null, 400);
      }

      // Получаем старый урок, чтобы удалить старый PDF если есть
      $oldLesson = $this->lessonModel->findById($lessonId);
      if ($oldLesson && !empty($oldLesson['pdf_url'])) {
        $oldPdfPath = $_SERVER['DOCUMENT_ROOT'] . $oldLesson['pdf_url'];
        if (file_exists($oldPdfPath) && is_file($oldPdfPath)) {
          unlink($oldPdfPath);
          error_log("Old PDF deleted: " . $oldLesson['pdf_url']);
        }
      }

      // Проверяем права на запись
      if (!is_writable($this->pdfUploadPath)) {
        if (!chmod($this->pdfUploadPath, 0755)) {
          return $this->jsonResponse(false, 'Директория недоступна для записи', null, 500);
        }
      }

      // Генерируем уникальное имя файла
      $fileExtension = 'pdf';
      $fileName = uniqid('lesson_pdf_') . '.' . $fileExtension;
      $filePath = $this->pdfUploadPath . $fileName;

      // Перемещаем загруженный файл
      if (move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
        if (file_exists($filePath)) {
          $pdfUrl = '/uploads/lessons/pdfs/' . $fileName;

          error_log("PDF uploaded successfully for lesson {$lessonId}: " . $pdfUrl);
          return $this->jsonResponse(true, 'PDF файл успешно загружен', [
            'pdf_url' => $pdfUrl,
            'file_name' => $fileName,
            'file_size' => filesize($filePath)
          ]);
        } else {
          return $this->jsonResponse(false, 'Ошибка сохранения файла', null, 500);
        }
      } else {
        return $this->jsonResponse(false, 'Ошибка сохранения файла', null, 500);
      }
    });
  }

  /**
   * Прокси для загрузки PDF файла урока (для обхода CORS)
   */
  public function getLessonPdf($courseId, $moduleId, $lessonId)
  {
    error_log("ModuleController::getLessonPdf called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: {$lessonId}");

    try {
      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
      }

      // Проверка доступа к курсу
      $this->checkCourseAccess($courseId);

      // Получаем урок
      $lesson = $this->lessonModel->findById($lessonId);
      if (!$lesson) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Lesson not found']);
        exit;
      }

      // Проверяем принадлежность урока модулю и курсу
      if ($lesson['module_id'] != $moduleId) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Lesson not found in module']);
        exit;
      }

      // Проверяем наличие PDF
      if (empty($lesson['pdf_url'])) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'PDF not found']);
        exit;
      }

      // Формируем путь к файлу
      $pdfPath = $_SERVER['DOCUMENT_ROOT'] . $lesson['pdf_url'];

      if (!file_exists($pdfPath) || !is_file($pdfPath)) {
        error_log("PDF file not found: " . $pdfPath);
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'PDF file not found']);
        exit;
      }

      // Устанавливаем CORS заголовки
      header("Access-Control-Allow-Origin: *");
      header("Access-Control-Allow-Methods: GET, OPTIONS");
      header("Access-Control-Allow-Headers: Content-Type, Authorization");

      // Устанавливаем заголовки для PDF
      header('Content-Type: application/pdf');
      header('Content-Disposition: inline; filename="' . basename($pdfPath) . '"');
      header('Content-Length: ' . filesize($pdfPath));
      header('Cache-Control: public, max-age=3600');

      // Отправляем файл
      readfile($pdfPath);
      exit;
    } catch (Exception $e) {
      error_log("Error serving PDF: " . $e->getMessage());
      http_response_code(500);
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'message' => 'Internal server error']);
      exit;
    }
  }

  /**
   * Общий метод для обработки запросов к модулям
   */
  private function handleModuleRequest($courseId, $callback)
  {
    try {
      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'];
      $userRole = $user['role'];

      // Только преподаватели и администраторы могут управлять курсами
      if (!in_array($userRole, ['teacher', 'admin'])) {
        return $this->jsonResponse(false, 'Access denied. Teacher role required.', null, 403);
      }

      $courseId = (int)$courseId;
      if ($courseId <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Проверяем доступ преподавателя к курсу
      if ($userRole === 'teacher' && !$this->checkTeacherAccess($courseId, $userId)) {
        return $this->jsonResponse(false, 'Access denied to this course', null, 403);
      }

      // Для администраторов проверяем существование курса
      if ($userRole === 'admin') {
        $course = $this->courseModel->findById($courseId);
        if (!$course) {
          return $this->jsonResponse(false, 'Course not found', null, 404);
        }
      }

      // Вызываем callback функцию
      return $callback($courseId, $userId);
    } catch (Exception $e) {
      error_log("Module request error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Общий метод для обработки запросов к урокам
   */
  private function handleLessonRequest($courseId, $moduleId, $lessonId, $callback)
  {
    try {
      error_log("handleLessonRequest called with courseId: {$courseId}, moduleId: {$moduleId}, lessonId: " . ($lessonId ?? 'null'));

      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        error_log("User not authenticated");
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;
      error_log("Authenticated user ID: " . $userId);


      // Преобразуем ID в числа
      $courseId = (int)$courseId;
      $moduleId = (int)$moduleId;
      $lessonId = $lessonId ? (int)$lessonId : null;

      if ($courseId <= 0 || $moduleId <= 0) {
        error_log("Invalid IDs - courseId: {$courseId}, moduleId: {$moduleId}");
        return $this->jsonResponse(false, 'Invalid IDs', null, 400);
      }

      // Проверяем принадлежность модуля
      if (!$this->moduleModel->isOwnedByUser($moduleId, $userId)) {
        error_log("User {$userId} does not own module {$moduleId}");
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      error_log("User authorized for module: " . $moduleId);

      // Вызываем callback функцию
      return $callback($courseId, $moduleId, $lessonId, $userId);
    } catch (Exception $e) {
      error_log("Lesson request error: " . $e->getMessage());
      error_log("Stack trace: " . $e->getTraceAsString());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Валидация данных модуля
   */
  private function validateModuleData($data)
  {
    $errors = [];

    if (empty($data['title'])) {
      $errors['title'] = 'Title is required';
    } elseif (strlen($data['title']) > 255) {
      $errors['title'] = 'Title must be less than 255 characters';
    }

    if (!empty($data['description']) && strlen($data['description']) > 500) {
      $errors['description'] = 'Description must be less than 500 characters';
    }

    /*if (empty($data['course_id']) || !is_numeric($data['course_id'])) {
      $errors['course_id'] = 'Valid course ID is required';
    }*/

    return $errors;
  }

  /**
   * Валидация данных урока
   */
  private function validateLessonData($data, $isUpdate = false)
  {
    $errors = [];

    if (empty($data['title'])) {
      $errors['title'] = 'Title is required';
    } elseif (strlen($data['title']) > 255) {
      $errors['title'] = 'Title must be less than 255 characters';
    }

    // PDF файл обязателен только при создании нового урока
    // При обновлении он может отсутствовать, если пользователь не меняет PDF
    if (!$isUpdate && empty($data['pdf_url'])) {
      $errors['pdf_url'] = 'PDF file is required';
    } elseif (!empty($data['pdf_url']) && strlen($data['pdf_url']) > 500) {
      $errors['pdf_url'] = 'PDF URL must be less than 500 characters';
    }

    if (!empty($data['video_url']) && !filter_var($data['video_url'], FILTER_VALIDATE_URL)) {
      $errors['video_url'] = 'Video URL must be a valid URL';
    }

    if (empty($data['module_id']) || !is_numeric($data['module_id'])) {
      $errors['module_id'] = 'Valid module ID is required';
    }

    return $errors;
  }

  /**
   * Валидация данных теста
   */
  private function validateTestData($data)
  {
    $errors = [];

    // Базовая валидация заголовка
    if (empty($data['title'])) {
      $errors['title'] = 'Title is required';
    }

    // Валидация вопросов
    if (empty($data['questions']) || !is_array($data['questions'])) {
      $errors['questions'] = 'At least one question is required';
    } else {
      foreach ($data['questions'] as $index => $question) {
        $questionErrors = $this->validateQuestion($question, $index);
        if (!empty($questionErrors)) {
          $errors["questions.{$index}"] = $questionErrors;
        }
      }
    }

    return $errors;
  }
  private function validateQuestion($question, $index)
  {
    $errors = [];

    // Базовая валидация текста вопроса
    if (empty($question['text'])) {
      $errors['text'] = 'Question text is required';
    }
    // Валидация изображения (если есть)
    if (!empty($question['image_url']) && !filter_var($question['image_url'], FILTER_VALIDATE_URL)) {
      // Проверяем, что это локальный путь к файлу
      if (!preg_match('/^\/uploads\/test-questions\/[a-zA-Z0-9_\-\.]+$/', $question['image_url'])) {
        $errors['image_url'] = 'Invalid image URL format';
      }
    }
    // Валидация типа вопроса
    $validTypes = ['single_choice', 'multiple_choice', 'matching', 'true_false'];
    $questionType = $question['type'] ?? 'single_choice';

    if (!in_array($questionType, $validTypes)) {
      $errors['type'] = 'Invalid question type';
      return $errors;
    }

    // Валидация в зависимости от типа вопроса
    switch ($questionType) {
      case 'single_choice':
      case 'multiple_choice':
        $errors = array_merge($errors, $this->validateChoiceQuestion($question, $index));
        break;

      case 'matching':
        $errors = array_merge($errors, $this->validateMatchingQuestion($question, $index));
        break;

      case 'true_false':
        $errors = array_merge($errors, $this->validateTrueFalseQuestion($question, $index));
        break;
    }

    return $errors;
  }
  /**
   * Валидация вопросов с выбором ответа (одиночный/множественный)
   */
  private function validateChoiceQuestion($question, $index)
  {
    $errors = [];

    if (empty($question['answers']) || !is_array($question['answers'])) {
      $errors['answers'] = 'At least two answers are required';
      return $errors;
    }

    if (count($question['answers']) < 2) {
      $errors['answers'] = 'At least two answers are required';
    }

    $hasCorrectAnswer = false;
    foreach ($question['answers'] as $answerIndex => $answer) {
      if (empty($answer['text'])) {
        $errors["answers.{$answerIndex}.text"] = 'Answer text is required';
      }

      // Проверяем наличие правильного ответа
      if (isset($answer['is_correct']) && $answer['is_correct']) {
        $hasCorrectAnswer = true;
      }
    }

    if (!$hasCorrectAnswer) {
      $errors['answers'] = 'At least one correct answer is required';
    }

    return $errors;
  }

  /**
   * Валидация вопроса на соответствие
   */
  private function validateMatchingQuestion($question, $index)
  {
    $errors = [];

    if (empty($question['pairs']) || !is_array($question['pairs'])) {
      $errors['pairs'] = 'At least two pairs are required for matching question';
      return $errors;
    }

    if (count($question['pairs']) < 2) {
      $errors['pairs'] = 'At least two pairs are required';
    }

    foreach ($question['pairs'] as $pairIndex => $pair) {
      if (empty($pair['left_item'])) {
        $errors["pairs.{$pairIndex}.left_item"] = 'Left item is required';
      }

      if (empty($pair['right_item'])) {
        $errors["pairs.{$pairIndex}.right_item"] = 'Right item is required';
      }
    }

    return $errors;
  }

  /**
   * Валидация вопроса верно/неверно
   */
  private function validateTrueFalseQuestion($question, $index)
  {
    $errors = [];

    // Для вопросов верно/неверно автоматически создаем ответы
    // поэтому не требуем наличия answers в input

    // Можно добавить дополнительную валидацию если нужно
    // Например, проверку на наличие правильного ответа в будущем

    return $errors;
  }

  /**
   * Обработка вложений
   */
  private function processAttachments($base64Files)
  {
    $attachments = [];
    error_log("Processing " . count($base64Files) . " attachments");

    foreach ($base64Files as $index => $base64File) {
      if (!preg_match('/^data:(.*?);base64,/', $base64File, $matches)) {
        error_log("Invalid base64 format for attachment {$index}");
        continue;
      }

      $mimeType = $matches[1];
      $base64Data = str_replace($matches[0], '', $base64File);
      $fileData = base64_decode($base64Data);

      if ($fileData === false) {
        error_log("Failed to decode base64 for attachment {$index}");
        continue;
      }

      // Проверяем размер файла (максимум 10MB)
      if (strlen($fileData) > 10 * 1024 * 1024) {
        error_log("Attachment {$index} exceeds size limit: " . strlen($fileData) . " bytes");
        continue;
      }

      // Определяем расширение файла
      $extension = $this->getExtensionFromMimeType($mimeType);
      $filename = uniqid() . '_' . time() . '.' . $extension;
      $filePath = $this->uploadPath . $filename;

      if (file_put_contents($filePath, $fileData)) {
        $attachments[] = [
          'name' => $filename,
          'path' => '/uploads/lessons/attachments/' . $filename,
          'size' => strlen($fileData),
          'type' => $mimeType
        ];
        error_log("Attachment {$index} saved successfully: " . $filename);
      } else {
        error_log("Failed to save attachment {$index}: " . $filename);
      }
    }

    error_log("Successfully processed " . count($attachments) . " attachments");
    return $attachments;
  }
  /**
   * Получение расширения из MIME типа
   */
  private function getExtensionFromMimeType($mimeType)
  {
    $mimeMap = [
      'application/pdf' => 'pdf',
      'application/msword' => 'doc',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.ms-powerpoint' => 'ppt',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
      'text/plain' => 'txt',
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'application/zip' => 'zip',
      'application/x-rar-compressed' => 'rar'
    ];

    return $mimeMap[$mimeType] ?? 'bin';
  }
  /**
   * Удаление файла
   */
  private function deleteFile($filePath)
  {
    if (!empty($filePath)) {
      $fullPath = $_SERVER['DOCUMENT_ROOT'] . $filePath;
      if (file_exists($fullPath) && is_file($fullPath)) {
        if (unlink($fullPath)) {
          error_log("File deleted successfully: " . $filePath);
        } else {
          error_log("Failed to delete file: " . $filePath);
        }
      } else {
        error_log("File not found: " . $filePath);
      }
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
      error_log("No authorization token found");
      return null;
    }

    error_log("Authorization token found, length: " . strlen($token));
    return $this->getUserFromToken($token);
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

  /**
   * Получить следующий модуль в курсе
   */
  private function getNextModule($courseId, $currentModuleId)
  {
    try {
      // Получаем все модули курса
      $modules = $this->moduleModel->findByCourseId($courseId);
      if (empty($modules)) {
        return null;
      }

      // Находим текущий модуль
      $currentIndex = -1;
      foreach ($modules as $index => $module) {
        if ($module['id'] == $currentModuleId) {
          $currentIndex = $index;
          break;
        }
      }

      // Если текущий модуль найден и есть следующий
      if ($currentIndex >= 0 && isset($modules[$currentIndex + 1])) {
        return $modules[$currentIndex + 1];
      }

      return null;
    } catch (Exception $e) {
      error_log("getNextModule error: " . $e->getMessage());
      return null;
    }
  }
}
