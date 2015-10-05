<?php
require_once('include/vkTools.php');
require_once('include/tgTools.php');

Logger::init('vk_handler', 0);
Logger::debug(true);
try {
  Logger::log(LOG_DEBUG, 'starting');
  $tg_tools = new tgTools();
  $vk_tools = new vkTools();

  if (!array_key_exists('code', $_REQUEST))
    throw new Exception('no code in request');

  if (!array_key_exists('state', $_REQUEST))
    throw new Exception('no state in request');

  $url = $tg_tools->processCode($vk_tools, $_REQUEST['code'], $_REQUEST['state']);

  header("Location: $url");

  Logger::log(LOG_DEBUG, 'finished');
} catch (Exception $e) {
  Logger::log(LOG_ERR, $e->getMessage());
  header('Content-Type: text/plain');
  print $e->getMessage();
}


?>
