<?php

class Locker {
  private $fd;
  public function __construct($filename) {
    if (!file_exists(dirname($filename))) {
      if (!mkdir(dirname($filename), 01777, true)) {
        throw new Exception("Can't create parent directory for $filename");
      }
    }

    if (!$this->fd = fopen($filename, 'a')) {
      throw new Exception("Can't open $filename");
    }

    if (!flock($this->fd, LOCK_EX)) {
      throw new Exception("Can't lock $filename");
    }

    ftruncate($this->fd, 0);
    fwrite($this->fd, getmypid());
    fflush($this->fd);
  }

  public function __destruct() {
    ftruncate($this->fd, 0);
    flock($this->fd, LOCK_UN);
    fclose($this->fd);
  }
}
