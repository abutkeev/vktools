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

// public section
  function __construct() {
    parent::__construct(Config::TG_TOKEN);

    $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8');
    $this->db  = new PDO('mysql:dbname='. Config::DB_NAME. ';host='. Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    Logger::init('tgTools', LOG_PERROR);
  }

  public function saveUser(User $user) {
    Logger::log(LOG_DEBUG, "saving user ". $user->getId());
    $this->db->prepare('INSERT INTO tg_users (id, first_name, last_name, username) VALUES (:id, :first_name, :last_name, :username) ON DUPLICATE KEY UPDATE first_name=:first_name, last_name=:last_name, username=:username')
      ->execute(array('id' => $user->getId(), 'first_name' => $user->getFirstName(), 'last_name' => $user->getLastName(), 'username' => $user->getUsername()));

    $this->user_id = $user->getId();
  }

  public function parseMessage($raw_post_data) {
    return Update::fromResponse(self::jsonValidate($raw_post_data, true))->getMessage();
  }

  public function getCommand(&$text) {
    if ($text[0] == '/') {
      $tmp = explode(' ', $text);
      $command = substr(array_shift($tmp), 1);

      $tmp1 = explode('_', $command);
      $command = array_shift($tmp1);

      $tmp = array_merge($tmp1, $tmp);

      $text = implode(' ', $tmp);
      return $command;
    }

    return NULL;
  }

  public function processMessage(vkTools $vk_tools, $command, $text, $message) {
    $this->sendChatAction($this->user_id, 'typing');
    if ($this->handle_session($vk_tools, $this->get_reply_id($message), $command, $text))
      return;

    if (isset($command)) {
      $this->execute($vk_tools, $command, $text);
    } else {
      $this->send_unknown_command_message();
    }
  }

  public function processCode(vkTools $vk_tools, $code, $state) {
    $vk_user_id = $vk_tools->saveToken($code);

    $sth = $this->db->prepare('SELECT id FROM tg_users WHERE SHA1(CONCAT(:secret, id)) = :state');
    $sth->execute(array('secret' => Config::SECRET, 'state' => $state));

    if (! $result = $sth->fetch(PDO::FETCH_ASSOC))
      throw new Exception('state is invalid');

    $this->db->prepare('UPDATE tg_users SET vk_user_id = :vk_user_id WHERE id = :id')
      ->execute(array('vk_user_id' => $vk_user_id, 'id' => $result['id']));

    $this->user_id = $result['id'];
    $this->sendMessage('Получилось!');

    return Config::URL;
  }

  public function getVkUser() {
    if (!isset($this->user_id))
      return null;

    $sth = $this->db->prepare('SELECT vk_user_id FROM tg_users WHERE id = :id');
    $sth->execute(array('id' => $this->user_id));

    if (! $result = $sth->fetch(PDO::FETCH_ASSOC))
      return null;

    return $result['vk_user_id'];
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

  public function sendMessage($text, $parse_mode = null, $disablePreview = false) {
    if (!isset($this->user_id))
      throw new Exception('current user id is not set');
    $message = parent::sendMessage($this->user_id, $text, $parse_mode, $disablePreview);
    $this->save_last_message_id($message->getMessageId());
    return $message;
  }

// protected section  

  // execute methods
  protected function execute_watch($vk_tools, $text) {
    Logger::log(LOG_DEBUG, "executing watch, text = $text");
    if (isset($text) && $text != '') {
      try {
        $user = $vk_tools->get_user($text, array());
        Logger::log(LOG_DEBUG, 'got user with id '. $user->{'id'});
        if ($this->watch_action($user->{'id'})) {
          $this->send_formatted_message('['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') добавлен в список наблюдения.');
        } else {
          $this->send_formatted_message('['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') уже есть в списке наблюдения.');
        }
      } catch (Exception $e) {
        if ($e->getCode() == 404) {
          $this->sendMessage('Не могу найти такого пользователя :(');
        } else {
          $this->send_fail_message();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
    } else {
      $message = $this->send_formatted_message("Хорошо, давай добавим человека в список наблюдения. Для этого пришли мне его или её _id_, _поддомен_ или _ссылку_ на его или её страницу.\n\n".
          "Например, если хочешь добавить в список Павла Дурова, прошли мне *1*, *durov* или *https://vk.com/durov*.\n\nЕсли ты передумал и не хочешь никого добавлять, пришли мне в ответ /0.", new ForceReply());
      $message_id = $message->getMessageId();
      Logger::log(LOG_DEBUG, 'message id: '. $message_id);
      $this->register_session($message_id, 'watch');
    }
  }

  protected function execute_notify($vk_tools, $text) {
    if (isset($text) && $text != '') {
      try {
        $user = $vk_tools->get_user($text, array());
        if ($this->add_notify_action($user->{'id'})) {
          $this->send_formatted_message('Теперь я буду писать тебе когда ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') появится онлайн.', new ReplyKeyboardHide());
        } else {
          $this->send_formatted_message('Я уже пишу тебе когда ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') появляется онлайн.', new ReplyKeyboardHide());
        }
      } catch (Exception $e) {
        if ($e->getCode() == 4404) {
          $this->execute_watch($vk_tools, $text);
          $this->execute_notify($vk_tools, $text);
        } elseif ($e->getCode() == 404) {
          $this->sendMessage('Не могу найти такого пользователя :(');
        } else {
          $this->send_fail_message();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
    } else {
      $sth = $this->db->prepare('SELECT id, first_name, last_name FROM watch JEFT JOIN users ON id = vk_user_id WHERE tg_user_id = :tg_user_id AND vk_user_id NOT IN (SELECT vk_user_id FROM notify WHERE tg_user_id = :tg_user_id)');
      $sth->execute(array('tg_user_id' => $this->user_id));
      $users = $sth->fetchAll();
      if (empty($users)) {
        $message = $this->send_formatted_message("Хорошо, давай добавим нового человека в список наблюдения и я буду писать тебе когда он или она будут онлайн.\n\n".
            "Сейчас я уже пишу тебе о выходе в онлайн всех людей из твоего списка наблюдения, поэтому пришли мне _id_, _поддомен_ или _ссылку_ на страницу человека, которого ты хочешь добавить.\n",
            new ForceReply());
      } else {
        $users_list = '';
        $keyboard = $this->generate_users_keyboard($users, $users_list);
        array_push($keyboard, array('/0 Не хочу никого добавлять'));
        $message = $this->send_formatted_message("Хорошо, давай я буду писать тебе когда нужный тебе человек будут онлайн.\n\nВот люди из твоего списка наблюдения, о которых я тебе ещё не пишу:\n". $users_list.
            "\nЕсли ты передумал и не хочешь никого добавлять, пришли в ответ /0.\nЕсли ты хочешь чтобы я тебе писал о новом человеке, пришли в ответ _id_, _поддомен_ или _ссылку_ на его или её страницу.\n",
            new ReplyKeyboardMarkup($keyboard, true, true));
      }

      $message_id = $message->getMessageId();
      Logger::log(LOG_DEBUG, 'message id: '. $message_id);
      $this->register_session($message_id, 'notify');
    }
  }

  protected function execute_mute($vk_tools, $text) {
    if (isset($text) && $text != '') {
      try {
        $user = $vk_tools->get_user($text, array());
        if ($this->del_notify_action($user->{'id'})) {
          $this->send_formatted_message('Теперь не буду писать тебе когда ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') появится онлайн.', new ReplyKeyboardHide());
        } else {
          $this->send_formatted_message('Я же не писал тебе когда ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') появится онлайн! Ну и сейчас не буду :)', new ReplyKeyboardHide());
        }
      } catch (Exception $e) {
        if ($e->getCode() == 404) {
          $this->sendMessage('Не могу найти такого пользователя :(');
        } else {
          $this->send_fail_message();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
    } else {
      $sth = $this->db->prepare('SELECT id, first_name, last_name FROM notify JEFT JOIN users ON id = vk_user_id WHERE tg_user_id = :tg_user_id');
      $sth->execute(array('tg_user_id' => $this->user_id));
      $users = $sth->fetchAll();
      if (empty($users)) {
        $message = $this->sendMessage("Сейчас я и так тебе ни о ком не пишу...", new ReplyKeyboardHide());
      } else {
        $users_list = '';
        $keyboard = $this->generate_users_keyboard($users, $users_list);
        array_push($keyboard, array('/0 Я передумал!'));
        $message = $this->send_formatted_message("Хочешь чтоб я перестал тебе писать тебе когда кто-то онлайн?\n\nВот люди, о которых я тебе не пишу:\n". $users_list.
            "\nО ком перестать писать?\n\nЕсли ты передумал, пришли в ответ /0.",
            new ReplyKeyboardMarkup($keyboard, true, true));
      }

      $message_id = $message->getMessageId();
      Logger::log(LOG_DEBUG, 'message id: '. $message_id);
      $this->register_session($message_id, 'mute');
    }
  }

  protected function execute_forget($vk_tools, $text) {
    if (isset($text) && $text != '') {
      try {
        $user = $vk_tools->get_user($text, array());
        if ($this->forget_action($user->{'id'})) {
          $this->send_formatted_message('Хорошо, я удалил ['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') из списка наблюдения.', new ReplyKeyboardHide());
        } else {
          $this->send_formatted_message('['. $vk_tools->get_user_name($user). '](https://vk.com/id'. $user->{'id'}. ') нет в списке наблюдения. Ну и сейчас не будет :)', new ReplyKeyboardHide());
        }
      } catch (Exception $e) {
        if ($e->getCode() == 404) {
          $this->sendMessage('Не могу найти такого пользователя :(');
        } else {
          $this->send_fail_message();
          Logger::log(LOG_ERR, $e->getMessage());
        } 
      }
    } else {
      $sth = $this->db->prepare('SELECT id, first_name, last_name FROM watch JEFT JOIN users ON id = vk_user_id WHERE tg_user_id = :tg_user_id');
      $sth->execute(array('tg_user_id' => $this->user_id));
      $users = $sth->fetchAll();
      if (empty($users)) {
        $message = $this->sendMessage("В списке неблюдения пусто...", new ReplyKeyboardHide());
      } else {
        $users_list = '';
        $keyboard = $this->generate_users_keyboard($users, $users_list);
        array_push($keyboard, array('/0 Я передумал!'));
        $message = $this->send_formatted_message("Хочешь удалить кого-то из списка наблюдения?\n\nВот люди, которые там сейчас:\n". $users_list.
            "\nКого удалить?\n\nЕсли ты передумал, пришли в ответ /0.",
            new ReplyKeyboardMarkup($keyboard, true, true));
      }

      $message_id = $message->getMessageId();
      Logger::log(LOG_DEBUG, 'message id: '. $message_id);
      $this->register_session($message_id, 'forget');
    }
  }

  protected function execute_sessions($vk_tools, $text) {
    Logger::log(LOG_DEBUG, 'execute_sessions starting, text: '. $text);
    if (!isset($text) || $text == '') {
      $sth = $this->db->prepare('SELECT id, first_name, last_name FROM watch JEFT JOIN users ON id = vk_user_id WHERE tg_user_id = :tg_user_id');
      $sth->execute(array('tg_user_id' => $this->user_id));
      $users = $sth->fetchAll();
      if (empty($users)) {
        $this->send_formatted_message("В списке неблюдения пусто... Если хочешь чтоб я сохранял сессии, добавь кого-нибудь в список наблюдения. Отправь мне /watch чтобы это сделать.", new ReplyKeyboardHide());
      } else {
        $users_list = '';

        $keyboard = array(array('/all Все сессии'));
        $keyboard = array_merge($keyboard, $this->generate_users_keyboard($users, $users_list));
        array_push($keyboard, array('/0 Я передумал!'));

        $message = $this->send_formatted_message("Хочешь чтоб я отправил тебе список сохранённых сессий?\n\nВот люди из твоего списка наблюдения:\n". $users_list.
            "\nЧьи сессии ты хочешь увидеть?\nЕсли ты хочешь увидеть все сессии, пришли в ответ /all.\n\nЕсли ты передумал, пришли в ответ /0.",
            new ReplyKeyboardMarkup($keyboard, true, true));
        $message_id = $message->getMessageId();
        Logger::log(LOG_DEBUG, 'message id: '. $message_id);
        $this->register_session($message_id, 'sessions');
      }
    } else {
      mb_internal_encoding("UTF-8");
      mb_regex_encoding('UTF-8');
      $args = mb_split('\s', $text);
      if ($args[0] == 'all')
        return $this->sessions_action($vk_tools);
      else {
        try {
          return $this->sessions_action($vk_tools, $vk_tools->get_user($args[0])->{'id'});
        } catch (Exception $e) {
          if ($e->getCode() == 404) {
            $this->sendMessage('Не могу найти такого пользователя :(');
          } else {
            $this->send_fail_message();
            Logger::log(LOG_ERR, $e->getMessage());
          } 
        }
      }
    }
  }

  protected function execute_online($vk_tools) {
    $sth = $this->db->prepare("SELECT u.id AS user_id, first_name, last_name, since, till, platform, mobile, app, current FROM online o LEFT JOIN users u ON u.id = o.user_id WHERE user_id in (SELECT vk_user_id FROM watch WHERE tg_user_id = :tg_user) AND current IS NOT NULL ORDER BY till DESC");
    $sth->execute(array('tg_user' => $this->user_id));

    $text = '';

    while ($session = $sth->fetch(PDO::FETCH_ASSOC)) {
      $user = $vk_tools->get_user($session['user_id'], array('sex'));
      $female = property_exists($user, 'sex') && $user->{'sex'} == 1;

      $text .= $this->get_session_text($session, $female, $vk_tools->get_user_name($user));
    }
    if ($text != '' )
      $this->send_formatted_message($text, new ReplyKeyboardHide());
    else
      $this->send_formatted_message('Из твоего списка наблюдения никого в онлайне нет :(', new ReplyKeyboardHide());
  }

  protected function sessions_action($vk_tools, $user_id = null, $count = 5) {
    if ($user_id) {
      $user = $vk_tools->get_user($user_id, array('sex'), 'gen');
      $text = "Вот $count последних сессий [". $vk_tools->get_user_name($user). '](https://vk.com/id'. $user_id. "):\n\n";

      $count = intval($count);
      $sth = $this->db->prepare("SELECT u.id AS user_id, first_name, last_name, since, till, platform, mobile, app, current FROM online o LEFT JOIN users u ON u.id = o.user_id WHERE u.id = :user_id AND user_id in (SELECT vk_user_id FROM watch WHERE tg_user_id = :tg_user) ORDER BY till DESC LIMIT $count");
      $sth->execute(array('user_id' => $user_id, 'tg_user' => $this->user_id));

      $female = property_exists($user, 'sex') && $user->{'sex'} == 1;

      while ($session = $sth->fetch(PDO::FETCH_ASSOC)) {
        $text .= $this->get_session_text($session, $female);
      }
    } else {
      $text = "Вот $count последних сессий:\n";
      $count = intval($count);
      $sth = $this->db->prepare("SELECT u.id AS user_id, first_name, last_name, since, till, platform, mobile, app, current FROM online o LEFT JOIN users u ON u.id = o.user_id WHERE user_id in (SELECT vk_user_id FROM watch WHERE tg_user_id = :tg_user) ORDER BY till DESC LIMIT $count");
      $sth->execute(array('tg_user' => $this->user_id));

      while ($session = $sth->fetch(PDO::FETCH_ASSOC)) {
        $user = $vk_tools->get_user($session['user_id'], array('sex'));
        $female = property_exists($user, 'sex') && $user->{'sex'} == 1;

        $text .= $this->get_session_text($session, $female, $vk_tools->get_user_name($user));
      }
    }
    $this->send_formatted_message($text, new ReplyKeyboardHide());
  }

  protected function get_session_text(array $session, $female = false, $name = null) {
    if ( $female ) {
      if ( isset($name) )
          $was = 'была';
      else
        $was = 'Была';

      $login = 'зашла';
      $logout = 'вышла';
    } else {
      if ( isset($name) )
          $was = 'был';
      else
        $was = 'Был';

      $login = 'зашел';
      $logout = 'вышел';
    }

    $text = '';
    if ( isset($name) ) {
      if ($session['current']) {
        $text .= '['. $name. '](https://vk.com/id'. $session['user_id']. ') *онлайн* '. $this->get_session_platform_name($session).
          ", $login ". $this->format_time($session['since']). ', последняя активность была '. $this->format_time($session['till']). ', длительность '. $this->format_duration(time() - $session['since']). ".\n\n";
      } else {
        $text .= '['. $name. '](https://vk.com/id'. $session['user_id']. ") $was онлайн ". $this->get_session_platform_name($session). ", $login ". $this->format_time($session['since']);
        if ($session['till'] - $session['since'] > 0)
          $text .=", $logout ". $this->format_time($session['till']);
        $text .= ', длительность '. $this->format_duration($session['till'] - $session['since']). ".\n\n";
      }
    } else {
      if ($session['current']) {
        $text .= '*Онлайн* '. $this->get_session_platform_name($session). ", $login ". $this->format_time($session['since']). 
          ', последняя активность была '. $this->format_time($session['till']). ', длительность '. $this->format_duration(time() - $session['since']). ".\n\n";
      } else {
        $text .= "$was онлайн ". $this->get_session_platform_name($session). ", $login ". $this->format_time($session['since']);
        if ($session['till'] - $session['since'] > 0)
          $text .= ", $logout ". $this->format_time($session['till']);
        $text .= ', длительность '. $this->format_duration($session['till'] - $session['since']). ".\n\n";
      }
    }
    return $text;
  }

  protected function format_time($time, $print_today = false) {
    $date = getdate($time);

    if ($date['mday'] == getdate()['mday']) {
      if ($print_today)
        return date('сегодня в H:i:s', $time);
      else
        return date('в H:i:s', $time);
    } elseif ($date['mday'] == getdate(time() -86400 )['mday']) {
        return date('вчера в H:i:s', $time);
    }
    
    return date('Y-m-d в H:i:s', $time);
  }

  protected function format_duration($duration) {
    if ($duration > 60*60)
      return gmdate('G:i:s', $duration);
    elseif ($duration > 60)
      return gmdate('i:s', $duration);
    elseif ($duration != 0)
      return gmdate('s', $duration);
    else
      return 'менее 10 минут';
  }
  
  protected function execute_auth($vk_tools) {
    Logger::log(LOG_DEBUG, 'execute_auth starting');
    $state = sha1(Config::SECRET.$this->user_id);
    Logger::log(LOG_DEBUG, 'state: '. $state);
    $url = $vk_tools->getOAuthUrl(array('offline'), $state);
    Logger::log(LOG_DEBUG, 'url: '. $url);
    
    $this->sendMessage('Хочешь авторизоваться? Перейди по ссылке: '. $url);
  }

  protected function execute($vk_tools, $command, $text) {
    switch ($command) {
      case 'watch':
        $this->execute_watch($vk_tools, $text);
        break;
      case 'notify':
        $this->execute_notify($vk_tools, $text);
        break;
      case 'mute':
        $this->execute_mute($vk_tools, $text);
        break;
      case 'forget':
        $this->execute_forget($vk_tools, $text);
        break;
      case 'online':
        $this->execute_online($vk_tools);
        break;
      case 'sessions':
        $this->execute_sessions($vk_tools, $text);
        break;
      case 'auth':
        $this->execute_auth($vk_tools);
        break;
      case 'help':
        $this->send_help_message();
        break;
      case 'start':
        $this->send_welcome_message();
        break;
      default:
        $this->send_unknown_command_message();
        break;
    }
  }

  // sessions
  protected function register_session($message_id, $type) {
    $this->db->prepare('INSERT INTO requests (message_id, type) VALUES (:message_id, :type)')->execute(array('message_id' => $message_id, 'type' => $type));
  }

  protected function handle_session($vk_tools, $message_id,  $command, $text) {
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
              $this->execute_watch($vk_tools, $text);
            return true;
          } elseif ($command == 0) {
            $this->send_ok_message();
            return true;
          }
          break;
        case 'notify':
          if (!isset($command))
            $this->execute_notify($vk_tools, $text);
          elseif ($command != '0')
            $this->execute_notify($vk_tools, $command);
          else 
            $this->send_ok_message();
          return true;
        case 'mute':
          if (!isset($command))
            $this->execute_mute($vk_tools, $text);
          elseif ($command != '0')
            $this->execute_mute($vk_tools, $command);
          else 
            $this->send_ok_message();
          return true;
        case 'forget':
          if (!isset($command))
            $this->execute_forget($vk_tools, $text);
          elseif ($command != '0')
            $this->execute_forget($vk_tools, $command);
          else 
            $this->send_ok_message();
          return true;
        case 'sessions':
          if (!isset($command))
            $this->execute_sessions($vk_tools, $text);
          elseif ($command != '0')
            $this->execute_sessions($vk_tools, $command);
          else 
            $this->send_ok_message();
          return true;
      }
    }

    return false;
  }

  // send message methods
  protected function send_formatted_message($text, $replyMarkup = null, $replyToMessageId = null) {
    $message = parent::sendMessage($this->user_id, $text, 'Markdown', true, $replyToMessageId, $replyMarkup);
    $this->save_last_message_id($message->getMessageId());
    return $message;
  }

  protected function send_success_message() {
    return $this->sendMessage('Всё получилось!');
  }

  protected function send_ok_message() {
    return $this->send_formatted_message('Ладно', new ReplyKeyboardHide());
  }

  protected function send_fail_message() {
    return $this->sendMessage('Прости, но при выполнении команды произошла ошибка :(');
  }

  protected function send_unknown_command_message() {
    return $this->sendMessage('Прости, но я не знаю что тебе на это ответить. Если хочешь узнать что я умею делать, напиши мне /help...');
  }

  protected function send_help_message() {
    $this->send_formatted_message('Я хотел бы рассказать про то, что я умею, но я сам пока об этом не знаю :( Пока можешь посмотреть список команд, нажав /.');
  }

  protected function send_welcome_message() {
    $this->send_formatted_message('Привет! Я бот для вконтактика. Пока я мало что умею, но [папочка](https://telegram.me/abutkeev) обещал научить меня ещё кое-чему. Напиши /help, если хочешь узнать больше!');
  }
  // actions
  protected function watch_action($user_id) {
    $sth = $this->db->prepare('SELECT * FROM watch WHERE tg_user_id = :tg_user_id AND vk_user_id = :vk_user_id');
    $sth->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    if ($sth->fetch())
      return false;

    $this->db->prepare('INSERT INTO watch (tg_user_id, vk_user_id) VALUES (:tg_user_id, :vk_user_id)')->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    return true;
  }

  protected function add_notify_action($user_id) {
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

  protected function del_notify_action($user_id) {
    $sth = $this->db->prepare('DELETE FROM notify WHERE tg_user_id = :tg_user_id AND vk_user_id = :vk_user_id');
    $sth->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    return $sth->rowCount();
  }

  protected function forget_action($user_id) {
    $this->db->prepare('DELETE FROM notify WHERE tg_user_id = :tg_user_id AND vk_user_id = :vk_user_id')->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    $sth = $this->db->prepare('DELETE FROM watch WHERE tg_user_id = :tg_user_id AND vk_user_id = :vk_user_id');
    $sth->execute(array('tg_user_id' => $this->user_id, 'vk_user_id' => $user_id));
    return $sth->rowCount();
  }

  // other
  protected function get_vk_user_name($id) {
    $sth = $this->db->prepare('SELECT first_name, last_name FROM users WHERE id = :id');
    $sth->execute(array('id' => $id));
    if ($user = $sth->fetch(PDO::FETCH_ASSOC)) {
      return '['. $user['first_name']. ' '. $user['last_name']. '](https://vk.com/id'. $id. ')';
    } else {
      return '[Пользователь'. $id. '](https://vk.com/id'. $id. ')';
    }
  }

  protected function get_session_platform_name($session) {
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

  protected function save_last_message_id($message_id) {
    $this->db->prepare('REPLACE INTO last_message (tg_user_id, message_id) VALUES (:tg_user_id, :message_id)')->execute(array('tg_user_id' => $this->user_id, 'message_id' => $message_id));
  }

  protected function get_reply_id($message) {
    if ($reply = $message->getReplyToMessage()) {
      $reply_id = $reply->getMessageId();
      Logger::log(LOG_DEBUG, "reply id $reply_id");

      return $reply_id;
    }
  }

  protected function generate_users_keyboard(array $users, &$users_list) {
        $keyboard = array();
        foreach ($users as $user) {
          $users_list .= '/'. $user['id']. "\t[". $user['first_name']. ' '. $user['last_name']. '](https://vk.com/id'. $user['id']. ")\n";
          array_push($keyboard, array('/'. $user['id']. ' '. $user['first_name']. ' '. $user['last_name']));
        }

        return $keyboard;
  }
}
?>
