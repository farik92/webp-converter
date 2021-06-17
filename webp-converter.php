<?php
/*
Plugin Name: WebP Converter
Description: Providing an easy way to convert images to WebP
Version: 1.0.0
Author: Maxim Akimov
License: GPLv2 or later
*/

defined( 'ABSPATH' ) ||
die( 'Constant is missing' );

use WebpConverter\ACTIONS;

require_once __DIR__ . '/vendors/vendor/autoload.php';

ACTIONS::SetHooks();
