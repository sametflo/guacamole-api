<?php

require_once 'guacapi.php';

$PK1 = '-----BEGIN PRIVATE KEY-----
Put one private key here
-----END PRIVATE KEY-----';

$PK2 = '-----BEGIN PRIVATE KEY-----
Put an other private key here
-----END PRIVATE KEY-----';

// In SSH and GROUPS, you can set all the JSON parameters used by the Guacamole API

$cnf = ['SSH' => [['name' => 'server1', 'URL' => 'server1.mydomain.org', 'PK' => $PK1],
                  ['name' => 'server2', 'URL' => 'server2.mydomain.org', 'PK' => $PK1],
                  ['name' => 'server3', 'URL' => 'server3.mydomain.org', 'PK' => $PK1],
                  ['name' => 'server4', 'URL' => 'server4.mydomain.org', 'PK' => $PK1, 'port' => 10022, 'user' => 'onlyme'],
                  ['name' => 'server5', 'URL' => 'server5.mydomain.org', 'PK' => $PK1, 'port' => 11122],
                  ['name' => 'server6', 'URL' => 'server6.mydomain.org', 'PK' => $PK1, 'port' => 22222],
                 ],
        'GROUPS' => [['name' => 'MAINGROUP',
                      'GROUPS' => [['name' => 'CHILDGROUP',
                                    'SSH' => [['name' => 'srv1', 'URL' => 'srv1.childgrp.mydomain.org', 'PK' => $PK2],
                                              ['name' => 'srv2', 'URL' => 'srv2.childgrp.mydomain.org', 'PK' => $PK2, 'port' => 11122],
                                              ['name' => 'srv3', 'URL' => 'srv3.childgrp.mydomain.org', 'PK' => $PK2],
                                              ['name' => 'srv4', 'URL' => 'srv4.childgrp.mydomain.org', 'PK' => $PK2, 'port' => 22222],
                                              ['name' => 'srv5', 'URL' => 'srv5.childgrp.mydomain.org', 'PK' => $PK2],
                                              ['name' => 'srv6', 'URL' => 'srv6.childgrp.mydomain.org', 'PK' => $PK2],
                                              ['name' => 'srv7', 'URL' => 'srv7.childgrp.mydomain.org', 'PK' => $PK2],
                                             ],
                                   ],
                                   ['name' => 'EMPTY1GROUP'],
                                   ['name' => 'EMPTY2GROUP'],
                                  ],
                    ]],
       ];

$api = new guacapi('https://guacamole.mydomain.org');
if(!$api->connect('mysuername', 'mypassword'))
  exit("Impossible de se connecter à guacamole\n");

$cnx = $api->getConnections();
$grp = $api->getConnectionGroups();

SetConf($cnf);

function SetConf($cnf, $parent = 'ROOT')
{
  if(isset($cnf['name'])){
    $group = SetGroup($cnf, $parent);
    if(!isset($group['identifier']))
      return False;
    $parent = $group['identifier'];
  }else
    $parent = 'ROOT';
  if(isset($cnf['SSH']) && is_array($cnf['SSH']))
   foreach($cnf['SSH'] as $server)
     SetSSH($server, $parent, 'defaultusername', 22);
  if(isset($cnf['GROUPS']) && is_array($cnf['GROUPS']))
    foreach($cnf['GROUPS'] as $group)
      SetConf($group, $parent);
}

function SetSSH($server, $parent, $defaultUser, $defaultPort)
{
  global $api, $dataSSH;
  if(!(isset($server['name']) && isset($server['URL'])))
    return False;
  echo($server['name'] . ' / ' . $server['URL'] . ' : ');
  $data = $dataSSH;
  $data['name'] = $server['name'];
  $data['parentIdentifier'] = $parent;
  $data['parameters']['hostname'] = $server['URL'];
  $data['parameters']['port'] = isset($server['port']) ? $server['port'] : $defaultPort;
  $data['parameters']['username'] = isset($server['username']) ? $server['username'] : $defaultUser;
  if(isset($server['PK']))
    $data['parameters']['private-key'] = $server['PK'];
  $ret = SearchSSH($data);
  if($ret){
    if($ret['parentIdentifier'] != $parent){
      $data['identifier'] = $ret['identifier'];
      $ret = $api->setConnection($data);
      echo("moved\n");
    }else
      echo("already exists\n");
    return $ret;
  }
  $ret = $api->setConnection($data);
  if(isset($ret['identifier']) && ('' != $ret['identifier'])){
    echo("added\n");
    return $ret;
  }
  echo("error " . json_encode($data) . "\n");
  return False;
}

function SetGroup($group, $parent)
{
  global $api, $dataGroup;
  echo("{$group['name']} : ");
  $data = $dataGroup;
  $data['name'] = $group['name'];
  $data['parentIdentifier'] = $parent;
  $ret = SearchGroup($data);
  if($ret){
    if($ret['parentIdentifier'] != $parent){
      $data['identifier'] = $ret['identifier'];
      $ret = $api->setConnectionGroup($data);
      echo("moved\n");
    }
    echo("already exists\n");
    return $ret;
  }
  $ret = $api->setConnectionGroup($data);
  if(isset($ret['identifier']) && ('' != $ret['identifier'])){
    echo("added\n");
    return $ret;
  }
  echo("error\n");
  return False;
}

function SearchData($data, $haystack)
{
  foreach($haystack as $entry)
    if(($entry['name'] == $data['name']) && ($entry['parentIdentifier'] == $data['parentIdentifier']))
      return $entry;
  return False;
}

function SearchGroup($data)
{
  global $grp;
  return SearchData($data, $grp);
}

function SearchSSH($data)
{
  global $cnx;
  return SearchData($data, $cnx);
}