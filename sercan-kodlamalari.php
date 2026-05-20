<?php
/**
 * Plugin Name: Sercan Kodlamaları
 * Description: Sercan için özel yönetim araçları. Modern WooCommerce ve yardımcı admin araçları içerir.
 * Version: 3.1.0
 * Author: Sercan
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SERCAN_KODLAMALARI_VERSION', '3.1.0');
define('SERCAN_KODLAMALARI_FILE', __FILE__);
define('SERCAN_KODLAMALARI_PATH', plugin_dir_path(__FILE__));
define('SERCAN_KODLAMALARI_URL', plugin_dir_url(__FILE__));

require_once SERCAN_KODLAMALARI_PATH . 'includes/class-sercan-kodlamalari-admin.php';
require_once SERCAN_KODLAMALARI_PATH . 'includes/class-sercan-draft-product-cleaner.php';
require_once SERCAN_KODLAMALARI_PATH . 'includes/class-sercan-url-source-splitter.php';

add_action('plugins_loaded', function () {
    new Sercan_Kodlamalari_Admin();

    if (class_exists('WooCommerce')) {
        new Sercan_Draft_Product_Cleaner();
    }

    new Sercan_URL_Source_Splitter();
});