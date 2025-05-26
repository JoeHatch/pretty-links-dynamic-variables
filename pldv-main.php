<?php
/**
 * Plugin Name: Pretty Links Dynamic Variables
 * Description: Appends a unique click ID to Pretty Links based on selected program software.
 * Version: 1.0.0
 * Author: StatsDrone
 * Author URI: https://statsdrone.com
 * Text Domain: pretty-links-dv
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

// Plugin constants
define('PLDV_VERSION', '3.0.0');
define('PLDV_LOG_FILE', WP_CONTENT_DIR . '/pretty-links-dv.log');

/**
 * Get software to parameter mappings
 */
function pldv_get_software_mappings() {
    return apply_filters('pldv_software_mappings', [
        'cellxpert' => 'afp1={clickid}',
        'referon' => 'clickid={clickid}',
        'incomeaccess' => 'c={clickid}',
        'myaffiliates' => 'payload={clickid}',
        'map' => 'cid={clickid}',
        'mexos' => 'var1={clickid}',
        'raventrack' => 's1={clickid}',
        'comeon' => 'var={clickid}',
        'omarsys' => 'var={clickid}',
        'firstcasinopartners' => 'clickid={clickid}',
        'alanbase' => 'sub_id1={clickid}',
        'smartico' => 'afp={clickid}',
        'tap' => 'afp={clickid}',
        'postaffiliatepro' => 's1={clickid}',
        'affelios' => 'clickid={clickid}',
        'affise' => 'sub1={clickid}',
        'realtimegaming' => 'subGid={clickid}',
        'quintessence' => 'anid={clickid}',
        'netrefer' => 'var1={clickid}',
        'goldenreels' => 'promo={clickid}',
        'poshfriends' => 'promo={clickid}',
        'superboss' => 'promo={clickid}',
        'profit' => 'promo={clickid}',
        'conquestador' => 'promo={clickid}',
        'bons' => 'promo={clickid}',
    ]);
}

/**
 * Safe logging function
 */
