<?php

$app_root = dirname(__FILE__);
$root = str_replace('/' . basename(__FILE__), '', $_SERVER['PHP_SELF']);
$method = strtolower($_SERVER['REQUEST_METHOD']);
$path = str_replace($root, '', $_SERVER['REQUEST_URI']);
if (substr($path, -1) == '/') {
  $path = substr($path, 0, -1);
}

require_once "$app_root/lib/sfYaml/sfYaml.php";
$site  = sfYaml::load("$app_root/config/site.yml");
$types = sfYaml::load("$app_root/config/post-types.yml");

date_default_timezone_set('GMT'); # what

dispatch($method, $path);

function dispatch($method, $path) {
  global $app_root, $root, $types;
  
  $url_parts = explode('/', substr($path, 1));
  
  if (count($url_parts) == 1 && $url_parts[0] == '') {
    $url_parts = array('page', 0);
  } else if ($method == 'get' && file_exists($public_path = "$app_root/public/$path")) {
    # allow files in the public directory to pass through
    # (stripping public/ from their filenames, natch)

    # first learn a little something about content types
    $filetypes = array(
      'css' => 'text/css',
      'js'  => 'text/javascript'
    );
    
    $content_type = $filetypes[array_pop(explode('.', $path))];
    if ($content_type)
      header("Content-Type: $content_type");
    
    echo file_get_contents($public_path);
    die;
  }
  
  switch($url_parts[0]) {
    case 'new':
      $type = $url_parts[1];
      new_post($type, $_POST['post']);
      break;
      
    case 'page':
      render('posts', array('index' => true, 'permalink' => false, 'posts' => get_posts('posts', intval($url_parts[1]))));
      break;
      
    case 'post':
      render('posts', array('index' => false, 'permalink' => true, 'posts' => array(get_post($url_parts[1]))));
      break;
      
    case 'edit':
      $post_id = $url_parts[1];
      edit_post($post_id, $_POST['post']);
      
    case 'delete':
      if ($method == 'post') {
        $post_id = $url_parts[1];
        delete_post($post_id);
        redirect_to($root);
      }
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
  global $root;
  
  $post = sfYaml::Load("$kind/$id.yml");

  $post['published'] = strftime($post['id']);
  $post['permalink'] = "{$root}/post/{$post['id']}";

  return $post;
}

function new_post($type, $params = null) {
  global $app_root, $root, $types;
  
  if ($params) {
    if (!isset($params['id'])) {
      $params['id'] = time();
    }

    $fhandle = fopen("$app_root/posts/{$params['id']}.yml", 'w');

    fwrite($fhandle, sfYaml::dump($params));
    fclose($fhandle);

    redirect_to("$root/post/{$params['id']}");
    die;
  } else if (isset($type) && in_array($type, array_keys($types))) {
    $fields = $types[$type];

    render('form', array('fields' => $fields, 'type' => $type), 'internal');
  } else {
    render('choose-type', array('types' => $types), 'internal');
  }
  
}

function edit_post($id, $params = null) {
  global $app_root, $root, $types;
  
  if ($params) {
    new_post(null, $params);
    die;
  } else {
    $post = sfYaml::load("posts/$id.yml");
    $fields = $types[$post['type']];
    
    render('form', array('fields' => $fields, 'type' => $type, 'post' => $post), 'internal');
  }
}

function delete_post($id) {
  unlink("posts/$id.yml");
  redirect_to($root);
}

function render($template, $data, $layout = 'theme') {
  global $site, $root, $app_root; # oops
    
  if ($template == 'permalink') {
    # we only have one post here
    $posts = array($posts);
  }
  
  ob_start();
  extract($data);
  include "$app_root/templates/$template.phtml";
  $content_for_layout = ob_get_contents();
  ob_end_clean();
  
  
  include "$app_root/templates/$layout.phtml";
  return;
}

function redirect_to($url) {
  header("Location: $url");
}