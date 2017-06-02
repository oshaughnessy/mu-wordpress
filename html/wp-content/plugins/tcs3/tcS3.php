<?php
/**
 * Plugin Name: TCS3 -- Send uploads directly to S3
 * Plugin URI: http://tcm.io
 * Description: Allows site admins to push uploads to S3
 * Version: 1.9.1
 * Author: TC McCarthy
 * Author URI: http://tcm.io
 * License: GPL2
 */

$root = dirname(__FILE__) . "/";
$version = "1.9";

require($root . "aws/aws-autoloader.php");
require_once($root . "classes/tcS3.class.php");
require_once($root . "classes/tcS3_wp.class.php");

//instantiate class with base ops
$tcS3 = (object) array(
    "_base" => new tcS3(),
    "_wp" => new tcS3_WP()
);