function pldv_log($message, $level = 'INFO') {
    // Only log if debugging is enabled
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    $timestamp = current_time('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    // Use simple file operations for reliability
    @file_put_contents(PLDV_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Generate cryptographically secure click ID
 */
function pldv_generate_click_id() {
    try {
        if (function_exists('random_bytes')) {
            return strtolower(base_convert(bin2hex(random_bytes(8)), 16, 36));
        }
    } catch (Exception $e) {
        pldv_log("random_bytes failed: " . $e->getMessage(), 'ERROR');
    }
    
    // Fallback to less secure but functional method
    return strtolower(base_convert(uniqid(mt_rand(), true), 16, 36));
}

/**
 * Get sanitized request URI
 */
function pldv_get_request_uri() {
    if (!isset($_SERVER['REQUEST_URI'])) {
        return '';
    }
    
    // Basic sanitization without WordPress functions for early hooks
    return trim(stripslashes($_SERVER['REQUEST_URI']));
}

/**
 * Check if Pretty Links table exists
 */
function pldv_table_exists() {
    global $wpdb;
    if (!$wpdb) {
        return false;
    }
    
    $table_name = $wpdb->prefix . 'prli_links';
    $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
    return $result === $table_name;
}

/**
 * Get Pretty Link by slug
 */
function pldv_get_link_by_slug($slug) {
    if (!pldv_table_exists()) {
        return null;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'prli_links';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE slug = %s AND link_status = 'enabled'",
        $slug
    ));
}

/**
 * Get software configuration for a link
 */
function pldv_get_link_software($link) {
    if (!is_object($link) || !isset($link->id)) {
        return '';
    }
    
    // Try link ID first
    $software = get_post_meta($link->id, '_pldv_software', true);
    
    // Fallback to WordPress post meta if available
    if (!$software && !empty($link->link_cpt_id)) {
        $software = get_post_meta($link->link_cpt_id, '_pldv_software', true);
    }
    
    return $software ? trim($software) : '';
}

/**
 * Generate final URL with click ID
 */
function pldv_generate_final_url($target_url, $software) {
    $mappings = pldv_get_software_mappings();
    
    if (!isset($mappings[$software])) {
        pldv_log("Unknown software mapping: {$software}", 'WARNING');
        return $target_url;
    }
    
    $click_id = pldv_generate_click_id();
    $query_string = str_replace('{clickid}', $click_id, $mappings[$software]);
    
    // Determine URL separator
    $separator = (strpos($target_url, '?') === false) ? '?' : '&';
    $final_url = $target_url . $separator . $query_string;
    
    pldv_log("Generated URL: {$final_url} (Click ID: {$click_id})");
    
    return $final_url;
}

/**
 * Handle Pretty Link redirect
 */
function pldv_handle_redirect($link, $software) {
    $final_url = pldv_generate_final_url($link->url, $software);
    
    if ($final_url === $link->url) {
        return; // No changes made
    }
    
    pldv_log("Redirecting link ID {$link->id} to: {$final_url}");
    
    // Send redirect headers
    status_header(302);
    header("Location: {$final_url}");
    exit;
}

/**
 * Check if path should be skipped
 */
function pldv_should_skip_path($path) {
    $skip_patterns = ['wp-', 'feed', '.', 'xmlrpc.php', 'robots.txt', 'sitemap'];
    
    foreach ($skip_patterns as $pattern) {
        if (strpos($path, $pattern) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Early interception function
 */
function pldv_early_intercept() {
    // Only run on frontend and if WordPress is loaded enough
    if (defined('DOING_AJAX') || defined('DOING_CRON') || (function_exists('is_admin') && is_admin())) {
        return;
    }
    
    $request_uri = pldv_get_request_uri();
    if (empty($request_uri)) {
        return;
    }
    
    pldv_log("Early intercept checking URI: {$request_uri}");
    
    // Extract and validate path
    $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
    
    // Skip if empty or looks like WordPress content
    if (empty($path) || pldv_should_skip_path($path)) {
        return;
    }
    
    // Look for Pretty Link
    $link = pldv_get_link_by_slug($path);
    if (!$link) {
        pldv_log("No Pretty Link found for slug: {$path}");
        return;
    }
    
    pldv_log("Found Pretty Link ID {$link->id} for slug: {$path}");
    
    // Check software configuration
    $software = pldv_get_link_software($link);
    if (!$software) {
        pldv_log("No software configured for link {$link->id}");
        return;
    }
    
    pldv_log("Processing link with software: {$software}");
    
    // Process redirect
    pldv_handle_redirect($link, $software);
}

/**
 * Multiple hook points for maximum compatibility
 */
add_action('muplugins_loaded', function() {
    pldv_log("Plugin starting at muplugins_loaded");
    pldv_early_intercept();
}, 1);

add_action('plugins_loaded', function() {
    pldv_log("Plugin starting at plugins_loaded");
    pldv_early_intercept();
}, 1);

add_action('init', function() {
    pldv_log("Plugin starting at init");
    pldv_early_intercept();
}, 1);

/**
 * Fallback: Standard Pretty Links filter approach
 */
add_filter('prli_redirect_url', function($target_url, $link) {
    try {
        pldv_log("Filter prli_redirect_url called for URL: {$target_url}");
        
        if (!is_object($link) || !isset($link->id)) {
            return $target_url;
        }
        
        $software = pldv_get_link_software($link);
        if (!$software) {
            pldv_log("No software configured for link {$link->id} in filter");
            return $target_url;
        }
        
        $final_url = pldv_generate_final_url($target_url, $software);
        pldv_log("Filter modified URL to: {$final_url}");
        
        return $final_url;
        
    } catch (Exception $e) {
        pldv_log("Filter error: " . $e->getMessage(), 'ERROR');
        return $target_url;
    }
}, 10, 2);

/**
 * Admin meta box for software selection
 */
add_action('add_meta_boxes', function() {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'pretty-link') {
        add_meta_box(
            'pldv_software_config',
            'Affiliate Program Software',
            'pldv_render_meta_box',
            'pretty-link',
            'side',
            'high'
        );
    }
});

/**
 * Render software selection meta box
 */
function pldv_render_meta_box($post) {
    wp_nonce_field('pldv_save_meta', 'pldv_nonce');
    
    $mappings = pldv_get_software_mappings();
    $selected = get_post_meta($post->ID, '_pldv_software', true);
    
    echo '<select name="pldv_software" id="pldv-software-select" style="width:100%;">';
    echo '<option value="">-- Select Software --</option>';
    
    foreach ($mappings as $slug => $template) {
        $display_name = ucwords(str_replace(['_', '-'], ' ', $slug));
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($slug),
            selected($slug, $selected, false),
            esc_html($display_name)
        );
    }
    
    echo '</select>';
    echo '<p class="description">Select affiliate software for click ID tracking.</p>';
    
    // Show debug info for admins
    if (current_user_can('manage_options')) {
        echo '<p class="description" style="font-size: 11px; color: #666;">';
        echo esc_html(sprintf('Post ID: %d | Current: %s', $post->ID, $selected ?: 'none'));
        echo '</p>';
    }
}

/**
 * Save meta box data with security checks
 */
add_action('save_post', function($post_id) {
    // Security checks
    if (!pldv_verify_save_security($post_id)) {
        return;
    }
    
    if (isset($_POST['pldv_software'])) {
        $software = sanitize_text_field($_POST['pldv_software']);
        
        // Validate software selection
        if (!empty($software)) {
            $mappings = pldv_get_software_mappings();
            if (!isset($mappings[$software])) {
                pldv_log("Invalid software selection attempted: {$software}", 'WARNING');
                return;
            }
        }
        
        update_post_meta($post_id, '_pldv_software', $software);
        pldv_log("Saved software '{$software}' for post ID: {$post_id}");
    }
});

/**
 * Verify save post security
 */
function pldv_verify_save_security($post_id) {
    // Check nonce
    if (!isset($_POST['pldv_nonce']) || !wp_verify_nonce($_POST['pldv_nonce'], 'pldv_save_meta')) {
        return false;
    }
    
    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return false;
    }
    
    // Check capabilities
    if (!current_user_can('edit_post', $post_id)) {
        return false;
    }
    
    // Check post type
    if (get_post_type($post_id) !== 'pretty-link') {
        return false;
    }
    
    return true;
}

/**
 * Admin debug page
 */
add_action('admin_menu', function() {
    if (current_user_can('manage_options')) {
        add_options_page(
            'Pretty Links DV Debug',
            'PLDV Debug',
            'manage_options',
            'pldv-debug',
            'pldv_render_debug_page'
        );
    }
});

/**
 * Render debug page
 */
function pldv_render_debug_page() {
    // Handle form submissions
    pldv_handle_debug_actions();
    
    echo '<div class="wrap">';
    echo '<h1>Pretty Links Dynamic Variables Debug</h1>';
    echo '<p>Plugin Version: ' . PLDV_VERSION . '</p>';
    
    // System status
    pldv_render_system_status();
    
    // Configuration test
    pldv_render_test_tools();
    
    // Database investigation
    pldv_render_database_info();
    
    // Log viewer
    pldv_render_log_viewer();
    
    echo '</div>';
}

/**
 * Handle debug page actions
 */
function pldv_handle_debug_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_POST['clear_log']) && check_admin_referer('pldv_clear_log')) {
        pldv_clear_log();
        echo '<div class="notice notice-success"><p>Debug log cleared.</p></div>';
    }
    
    if (isset($_POST['test_configuration']) && check_admin_referer('pldv_test_config')) {
        pldv_run_configuration_test();
    }
}

