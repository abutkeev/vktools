<?php
require_once('vkApi.php');
require_once('Logger.php');
require_once('Config.php');
include_once('tgTools.php');

class vkTools extends vkApi{
  private $db;
  private $tg;
  private $user_id;
  private $skip_user_fields = array('online', 'last_seen', 'online_mobile', 'online_app', 'id', 'counters/online_friends');

  function __construct($user_id = null) {

    $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4');
    $this->db  = new PDO('mysql:dbname='. Config::DB_NAME. ';host='. Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    Logger::init('vkTools', LOG_PERROR);

    $this->tg = new tgTools();

    if (isset($user_id)) {
      $sth = $this->db->prepare('SELECT token FROM access_tokens WHERE vk_user_id = :user_id AND (till > UNIX_TIMESTAMP() OR till IS NULL)');
      $sth->execute(array('user_id' => $user_id));

      if (!$result = $sth->fetch(PDO::FETCH_ASSOC))
        parent::__construct(null, Config::VK_API_TIMEOUT);
      else {
        parent::__construct($result['token'], Config::VK_API_TIMEOUT);
        $this->user_id = $user_id;
      }

    } else {
      parent::__construct(null, Config::VK_API_TIMEOUT);
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
      try {
        $this->save_online($user_id);
        Logger::log(LOG_DEBUG, "save online for $user_id done");
        usleep(0.2 * 1000000);
      } catch (ErrorException $ex) {
        Logger::log(LOG_ERR, "got error then saving online for user $user_id: ". $ex->getMessage());
      }
    }
    Logger::temporary_debug_off();
  }

  public function save_online($user_id, $online_timeout = 15*60) {
    $this->db->beginTransaction();
    $sth = $this->db->prepare('SELECT id, since, till, platform, mobile, app FROM online WHERE user_id = :user_id AND current = 1 FOR UPDATE');
    $sth->execute(array('user_id' => $user_id));
    $db_info = $sth->fetch(PDO::FETCH_ASSOC);

    try {
      $info = $this->get_online($user_id);
    } catch (Exception $ex) {
      $this->db->rollBack();
      throw $ex;
    }

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
    Logger::log(LOG_DEBUG, "get_user starting, id: $id, name_case: $name_case");
    if (is_string($id)) {
      $count = 0;
      $id = str_replace('https://vk.com/', '', $id);
      $id = str_replace('http://vk.com/', '', $id);
      $id = str_replace('vk.com/', '', $id);
    }
    if (is_numeric($id) && intval($id) == $id) {
      Logger::log(LOG_DEBUG, "user_id $id is int, getting user data from db");
      return $this->get_user_from_db($id, $fields, $name_case);
    } else {
      Logger::log(LOG_DEBUG, "user_id $id is not int, requesting user data from vk");
      $user = parent::get_user($id, $fields, $name_case);
      $this->save_user_to_db($user, $name_case);
    }

    if (strtolower($name_case) == 'nom') {
      Logger::log(LOG_DEBUG, 'Saving vk user to db: id: '. $user->{'id'}. ', first_name: '. $user->{'first_name'}. ', last_name: '. $user->{'last_name'});
      $this->db->prepare('REPLACE INTO users (id, first_name, last_name) VALUES (:id, :first_name, :last_name)')
        ->execute(array('id' => $user->{'id'}, 'first_name' => $user->{'first_name'}, 'last_name' => $user->{'last_name'}));
    }

    return $user;
  }

  public function get_users($ids, $fields = array(), $name_case = 'nom') {
    $response = parent::get_users($ids, $fields, $name_case);

    $this->check_get_users_response($response);
    foreach ($response->{'response'} as $user) {
      $this->save_user_to_db($user, $name_case);
    }

    return $response->{'response'};
  }

  public function saveUsersAttrs ($user_id) {
    $info = $this->get_user_full($user_id);
    foreach (get_object_vars($info) as $name => $value) {
      $this->save_user_attr($user_id, $name, $value);
    }
    $this->clear_user_attrs($user_id);
  }

  public function saveUsersSubscriptions($user_id) {
    $current = $this->get_subscriptions($user_id);
    $old = $this->get_subscriptions_from_db($user_id);

    $params = array('user_id' => $user_id);
    $new_sth = $this->db->prepare('INSERT INTO user_subscriptions (user_id, subscription_id, type) VALUES (:user_id, :value, :type)');
    $delete_sth = $this->db->prepare('DELETE FROM user_subscriptions WHERE user_id = :user_id AND subscription_id = :value AND type = :type');
    $log_sth = $this->db->prepare('INSERT INTO user_subscriptions_change (user_id, subscription_id, type, action) VALUES (:user_id, :value, :type, :action)');

    $action = 'follow';
    $params['type'] = 'user';
    foreach (array_diff($current->{'users'}->{'items'}, $old['users']) as $new_user) {
      $params['value'] = $new_user;
      $new_sth->execute($params);
      $log_sth->execute(array_merge($params, array('action' => $action)));
    }

    $params['type'] = 'page';
    foreach (array_diff($current->{'groups'}->{'items'}, $old['pages']) as $new_page) {
      $params['value'] = $new_page;
      $new_sth->execute($params);
      $log_sth->execute(array_merge($params, array('action' => $action)));
    }

    $action = 'unfollow';
    $params['type'] = 'user';
    foreach (array_diff($old['users'], $current->{'users'}->{'items'}) as $delete_user) {
      $params['value'] = $delete_user;
      $delete_sth->execute($params);
      $log_sth->execute(array_merge($params, array('action' => $action)));
    }

    $params['type'] = 'page';
    foreach (array_diff($old['pages'], $current->{'groups'}->{'items'}) as $delete_page) {
      $params['value'] = $delete_page;
      $delete_sth->execute($params);
      $log_sth->execute(array_merge($params, array('action' => $action)));
    }
  }

  private function get_subscriptions_from_db($user_id) {
    $sth = $this->db->prepare('SELECT subscription_id, type FROM user_subscriptions WHERE user_id = :user_id');
    $sth->execute(array('user_id' => $user_id));

    $result = array('users' => array(), 'pages' => array());
    while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
      switch ($row['type']) {
        case 'user':
          array_push($result['users'], $row['subscription_id']);
          break;
        case 'page':
          array_push($result['pages'], $row['subscription_id']);
          break;
        default:
          throw new Exception('Unexpected type '. $row['type']);
      }
    }
    return $result;
  }

