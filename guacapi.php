<?php

$dataSSH = [
  'identifier' => '',
  'parentIdentifier' => 'ROOT',
  'protocol' => 'ssh',
  'attributes' => [
    'max-connections' => '',
  ],
  'parameters' => [
    'port' => 22,
  ],
];

$dataGroup = [
  'parentIdentifier' => 'ROOT',
  'type' => 'ORGANIZATIONAL',
  'attributes' => [
    'max-connections' => '',
  ],
];

$dataUser = [
  'username' => '',
  'attributes' => [
    'guac-email-address' => null,
    'guac-organizational-role' => null,
    'guac-full-name' => null,
    'expired' => '',
    'timezone' => null,
    'access-window-start' => '',
    'guac-organization' => null,
    'access-window-end' => '',
    'disabled' => '',
    'valid-until' => '',
    'valid-from' => '',
  ],
];

$dataUserGroup = [
  'identifier' => '',
  'attributes' => [
    'disabled' => '',
  ],
];

$dataSharingProfile = [
];

class guacapi
{

  private $url;
  private $curl;
  private $params;
  private $result;
  private $session;
  private $response;
  private $authUser;
  private $debuglevel;

  function __construct($url, $debuglevel = 0)
  {
    $this->url = "$url/api/";
    $this->params = [];
    $this->result = '';
    $this->session = '';
    $this->debug($debuglevel);

    if (!function_exists('curl_init'))
      throw new Exception('php cURL extension must be installed and enabled');

    $this->curl = curl_init();
    curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
  }

  function __destruct()
  {
    $this->deleteToken();
    curl_close($this->curl);
  }