/**
 * Render system status
 */
function pldv_render_system_status() {
    echo '<h2>System Status</h2>';
    echo '<table class="wp-list-table widefat">';
    echo '<tbody>';
    
    // Pretty Links plugin status
    $pretty_links_active = is_plugin_active('pretty-link/pretty-link.php') || is_plugin_active('pretty-links-pro/pretty-links-pro.php');
    echo '<tr>';
    echo '<td><strong>Pretty Links Plugin</strong></td>';
    echo '<td>' . ($pretty_links_active ? 
        '<span style="color: green;">✓ Active</span>' : 
        '<span style="color: red;">✗ Not Active</span>') . '</td>';
    echo '</tr>';
    
    // Database table
    echo '<tr>';
    echo '<td><strong>Database Table</strong></td>';
    echo '<td>' . (pldv_table_exists() ? 
        '<span style="color: green;">✓ Exists</span>' : 
        '<span style="color: red;">✗ Missing</span>') . '</td>';
    echo '</tr>';
    
    // Log file
    echo '<tr>';
    echo '<td><strong>Debug Log</strong></td>';
    echo '<td>' . (file_exists(PLDV_LOG_FILE) ? 
        '<span style="color: green;">✓ Available</span>' : 
        '<span style="color: orange;">• Not Created</span>') . '</td>';
    echo '</tr>';
    
    echo '</tbody></table>';
}

/**
 * Render test tools
 */
function pldv_render_test_tools() {
    echo '<h2>Test Tools</h2>';
    
    echo '<form method="post" style="margin-bottom: 20px;">';
    wp_nonce_field('pldv_test_config');
    echo '<button type="submit" name="test_configuration" class="button">Run Configuration Test</button>';
    echo '</form>';
    
    // URL generation test
    echo '<h3>URL Generation Test</h3>';
    $mappings = pldv_get_software_mappings();
    $sample_click_id = pldv_generate_click_id();
    
    echo '<p><strong>Sample Click ID:</strong> <code>' . esc_html($sample_click_id) . '</code></p>';
    
    echo '<details><summary>Available Software Mappings</summary>';
    echo '<ul>';
    foreach ($mappings as $software => $template) {
        $sample = str_replace('{clickid}', $sample_click_id, $template);
        echo '<li><strong>' . esc_html(ucwords(str_replace(['_', '-'], ' ', $software))) . ':</strong> ' . esc_html($sample) . '</li>';
    }
    echo '</ul></details>';
}

