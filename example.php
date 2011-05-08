<?php
  require 'atdHTMLMinifier.class.php';
  $html = file_get_contents('example.html');
  $html = atdHTMLMinifier::minify($html);
  echo $html;