  protected function get_user_from_db($id, $fields = array(), $name_case = 'nom') {
//    Logger::temporary_debug_on();
    $sth = $this->db->prepare('SELECT type, value FROM user_properties WHERE user_id = :id AND updated > DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $sth->execute(array('id' => $id));

    $user = new stdClass();
    $user->{'id'} = $id;

    while ($field = $sth->fetch(PDO::FETCH_ASSOC)) {
      $user->{$field['type']} = json_decode($field['value']);
    }

    if (!$this->check_fields($user, $fields, $name_case)) {
      $user = parent::get_user($id, $fields, $name_case);
      $this->save_user_to_db($user, $name_case);
      Logger::temporary_debug_off();
      return $user;
    } else {
      Logger::log(LOG_DEBUG, 'returning user data from db');
      Logger::temporary_debug_off();
      return $user;
    }
  }


  private function check_fields(stdClass &$user, $fields, $name_case = 'nom'){
    $name_case = strtolower($name_case);

    if ($name_case != 'nom') {
      if (property_exists($user, 'first_name_'. $name_case) && property_exists($user, 'last_name_'. $name_case)) {
        $user->{'first_name'} = $user->{'first_name_'. $name_case};
        $user->{'last_name'} = $user->{'last_name_'. $name_case};
      } else {
        Logger::log(LOG_DEBUG, "no first_name, last_name property for $name_case in user object, requesting user");
        return false;
      }
    }

    if (! property_exists($user, 'id') || ! property_exists($user, 'first_name') || ! property_exists($user, 'last_name')) {
      Logger::log(LOG_DEBUG, "no first_name, last_name or id property in user object, requesting user");
      return false;
    }

    foreach ($this->skip_user_fields as $skip) {
      if (in_array($skip, $fields)) {
        Logger::log(LOG_DEBUG, "find $skip in fields, requesting user");
        return false;
      }
    }

    foreach ($fields as $field) {
      if (!property_exists($user, $field)) {
        Logger::log(LOG_DEBUG, "property $field not in db or expired, requesting user");
        return false;
      }
    }

    return true;
  }

  private $attr_names = array();

  private function fill_user_attrs($user_id) {
    $sth = $this->db->prepare('SELECT name, int_val, str_val, confirmation_cnt FROM users_attrs WHERE user_id = :user_id AND requester_id = :requester_id');
    $sth->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id));

