<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require __DIR__ . '/../vendor/autoload.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../config/db.php';

// Автозагрузка классов
spl_autoload_register(function ($class) {
  $paths = ['app/Controllers', 'app/Models', 'app/Middlewares', 'core'];
  foreach ($paths as $path) {
    $file = __DIR__ . '/../' . $path . '/' . $class . '.php';
    if (file_exists($file)) {
      require_once $file;
      return;
    }
  }
});

$router = new Router();

// Подключаем маршруты
require_once __DIR__ . '/../routes/api.php';

$router->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
