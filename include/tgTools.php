<?php
require_once('Logger.php');
require_once('Config.php');
include_once('tg_api.php');

use TelegramBot\Api\Types\User;

class tgTools extends TelegramBot\Api\BotApi{
  private $db;
  private $user_id;

  function __construct() {
    parent::__construct(Config::TG_TOKEN);

    $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8');
    $this->db  = new PDO('mysql:dbname='. Config::DB_NAME. ';host='. Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    Logger::init('tgTools', LOG_PERROR);
  }

  public function saveUser(User $user) {
    Logger::log(LOG_DEBUG, "saving user ". $user->getId());
    $this->db->prepare('REPLACE INTO tg_users (id, first_name, last_name, username) VALUES (:id, :first_name, :last_name, :username)')
      ->execute(array('id' => $user->getId(), 'first_name' => $user->getFirstName(), 'last_name' => $user->getLastName(), 'username' => $user->getUsername()));

    $this->user_id = $user->getId();
  }

  public function sendMessage($text, $parse_mode = null, $disablePreview = false) {
    parent::sendMessage($this->user_id, $text, $parse_mode, $disablePreview);
  }

  public function sendSuccessMessage() {
    $this->sendMessage('Команда выполнена успешно');
  }

  public function sendFailMessage() {
    $this->sendMessage('При выполнении команды произошла ошибка');
  }

  public function watch($user_id) {
    $sth = $this->db->prepare('SELECT * FROM watch WHERE tg_user_id = :tg_user_id AND vk_user_id = :vk_user_id');
    $sth->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    if ($sth->fetch())
      return false;

    $this->db->prepare('INSERT INTO watch (tg_user_id, vk_user_id) VALUES (:tg_user_id, :vk_user_id)')->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    return true;
  }

  public function addNotify($user_id) {
    $sth = $this->db->prepare('SELECT * FROM watch WHERE tg_user_id = :tg_user_id AND vk_user_id = :vk_user_id');
    $sth->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    if (!$sth->fetch())
      throw new Exception("User $user_id not in watch list for ". $this->user_id, 4404);

    $sth = $this->db->prepare('SELECT * FROM notify WHERE tg_user_id = :tg_user_id AND vk_user_id = :vk_user_id');
    $sth->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    if ($sth->fetch())
      return false;

    $this->db->prepare('INSERT INTO notify (tg_user_id, vk_user_id) VALUES (:tg_user_id, :vk_user_id)')->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    return true;
  }

  public function notify($session_id) {
    Logger::$debug = true;
    Logger::log(LOG_DEBUG, "notify for session $session_id started");
    $sth = $this->db->prepare('SELECT user_id, platform, mobile, app FROM online WHERE id = :id');
    $sth->execute(array('id' => $session_id));
    $session = $sth->fetch(PDO::FETCH_ASSOC);

    $sth = $this->db->prepare('SELECT tg_user_id FROM notify WHERE vk_user_id = :vk_user_id');
    $sth->execute(array('vk_user_id' => $session['user_id']));

    while ($tg_user = $sth->fetch(PDO::FETCH_ASSOC)) {
      parent::sendMessage($tg_user['tg_user_id'], 'Пользователь '. $this->get_vk_user_name($session['user_id']). ' оплайн'. $this->get_session_platform_name($session), 'Markdown', true);
    }
    Logger::log(LOG_DEBUG, 'notify finished');
    Logger::$debug = false;
  }

  public function get_vk_user_name($id) {
    $sth = $this->db->prepare('SELECT first_name, last_name FROM users WHERE id = :id');
    $sth->execute(array('id' => $id));
    if ($user = $sth->fetch(PDO::FETCH_ASSOC)) {
      return '['. $user['first_name']. ' '. $user['last_name']. '](https://vk.com/id'. $id. ')';
    } else {
      return '['. $id. '](https://vk.com/id'. $id. ')';
    }
  }

  public function get_session_platform_name($session) {
    switch ($session['platform']) {
      case 1:
        if ($session['app'] == NULL) {
          return ' (мобильная версия)';
        } else {
          return ' ([мобильное приложение](https://vk.com/app'. $session['app']. '))';
        }
      case 2:
        return ' (приложение для iPhone)';
      case 3:
        return ' (приложение для iPad)';
      case 4:
        return ' (приложение для Android)';
      case 5:
        return ' (приложение для Windows Phone)';
      case 6:
        return ' (приложение для Windows 8)';
      case 7:
        if ($session['app'] == NULL) {
          return ' (полная версия сайта)';
        } else {
          return ' ([приложение](https://vk.com/app'. $session['app']. '))';
        }
    }
    return " (platform: {$session['platform']}, mobile: {$session['mobile']}, app: {$session['app']})";
  }

}
?>
