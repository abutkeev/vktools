<?php
  final class Logger {
    static private $instance;
    static private $debug = false;

    static private $old_debug = false;

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
      elseif ($priority != LOG_DEBUG)
        syslog($priority, $message);
    }

    static function debug($state) {
      self::$debug = $state;
      self::$old_debug = $state;
    }

    static function temporary_debug_on() {
      self::$old_debug = self::$debug;

      self::$debug = true;
    }

    static function temporary_debug_off() {
      self::$debug = self::$old_debug;
    }
  }
?>
