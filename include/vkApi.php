<?php
require_once('Logger.php');

class vkApi {
  private $token;
  private $lang = 'ru';
  private $v = '5.37';
  private $https = '1';

  private $http_context;

  function __construct($token = null, $timeout = 1) {
    if ($token)
      $this->token = $token;

    Logger::init('vkApi', LOG_PERROR);
    
    $this->http_context = stream_context_create(
          array('http' =>
            array(
              'timeout' => $timeout,
            )
          )
        );
  }

  public function hasToken() {
    return isset($this->token);
  }

  public function setToken($token) {
    $this->token = $token;
  }

  private function get_params_string($parameters) {
    $params = array();
    foreach ($parameters as $k => $v) {
      array_push($params, $k. '='. $v);
    }
    return implode('&', $params);
  }

  private function http_get($url) {
    $data = file_get_contents($url, false, $this->http_context);
    if ($data === FALSE)
      throw new Exception('file_get_contents returns FALSE');

    return $data;
  }

  protected function call($method, $parameters = array()) {
    if (isset($this->token))
      $parameters['token'] = $this->token; 
    $parameters['lang'] = $this->lang; 
    $parameters['v'] = $this->v; 
    $parameters['https'] = $this->https; 

    $url = 'https://api.vk.com/method/'. $method. '?'. $this->get_params_string($parameters);
    Logger::log(LOG_DEBUG, "url: $url");

    $data = $this->http_get($url);

    if (! $result = json_decode($data) )
      throw new Exception("can't decode data");

    return $result;
  }

  protected function get_oauth_url(array $parameters) {
    return 'https://oauth.vk.com/authorize?'. $this->get_params_string($parameters);
  }

  protected function get_access_token(array $parameters) {
    $url = 'https://oauth.vk.com/access_token?'. $this->get_params_string($parameters);

    $data = $this->http_get($url);

    if (! $result = json_decode($data) )
      throw new Exception("can't decode data");

    return $result;
  }

  public function get_users($ids, $fields = array(), $name_case = 'nom') {
    if (is_array($ids))
      $ids = implode(',', $ids);
    return $this->call('users.get', array('user_ids' => $ids, 'fields' => implode(',', $fields), 'name_case' => $name_case));
  }

  protected function check_get_users_response($result) {
    if (is_array($result))
      return;

    if (!property_exists($result, 'response'))
      throw new Exception('No response property, result:'. var_export($response, true));

    if (!is_array($result->{'response'}))
      throw new Exception('Response is not array');

    if (count($result->{'response'}) == 0 )
      throw new Exception('User not found', 404);

    if (count($result->{'response'}) != 1 )
      throw new Exception('Response have '. count($result->{'response'}). ' items');
  }

  public function get_user($id, $fields = array('online', 'last_seen', 'online_mobile'), $name_case = 'nom') {
    $result = $this->get_users($id, $fields, $name_case);
    $this->check_get_users_response($result);
    if (is_array($result))
      return $result[0];
    else
      return $result->{'response'}[0];
  }

  public function get_online($user_id) {
    $info = $this->get_user($user_id, array('online', 'last_seen', 'online_mobile'));
    if (!is_object($info))
      throw new Exception('info is not object');

    if (!property_exists($info, 'id'))
      throw new Exception('No id property');

    if ($user_id != $info->{'id'})
      throw new Exception("user_id ($user_id) != id (". $info->{'id'}. ')');

    return $this->get_online_by_info($info);
  }

  public function get_online_by_info($info) {
    if (!is_object($info))
      throw new Exception('info is not object');

    if (!property_exists($info, 'id'))
      throw new Exception('No id property');

    if (!property_exists($info, 'online'))
      throw new Exception('No online property');

    $result = array();
    $result['mobile'] = 0;
    $result['platform'] = 7;
    $result['last_seen'] = NULL;
    $result['app'] = NULL;

    $result['user_id'] = $info->{'id'};
    $result['online'] = $info->{'online'};
    if ($result['online'])
      $result['last_seen'] = $this->get_time();
  
    if (property_exists($info, 'online_mobile'))
      $result['mobile'] = $info->{'online_mobile'};

    if (property_exists($info, 'online_app'))
      $result['app'] = $info->{'online_app'};

    if (property_exists($info, 'last_seen')) {
      if (is_object($info->{'last_seen'})) {
        if (property_exists($info->{'last_seen'}, 'platform'))
          $result['platform'] = $info->{'last_seen'}->{'platform'};
        if (property_exists($info->{'last_seen'}, 'time'))
          $result['last_seen'] = $info->{'last_seen'}->{'time'};
      }
    }
    return $result;
  }

  public function get_time() {
    return time();
  }
}
