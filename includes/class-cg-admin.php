<?php
class CG_Admin {
 public function __construct() {
    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_cg_save_grid', [$this, 'save_grid_ajax']);
    add_action('wp_ajax_cg_get_grid', [$this, 'get_grid_ajax']);
    add_action('wp_ajax_cg_delete_grid', [$this, 'delete_grid_ajax']);
    // Remove these duplicate lines:
    // add_action('wp_ajax_cg_get_grid', 'handle_get_grid');
    // add_action('wp_ajax_cg_delete_grid', 'handle_delete_grid');
}

    public function add_admin_menu() {
        add_menu_page(
            'Category Grids',
            'Category Grids',
            'manage_options',
            'category-grids',
            [$this, 'render_admin_page'],
            'dashicons-grid-view'
        );
    }

    public function enqueue_assets($hook) {
    if ($hook !== 'toplevel_page_category-grids') return;
    
    wp_enqueue_media(); // This is crucial for wp.media
    wp_enqueue_style('cg-admin-css', CG_PLUGIN_URL . 'assets/css/admin.css');
    wp_enqueue_script('cg-admin-js', CG_PLUGIN_URL . 'assets/js/admin.js', 
        ['jquery', 'wp-util', 'media-editor'], // Added media-editor dependency
        CG_VERSION, 
        true
    );
    
    wp_localize_script('cg-admin-js', 'cg_admin_vars', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cg_admin_nonce'),
        'default_image' => CG_PLUGIN_URL . 'assets/images/default-category.jpg'
    ]);
}

    public function save_grid_ajax() {
    try {
        // Verify nonce first
        if (!check_ajax_referer('cg_admin_nonce', 'nonce', false)) {
            throw new Exception('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            throw new Exception('Permission denied');
        }

            // Validate required fields
            if (empty($_POST['name']) || empty($_POST['slug'])) {
                throw new Exception('Name and slug are required');
            }

            // Get and validate categories
            $categories = json_decode(stripslashes($_POST['categories']), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($categories)) {
                throw new Exception('Invalid categories data');
            }

            // Get and validate settings
            $settings = json_decode(stripslashes($_POST['settings']), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($settings)) {
                throw new Exception('Invalid settings data');
            }

            // Sanitize categories
            $sanitized_categories = array_map(function($category) {
                return array(
                    'id' => absint($category['id']),
                    'image' => esc_url_raw($category['image']),
                    'link' => esc_url_raw($category['link']),
                    'alt' => sanitize_text_field($category['alt'])
                );
            }, $categories);

            // Sanitize settings
            $sanitized_settings = array(
                'desktop_columns' => absint($settings['desktop_columns']),
                'mobile_columns' => absint($settings['mobile_columns']),
                'carousel_mobile' => (bool) $settings['carousel_mobile'],
                'image_size' => sanitize_text_field($settings['image_size'])
            );

            // Prepare data for database
            $data = array(
                'name' => sanitize_text_field($_POST['name']),
                'slug' => sanitize_title($_POST['slug']),
                'categories' => wp_json_encode($sanitized_categories),
                'settings' => wp_json_encode($sanitized_settings),
                'updated_at' => current_time('mysql')
            );

            global $wpdb;
            $table_name = $wpdb->prefix . 'category_grids';

            // Insert or update based on grid_id
        if (!empty($_POST['grid_id'])) {
            $result = $wpdb->update(
                $table_name,
                $data,
                array('grid_id' => absint($_POST['grid_id']))
            );
            
            // Add this after successful update
            if ($result !== false) {
                do_action('cg_grid_updated', absint($_POST['grid_id']));
            }
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table_name, $data);
            
            // Add this after successful insert
            if ($result !== false) {
                do_action('cg_grid_created', $wpdb->insert_id);
            }
        }

        if ($result === false) {
            throw new Exception($wpdb->last_error ?: 'Database error occurred');
        }

        wp_send_json_success('Grid saved successfully');

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

    public function get_grid_ajax() {
        try {
            if (!check_ajax_referer('cg_admin_nonce', 'nonce', false)) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Permission denied');
            }

            $grid_id = absint($_POST['id']);
            if (!$grid_id) {
                throw new Exception('Invalid grid ID');
            }

            $grid = CG_DB::get_grid_by_id($grid_id);
            if (!$grid) {
                throw new Exception('Grid not found');
            }

            // Decode JSON data
            $categories = json_decode($grid->categories, true);
            $settings = json_decode($grid->settings, true);

            // Add category names to the response
            foreach ($categories as &$category) {
                $term = get_term($category['id'], 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $category['name'] = $term->name;
                }
            }

            wp_send_json_success([
                'id' => $grid->grid_id,
                'name' => $grid->name,
                'slug' => $grid->slug,
                'categories' => $categories,
                'settings' => $settings
            ]);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function delete_grid_ajax() {
    try {
        if (!check_ajax_referer('cg_admin_nonce', 'nonce', false)) {
            throw new Exception('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            throw new Exception('Permission denied');
        }

        $grid_id = absint($_POST['id']);
        if (!$grid_id) {
            throw new Exception('Invalid grid ID');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'category_grids';
        
        $result = $wpdb->delete(
            $table_name,
            ['grid_id' => $grid_id],
            ['%d']
        );

        if ($result === false) {
            throw new Exception($wpdb->last_error ?: 'Failed to delete grid');
        }

        // Add this line after successful deletion
        do_action('cg_grid_deleted', $grid_id);

        wp_send_json_success('Grid deleted successfully');

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

    public function render_admin_page() {
        ?>
        <div class="wrap cg-admin-container">
            <div class="cg-admin-header">
                <h1><?php esc_html_e('Category Grids', 'category-grid'); ?></h1>
                <button id="cg-add-new" class="button button-primary">
                    <?php esc_html_e('Add New Grid', 'category-grid'); ?>
                </button>
            </div>

            <div class="cg-grid-list">
                <?php $this->render_grids_table(); ?>
            </div>

            <div class="cg-grid-editor" style="display:none;">
                <?php $this->render_grid_editor(); ?>
            </div>
        </div>
        <?php
    }

    private function render_grids_table() {
        $grids = CG_DB::get_all_grids();
        ?>
        <table class="wp-list-table widefat fixed striped cg-grids-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'category-grid'); ?></th>
                    <th><?php esc_html_e('Shortcode', 'category-grid'); ?></th>
                    <th><?php esc_html_e('Created', 'category-grid'); ?></th>
                    <th><?php esc_html_e('Actions', 'category-grid'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grids as $grid) : ?>
                    <tr>
                        <td><?php echo esc_html($grid->name); ?></td>
                        <td><code>[category_grid slug="<?php echo esc_attr($grid->slug); ?>"]</code></td>
                        <td><?php echo date_i18n(get_option('date_format'), strtotime($grid->created_at)); ?></td>
                        <td>
                            <button class="button cg-edit-grid" data-id="<?php echo esc_attr($grid->grid_id); ?>">
                                <?php esc_html_e('Edit', 'category-grid'); ?>
                            </button>
                            <button class="button cg-delete-grid" data-id="<?php echo esc_attr($grid->grid_id); ?>">
                                <?php esc_html_e('Delete', 'category-grid'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_grid_editor() {
        ?>
        <div class="cg-editor-container">
            <div class="cg-editor-header">
                <h2><?php esc_html_e('Edit Category Grid', 'category-grid'); ?></h2>
                <div class="cg-editor-actions">
                    <button id="cg-save-grid" class="button button-primary">
                        <?php esc_html_e('Save Grid', 'category-grid'); ?>
                    </button>
                    <button id="cg-cancel-edit" class="button">
                        <?php esc_html_e('Cancel', 'category-grid'); ?>
                    </button>
                </div>
            </div>

            <div class="cg-form-section">
                <div class="cg-form-group">
                    <label for="cg-grid-name"><?php esc_html_e('Grid Name', 'category-grid'); ?></label>
                    <input type="text" id="cg-grid-name" class="regular-text">
                </div>

                <div class="cg-form-group">
                    <label for="cg-grid-slug"><?php esc_html_e('Grid Slug', 'category-grid'); ?></label>
                    <input type="text" id="cg-grid-slug" class="regular-text">
                    <p class="description"><?php esc_html_e('Used in the shortcode', 'category-grid'); ?></p>
                </div>
            </div>

            <div class="cg-form-section">
                <h3><?php esc_html_e('Categories', 'category-grid'); ?></h3>
                <div class="cg-category-selection">
                    <div class="cg-available-categories">
                        <h4><?php esc_html_e('Available Categories', 'category-grid'); ?></h4>
                        <ul class="cg-category-list">
                            <?php $this->render_category_list(); ?>
                        </ul>
                    </div>
                    <div class="cg-selected-categories">
                        <h4><?php esc_html_e('Selected Categories', 'category-grid'); ?></h4>
                        <ul class="cg-selected-list" id="cg-selected-categories"></ul>
                    </div>
                </div>
            </div>

            <div class="cg-form-section">
                <h3><?php esc_html_e('Display Settings', 'category-grid'); ?></h3>
                <?php $this->render_display_settings(); ?>
            </div>
        </div>
        <?php
    }

    private function render_category_list() {
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        foreach ($categories as $category) {
            echo '<li data-id="' . esc_attr($category->term_id) . '">';
            echo '<span class="cg-category-name">' . esc_html($category->name) . '</span>';
            echo '<button class="button cg-add-category">' . esc_html__('Add', 'category-grid') . '</button>';
            echo '</li>';
        }
    }

    private function render_display_settings() {
        ?>
        <div class="cg-settings-grid">
            <div class="cg-form-group">
                <label for="cg-desktop-columns"><?php esc_html_e('Desktop Columns', 'category-grid'); ?></label>
                <select id="cg-desktop-columns">
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4" selected>4</option>
                    <option value="5">5</option>
                    <option value="6">6</option>
                </select>
            </div>

            <div class="cg-form-group">
                <label for="cg-mobile-columns"><?php esc_html_e('Mobile Columns', 'category-grid'); ?></label>
                <select id="cg-mobile-columns">
                    <option value="1">1</option>
                    <option value="2" selected>2</option>
                    <option value="3">3</option>
                </select>
            </div>

            <div class="cg-form-group">
                <label for="cg-image-size"><?php esc_html_e('Image Size', 'category-grid'); ?></label>
                <select id="cg-image-size">
                    <?php foreach (get_intermediate_image_sizes() as $size) : ?>
                        <option value="<?php echo esc_attr($size); ?>">
                            <?php echo esc_html($size); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cg-form-group">
                <label>
                    <input type="checkbox" id="cg-carousel-mobile" checked>
                    <?php esc_html_e('Enable Carousel on Mobile', 'category-grid'); ?>
                </label>
            </div>
        </div>
        <?php
    }
}