<?php
require_once('vkApi.php');
require_once('Logger.php');
require_once('Config.php');
include_once('tgTools.php');

class vkTools extends vkApi{
  private $db;
  private $tg;

  function __construct($user_id = null) {

    $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8');
    $this->db  = new PDO('mysql:dbname='. Config::DB_NAME. ';host='. Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    Logger::init('vkTools', LOG_PERROR);

    $this->tg = new tgTools();

    if (isset($user_id)) {
      $sth = $this->db->prepare('SELECT token FROM access_tokens WHERE vk_user_id = :user_id AND (till > UNIX_TIMESTAMP() OR till IS NULL)');
      $sth->execute(array('user_id' => $user_id));

      if (!$result = $sth->fetch(PDO::FETCH_ASSOC))
        parent::__construct();
      else
        parent::__construct($result['token']);

    } else {
      parent::__construct();
    }
  }

  public function getOAuthUrl(array $scope, $state = null) {
    $params['client_id'] = Config::VK_CLIENT_ID;
    $params['display'] = 'page';
    $params['redirect_uri'] = Config::VK_REDIRECT_URL;
    $params['scope'] = implode(',', $scope);
    $params['response_type'] = 'code';
    if (isset($state))
      $params['state'] = $state;

    return $this->get_oauth_url($params);
  }

  public function saveToken($code) {
    $params['client_id'] = Config::VK_CLIENT_ID;
    $params['client_secret'] = Config::VK_SECRET;
    $params['redirect_uri'] = Config::VK_REDIRECT_URL;
    $params['code'] = $code;

    $result = $this->get_access_token($params);

    if (property_exists($result, 'error'))
      if (property_exists($result, 'error_description'))
        throw new Exception($result->{'error'}. ': '. $result->{'error_description'});
      else
        throw new Exception($result->{'error'});

    if (!property_exists($result, 'access_token'))
      throw new Exception('no access_token');

    if (!property_exists($result, 'user_id'))
      throw new Exception('no user_id');

    if (!property_exists($result, 'expires_in') || $result->{'expires_in'} == 0)
      $till = NULL;
    else
      $till = time() + $result->{'expires_in'};

    $this->db->prepare('REPLACE INTO access_tokens (vk_user_id, token, till) VALUES (:user_id, :access_token, :till)')
      ->execute(array('user_id' => $result->{'user_id'}, 'access_token' => $result->{'access_token'}, 'till' => $till));

    return $result->{'user_id'};
  }

  public function merge_sessions($diff = 14*60, $max_age=3600) {
#    Logger::$debug = true;
    $this->db->beginTransaction();

    $sth_key = $this->db->query('SELECT user_id, platform, mobile, app FROM online GROUP BY user_id, platform, mobile, app');

    $sth_null = $this->db->prepare(
        'SELECT id, since, till, platform, mobile, app FROM online WHERE UNIX_TIMESTAMP() - till < :max_age AND user_id = :user_id AND platform = :platform AND mobile = :mobile AND app is NULL FOR UPDATE');
    $sth_not_null = $this->db->prepare(
        'SELECT id, since, till, platform, mobile, app FROM online WHERE UNIX_TIMESTAMP() - till < :max_age AND user_id = :user_id AND platform = :platform AND mobile = :mobile AND app = :app FOR UPDATE');

    while ($key = $sth_key->fetch(PDO::FETCH_ASSOC)) {

      if (isset($key['app'])) {
        $sth = $sth_not_null;
        $sth->execute(array('user_id' => $key['user_id'], 'platform' => $key['platform'], 'mobile' => $key['mobile'], 'app' => $key['app'], 'max_age' => $max_age));
      } else {
        $sth = $sth_null;
        $sth->execute(array('user_id' => $key['user_id'], 'platform' => $key['platform'], 'mobile' => $key['mobile'], 'max_age' => $max_age));
      }

      $update_sth = $this->db->prepare('UPDATE online SET since = :since WHERE id = :id');
      $delete_sth = $this->db->prepare('DELETE FROM online WHERE id = :id');
      $prev = $sth->fetch(PDO::FETCH_ASSOC);
      while ($info = $sth->fetch(PDO::FETCH_ASSOC)) {
        if ($info['since'] - $prev['till'] < $diff) {
          $update_sth->execute(array('since' => $prev['since'], 'id' => $info['id']));
          $delete_sth->execute(array('id' => $prev['id']));
          $prev['id'] = $info['id'];
          $prev['till'] = $info['till'];
        } else {
          $prev = $info;
        }
      }
    }
    $this->db->commit();
  }

  public function save_all_online($online_timeout = 15*60) {
#    Logger::temporary_debug_on();
    $sth = $this->db->query('SELECT vk_user_id FROM watch GROUP BY vk_user_id');
    while ($user = $sth->fetch(PDO::FETCH_ASSOC)) {
      $user_id = $user['vk_user_id'];
      Logger::log(LOG_DEBUG, "save online for $user_id");
      $this->save_online($user_id);
    }
    Logger::temporary_debug_off();
  }

  public function save_online($user_id, $online_timeout = 15*60) {
    $this->db->beginTransaction();
    $sth = $this->db->prepare('SELECT id, since, till, platform, mobile, app FROM online WHERE user_id = :user_id AND current = 1 FOR UPDATE');
    $sth->execute(array('user_id' => $user_id));
    $db_info = $sth->fetch(PDO::FETCH_ASSOC);

    $info = $this->get_online($user_id);
    if ($info['online'] && $this->get_time() - $info['last_seen'] > $online_timeout)
      $info['online'] = 0;

    Logger::log(LOG_DEBUG, var_export($info, true));
    Logger::log(LOG_DEBUG, var_export($db_info, true));
    if ($db_info && $info['online'] && $db_info['platform'] == $info['platform'] && $db_info['mobile'] == $info['mobile'] && $db_info['app'] == $info['app'] ) {
      $this->db->prepare('UPDATE online SET till = :till WHERE id = :id')->execute(array('id' => $db_info['id'], 'till' => $info['last_seen']));
      $this->db->commit();
      return;
    } else {
      if ($db_info && !$info['online']) {
        $this->db->prepare('UPDATE online SET current = NULL, till = :till where id = :id')->execute(array('id' => $db_info['id'], 'till' => $info['last_seen']));
        $this->db->commit();
        return;
      }
      
      if ($db_info) 
        $this->db->prepare('UPDATE online SET current = NULL where id = :id')->execute(array('id' => $db_info['id']));

      if ($info['online'])
        $info['current'] = 1;
      else {
        $this->db->commit();
        return;
      }

      $info['since'] = $info['last_seen'];
      $info['till'] = $info['last_seen'];

      unset($info['online']);
      unset($info['last_seen']);

      $sth = $this->db->prepare('INSERT INTO online (user_id, since, till, platform, mobile, app, current) VALUES (:user_id, :since, :till, :platform, :mobile, :app, :current)');
      $sth->execute($info);
      $session_id = $this->db->lastInsertId();
      $this->db->commit();
      $this->tg->notify($session_id);
    }
  }

  public function get_user_name($info) {
    if (property_exists($info, 'first_name') && property_exists($info, 'last_name')) {
      return $info->{'first_name'}. ' '. $info->{'last_name'};
    } else {
      throw new Exception('first_name or last_name is not defined');
    }
  }

  public function get_user($id, $fields = array('online', 'last_seen', 'online_mobile'), $name_case = 'nom') {
    if (is_string($id)) {
      $count = 0;
      $id = str_replace('https://vk.com/', '', $id);
      $id = str_replace('http://vk.com/', '', $id);
      $id = str_replace('vk.com/', '', $id);
    }
    $user = parent::get_user($id, $fields, $name_case);
    if (strtolower($name_case) == 'nom')
      $this->db->prepare('REPLACE INTO users (id, first_name, last_name) VALUES (:id, :first_name, :last_name)')
        ->execute(array('id' => $user->{'id'}, 'first_name' => $user->{'first_name'}, 'last_name' =>$user->{'last_name'}));

    return $user;
  }
}
?>
