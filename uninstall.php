<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-db-install.php';
PL_DB_Install::drop_tables();
