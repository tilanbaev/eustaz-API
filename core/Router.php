<?php
class Router
{
  private $routes = [];

  public function add($method, $path, $callback)
  {
    $this->routes[] = [
      'method' => $method,
      'path' => $path,
      'callback' => $callback,
      'pattern' => $this->convertPathToPattern($path)
    ];
  }

  public function dispatch($method, $uri)
  {
    // Логируем для отладки
    error_log("=== ROUTER DISPATCH ===");
    error_log("Dispatching: $method $uri");

    // Убираем query параметры если есть
    $uri = strtok($uri, '?');

    // Убедимся что URI начинается с /
    if (strpos($uri, '/') !== 0) {
      $uri = '/' . $uri;
    }

    foreach ($this->routes as $route) {
      error_log("Checking route: {$route['method']} {$route['path']}");

      // Проверяем метод
      if ($route['method'] !== $method) {
        continue;
      }

      // Проверяем совпадение пути
      if (preg_match($route['pattern'], $uri, $matches)) {
        $params = [];
        foreach ($matches as $key => $value) {
          if (is_string($key)) {
            // Преобразуем числовые параметры в числа
            if (is_numeric($value)) {
              $params[$key] = (int)$value;
            } else {
              $params[$key] = $value;
            }
          }
        }

        error_log("ROUTE MATCHED: " . $route['path']);
        error_log("Params: " . print_r($params, true));

        try {
          // Если callback - массив [controller, method]
          if (is_array($route['callback'])) {
            $controller = $route['callback'][0];
            $methodName = $route['callback'][1];

            // Убираем лишние пробелы и невидимые символы из имени метода СРАЗУ
            if (is_string($methodName)) {
              $methodName = trim($methodName);
              $methodName = preg_replace('/[\x00-\x1F\x7F]/u', '', $methodName); // Удаляем невидимые символы
              $methodName = preg_replace('/\s+/', '', $methodName); // Удаляем все пробелы внутри строки
            }
            
            // Создаем экземпляр контроллера если нужно
            if (is_string($controller)) {
              $controller = new $controller();
            }
            
            // Проверяем что контроллер является объектом
            if (!is_object($controller)) {
              error_log("Controller is not an object: " . gettype($controller));
              http_response_code(500);
              header('Content-Type: application/json');
              echo json_encode([
                'success' => false,
                'message' => 'Invalid controller type: ' . gettype($controller)
              ]);
              exit;
            }
            
            // Проверяем существование и вызываемость метода ПЕРЕД вызовом - ОБЯЗАТЕЛЬНО
            if (!is_string($methodName) || empty($methodName)) {
              error_log("Method name is invalid: " . var_export($methodName, true));
              http_response_code(500);
              header('Content-Type: application/json');
              echo json_encode([
                'success' => false,
                'message' => 'Invalid method name'
              ]);
              exit;
            }
            
            // Используем is_callable для более надежной проверки
            $isCallable = is_callable([$controller, $methodName]);
            $methodExists = method_exists($controller, $methodName);
            
            if (!$isCallable || !$methodExists) {
              $availableMethods = get_class_methods($controller);
              $allPublicMethods = array_filter($availableMethods, function($method) {
                return strpos($method, '__') !== 0; // Исключаем магические методы
              });
              
              error_log("Method not callable: " . get_class($controller) . "::" . $methodName);
              error_log("Method exists: " . ($methodExists ? 'YES' : 'NO'));
              error_log("Is callable: " . ($isCallable ? 'YES' : 'NO'));
              error_log("Method name (trimmed): '" . $methodName . "'");
              error_log("Method name length: " . strlen($methodName));
              error_log("Method name bytes: " . bin2hex($methodName));
              error_log("Available public methods: " . implode(', ', array_slice($allPublicMethods, 0, 20)));
              error_log("Is method in list: " . (in_array($methodName, $availableMethods) ? 'YES' : 'NO'));
              
              http_response_code(500);
              header('Content-Type: application/json');
              echo json_encode([
                'success' => false,
                'message' => 'Method not found or not callable: ' . $methodName . ' in class ' . get_class($controller),
                'method_name' => $methodName,
                'method_name_hex' => bin2hex($methodName),
                'method_exists' => $methodExists,
                'is_callable' => $isCallable,
                'available_methods' => array_values(array_slice($allPublicMethods, 0, 50))
              ]);
              exit;
            }
            
            // Вызываем метод с параметрами
            return call_user_func_array([$controller, $methodName], array_values($params));
          } else {
            // Простой callback
            return call_user_func_array($route['callback'], array_values($params));
          }
        } catch (Exception $e) {
          error_log("Router callback error: " . $e->getMessage());
          error_log("Stack trace: " . $e->getTraceAsString());
          http_response_code(500);
          echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
          return;
        }
      }
    }

    error_log("NO ROUTE FOUND for: $method $uri");
    http_response_code(404);
    echo json_encode(['error' => 'Route not found']);
  }

  private function convertPathToPattern($path)
  {
    // Экранируем слэши
    $pattern = str_replace('/', '\/', $path);

    // Заменяем {param} на regex группы - используем \d+ для числовых ID
    $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>\d+)', $pattern);

    // Добавляем якоря
    return '/^' . $pattern . '$/';
  }

  // Метод для отладки - показать все зарегистрированные маршруты
  public function debugRoutes()
  {
    error_log("=== REGISTERED ROUTES ===");
    foreach ($this->routes as $route) {
      error_log("{$route['method']} {$route['path']} -> {$route['pattern']}");
    }
  }
}
