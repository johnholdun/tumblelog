<?php

$app_root = dirname(__FILE__);
$root = str_replace('/' . basename(__FILE__), '', $_SERVER['PHP_SELF']);
$path = str_replace($root, '', $_SERVER['REQUEST_URI']);
if (substr($path, -1) == '/') {
  $path = substr($path, 0, -1);
}

require_once "$app_root/lib/sfYaml/sfYaml.php";
$types = sfYaml::load("$app_root/config/post-types.yml");

dispatch($path);

function dispatch($path) {
  global $app_root, $root, $types;
  
  $url_parts = explode('/', substr($path, 1));
  
  if (count($url_parts) == 1 && $url_parts[0] == '') {
    $url_parts = array('page', 0);
  }
  
  switch($url_parts[0]) {
    case 'new':
      $type = $url_parts[1];
      include 'new/index.php';
      break;
      
    case 'page':
      render('index', get_posts('posts', intval($url_parts[1])));
      break;
      
    case 'post':
      render('permalink', get_post($url_parts[1]));
      break;
  }    
}

function get_posts($kind = 'posts', $page = 0, $per_page = 10) {
  # $kind corresponds to a directory
  
  # grab all our filenames while blowing out '.' and '..'
  $posts = array_slice(scandir($kind, 1), 0, -2);

  if ($kind == 'posts') {
    # remove posts that haven't been published yet
    # remember, filenames = unix timestamps for publish dates
    $most_recent_filename = time() . '.yml';

    foreach ($posts as $n => $filename) {
      if ($filename <= $most_recent_filename) {
        $offset = $n;
        break;
      }
    }    
  } else {
    $offset = 0;
  }
  
  # get just one page's worth
  $posts = array_slice($posts, $page * $per_page + $offset, $per_page);
  
  # now replace filenames with data
  foreach ($posts as &$post) {
    $post = get_post(substr($post, 0, -4));
  }
  
  return $posts;
}

function get_post($id, $kind = 'posts') {
  return sfYaml::Load("$kind/$id.yml");
}

function render($template, $posts, $layout = 'default') {
  $output = '';
  
  switch($template) {
    case 'index':
      foreach($posts as $post) {
        $output .= demo_parse_fixme($post);
      }
      
      break;
    case 'permalink':
      $output .= demo_parse_fixme($posts);
      break;
  }
  
  echo $output;
}

function demo_parse_fixme($post) {
  $output = '';
  
  foreach ($post as $field => $content) {
    $output .= "<dt>$field</dt> <dd>$content</dd>";
  }
  
  $output = "<dl>$output</dl>";
  
  return $output;
}