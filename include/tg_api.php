<?php
function __autoload($class_name) {
  global $prefix;
  if (strpos($class_name, 'TelegramBot\Api') == 0) {
    $file = str_replace('\\', '/', str_replace('TelegramBot\\Api\\', __DIR__.'/tg_api/src/', $class_name)). '.php';
    if (file_exists($file))
      include($file);
  }
}
include_once(__DIR__.'/tg_api/src/Types/Update.php');
include_once(__DIR__.'/tg_api/src/BaseType.php');
?>
