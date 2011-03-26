<?php

require_once 'lib/markdown.php';
require_once 'lib/smartypants.php';
require_once 'lib/sfYaml/sfYaml.php';

try {
  require_once 'config.php';
} catch (Exception $e) {
  require_once 'install.php';
  die;
}

require_once "./posts/post_slugs.php";

$env['post-slugs'] = $post_slugs;

$env['app-root'] = dirname(__FILE__);
$env['root'] = str_replace('/' . basename(__FILE__), '', $_SERVER['PHP_SELF']);
$env['request'] = array(
  'method' => strtolower($_SERVER['REQUEST_METHOD']),
  'path' => str_replace($env['root'], '', $_SERVER['REQUEST_URI'])
);

if (substr($env['request']['path'], -1) == '/') {
  $env['request']['path'] = substr($env['request']['path'], 0, -1);
}

date_default_timezone_set('GMT'); # what

dispatch($env['request']);

function dispatch($request) {
  global $env;
  
  $url_parts = explode('/', substr($request['path'], 1));
  
  if (count($url_parts) == 1 && $url_parts[0] == '') {
    $url_parts = array('page', 0);
  } else if ($request['method'] == 'get' && file_exists($public_path = "{$env['app-root']}/public/{$request['path']}")) {
    # allow files in the public directory to pass through
    # (stripping public/ from their filenames, natch)

    # first learn a little something about content types
    $filetypes = array(
      'css' => 'text/css',
      'js'  => 'text/javascript'
    );
    
    $content_type = $filetypes[array_pop(explode('.', $request['path']))];
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
      if ($request['method'] == 'post') {
        $post_id = $url_parts[1];
        delete_post($post_id);
        redirect_to($env['root']);
      }
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

  $fhandle = fopen("{$env['app-root']}/posts/{$params['id']}.yml", 'w');

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
  
  $fhandle = fopen("{$env['app-root']}/posts/post_slugs.php", 'w');
  fwrite($fhandle, '<?php $post_slugs=' . var_export($env['post-slugs'], true) . ';');
  fclose($fhandle);
}

function render($template, $data, $layout = 'theme') {
  global $env;
    
  if ($template == 'permalink') {
    # we only have one post here
    $posts = array($posts);
  }
  
  ob_start();
  extract($data);
  include "{$env['app-root']}/templates/$template.phtml";
  $content_for_layout = ob_get_contents();
  ob_end_clean();
  
  
  include "{$env['app-root']}/templates/$layout.phtml";
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