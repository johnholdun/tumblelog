<?php

$app_root = dirname(__FILE__);
$root = str_replace('/' . basename(__FILE__), '', $_SERVER['PHP_SELF']);
$method = strtolower($_SERVER['REQUEST_METHOD']);
$path = str_replace($root, '', $_SERVER['REQUEST_URI']);
if (substr($path, -1) == '/') {
  $path = substr($path, 0, -1);
}

require_once "$app_root/lib/markdown.php";
require_once "$app_root/lib/smartypants.php";
require_once "$app_root/lib/sfYaml/sfYaml.php";
require_once "$app_root/posts/post_slugs.php";
require_once "$app_root/config.php";

date_default_timezone_set('GMT'); # what

dispatch($method, $path);

function dispatch($method, $path) {
  global $app_root, $root, $config, $post_slugs;
  
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
      
    case 'edit':
      $post_id = $url_parts[1];
      edit_post($post_id, $_POST['post']);
      break;
      
    case 'delete':
      if ($method == 'post') {
        $post_id = $url_parts[1];
        delete_post($post_id);
        redirect_to($root);
      }
      break;
      
    default:
      if (array_key_exists($url_parts[0], $post_slugs)) {
        render('posts', array('index' => false, 'permalink' => true, 'posts' => array(get_post($post_slugs[$url_parts[0]]))));
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

  return fill_generated_post_fields($post);
}

function new_post($type, $params = null) {
  global $app_root, $root, $config;
  
  if ($params) {
    $post = save_post($params);
    redirect_to_post($post);
    die;
  } else if (isset($type) && in_array($type, array_keys($config['post-types']))) {
    $fields = $config['post-types'][$type];

    render('form', array('fields' => $fields, 'type' => $type), 'internal');
  } else {
    render('choose-type', array('types' => $config['post-types']), 'internal');
  }
  
}

function edit_post($id, $params = null) {
  global $app_root, $root, $config;
  
  if ($params) {
    new_post(null, $params);
    die;
  } else {
    $post = sfYaml::load("posts/$id.yml");
    $fields = $config['post-types'][$post['type']];
    
    render('form', array('fields' => $fields, 'type' => $type, 'post' => $post), 'internal');
  }
}

function delete_post($id) {
  unlink("posts/$id.yml");
  redirect_to($root);
}

function save_post($params) {
  global $app_root, $config, $post_slugs;
  
  if (!isset($params['id'])) {
    $params['id'] = time();
  }
  
  if (!isset($params['slug']) || !strlen($params['slug'])) {
    $slugs = array_keys($post_slugs);
    $last_slug = $slugs[-1];
    if (is_numeric($last_slug) && !array_key_exists($next_slug = strval(intval($last_slug) + 1))) {
      $params['slug'] = $next_slug;
    } else {
      foreach($params as $field => $value) {
        if (!strlen($value)) { continue; }
        $field_type = $config['post-types'][$params['type']][$field];
        if ($field_type == 'string' || $field_type == 'text') {
          $slugified_words = explode(' ', preg_replace('/[^a-z0-9 ]/', '', strip_tags(strtolower($value))));
          $slug = array_shift($slugified_words);
          foreach ($slugified_words as $word) {
            if (strlen($slug . "-$word") <= 30) {
              $slug .= "-$word";              
            }
          }
          $base_slug = $slug;
          $n = 2;
          while (array_key_exists($slug, $post_slugs)) {
            $slug = "$base_slug-$n";
            $n++;
          }
          $params['slug'] = $slug;
          break;
        }
      }
    }
  }

  $fhandle = fopen("$app_root/posts/{$params['id']}.yml", 'w');

  foreach ($params as $field => &$param) {
    if ($field == 'id' || $field == 'type') continue;

    $field_type = $config['post-types'][$params['type']][$field];
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
  
  $post_slugs[$params['slug']] = $params['id'];
  $fhandle = fopen("$app_root/posts/post_slugs.php", 'w');
  fwrite($fhandle, '<?php $post_slugs=' . var_export($post_slugs, true) . ';');
  
  return $params;
}

function render($template, $data, $layout = 'theme') {
  global $config, $root, $app_root; # oops
    
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

function fill_generated_post_fields($post) {
  global $root;
  
  $post['published'] = strftime($post['id']);
  $post['permalink'] = "{$root}/{$post['slug']}";
  
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