<?php
class CG_Frontend {
    public function __construct() {
        add_shortcode('category_grid', [$this, 'render_grid']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        $cache = CG_Cache::init();
        $style_version = $cache->get_asset_version('frontend-css');
        $script_version = $cache->get_asset_version('frontend-js');
        
        wp_enqueue_style(
            'cg-frontend-css', 
            CG_PLUGIN_URL . 'assets/css/frontend.css', 
            [], 
            $style_version
        );
        
        wp_enqueue_script(
            'cg-frontend-js', 
            CG_PLUGIN_URL . 'assets/js/frontend.js', 
            ['jquery'], 
            $script_version, 
            true
        );
    }

    public function render_grid($atts) {
        $atts = shortcode_atts(['slug' => ''], $atts);
        if (empty($atts['slug'])) return '';

        // Try to get cached output first
        $cache = CG_Cache::init();
        $cached_output = $cache->get_shortcode_cache($atts['slug']);
        
        if ($cached_output !== false) {
            return $cached_output;
        }

        // Get grid data with cache support
        $grid = $cache->get_grid($atts['slug']);
        if (!$grid) return '';

        $categories = json_decode($grid->categories, true);
        $settings = json_decode($grid->settings, true);
        
        // Generate cache key for this specific output
        $output_cache_key = $cache->get_output_cache_key($grid->slug, $settings);
        
        // Check if we have full output cached
        $output = wp_cache_get($output_cache_key, CG_Cache::CACHE_GROUP);
        
        if ($output === false) {
            // Start output buffering to capture the HTML
            ob_start();
            ?>
            <div class="cg-wrapper">
                <div class="cg-grid-container" 
                     data-columns="<?php echo esc_attr($settings['desktop_columns']); ?>"
                     data-mobile-columns="<?php echo esc_attr($settings['mobile_columns']); ?>"
                     data-carousel="<?php echo $settings['carousel_mobile'] ? 'true' : 'false'; ?>">
                    
                    <div class="cg-category-heading">
                        <span>Categories</span>
                    </div>

                    <div class="cg-row">
                        <?php foreach ($categories as $category) : 
                            $term = get_term($category['id']);
                            if (!$term) continue;
                            
                            $image_url = !empty($category['image']) ? $category['image'] : 
                                (get_term_meta($category['id'], 'thumbnail_id', true) ? 
                                 wp_get_attachment_image_url(get_term_meta($category['id'], 'thumbnail_id', true), $settings['image_size']) : 
                                 CG_PLUGIN_URL . 'assets/images/default-category.jpg');
                            ?>
                            <div class="cg-bx">
                                <div class="cg-tilethumb">
                                    <a href="<?php echo !empty($category['link']) ? esc_url($category['link']) : esc_url(get_term_link($term)); ?>"
                                       class="cg-category-tile">
                                        <img src="<?php echo esc_url($image_url); ?>" 
                                             alt="<?php echo !empty($category['alt']) ? esc_attr($category['alt']) : esc_attr($term->name); ?>"
                                             class="cg-category-image"
                                             width="480"
                                             height="480"
                                             loading="lazy">
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php
            
            // Get the buffered content
            $output = ob_get_clean();
            
            // Cache the complete output
            $cache->cache_grid_output($grid->slug, $settings, $output);
        }

        return $output;
    }
}