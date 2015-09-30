<?php
require_once('Logger.php');
require_once('Config.php');
require_once('vkTools.php');
include_once('tg_api.php');

use TelegramBot\Api\Types\User;
use TelegramBot\Api\Types\Update;
use TelegramBot\Api\Types\ForceReply;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\ReplyKeyboardHide;

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
    $message = parent::sendMessage($this->user_id, $text, $parse_mode, $disablePreview);
    $this->saveLastMessageId($message->getMessageId());
    return $message;
  }

  public function sendFormattedMessage($text, $replyMarkup = null, $replyToMessageId = null) {
    $message = parent::sendMessage($this->user_id, $text, 'Markdown', true, $replyToMessageId, $replyMarkup);
    $this->saveLastMessageId($message->getMessageId());
    return $message;
  }

  public function sendSuccessMessage() {
    return $this->sendMessage('Всё получилось!');
  }

  public function sendFailMessage() {
    return $this->sendMessage('Прости, но при выполнении команды произошла ошибка :(');
  }

  public function sendUnknownCommandMessage() {
    return $this->sendMessage('Прости, но я не знаю что тебе на это ответить. Если хочешь узнать что я умею делать, напиши мне /help...');
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

  public function saveLastMessageId($message_id) {
    $this->db->prepare('REPLACE INTO last_message (tg_user_id, message_id) VALUES (:tg_user_id, :message_id)')->execute(array('tg_user_id' => $this->user_id, 'message_id' => $message_id));
  }

  public function getReplyId($message) {
    if ($reply = $message->getReplyToMessage()) {
      $reply_id = $reply->getMessageId();
      Logger::log(LOG_DEBUG, "reply id $reply_id");

      return $reply_id;
    }
  }

  public function processMessage(vkTools $vk_tools, $command, $text, $message) {
    $this->sendChatAction($this->user_id, 'typing');
    if ($this->handleSession($vk_tools, $this->getReplyId($message), $command, $text))
      return;

    if (isset($command)) {
      $this->execute($vk_tools, $command, $text);
    } else {
      $this->sendUnknownCommandMessage();
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
      $message = $this->sendFormattedMessage("Хорошо, давай добавим человека в список наблюдения. Для этого пришли мне его или её _id_, _поддомен_ или _ссылку_ на его или её страницу.\n\n".
          "Например, если хочешь добавить в список Павла Дурова, прошли мне *1*, *durov* или *https://vk.com/durov*.\n\nЕсли ты передумал и не хочешь никого добавлять, пришли мне в ответ /0.", new ForceReply());
      $message_id = $message->getMessageId();
      Logger::log(LOG_DEBUG, 'message id: '. $message_id);
      $this->registerSession($message_id, 'watch');
    }
  }

  public function registerSession($message_id, $type) {
    $this->db->prepare('INSERT INTO requests (message_id, type) VALUES (:message_id, :type)')->execute(array('message_id' => $message_id, 'type' => $type));
  }

  public function handleSession($vk_tools, $message_id,  $command, $text) {
    if (!isset($message_id)) {
      $sth = $this->db->prepare('SELECT message_id FROM last_message WHERE tg_user_id = :tg_user_id');
      $sth->execute(array('tg_user_id' => $this->user_id));

      if ($info = $sth->fetch(PDO::FETCH_ASSOC)) {
        $message_id = $info['message_id'];
        Logger::log(LOG_DEBUG, "last_message_id: $message_id");
      } else {
        return false;
      }
    }

    $sth = $this->db->prepare('SELECT type FROM requests WHERE message_id = :message_id');
    $sth->execute(array('message_id' => $message_id));

    if ($info = $sth->fetch(PDO::FETCH_ASSOC)) {
      $this->db->prepare('DELETE FROM requests WHERE message_id = :message_id')->execute(array('message_id' => $message_id));
      switch ($info['type']) {
        case 'watch':
          if (!isset($command)) {
            if ($command != '0')
              $this->executeWatch($vk_tools, $text);
            return true;
          } elseif ($command == 0) {
            $this->sendFormattedMessage('Ладно', new ReplyKeyboardHide());
            return true;
          }
          break;
        case 'notify':
          if (!isset($command))
            $this->executeNotify($vk_tools, $text);
          elseif ($command != '0')
            $this->executeNotify($vk_tools, $command);
          else 
            $this->sendFormattedMessage('Ладно', new ReplyKeyboardHide());
          return true;
      }
    }

    return false;
  }

  public function executeNotify($vk_tools, $text){
    if (isset($text) && $text != '') {
      try {
        $user = $vk_tools->get_user($text, array());
        if ($this->addNotify($user->{'id'})) {
          $this->sendFormattedMessage('Теперь я буду писать тебе когда ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') появится онлайн.', new ReplyKeyboardHide());
        } else {
          $this->sendFormattedMessage('Я уже пишу тебе когда ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') появляется онлайн.', new ReplyKeyboardHide());
        }
      } catch (Exception $e) {
        if ($e->getCode() == 4404) {
          $this->executeWatch($vk_tools, $text);
          $this->executeNotify($vk_tools, $text);
        } elseif ($e->getCode() == 404) {
          $this->sendMessage('Не могу найти такого пользователя :(');
        } else {
          $this->sendFailMessage();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
    } else {
      $sth = $this->db->prepare('SELECT id, first_name, last_name FROM watch JEFT JOIN users ON id = vk_user_id WHERE tg_user_id = :tg_user_id AND vk_user_id NOT IN (SELECT vk_user_id FROM notify WHERE tg_user_id = :tg_user_id)');
      $sth->execute(array('tg_user_id' => $this->user_id));
      $users = $sth->fetchAll();
      if (empty($users)) {
        $message = $this->sendFormattedMessage("Хорошо, давай добавим нового человека в список наблюдения и я буду писать тебе когда он или она будут онлайн.\n\n".
            "Сейчас я уже пишу тебе о выходе в онлайн всех людей из твоего списка наблюдения, поэтому пришли мне _id_, _поддомен_ или _ссылку_ на страницу человека, которого ты хочешь добавить.\n",
            new ForceReply());
      } else {
        $users_list = '';
        $keyboard = $this->generateUsersKeyboard($users, $users_list);
        $message = $this->sendFormattedMessage("Хорошо, давай я буду писать тебе когда нужный тебе человек будут онлайн.\n\nВот люди из твоего списка наблюдения, о которых я тебе ещё не пишу:\n". $users_list.
            "\nЕсли ты передумал и не хочешь никого добавлять, пришли в ответ /0.\nЕсли ты хочешь чтобы я тебе писал о новом человеке, пришли в ответ _id_, _поддомен_ или _ссылку_ на его или её страницу.\n",
            new ReplyKeyboardMarkup($keyboard, true, true));
      }

      $message_id = $message->getMessageId();
      Logger::log(LOG_DEBUG, 'message id: '. $message_id);
      $this->registerSession($message_id, 'notify');
    }
  }

  public function generateUsersKeyboard(array $users, &$users_list) {
        $keyboard = array();
        foreach ($users as $user) {
          $users_list = '/'. $user['id']. "\t[". $user['first_name']. ' '. $user['last_name']. '](https://vk.com/'. $user['id']. ")\n";
          array_push($keyboard, array('/'. $user['id']. ' '. $user['first_name']. ' '. $user['last_name']));
        }
        $str = '/0 Не хочу никого добавлять';
#        $users_list .= $str. "\n";
        array_push($keyboard, array($str));
        return $keyboard;
  }

  public function execute($vk_tools, $command, $text) {
    switch ($command) {
      case 'watch':
        $this->executeWatch($vk_tools, $text);
        break;
      case 'notify':
        $this->executeNotify($vk_tools, $text);
        break;
      case 'help':
        $this->sendHelp();
        break;
      default:
        $this->sendUnknownCommandMessage();
        break;
    }
  }

  public function sendHelp() {
    $this->sendFormattedMessage('Я хотел бы рассказать про то, что я умею, но я сам пока об этом не знаю :(');
  }

}
?>
