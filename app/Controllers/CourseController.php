<?php
class CourseController extends BaseAuth
{
  protected $courseModel;
  protected $enrollmentModel;
  protected $uploadPath;

  public function __construct()
  {
    parent::__construct();
    $this->courseModel = new Course();
    $this->enrollmentModel = new Enrollment();
    $this->uploadPath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/courses/previews/';

    if (!file_exists($this->uploadPath)) {
      mkdir($this->uploadPath, 0755, true);
    }
  }

  /**
   * Создание нового курса
   */
  public function create()
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

      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) {
        return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
      }

      // Обработка изображения
      $imagePath = null;
      if (!empty($input['preview_image'])) {
        $imagePath = $this->saveBase64Image($input['preview_image']);
        if (!$imagePath) {
          return $this->jsonResponse(false, 'Invalid image format', null, 400);
        }
        $input['preview_image'] = $imagePath;
      } else {
        $input['preview_image'] = null;
      }

      // Добавляем user_id к данным курса
      $input['user_id'] = $userId;

      // Валидация
      $errors = $this->validateCourseData($input);
      if (!empty($errors)) {
        if (!empty($imagePath)) {
          $this->deleteImage($imagePath);
        }
        return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
      }

      // Создание курса
      $courseId = $this->courseModel->create($input);

      if ($courseId) {
        $course = $this->courseModel->findById($courseId);
        if (!empty($course['preview_image'])) {
          $course['preview_image_url'] = $this->getFullImageUrl($course['preview_image']);
        }

        return $this->jsonResponse(true, 'Course created successfully', [
          'id' => $courseId,
          'course' => $course
        ], 201);
      } else {
        if (!empty($imagePath)) {
          $this->deleteImage($imagePath);
        }
        return $this->jsonResponse(false, 'Failed to create course', null, 500);
      }
    } catch (Exception $e) {
      error_log("Course creation error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение курса по ID с проверкой доступа
   */
  public function show($id)
  {
    try {
      if (!is_numeric($id) || $id <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      $user = $this->getUserFromRequest();
      $course = $this->courseModel->findById($id);

      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем, записан ли пользователь на курс
      $isEnrolled = false;
      if ($user) {
        $enrollment = $this->enrollmentModel->findByUserAndCourse($user['id'], $id);
        $isEnrolled = $enrollment && $enrollment['status'] === 'approved';
      }

      // Если пользователь не записан, возвращаем ограниченную информацию
      if (!$isEnrolled) {
        $limitedCourse = [
          'id' => $course['id'],
          'title' => $course['title'],
          'description' => $course['description'],
          'preview_image_url' => !empty($course['preview_image'])
            ? $this->getFullImageUrl($course['preview_image'])
            : null,
          'price' => $course['price'],
          'duration' => $course['duration'],
          'level' => $course['level'],
          'is_enrolled' => false,
          'access_restricted' => true
        ];
        return $this->jsonResponse(true, 'Course found (limited access)', $limitedCourse);
      }

      // Полная информация для записанных пользователей
      if (!empty($course['preview_image'])) {
        $course['preview_image_url'] = $this->getFullImageUrl($course['preview_image']);
      }
      $course['is_enrolled'] = true;

      return $this->jsonResponse(true, 'Course found', $course);
    } catch (Exception $e) {
      error_log("Course show error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение всех курсов
   */
  public function index()
  {
    try {
      $courses = $this->courseModel->findAll();

      foreach ($courses as &$course) {
        if (!empty($course['preview_image'])) {
          $course['preview_image_url'] = $this->getFullImageUrl($course['preview_image']);
        }
      }

      return $this->jsonResponse(true, 'Courses retrieved successfully', $courses);
    } catch (Exception $e) {
      error_log("Courses index error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }
  // Получение курсов в зависимости от роли пользователя
  public function teachercourse()
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;
      $userRole = $user['role'] ?? 'user';

      switch ($userRole) {
        case 'teacher':
          if (method_exists($this->courseModel, 'findByUserId')) {
            $courses = $this->courseModel->findByUserId($userId);
          } else {
            $courses = $this->courseModel->findAllBy('user_id', $userId);
          }
          break;

        case 'admin':
          $courses = $this->courseModel->findAll();
          break;

        case 'student':
        default:
          // Используем существующий метод вместо findPublishedCourses
          if (method_exists($this->courseModel, 'findAllBy')) {
            $courses = $this->courseModel->findAllBy('status', 'published');
          } else {
            $courses = $this->courseModel->findAll();
          }
          break;
      }

      // Обработка изображений
      foreach ($courses as &$course) {
        if (!empty($course['preview_image'])) {
          $course['preview_image_url'] = $this->getFullImageUrl($course['preview_image']);
        }
      }

      return $this->jsonResponse(true, 'Courses retrieved successfully', $courses);
    } catch (Exception $e) {
      error_log("Courses index error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }
  /**
   * Обновление курса
   */
  public function update($id)
  {
    try {
      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;

      if (!is_numeric($id) || $id <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      // Проверяем существование курса
      $existingCourse = $this->courseModel->findById($id);
      if (!$existingCourse) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      // Проверяем, принадлежит ли курс пользователю
      if ($existingCourse['user_id'] != $userId) {
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      // Получение данных
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) {
        return $this->jsonResponse(false, 'Invalid JSON data', null, 400);
      }

      $oldImagePath = null;
      $imagePath = null;

      // Обработка нового изображения
      if (!empty($input['preview_image'])) {
        $imagePath = $this->saveBase64Image($input['preview_image']);
        if (!$imagePath) {
          return $this->jsonResponse(false, 'Invalid image format', null, 400);
        }
        $input['preview_image'] = $imagePath;
        $oldImagePath = $existingCourse['preview_image'];
      } else {
        $input['preview_image'] = $existingCourse['preview_image'];
      }

      // Валидация
      $errors = $this->validateCourseData($input);
      if (!empty($errors)) {
        if (!empty($imagePath)) {
          $this->deleteImage($imagePath);
        }
        return $this->jsonResponse(false, 'Validation errors', ['errors' => $errors], 400);
      }

      // Обновление курса
      $result = $this->courseModel->update($id, $input);

      if ($result) {
        if ($oldImagePath && !empty($input['preview_image']) && $oldImagePath != $input['preview_image']) {
          $this->deleteImage($oldImagePath);
        }

        $updatedCourse = $this->courseModel->findById($id);
        if (!empty($updatedCourse['preview_image'])) {
          $updatedCourse['preview_image_url'] = $this->getFullImageUrl($updatedCourse['preview_image']);
        }

        return $this->jsonResponse(true, 'Course updated successfully', $updatedCourse);
      } else {
        if (!empty($imagePath)) {
          $this->deleteImage($imagePath);
        }
        return $this->jsonResponse(false, 'Failed to update course', null, 500);
      }
    } catch (Exception $e) {
      error_log("Course update error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Удаление курса
   */
  public function delete($id)
  {
    try {
      // Проверка аутентификации
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;

      if (!is_numeric($id) || $id <= 0) {
        return $this->jsonResponse(false, 'Invalid course ID', null, 400);
      }

      $course = $this->courseModel->findById($id);
      if (!$course) {
        return $this->jsonResponse(false, 'Course not found', null, 404);
      }

      if ($course['user_id'] != $userId) {
        return $this->jsonResponse(false, 'Access denied', null, 403);
      }

      $result = $this->courseModel->delete($id);

      if ($result) {
        if (!empty($course['preview_image'])) {
          $this->deleteImage($course['preview_image']);
        }
        return $this->jsonResponse(true, 'Course deleted successfully');
      } else {
        return $this->jsonResponse(false, 'Course not found or already deleted', null, 404);
      }
    } catch (Exception $e) {
      error_log("Course delete error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение бесплатных курсов
   */
  public function freeCourses()
  {
    try {
      $courses = $this->courseModel->findFreeCourses();

      foreach ($courses as &$course) {
        if (!empty($course['preview_image'])) {
          $course['preview_image_url'] = $this->getFullImageUrl($course['preview_image']);
        }
      }

      return $this->jsonResponse(true, 'Free courses retrieved successfully', $courses);
    } catch (Exception $e) {
      error_log("Free courses error: " . $e->getMessage());
      return $this->jsonResponse(false, 'Internal server error', null, 500);
    }
  }

  /**
   * Получение курсов текущего пользователя
   */
  public function myCourses()
  {
    try {
      $user = $this->getUserFromRequest();
      if (!$user) {
        return $this->jsonResponse(false, 'User not authenticated', null, 401);
      }

      $userId = $user['id'] ?? null;

      if (method_exists($this->courseModel, 'findByUserId')) {
        $courses = $this->courseModel->findByUserId($userId);
      } else {
        $courses = $this->courseModel->findAllBy('user_id', $userId);
      }

      foreach ($courses as &$course) {
        if (!empty($course['preview_image'])) {
          $course['preview_image_url'] = $this->getFullImageUrl($course['preview_image']);
        }
      }

      return $this->jsonResponse(true, 'User courses retrieved successfully', $courses);
    } catch (Exception $e) {
      error_log("My courses error: " . $e->getMessage());
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
   * Валидация данных курса
   */
  private function validateCourseData($data)
  {
    $errors = [];

    if (empty($data['title'])) {
      $errors['title'] = 'Title is required';
    } elseif (strlen($data['title']) > 255) {
      $errors['title'] = 'Title must be less than 255 characters';
    }

    if (empty($data['description'])) {
      $errors['description'] = 'Description is required';
    } elseif (strlen($data['description']) > 2000) {
      $errors['description'] = 'Description must be less than 2000 characters';
    }

    if (!isset($data['price']) || !is_numeric($data['price']) || $data['price'] < 0) {
      $errors['price'] = 'Price must be a non-negative number';
    }

    if (isset($data['is_free']) && !is_bool($data['is_free'])) {
      $errors['is_free'] = 'is_free must be a boolean value';
    }

    return $errors;
  }

  /**
   * Сохранение base64 изображения в файл
   */
  private function saveBase64Image($base64Image)
  {
    if (!preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/', $base64Image, $matches)) {
      return false;
    }

    $imageType = $matches[1];
    $base64Image = str_replace($matches[0], '', $base64Image);
    $imageData = base64_decode($base64Image);

    if ($imageData === false) {
      return false;
    }

    if (strlen($imageData) > 5 * 1024 * 1024) {
      return false;
    }

    $filename = uniqid() . '_' . time() . '.' . $imageType;
    $filePath = $this->uploadPath . $filename;

    if (file_put_contents($filePath, $imageData)) {
      return '/uploads/courses/previews/' . $filename;
    }

    return false;
  }

  /**
   * Удаление изображения
   */
  private function deleteImage($imagePath)
  {
    if (!empty($imagePath)) {
      $fullPath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;
      if (file_exists($fullPath) && is_file($fullPath)) {
        unlink($fullPath);
      }
    }
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
