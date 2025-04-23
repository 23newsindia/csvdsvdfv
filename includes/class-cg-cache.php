<?php
class CG_Cache {
    const CACHE_GROUP = 'category_grid';
    const CACHE_EXPIRY = HOUR_IN_SECONDS;
    const GRID_EXPIRY = 30 * MINUTE_IN_SECONDS;

    public function __construct() {
        // Initialize cache hooks
        $this->init_hooks();
    }

    protected function init_hooks() {
        // Clear cache when grid is updated
        add_action('cg_grid_updated', [$this, 'clear_grid_cache']);
        add_action('cg_grid_deleted', [$this, 'clear_grid_cache']);
        
        // Clear cache when categories are modified
        add_action('created_product_cat', [$this, 'clear_term_cache']);
        add_action('edited_product_cat', [$this, 'clear_term_cache']);
        add_action('delete_product_cat', [$this, 'clear_term_cache']);
        
        // Clear cache when product category thumbnails change
        add_action('updated_term_meta', [$this, 'handle_term_meta_update'], 10, 4);
        
        // Clear all cache when plugin settings are updated
        add_action('cg_settings_updated', [$this, 'clear_all_cache']);
        
        // Clear cache on WooCommerce product changes that might affect categories
        add_action('woocommerce_update_product', [$this, 'clear_product_cache']);
        add_action('woocommerce_product_set_stock', [$this, 'clear_product_cache']);
    }

    public static function init() {
        static $instance = null;
        if (is_null($instance)) {
            $instance = new self();
        }
        return $instance;
    }
    
    public function get_asset_version($asset) {
        $file_map = [
            'frontend-css' => CG_PLUGIN_DIR . 'assets/css/frontend.css',
            'frontend-js' => CG_PLUGIN_DIR . 'assets/js/frontend.js',
            'admin-css' => CG_PLUGIN_DIR . 'assets/css/admin.css',
            'admin-js' => CG_PLUGIN_DIR . 'assets/js/admin.js'
        ];
        
        if (isset($file_map[$asset])) {
            return file_exists($file_map[$asset]) ? 
                   filemtime($file_map[$asset]) : CG_VERSION;
        }
        
        return CG_VERSION;
    }
  
    public function get_cache_key($type, $identifier, $settings = []) {
        $key_parts = [$type, $identifier];
        
        if (!empty($settings)) {
            if (!empty($settings['desktop_columns'])) {
                $key_parts[] = 'cols_' . $settings['desktop_columns'];
            }
            if (!empty($settings['mobile_columns'])) {
                $key_parts[] = 'mcols_' . $settings['mobile_columns'];
            }
            if (!empty($settings['carousel_mobile'])) {
                $key_parts[] = 'carousel_' . ($settings['carousel_mobile'] ? 'on' : 'off');
            }
            if (!empty($settings['image_size'])) {
                $key_parts[] = 'size_' . $settings['image_size'];
            }
        }
        
        return 'cg_' . md5(implode('_', $key_parts)) . '_' . CG_VERSION;
    }

    /**
     * Get cached grid data
     * 
     * @param string $slug Grid slug
     * @param array $settings Grid settings
     * @return mixed|false Cached data or false if not found
     */
    public function get_grid($slug, $settings = []) {
        $key = $this->get_cache_key('grid', $slug, $settings);
        $grid = wp_cache_get($key, self::CACHE_GROUP);
        
        if ($grid === false) {
            $grid = CG_DB::get_grid($slug);
            if ($grid) {
                $this->set_grid_cache($key, $grid);
            }
        }
        
        return $grid;
    }

    /**
     * Store grid data in cache
     * 
     * @param string $key Cache key
     * @param object $grid Grid data object
     */
    protected function set_grid_cache($key, $grid) {
        wp_cache_set($key, $grid, self::CACHE_GROUP, self::GRID_EXPIRY);
        
        // Also store in transient for persistent cache
        set_transient($key, $grid, self::GRID_EXPIRY);
    }

    /**
     * Clear specific grid cache
     * 
     * @param int|string $identifier Grid ID or slug
     */
    public function clear_grid_cache($identifier) {
        if (is_numeric($identifier)) {
            $grid = CG_DB::get_grid_by_id($identifier);
            $identifier = $grid ? $grid->slug : $identifier;
        }
        
        // Clear all possible variations of this grid's cache
        $base_key = $this->get_cache_key('grid', $identifier);
        $this->clear_cache_by_prefix($base_key);
        
        // Clear shortcode output cache
        $this->clear_shortcode_cache($identifier);
    }

    /**
     * Clear cache entries matching a prefix
     * 
     * @param string $prefix Key prefix to match
     */
    protected function clear_cache_by_prefix($prefix) {
        global $wpdb;
        
        // Clear object cache
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group(self::CACHE_GROUP);
        } else {
            // Fallback for older WP versions
            wp_cache_flush();
        }
        