    $this->attr_names = array();

    while ($attr = $sth->fetch(PDO::FETCH_ASSOC)) {
      $this->attr_names[$user_id][$attr['name']]['int_val'] = $attr['int_val'];
      $this->attr_names[$user_id][$attr['name']]['str_val'] = $attr['str_val'];
      $this->attr_names[$user_id][$attr['name']]['confirmation_cnt'] = $attr['confirmation_cnt'];
    }
  }

  private function clear_user_attrs($user_id) {
    if (!is_array($this->attr_names[$user_id])) {
      unset($this->attr_names[$user_id]);
      return;
    }

    foreach($this->attr_names[$user_id] as $name => $old) {
      if ($old['confirmation_cnt'] >= 10) {
        Logger::log(LOG_DEBUG, "clearing $name from $user_id: ". var_export($old, true));
        $this->db->prepare('INSERT INTO users_attrs_change (user_id, requester_id, name, old_int_val, new_int_val, old_str_val, new_str_val) VALUES (:user_id, :requester_id, :name, :old_int_val, NULL, :old_str_val, NULL)')
          ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name, 'old_int_val' => $old['int_val'], 'old_str_val' => $old['str_val']));

        $this->db->prepare('DELETE FROM users_attrs WHERE user_id = :user_id AND requester_id = :requester_id AND name = :name')
          ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name));
      } else {
        Logger::log(LOG_DEBUG, "Don't clear user $user_id attr $name because confirmation_cnt=". $old['confirmation_cnt']);
        $this->db->prepare('UPDATE users_attrs SET confirmation_cnt = confirmation_cnt + 1 WHERE user_id = :user_id AND requester_id = :requester_id AND name = :name')
          ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name));
      }
    }
    unset($this->attr_names[$user_id]);
  }

  private function save_user_attr($user_id, $name, $value) {
    if (in_array($name, $this->skip_user_fields))
      return;

    if (empty($value) && isset($this->attr_names[$user_id][$name]) && $this->attr_names[$user_id][$name]['confirmation_cnt'] < 10) {
      Logger::log(LOG_DEBUG, "Don't save user $user_id empty attr $name because confirmation_cnt=". $this->attr_names[$user_id][$name]['confirmation_cnt']);
      unset($this->attr_names[$user_id][$name]);
      $this->db->prepare('UPDATE users_attrs SET confirmation_cnt = confirmation_cnt + 1 WHERE user_id = :user_id AND requester_id = :requester_id AND name = :name')
        ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name));
    } elseif (is_int($value)) {
      $this->save_user_int_attr_change($user_id, $name, $value);
      $this->db->prepare('REPLACE INTO users_attrs (user_id, requester_id, name, int_val, str_val, updated, confirmation_cnt) VALUES (:user_id, :requester_id, :name, :value, NULL, NOW(), 0)')
        ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name, 'value' => $value));
    } elseif (is_string($value)) {
      $this->save_user_str_attr_change($user_id, $name, $value);
      $this->db->prepare('REPLACE INTO users_attrs (user_id, requester_id, name, int_val, str_val, updated, confirmation_cnt) VALUES (:user_id, :requester_id, :name, NULL, :value, NOW(), 0)')
        ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name, 'value' => $value));
    } elseif (is_object($value)) {
      foreach (get_object_vars($value) as $n => $v) {
        $this->save_user_attr($user_id, $name. '/'. $n, $v);
      }
    } elseif (is_array($value)) {
      foreach ($value as $n => $v) {
        $this->save_user_attr($user_id, $name. '/'. $n, $v);
      }
    } else {
      throw new Exception('value has unexpected type: '. var_export($value, true));
    }

  }

  private function get_user_attr($user_id, $name) {
    if (!isset($this->attr_names[$user_id]) || !is_array($this->attr_names[$user_id]))
      $this->fill_user_attrs($user_id);

    if (array_key_exists($name, $this->attr_names[$user_id])) {
      $value = $this->attr_names[$user_id][$name];
      unset($this->attr_names[$user_id][$name]);
      return $value;
    } else
      return NULL;
  }

  private function save_user_int_attr_change($user_id, $name, $value) {
    Logger::log(LOG_DEBUG, "save_user_int_attr_change starting, user_id: $user_id, name: $name, value: $value");
    if ($old = $this->get_user_attr($user_id, $name)) {
      Logger::log(LOG_DEBUG, 'old str_val: '. $old['str_val']. ', old int_val: '. $old['int_val']);
      if ($old['int_val'] != $value) {
        Logger::log(LOG_INFO, "$name value changed for user $user_id: new: $value, old: ". $old['int_val']);
        $this->db->prepare('INSERT INTO users_attrs_change (user_id, requester_id, name, old_int_val, new_int_val, old_str_val, new_str_val) VALUES (:user_id, :requester_id, :name, :old_int_val, :value, :old_str_val, NULL)')
          ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name, 'old_int_val' => $old['int_val'], 'value' => $value, 'old_str_val' => $old['str_val']));
      }
    } else {
      $this->db->prepare('INSERT INTO users_attrs_change (user_id, requester_id, name, old_int_val, new_int_val, old_str_val, new_str_val) VALUES (:user_id, :requester_id, :name, NULL, :value, NULL, NULL)')
        ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name, 'value' => $value));
    }
  }

  private function save_user_str_attr_change($user_id, $name, $value) {
    Logger::log(LOG_DEBUG, "save_user_str_attr_change starting, user_id: $user_id, name: $name, value: $value");
    if ($old = $this->get_user_attr($user_id, $name)) {
      Logger::log(LOG_DEBUG, 'old str_val: '. $old['str_val']. ', old int_val: '. $old['int_val']);
      if ($old['str_val'] != $value) {
        Logger::log(LOG_INFO, "$name value changed for user $user_id: new: $value, old: ". $old['str_val']);
        $this->db->prepare('INSERT INTO users_attrs_change (user_id, requester_id, name, old_int_val, new_int_val, old_str_val, new_str_val) VALUES (:user_id, :requester_id, :name, :old_int_val, NULL, :old_str_val, :value)')
          ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name, 'old_int_val' => $old['int_val'], 'value' => $value, 'old_str_val' => $old['str_val']));
      }
    } else {
      Logger::log(LOG_DEBUG, 'no old values');
      $this->db->prepare('INSERT INTO users_attrs_change (user_id, requester_id, name, old_int_val, new_int_val, old_str_val, new_str_val) VALUES (:user_id, :requester_id, :name, NULL, NULL, NULL, :value)')
        ->execute(array('user_id' => $user_id, 'requester_id' => $this->user_id, 'name' => $name, 'value' => $value));
    }
  }

  protected function save_user_to_db(stdClass $user, $name_case = 'nom') {
    if (!property_exists($user, 'id'))
      throw new Exception('no user id');

    $name_case = strtolower($name_case);
    foreach (get_object_vars($user) as $type => $value) {
      if (in_array($type, $this->skip_user_fields))
        continue;

      if ($name_case != 'nom' && ($type == 'first_name' || $type == 'last_name'))
          $type .= '_'. $name_case;

      $this->db->prepare('INSERT INTO user_properties (user_id, type, value) VALUES (:user_id, :type, :value) ON DUPLICATE KEY UPDATE value=:value, updated=NOW()')
        ->execute(array('user_id' => $user->{'id'}, 'type' => $type, 'value' => json_encode($value)));
    }
  }
}
?>
