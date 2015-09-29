<?php
require_once('include/vkTools.php');
require_once('include/tgTools.php');

Logger::init('tg_handler', 0);
Logger::debug(true);
try {
  Logger::log(LOG_DEBUG, 'starting');
  $tg_tools = new tgTools();
  $vk_tools = new vkTools();

  $message = $tg_tools->parseMessage($HTTP_RAW_POST_DATA);
  
  $tg_tools->saveUser($message->getFrom());

  $command = NULL;
  $text = $message->getText();
  $command = $tg_tools->getCommand($text);

  Logger::log(LOG_DEBUG, "command: $command");
  Logger::log(LOG_DEBUG, "text: $text");

  $tg_tools->processMessage($vk_tools, $command, $text, $message);

  Logger::log(LOG_DEBUG, 'finished');
} catch (\TelegramBot\Api\Exception $e) {
  Logger::log(LOG_ERR, $e->getMessage());
}


?>
