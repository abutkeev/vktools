<?php
require_once('vkApi.php');
require_once('Logger.php');
require_once('Config.php');
include_once('tgTools.php');

class vkTools extends vkApi{
  private $db;
  private $tg;

  function __construct() {
    parent::__construct(Config::TOKEN);

    $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8');
    $this->db  = new PDO('mysql:dbname='. Config::DB_NAME. ';host='. Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    Logger::init('vkTools', LOG_PERROR);

    $this->tg = new tgTools();
  }

  public function merge_sessions($diff = 14*60, $max_age=3600) {
#    Logger::$debug = true;
    $this->db->beginTransaction();

    $sth_key = $this->db->query('SELECT user_id, platform, mobile, app FROM online GROUP BY user_id, platform, mobile, app');

    $sth_null = $this->db->prepare(
        'SELECT id, since, till, platform, mobile, app FROM online WHERE UNIX_TIMESTAMP() - since < :max_age AND user_id = :user_id AND platform = :platform AND mobile = :mobile AND app is NULL FOR UPDATE');
    $sth_not_null = $this->db->prepare(
        'SELECT id, since, till, platform, mobile, app FROM online WHERE UNIX_TIMESTAMP() - since < :max_age AND user_id = :user_id AND platform = :platform AND mobile = :mobile AND app = :app FOR UPDATE');

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

  public function get_user($id, $fields = array('online', 'last_seen', 'online_mobile')) {
    $user = parent::get_user($id, $fields, 'nom');
    $this->db->prepare('REPLACE INTO users (id, first_name, last_name) VALUES (:id, :first_name, :last_name)')
      ->execute(array('id' => $user->{'id'}, 'first_name' => $user->{'first_name'}, 'last_name' =>$user->{'last_name'}));

    return $user;
  }
}
?>
