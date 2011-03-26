<?php

$env = array(
  'site' => array(
    'title' => 'My Tumblelog',
    'description' => 'The Quick Brown Fox Jumps Over the Lazy Dog'
  ),
  'post-types' => array(
    'text' => array('title' => 'string', 'body' => 'text'),
    'link' => array('url' => 'string', 'text' => 'text'),
    'quote' => array('text' => 'text', 'source' => 'text'),
    'photo' => array('url' => 'string', 'link' => 'string', 'caption' => 'text'),
    'video' => array('embed' => 'string', 'caption' => 'text'),
    'audio' => array('source' => 'string', 'caption' => 'text')
  )
);
