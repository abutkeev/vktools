<?php

require_once('include/vkTools.php');
if ($argc != 2) exit;
$tools = new vkTools();
$tools->merge_sessions($argv[1]);
