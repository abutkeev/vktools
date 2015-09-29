<?php

require_once('include/vkTools.php');
Logger::init('save_online', 0);
$tools = new vkTools();
$tools->save_all_online();
$tools->merge_sessions(12*60);
