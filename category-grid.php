<?php
/*
Plugin Name: Category Grid Display
Description: Display product categories in responsive grids with carousel support
Version: 1.0.0
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

// Define constants
define('CG_VERSION', '1.0.0');
define('CG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CG_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include core classes
require_once CG_PLUGIN_DIR . 'includes/class-cg-db.php';
require_once CG_PLUGIN_DIR . 'includes/class-cg-admin.php';
require_once CG_PLUGIN_DIR . 'includes/class-cg-frontend.php';
require_once CG_PLUGIN_DIR . 'includes/class-cg-cache.php';

// Initialize components
register_activation_hook(__FILE__, ['CG_DB', 'create_tables']);
add_action('plugins_loaded', function() {
    CG_DB::check_tables();
    CG_Cache::init(); // Initialize cache
    new CG_Admin();
    new CG_Frontend();
});