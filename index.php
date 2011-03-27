<?php

require 'lib/markdown.php';
require 'lib/smartypants.php';
require 'lib/sfYaml/sfYaml.php';

require 'config/env.php';
require 'config/user.php';
require "posts/post_slugs.php";

$env = array_merge($env, array(
  'authorized' => false,
  'user' => $user,
  'post-slugs' => $post_slugs,
  'root' => str_replace('/' . basename(__FILE__), '', $_SERVER['PHP_SELF']),
  'app-root' => dirname(__FILE__),
  'request' => array(
    'method' => strtolower($_SERVER['REQUEST_METHOD'])
  )  
));

$env['request']['path'] = str_replace($env['root'], '', $_SERVER['REQUEST_URI']);
if (substr($env['request']['path'], -1) == '/') {
  $env['request']['path'] = substr($env['request']['path'], 0, -1);
}

if ($env['request']['method'] == 'get' && $env['request']['path'] &&
  file_exists($public_path = "{$env['app-root']}/public{$env['request']['path']}")) {
  # allow files in the public directory to pass through
  # (stripping public/ from their filenames, natch)

  # first learn a little something about content types
  $filetypes = array(
    'css' => 'text/css',
    'js'  => 'text/javascript'
  );
  
  $content_type = $filetypes[array_pop(explode('.', $env['request']['path']))];
  if ($content_type)
    header("Content-Type: $content_type");
  
  echo file_get_contents($public_path);
  die;
}

date_default_timezone_set('GMT');

session_start();
if (isset($_SESSION['_tumblelog_session']) &&
    isset($env['user']['session_key']) &&
    $env['user']['session_key'] == $_SESSION['_tumblelog_session']) {
    $env['authorized'] = true;
    set_user_session_key();
}

# do we have our settings?
if (isset($env['site'])) {
  dispatch($env['request']);
} else {
  dispatch(array('path' => '/settings', 'method' => $env['request']['method']));
}

