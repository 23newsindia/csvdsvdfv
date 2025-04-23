<?php
class CG_DB {
    private static $table_name = 'category_grids';

    public static function create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            grid_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            categories LONGTEXT NOT NULL,
            settings LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (grid_id),
            UNIQUE KEY slug (slug)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function check_tables() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            self::create_tables();
        }
    }

    public static function get_grid($slug) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}category_grids 
            WHERE slug = %s", 
            $slug
        ));
    }

    public static function get_grid_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}category_grids 
            WHERE grid_id = %d", 
            $id
        ));
    }

    public static function save_grid($data) {
        global $wpdb;
        
        $defaults = [
            'name' => '',
            'slug' => sanitize_title($data['name']),
            'categories' => json_encode([]),
            'settings' => json_encode([
                'desktop_columns' => 4,
                'mobile_columns' => 2,
                'carousel_mobile' => true,
                'image_size' => 'medium'
            ]),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        $insert_data = wp_parse_args($data, $defaults);
        
        return $wpdb->insert(
            $wpdb->prefix . 'category_grids',
            $insert_data
        );
    }

    public static function update_grid($id, $data) {
        global $wpdb;
        
        $data['updated_at'] = current_time('mysql');
        
        return $wpdb->update(
            $wpdb->prefix . 'category_grids',
            $data,
            ['grid_id' => $id]
        );
    }

    public static function get_all_grids() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT grid_id, name, slug, created_at 
             FROM {$wpdb->prefix}" . self::$table_name . "
             ORDER BY created_at DESC"
        );
    }
}