        // Clear transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                 OR option_name LIKE %s",
                '_transient_' . $prefix . '%',
                '_transient_timeout_' . $prefix . '%'
            )
        );
    }
  
  
  /**
     * Handle term meta updates (primarily for category thumbnails)
     */
    public function handle_term_meta_update($meta_id, $term_id, $meta_key, $meta_value) {
        if ($meta_key === 'thumbnail_id') {
            $this->clear_term_cache($term_id);
        }
    }

    /**
     * Clear cache for a product category term
     */
    public function clear_term_cache($term_id) {
        // Clear the term's direct cache
        $key = $this->get_cache_key('term', $term_id);
        wp_cache_delete($key, self::CACHE_GROUP);
        delete_transient($key);

        // Clear cache for all ancestor terms
        $ancestors = get_ancestors($term_id, 'product_cat');
        foreach ($ancestors as $ancestor_id) {
            $ancestor_key = $this->get_cache_key('term', $ancestor_id);
            wp_cache_delete($ancestor_key, self::CACHE_GROUP);
            delete_transient($ancestor_key);
        }

        // Find and clear all grids containing this term
        $this->clear_grids_with_term($term_id);
    }

    /**
     * Clear cache for grids containing a specific term
     */
    protected function clear_grids_with_term($term_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'category_grids';
        $grids = $wpdb->get_results("SELECT grid_id, slug, categories FROM {$table_name}");

        foreach ($grids as $grid) {
            $categories = json_decode($grid->categories, true);
            if (!is_array($categories)) continue;

            foreach ($categories as $category) {
                if (isset($category['id']) && $category['id'] == $term_id) {
                    $this->clear_grid_cache($grid->slug);
                    break;
                }
            }
        }
    }

    /**
     * Handle product updates that might affect category displays
     */
    public function clear_product_cache($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) return;

        // Get all categories this product belongs to
        $categories = wc_get_product_term_ids($product_id, 'product_cat');
        
        foreach ($categories as $category_id) {
            $this->clear_term_cache($category_id);
        }

        // Clear any direct product cache
        $key = $this->get_cache_key('product', $product_id);
        wp_cache_delete($key, self::CACHE_GROUP);
        delete_transient($key);
    }

    /**
     * Clear shortcode output cache stored in post meta
     */
    protected function clear_shortcode_cache($grid_slug) {
        global $wpdb;
        $meta_key = '_cg_shortcode_cache_' . md5($grid_slug);
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
                $meta_key
            )
        );
    }

    /**
     * Clear all plugin cache completely
     */
    public function clear_all_cache() {
        global $wpdb;
        
        // Clear object cache
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group(self::CACHE_GROUP);
        } else {
            wp_cache_flush();
        }

        // Clear all transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_cg_%' 
             OR option_name LIKE '_transient_timeout_cg_%'"
        );

        // Clear shortcode cache
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} 
             WHERE meta_key LIKE '_cg_shortcode_cache_%'"
        );
    }
  
  
  
   /**
     * Get cached grid HTML output
     * 
     * @param string $slug Grid slug
     * @param array $settings Grid settings
     * @return string|false Cached HTML or false if not found
     */
    public function get_grid_output($slug, $settings) {
        // Check if we should bypass cache
        if ($this->should_bypass_cache()) {
            return false;
        }

        $cache_key = $this->get_output_cache_key($slug, $settings);
        $output = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if ($output === false) {
            // Check persistent cache
            $output = get_transient($cache_key);
            
            if ($output === false) {
                return false; // No cached version available
            }
            
            // Store in object cache for subsequent requests
            wp_cache_set($cache_key, $output, self::CACHE_GROUP, self::CACHE_EXPIRY);
        }
        
        return $output;
    }

    /**
     * Cache grid HTML output
     * 
     * @param string $slug Grid slug
     * @param array $settings Grid settings
     * @param string $html Rendered HTML
     */
    public function cache_grid_output($slug, $settings, $html) {
        if ($this->should_bypass_cache()) {
            return;
        }

        $cache_key = $this->get_output_cache_key($slug, $settings);
        
        // Cache in both object cache and transient
        wp_cache_set($cache_key, $html, self::CACHE_GROUP, self::CACHE_EXPIRY);
        set_transient($cache_key, $html, self::CACHE_EXPIRY);
        
        // Store in post meta if we're in a post context
        if (is_singular()) {
            $this->cache_shortcode_output($slug, $html);
        }
    }

    /**
     * Generate output-specific cache key
     */
    protected function get_output_cache_key($slug, $settings) {
        $current_user = wp_get_current_user();
        $user_role = !empty($current_user->roles) ? implode(',', $current_user->roles) : 'guest';
        
        // Include language in cache key for multilingual sites
        $language = function_exists('pll_current_language') ? pll_current_language() : 
                   (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'default');
        
        $key_data = [
            'output',
            $slug,
            'lang_' . $language,
            'role_' . $user_role,
            'cols_' . ($settings['desktop_columns'] ?? 3),
            'mcols_' . ($settings['mobile_columns'] ?? 2),
            'carousel_' . ($settings['carousel_mobile'] ? 'on' : 'off'),
            'size_' . ($settings['image_size'] ?? 'medium')
        ];
        
        return 'cg_' . md5(implode('_', $key_data)) . '_' . CG_VERSION;
    }

    /**
     * Cache shortcode output in post meta
     */
    protected function cache_shortcode_output($slug, $html) {
        global $post;
        if (!$post || !is_a($post, 'WP_Post')) {
            return;
        }
        
        $meta_key = '_cg_shortcode_cache_' . md5($slug);
        update_post_meta($post->ID, $meta_key, $html);
    }

    /**
     * Check if we should bypass cache
     */
    protected function should_bypass_cache() {
    
     return false;
    
      
    }

    /**
     * Get cached shortcode output from post meta
     */
    public function get_shortcode_cache($slug) {
        global $post;
        if (!$post || !is_a($post, 'WP_Post')) {
            return false;
        }
        
        $meta_key = '_cg_shortcode_cache_' . md5($slug);
        return get_post_meta($post->ID, $meta_key, true);
    }
  
  
  
  
}
