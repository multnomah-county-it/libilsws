<?php

// Set the current directory so we can run this from a cron job more easily
$install_path = $argv[1];
$yaml_file = $argv[2];

chdir($install_path);

require_once 'vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

$data = Yaml::parseFile($yaml_file);
$yaml = Yaml::dump($data, 4, 4);

file_put_contents($yaml_file, $yaml);

// EOF