function dispatch($request) {
  global $env;
  
  $url_parts = explode('/', substr($request['path'], 1));
  
  if (count($url_parts) == 1 && $url_parts[0] == '') {
    $url_parts = array('page', 0);
  }
  
  switch($url_parts[0]) {
    case 'page':
      render('posts', array('index' => true, 'permalink' => false, 'posts' => get_posts('posts', intval($url_parts[1]))));
      break;
      
    case 'new':
      require_authorization();
      $type = $url_parts[1];
      new_post($type, $_POST['post']);
      break;
      
    case 'edit':
      require_authorization();
      $post_id = $url_parts[1];
      edit_post($post_id, $_POST['post']);
      break;
      
    case 'delete':
      require_authorization();
      if ($request['method'] == 'post') {
        $post_id = $url_parts[1];
        delete_post($post_id);
        redirect_to($env['root']);
      }
      break;
      
    case 'settings':
      if ($env['site']) require_authorization();
      if ($request['method'] == 'post') {        
        $env['site'] = $_POST['site'];
        $env['user'] = $_POST['user'];
        $env['user']['password'] = md5($env['user']['password']);
        save_settings();
        save_user();
        
        header("Location: {$env['root']}");
      } else {
        render('settings', array(), 'internal');
      }
      break;
      
    case 'login':
      if ($request['method'] == 'post' && $_POST['user']['email'] == $env['user']['email'] && md5($_POST['user']['password']) == $env['user']['password']) {
        set_user_session_key();
        redirect_to($env['root']);
      } else {
        render('login', array('email' => $_POST['user']['email']), 'internal');        
      }
      break;
      
    case 'logout':
      require_authorization();
      unset($_SESSION['_tumblelog_session']);
      unset($env['user']['session_key']);
      save_user();
      redirect_to($env['root']);
      die;
      break;
      
    default:
      if (array_key_exists($url_parts[0], $env['post-slugs'])) {
        render('posts', array('index' => false, 'permalink' => true, 'posts' => array(get_post($env['post-slugs'][$url_parts[0]]))));
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
  global $env;
  
  $post = sfYaml::Load("$kind/$id.yml");

  return fill_generated_post_fields($post);
}

function new_post($type, $params = null) {
  global $env;
  
  if ($params) {
    $post = save_post($params);
    redirect_to_post($post);
    die;
  } else if (isset($type) && in_array($type, array_keys($env['post-types']))) {
    $fields = $env['post-types'][$type];

    render('form', array('fields' => $fields, 'type' => $type), 'internal');
  } else {
    render('choose-type', array('types' => $env['post-types']), 'internal');
  }
  
}

function edit_post($id, $params = null) {
  global $env;
  
  if ($params) {
    new_post(null, $params);
    die;
  } else {
    $post = sfYaml::load("posts/$id.yml");
    $fields = $env['post-types'][$post['type']];
    
    render('form', array('fields' => $fields, 'type' => $type, 'post' => $post), 'internal');
  }
}

function delete_post($id) {
  global $env;
  
  $post = get_post($id);
  unlink("posts/$id.yml");
  
  unset($env['post-slugs'][$post['slug']]);
  save_slugs();
  
  redirect_to($env['root']);
}

function save_post($params) {
  global $env;
  
  if (!isset($params['id'])) {
    $params['id'] = time();
  }
  
  if (!isset($params['slug']) || !strlen($params['slug'])) {
    $slugs = array_keys($env['post-slugs']);
    $last_slug = $slugs[-1];
    if (is_numeric($last_slug) && !array_key_exists($next_slug = strval(intval($last_slug) + 1))) {
      $params['slug'] = $next_slug;
    } else {
      foreach($params as $field => $value) {
        if (!strlen($value)) { continue; }
        $field_type = $env['post-types'][$params['type']][$field];
        if ($field_type == 'string' || $field_type == 'text') {
          $slugified_words = explode(' ', preg_replace('/[^a-z0-9 -]/', '', trim(strtolower(strip_tags($value)))));
          $slug = array_shift($slugified_words);
          foreach ($slugified_words as $word) {
            if (strlen($slug . "-$word") <= 30) {
              $slug .= "-$word";              
            }
          }
          $base_slug = $slug;
          $n = 2;
          while (array_key_exists($slug, $env['post-slugs'])) {
            $slug = "$base_slug-$n";
            $n++;
          }
          $params['slug'] = $slug;
          break;
        }
      }
    }
  } else {
    # make sure it's good anyway though
    $params['slug'] = str_replace(' ', '-', preg_replace('/[^a-z0-9 -]+/', '', trim(strtolower($params['slug']))));
  }

  $fhandle = fopen("posts/{$params['id']}.yml", 'w');

  foreach ($params as $field => &$param) {
    if ($field == 'id' || $field == 'type') continue;

    $field_type = $env['post-types'][$params['type']][$field];
    if ($field_type == 'string' || $field_type == 'text') {
      $param = SmartyPants(stripslashes($param));

      if ($field_type == 'text') {
        $param = Markdown($param);
      }
    }

    $param = superhtmlentities($param);
  }

  fwrite($fhandle, sfYaml::dump($params));
  fclose($fhandle);
  
  $env['post-slugs'][$params['slug']] = $params['id'];
  save_slugs();
  
  return $params;
}

function save_slugs() {
  global $env;
  
  save_config_file('posts/post_slugs.php', 'post_slugs', $env['post-slugs']);
}

function save_settings() {
  global $env;

  # just copy in the permanent stuff
  $env_copy = array(
    'site' => $env['site'],
    'post-types' => $env['post-types']
  );
    
  save_config_file('config/env.php', 'env', $env_copy);
}

function save_user() {
  global $env;
  save_config_file('config/user.php', 'user', $env['user']);
}

function save_config_file($filename, $var_name, $data) {
  $fhandle = fopen($filename, 'w');
  fwrite($fhandle, "<?php \${$var_name}=" . var_export($data, true) . ';');
  fclose($fhandle); 
}

function set_user_session_key() {
  global $env;
  
  # you should change this
  $secret_key = 'il3kn324h23i3rb2f238ufvucb897ssd7f23n3nr2339vy987bvg7ds89f7dfb';
  $env['user']['session_key'] = md5($secret_key . $env['user']['email'] . $env['user']['password'] . time());
  $_SESSION['_tumblelog_session'] = $env['user']['session_key'];
  save_user();
}

function require_authorization() {
  global $env;
  
  if (!$env['authorized']) {
    header("Location: {$env['root']}");
    die;
  }
}

function render($template, $data = array(), $layout = 'theme') {
  global $env;
    
  if ($template == 'permalink') {
    # we only have one post here
    $posts = array($posts);
  }
  
  ob_start();
  extract($data);
  include "templates/$template.phtml";
  $content_for_layout = ob_get_contents();
  ob_end_clean();
  
  
  include "templates/$layout.phtml";
  return;
}

function fill_generated_post_fields($post) {
  global $env;
  
  $post['published'] = strftime($post['id']);
  $post['permalink'] = "{$env['root']}/{$post['slug']}";
  
  return $post;
}

function redirect_to($url) {
  header("Location: $url");
}

function redirect_to_post($post) {
  $post = fill_generated_post_fields($post);
  redirect_to($post['permalink']);
}

function superhtmlentities($string) { 
  $string = str_split($string);

  foreach($string as &$char) { 
    $num = ord($char);
    if ($num > 127) { $char = "&#{$num};"; }
  }
  
  return implode('', $string); 
}
