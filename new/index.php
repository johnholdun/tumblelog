<?php

if (isset($_POST['post'])) {
  $params = $_POST['post'];
  if (!isset($params['id'])) {
    $params['id'] = time();
  }

  $fhandle = fopen("$root/posts/{$params['id']}.yml", 'w');

  # get rid of id, we don't want that now
  unset($params['id']);

  fwrite($fhandle, sfYaml::dump($params));
  fclose($fhandle);
} else if (isset($type) && in_array($type, array_keys($types))) {
  $fields = $types[$type];
  
  include 'form.phtml';
} else {
  include 'choose-type.phtml';
}
