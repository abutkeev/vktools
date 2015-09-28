<?php
require_once('include/vkTools.php');
require_once('include/tgTools.php');

use TelegramBot\Api\Types\Update;

Logger::init('tg_handler', 0);
Logger::$debug = true;
try {
  Logger::log(LOG_DEBUG, 'starting');
  $tg_tools = new tgTools();
  $vk_tools = new vkTools();

  $message = Update::fromResponse(TelegramBot\Api\BotApi::jsonValidate($HTTP_RAW_POST_DATA, true))->getMessage();
  
  $tg_tools->saveUser($message->getFrom());

  $command = NULL;
  $text = $message->getText();
  if ($text[0] == '/') {
     $tmp = explode(' ', $text);
     $command = array_shift($tmp);
     $text = implode(' ', $tmp);
  }
  Logger::log(LOG_DEBUG, "command: $command");
  Logger::log(LOG_DEBUG, "text: $text");

  switch ($command) {
    case '/watch':
      try {
        $user = $vk_tools->get_user($text, array());
        if ($tg_tools->watch($user->{'id'})) {
          $tg_tools->sendMessage('Пользователь ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') добавлен в список наблюдения', 'Markdown', true);
        } else {
          $tg_tools->sendMessage('Пользователь ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') уже есть в списке наблюдения', 'Markdown', true);
        }
      } catch (Exception $e) {
        if ($e->getCode() == 404) {
          $tg_tools->sendMessage('Пользователь не найден');
        } else {
          $tg_tools->sendFailMessage();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
      break;
    case '/notify':
      try {
        $user = $vk_tools->get_user($text, array());
        if ($tg_tools->addNotify($user->{'id'})) {
          $tg_tools->sendMessage('Уведомления для пользователя ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') включены', 'Markdown', true);
        } else {
          $tg_tools->sendMessage('Уведомления для пользователя ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') уже были включены', 'Markdown', true);
        }
      } catch (Exception $e) {
        if ($e->getCode() == 4404) {
          $tg_tools->sendMessage('Пользователь не в списке наблюдения');
        } elseif ($e->getCode() == 404) {
          $tg_tools->sendMessage('Пользователь не найден');
        } else {
          $tg_tools->sendFailMessage();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
      break;
    default:
      $tg_tools->sendFailMessage();
      break;
  }

  Logger::log(LOG_DEBUG, 'finished');
} catch (\TelegramBot\Api\Exception $e) {
  Logger::log(LOG_ERR, $e->getMessage());
}


?>
