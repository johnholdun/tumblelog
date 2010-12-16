<?php

if (isset($_POST['post'])) {
  $params = $_POST['post'];
  if (!isset($params['id'])) {
    $params['id'] = time();
  }

  $fhandle = fopen("$app_root/posts/{$params['id']}.yml", 'w');

  fwrite($fhandle, sfYaml::dump($params));
  fclose($fhandle);
  
  header("Location: $root/post/{$params['id']}");
  die;
} else if (isset($type) && in_array($type, array_keys($types))) {
  $fields = $types[$type];
  
  include 'form.phtml';
} else {
  include 'choose-type.phtml';
}
