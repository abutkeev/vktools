<?php

require_once('include/vkTools.php');
require_once('include/Locker.php');

$l = new Locker('/run/lock/vktools/save_online.lock');

Logger::init('save_online', 0);

if ($argc == 2 && $argv[1] == 'debug')
  Logger::debug(true);

$tools = new vkTools();
$tools->save_all_online();
$tools->merge_sessions(12*60);