/**
 * Run configuration test
 */
function pldv_run_configuration_test() {
    echo '<div class="notice notice-info">';
    echo '<h4>Configuration Test Results</h4>';
    
    try {
        // Test software mappings
        $mappings = pldv_get_software_mappings();
        echo '<p>✓ ' . count($mappings) . ' software mappings loaded</p>';
        
        // Test database
        if (pldv_table_exists()) {
            global $wpdb;
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}prli_links WHERE link_status = 'enabled'");
            echo '<p>✓ Database connection OK - ' . absint($count) . ' enabled Pretty Links found</p>';
        } else {
            echo '<p style="color: red;">✗ Pretty Links database table not found</p>';
        }
        
        // Test click ID generation
        $click_id = pldv_generate_click_id();
        echo '<p>✓ Click ID generation OK - sample: ' . esc_html($click_id) . '</p>';
        
        // Test logging
        pldv_log('Configuration test executed');
        echo '<p>✓ Logging system OK</p>';
        
    } catch (Exception $e) {
        echo '<p style="color: red;">✗ Test failed: ' . esc_html($e->getMessage()) . '</p>';
    }
    
    echo '</div>';
}

/**
 * Render database information
 */
function pldv_render_database_info() {
    if (!pldv_table_exists()) {
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'prli_links';
    
    echo '<h2>Database Information</h2>';
    
    // Show configured links
    $configured_links = $wpdb->get_results($wpdb->prepare("
        SELECT pm.post_id, pm.meta_value as software, pl.*
        FROM {$wpdb->postmeta} pm 
        LEFT JOIN {$table_name} pl ON (pm.post_id = pl.id OR pm.post_id = pl.link_cpt_id)
        WHERE pm.meta_key = %s 
        AND pm.meta_value != ''
        ORDER BY pm.post_id DESC
    ", '_pldv_software'));
    
    if ($configured_links) {
        echo '<h3>Configured Pretty Links</h3>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        echo '<th>Name</th>';
        echo '<th>Slug</th>';
        echo '<th>Software</th>';
        echo '<th>Status</th>';
        echo '<th>Test</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($configured_links as $link) {
            echo '<tr>';
            echo '<td>' . esc_html($link->name ?: 'N/A') . '</td>';
            echo '<td><code>' . esc_html($link->slug ?: 'N/A') . '</code></td>';
            echo '<td>' . esc_html(ucwords(str_replace(['_', '-'], ' ', $link->software))) . '</td>';
            echo '<td>' . esc_html($link->link_status ?: 'N/A') . '</td>';
            
            if ($link->slug && $link->link_status === 'enabled') {
                $test_url = home_url($link->slug);
                echo '<td><a href="' . esc_url($test_url) . '" target="_blank" class="button button-small">Test</a></td>';
            } else {
                echo '<td>-</td>';
            }
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    } else {
        echo '<p>No Pretty Links have been configured with software settings yet.</p>';
    }
}

/**
 * Render log viewer
 */
function pldv_render_log_viewer() {
    echo '<h2>Debug Log</h2>';
    
    echo '<form method="post" style="margin-bottom: 15px;">';
    wp_nonce_field('pldv_clear_log');
    echo '<button type="submit" name="clear_log" class="button" onclick="return confirm(\'Are you sure you want to clear the debug log?\')">Clear Log</button>';
    echo '</form>';
    
    if (file_exists(PLDV_LOG_FILE) && filesize(PLDV_LOG_FILE) > 0) {
        $log_content = file_get_contents(PLDV_LOG_FILE);
        $log_lines = explode("\n", trim($log_content));
        $recent_lines = array_slice($log_lines, -50); // Last 50 lines
        
        echo '<textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px;">';
        echo esc_textarea(implode("\n", $recent_lines));
        echo '</textarea>';
        echo '<p class="description">Showing last 50 log entries.</p>';
    } else {
        echo '<p>No debug log entries found.</p>';
    }
}

/**
 * Clear debug log
 */
function pldv_clear_log() {
    if (file_exists(PLDV_LOG_FILE)) {
        @unlink(PLDV_LOG_FILE);
    }
}

/**
 * Plugin activation check
 */
register_activation_hook(__FILE__, function() {
    pldv_log('Plugin activated - version ' . PLDV_VERSION);
});

/**
 * Log plugin load
 */
pldv_log('Plugin v' . PLDV_VERSION . ' loaded');