  public function verifyHost($param)
  {
    curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, $param);
  }

  public function verifyPeer($param)
  {
    curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, $param);
  }

  public function debug($activate)
  {
    $this->debuglevel = is_int($activate) ? $activate : 0;
  }

  // Communication functions

  private function httpreq($url, $params = [], $data = '', $method = 'GET', $content_type = '')
  {
    if($params)
      $url .= '?' . http_build_query($params);
    if($this->debuglevel > 0)
      echo("URL : " . $this->url . "$url ($method)\n");
    if($this->debuglevel > 2)
      curl_setopt($this->curl, CURLOPT_VERBOSE, 1);
    curl_setopt($this->curl, CURLOPT_URL, $this->url . $url);
    curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($this->curl, CURLOPT_HTTPHEADER, ["Content-Type: $content_type"]);
    curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
    $this->result = curl_exec($this->curl);
    if($this->debuglevel > 1)
      echo("Result : {$this->result}\n");
    return $this->result;
  }

  private function req($url, $data = '', $method = 'GET', $content_type = '')
  {
    $params = $this->params;
    if(('GET' == $method) && is_array($data)){
      $params = array_replace($params, $data);
      $data = '';
    }
    return $this->httpreq($this->session . $url, array_filter($params), $data, $method, $content_type);
  }

  private function delete($url)
  {
    return $this->req($url, '', 'DELETE');
  }

  private function reqJSON($url, $data, $method)
  {
    $this->req($url, json_encode($data), $method, 'application/json');
    $result = json_decode($this->result, true);
    if(NULL === $result)
      return $this->result;
    else
      return $result;
  }

  private function setJSON($url, $data)
  {
    if (!empty($data['identifier'])) {
      $method = 'PUT';
      $url .= '/' . $data['identifier'];
    } else
      $method = 'POST';
    return $this->reqJSON($url, $data, $method);
  }

  // JSON-PATCH

  public function patchAdd(&$data, $path, $value)
  {
    $data[] = ['op' => 'add', 'path' => $path, 'value' => $value];
  }

  public function patchAddPaths(&$data, $path, $list, $value)
  {
    foreach($list as $param)
      patchAdd($data, $path . $param, $value);
  }

  public function patchAddValues(&$data, $path, $values)
  {
    foreach($values as $value)
      patchAdd($data, $path, $value);
  }

  public function patchRemove(&$data, $path, $value = NULL)
  {
    $patch = ['op' => 'remove', 'path' => $path];
    if(NULL != $value)
      $patch['value'] = $value;
    $data[] = $patch;
  }

  public function patchRemovePaths(&$data, $path, $list, $value = NULL)
  {
    foreach($list as $param)
      patchRemove($data, $path . $param, $value);
  }

  public function patchRemoveValues(&$data, $path, $values)
  {
    foreach($values as $value)
      patchRemove($data, $path, $value);
  }

  public function addUserConnections(&$data, $ids)
  {
    patchAddPaths($data, '/connectionPermissions/', $ids, 'READ');
  }

  public function removeUserConnections(&$data, $ids)
  {
    patchRemovePaths($data, '/connectionPermissions/', $ids, 'READ');
  }

  public function addUserConnectionGroups(&$data, $ids)
  {
    patchAddPaths($data, '/connectionGroupPermissions/', $ids, 'READ');
  }

  public function removeUserConnectionGroups(&$data, $ids)
  {
    patchRemovePaths($data, '/connectionGroupPermissions/', $ids, 'READ');
  }

  public function addUserGroups(&$data, $ids)
  {
    patchAddValues($data, '/', $ids);
  }

  public function removeUserGroups(&$data, $ids)
  {
    patchRemoveValues($data, '/', $ids);
  }

  public function addMembersToGroup(&$data, $users)
  {
    patchAddValues($data, '/', $users);
  }

  public function removeMembersToGroup(&$data, $users)
  {
    patchRemoveValues($data, '/', $users);
  }

  // AUTHENTICATION

  public connect($username, $password)
  {
    $this->authUser = $username; // No need/desire to store password !
    $this->getToken($username, $password);
    return !empty($this->params['token']);
  }

  private function getToken($username, $password)
  {
    $this->params = [];
    $this->session = '';
    $data = json_decode($this->req('tokens', "username=$username&password=$password", 'POST', 'application/x-www-form-urlencoded'), true);
    $this->session = isset($data['dataSource']) ? "session/data/{$data['dataSource']}/" : '';
    $this->params = isset($data['authToken']) ? ['token' => $data['authToken']] : [];
  }

  private function deleteToken()
  {
    if(empty($this->params['token']))
      return false;
    $token = $this->params['token'];
    $this->params = [];
    $this->session = '';
    $this->authUser = '';
    return $this->delete("tokens/$token");
  }

  // LANGUAGES

  public function getLanguages()
  {
    return json_decode($this->httpreq('languages'), true);
  }

  // USERS

  /* permissions
   *   The set of permissions to filter with.
   *   A user must have one or more of these permissions to appear in the result.
   *   Valid values are listed within PermissionSet.ObjectType.
   */
  public function getUsers($permissions = '')
  {
    if(is_array($permissions))
      $permissions = implode(',', $permissions);
    if('' != $permissions)
      $permissions = ['permission' => $permissions];
    return json_decode($this->req('users', $permissions), true);
  }

  // Returns the authenticated user if username parameter is not provided or is an empty string
  public function getUser($username = '')
  {
    if('' == $username)
      return json_decode($this->req('self'), true);
    return json_decode($this->req("users/$username"), true);
  }

  // Peut-on créer un utilisateur avec un id renseigné ?
  public function createUser($data)
  {
    global $dataUser;
    return json_decode($this->reqJSON('users', array_replace($dataUser, $data), 'PUT'), true);
  }

  public function updateUser($data)
  {
    global $dataUser;
    return json_decode($this->reqJSON("users/{$data['username']}", array_replace($dataUser, $data), 'POST'), true);
  }

  public function deleteUser($username)
  {
    return $this->delete("users/$username");
  }

  public function updateUserPassword($username, $oldpwd, $newpwd)
  {
    return json_decode($this->reqJSON("users/$username/password", ['oldPassword' => $oldpwd, 'newPassword' => $newpwd], 'PUT'), true);
  }

  // USER GROUPS

  /* permissions
   *   The set of permissions to filter with.
   *   A group must have one or more of these permissions to appear in the result.
   *   Valid values are listed within PermissionSet.ObjectType.
   */
  public function getUserGroups($permissions = '')
  {
    if(is_array($permissions))
      $permissions = implode(',', $permissions);
    if('' != $permissions)
      $permissions = ['permission' => $permissions];
    return json_decode($this->req('userGroups', $permissions), true);
  }

  public function getUserGroup($groupname)
  {
    return json_decode($this->req("userGroups/$groupname"), true);
  }

  public function setUserGroup($data, $update)
  {
    global $dataUserGroup;
    return json_decode($this->seqJSON('userGroups', array_replace($dataUserGroup, $data), $update), true);
  }

  public function deleteUserGroup($groupname)
  {
    return $this->delete("userGroups/$groupname");
  }

  // CONNECTIONS

  public function getConnections()
  {
    return json_decode($this->req("connections"), true);
  }

  public function getConnection($id)
  {
    return json_decode($this->req("connections/$id"), true);
  }

  public function getConnectionHistory($id)
  {
    return getConnections("$id/history");
  }

  public function getConnectionParameters($id)
  {
    return getConnections("$id/parameters");
  }

  public function setConnection($data)
  {
    return $this->setJSON('connections', $data);
  }

  public function deleteConnection($id)
  {
    return $this->delete("connections/$id");
  }

  // CONNECTION-GROUPS

  public function getConnectionGroupTree($path = 'ROOT')
  {
    return json_decode($this->req("connectionGroups/$path/tree"), true);
  }

  // You'll get all connections if id is empty or not set
  public function getConnectionGroups()
  {
    return json_decode($this->req('connectionGroups'), true);
  }

  public function getConnectionGroup($id)
  {
    return json_decode($this->req("connectionGroups/$id"), true);
  }

  public function setConnectionGroup($data)
  {
    return $this->setJSON('connectionGroups', $data);
  }

  public function deleteConnectionGroup($id)
  {
    return $this->delete("connectionGroups/$id");
  }

  // History

  public function getConnectionsHistory($contains = '', $order = '')
  {
    return json_decode($this->req('history/connections', ['contains' => $contains, 'order' => $order]), true);
  }

  public function getUsersHistory($order = '')
  {
    return json_decode($this->req('history/users', ['order' => $order]), true);
  }

  // Users and groups permissions

  // PATCHES...

  public function getPatches()
  {
    return json_decode($this->httpreq('patches'), true);
  }

  // Active Connection

  public function getActiveConnections($permissions = '')
  {
    if(is_array($permissions))
      $permissions = implode(',', $permissions);
    if('' != $permissions)
      $permissions = ['permission' => $permissions];
    return json_decode($this->req('activeConnections', $permissions), true);
  }

  public function getActiveConnection($id)
  {
    return json_decode($this->req("activeConnections/$id"), true);
  }

  // You can kill one or more (array) connections
  public function killActiveConnections($id)
  {
    $patch = [];
    if (is_array($id))
      patchRemovePaths($patch, '/', $id);
    else
      patchRemove($patch, "/$id");
    return $this->reqJSON('activeConnections', $patch, "PATCH");
  }

  public function getSharingCredentials($id, $sharingProfile)
  {
    return json_decode($this->req("activeConnections/$id/sharingCredentials/$sharingProfile"), true);
  }

  // Sharing profile

  public function getSharingProfile($id)
  {
    return json_decode($this->req("sharingProfiles/$id"), true);
  }

  public function setSharingProfile($data, $update)
  {
    global $dataSharingProfile;
    return json_decode($this->seqJSON('sharingProfiles', array_replace($dataSharingProfile, $data), $update), true);
  }

  public function deleteSharingProfile($id)
  {
    return $this->delete("sharingProfiles/$id");
  }

  public function getSharingProfileParameters($id)
  {
    return json_decode($this->req("sharingProfiles/$id/parameters"), true);
  }

  // Permissions

  private function effectivePermissionsURL($username)
  {
    if($username = $this->authUser)
      return 'self/effectivePermissions';
    return "users/$username/effectivePermissions";
  }

  public function getEffectivePermissions($username)
  {
    return json_decode($this->req(effectivePermissionsURL($username)), true);
  }

  private function permissionsURL($id, $group)
  {
    if($group)
      return "userGroups/$id/permissions";
    if($id = $this->authUser)
      return 'self/permissions';
    return "users/$username/permissions";
  }

  public function getPermissions($id, $group = false)
  {
    return json_decode($this->req(permissionsURL($id, $group)), true);
  }

  public function setUserPermissions($id, $data, $group = false)
  {
    return $this->reqJSON(permissionsURL($id, $group), $data, 'PATCH');
  }

  // Membership

  private function userGroupsURL($id, $group)
  {
    if($group)
      return "userGroups/$id/userGroups";
    if($id = $this->authUser)
      return 'self/userGroups';
    return "users/$username/userGroups";
  }

  public function getUsersGroups($id, $group)
  {
    return json_decode($this->req(userGroupsURL($id, $group)), true);
  }

  public function setUsersGroups($id, $group, $data)
  {
    return $this->reqJSON(userGroupsURL($id, $group), $data, 'PATCH');
  }

  public function getMemberUsers($username, $data)
  {
    return json_decode($this->req("userGroups/$username/memberUsers"), true);
  }

  public function setMemberUsers($username, $data)
  {
    return patchGroup($groupname, "userGroups/$username/memberUsers", $data);
  }

  public function getMemberUserGroups($username, $data)
  {
    return json_decode($this->req("userGroups/$username/memberUserGroups"), true);
  }

  public function setMemberUserGroups($username, $data)
  {
    return patchGroup($groupname, "userGroups/$username/memberUserGroups", $data);
  }

  // Schema

  private function getSchemaInformation($info)
  {
    return json_decode($this->req("schema/$info"), true);
  }

  public function getSchemaUserAttributes()
  {
    return getSchemaInformation('userAttributes');
  }

  public function getSchemaUserGroupAttributes()
  {
    return getSchemaInformation('userGroupAttributes');
  }

  public function getSchemaConnectionAttributes()
  {
    return getSchemaInformation('connectionAttributes');
  }

  public function getSchemaSharingProfileAttributes()
  {
    return getSchemaInformation('sharingProfileAttributes');
  }

  public function getSchemaConnectionGroupAttributes()
  {
    return getSchemaInformation('connectionGroupAttributes');
  }

  public function getSchemaProtocols()
  {
    return getSchemaInformation('protocols');
  }

  // Tunnels

  public function getTunnels()
  {
    return json_decode($this->httpreq('session/tunnels'), true);
  }

  private function getTunnelsInformation($info)
  {
    return json_decode($this->httpreq("session/tunnels/$info"), true);
  }

  public function getSharingProfiles($tunnel)
  {
    return getTunnelsInformation("$tunnel/protocol");
  }

  public function getActiveConnectionSharingProfiles($tunnel)
  {
    return getTunnelsInformation("$tunnel/activeConnection/connection/sharingProfiles");
  }

  public function getActiveConnectionSharingCredentials($tunnel, $sharingProfile)
  {
    return getTunnelsInformation("$tunnel/activeConnection/connection/sharingCredentials/$sharingProfile");
  }

}