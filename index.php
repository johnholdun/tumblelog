<?php

$app_root = dirname(__FILE__);
$root = str_replace('/' . basename(__FILE__), '', $_SERVER['PHP_SELF']);
$path = str_replace($root, '', $_SERVER['REQUEST_URI']);
if (substr($path, -1) == '/') {
  $path = substr($path, 0, -1);
}

require_once "$app_root/lib/sfYaml/sfYaml.php";
$site  = sfYaml::load("$app_root/config/site.yml");
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
  
  # grab all our filenames
  $posts_filenames = scandir($kind, 1);
  $posts = array();
  
  foreach($posts_filenames as $filename) {
    # remove files that aren't posts
    # and posts that haven't been published yet
    # remember, filenames = unix timestamps for publish dates
    $most_recent_filename = time() . '.yml';

    if (substr($filename, 0, 1) != '.' && ($kind != 'posts' || $filename <= $most_recent_filename)) {
      $posts[] = $filename;
    }
  }

  # get just one page's worth
  $posts = array_slice($posts, $page * $per_page, $per_page);
  
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
  global $site, $root; # oops
    
  if ($template == 'permalink') {
    # we only have one post here
    $posts = array($posts);
  }
  
  # if we were OO here (and we oughta be), this is where we'd fill in
  # stuff like permalink URLs and default values. oh wait let's do that
  
  foreach($posts as &$post) {
    $post['permalink'] = "{$root}/post/{$post['id']}";
  }
  
  include 'layout.phtml';
  return;
}
