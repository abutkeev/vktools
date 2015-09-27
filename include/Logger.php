<?php
  final class Logger {
    static private $instance;
    static public $debug = false;
    private function __construct($ident = 'Logger', $option = LOG_PERROR, $facility = LOG_LOCAL0) {
      openlog($ident, $option, $facility);
      self::$instance = $this;
    }

    static function init($ident, $option = 0, $facility = LOG_LOCAL0) {
      if (!isset(self::$instance)) {
        new self($ident, $option, $facility);
      }
    }
    static function log($priority, $message) {
      if (!isset(self::$instance))
        new self();
      if ($priority == LOG_DEBUG && self::$debug)
        syslog($priority, $message);
    }
  }
?>
