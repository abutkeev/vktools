<?php
require_once('Logger.php');
require_once('Config.php');
require_once('vkTools.php');
include_once('tg_api.php');

use TelegramBot\Api\Types\User;
use TelegramBot\Api\Types\Update;
use TelegramBot\Api\Types\ForceReply;

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
    return parent::sendMessage($this->user_id, $text, $parse_mode, $disablePreview);
  }

  public function sendFormattedMessage($text, $replyMarkup = null, $replyToMessageId = null) {
    return parent::sendMessage($this->user_id, $text, 'Markdown', true, $replyToMessageId, $replyMarkup);
  }

  public function sendSuccessMessage() {
    return $this->sendMessage('Всё получилось!');
  }

  public function sendFailMessage() {
    return $this->sendMessage('Прости, но при выполнении команды произошла ошибка :(');
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
    Logger::log(LOG_DEBUG, "notify for session $session_id started");
    $sth = $this->db->prepare('SELECT user_id, platform, mobile, app FROM online WHERE id = :id');
    $sth->execute(array('id' => $session_id));
    $session = $sth->fetch(PDO::FETCH_ASSOC);

    $sth = $this->db->prepare('SELECT tg_user_id FROM notify WHERE vk_user_id = :vk_user_id');
    $sth->execute(array('vk_user_id' => $session['user_id']));

    while ($tg_user = $sth->fetch(PDO::FETCH_ASSOC)) {
      parent::sendMessage($tg_user['tg_user_id'], $this->get_vk_user_name($session['user_id']). ' онлайн'. $this->get_session_platform_name($session), 'Markdown', true);
    }
    Logger::log(LOG_DEBUG, 'notify finished');
  }

  public function get_vk_user_name($id) {
    $sth = $this->db->prepare('SELECT first_name, last_name FROM users WHERE id = :id');
    $sth->execute(array('id' => $id));
    if ($user = $sth->fetch(PDO::FETCH_ASSOC)) {
      return '['. $user['first_name']. ' '. $user['last_name']. '](https://vk.com/id'. $id. ')';
    } else {
      return '[Пользователь'. $id. '](https://vk.com/id'. $id. ')';
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

  public function parseMessage($raw_post_data) {
    return Update::fromResponse(self::jsonValidate($raw_post_data, true))->getMessage();
  }

  public function getCommand(&$text) {
    if ($text[0] == '/') {
      $tmp = explode(' ', $text);
      $command = substr(array_shift($tmp), 1);
      $text = implode(' ', $tmp);
      return $command;
    }

    return NULL;
  }

  public function processMessage(vkTools $vk_tools, $command, $text, $message) {
    if ($reply = $message->getReplyToMessage()) {
      $reply_id = $reply->getMessageId();
      Logger::log(LOG_DEBUG, "reply id $reply_id");
      if ($this->handleSession($vk_tools, $reply_id, $command, $text))
        return;
    }

    if (isset($command)) {
      $this->execute($vk_tools, $command, $text);
    } else {
      $this->sendFailMessage();
    }
  }

  public function executeWatch($vk_tools, $text) {
    Logger::log(LOG_DEBUG, "executing watch, text = $text");
    if (isset($text) && $text != '') {
      try {
        $user = $vk_tools->get_user($text, array());
        Logger::log(LOG_DEBUG, 'got user with id '. $user->{'id'});
        if ($this->watch($user->{'id'})) {
          $this->sendFormattedMessage('['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') добавлен в список наблюдения.');
        } else {
          $this->sendFormattedMessage('['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') уже есть в списке наблюдения.');
        }
      } catch (Exception $e) {
        if ($e->getCode() == 404) {
          $this->sendMessage('Не могу найти такого пользователя :(');
        } else {
          $this->sendFailMessage();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
    } else {
      $message = $this->sendFormattedMessage("Хорошо, давай добавим человека в список наблюдения. Для этого пришли мне его или её *id*, *поддомен* или *ссылку* на его страницу.\n\n".
          "Например, если хочешь добавить в список Павла Дурова, прошли мне _1_, _durov_ или _https://vk.com/durov_.", new ForceReply());
      $message_id = $message->getMessageId();
      Logger::log(LOG_DEBUG, 'message id: '. $message_id);
      $this->registerSession($message_id, 'watch');
    }
  }

  public function registerSession($message_id, $type) {
    $this->db->prepare('INSERT INTO requests (message_id, type) VALUES (:message_id, :type)')->execute(array('message_id' => $message_id, 'type' => $type));
  }

  public function handleSession($vk_tools, $message_id,  $command, $text) {
    $sth = $this->db->prepare('SELECT type FROM requests WHERE message_id = :message_id');
    $sth->execute(array('message_id' => $message_id));

    if ($info = $sth->fetch(PDO::FETCH_ASSOC)) {
      $this->db->prepare('DELETE FROM requests WHERE message_id = :message_id')->execute(array('message_id' => $message_id));
      switch ($info['type']) {
        case 'watch':
          if (!isset($command)) {
            $this->executeWatch($vk_tools, $text);
            return true;
          }
          break;
        case 'notify':
          if (!isset($command))
            $this->executeNotify($vk_tools, $text);
          else
            $this->executeNotify($vk_tools, $command);
          return true;
      }
    }

    return false;
  }

  public function executeNotify($vk_tools, $text){
    try {
      $user = $vk_tools->get_user($text, array());
      if ($this->addNotify($user->{'id'})) {
        $this->sendFormattedMessage('Уведомления для пользователя ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') включены.');
      } else {
        $this->sendFormattedMessage('Уведомления для пользователя ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') уже были включены.');
      }
    } catch (Exception $e) {
      if ($e->getCode() == 4404) {
        $this->sendMessage('Пользователь не в списке наблюдения');
      } elseif ($e->getCode() == 404) {
        $this->sendMessage('Пользователь не найден');
      } else {
        $this->sendFailMessage();
        Logger::log(LOG_ERR, $e->getMessage());
      } 
    }
  }

  public function execute($vk_tools, $command, $text) {
    switch ($command) {
      case 'watch':
        $this->executeWatch($vk_tools, $text);
        break;
      case 'notify':
        $this->executeNotify($vk_tools, $text);
        break;
      default:
        $this->sendFailMessage();
        break;
    }
  }

}
?>
