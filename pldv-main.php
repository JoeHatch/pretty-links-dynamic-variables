<?php
/*
Plugin Name: Pretty Links Dynamic Variables
Description: Appends a unique click ID to Pretty Links based on selected program software.
Version: 1.0
Author: StatsDrone
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Map software to dynamic query param with {clickid}
function prli_dv_get_software_map() {
    return [
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
    ];
}

// Simple logging function
function prli_dv_log($message) {
    $logfile = WP_CONTENT_DIR . '/dv-test.log';
    $timestamp = current_time('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] {$message}" . PHP_EOL;
    @file_put_contents($logfile, $log_message, FILE_APPEND | LOCK_EX);
}

// Generate click ID
function prli_dv_generate_click_id() {
    if (function_exists('random_bytes')) {
        return strtolower(base_convert(bin2hex(random_bytes(8)), 16, 36));
    } else {
        return strtolower(base_convert(uniqid(mt_rand(), true), 16, 36));
    }
}

// Hook at the very beginning
add_action('muplugins_loaded', function() {
    prli_dv_log("SUPER EARLY: Plugin starting at muplugins_loaded");
    prli_dv_early_intercept();
});

add_action('plugins_loaded', function() {
    prli_dv_log("EARLY: Plugin starting at plugins_loaded");
    prli_dv_early_intercept();
});

add_action('init', function() {
    prli_dv_log("INIT: Plugin starting at init");
    prli_dv_early_intercept();
});

function prli_dv_early_intercept() {
    // Only run on frontend
    if (is_admin()) {
        return;
    }
    
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    prli_dv_log("EARLY INTERCEPT: Checking URI: {$request_uri}");
    
    // Parse the path
    $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
    
    // Skip if empty or looks like WordPress content
    if (empty($path) || 
        strpos($path, 'wp-') === 0 ||
        strpos($path, 'feed') !== false ||
        strpos($path, '.') !== false) {
        return;
    }
    
    // Check if this could be a Pretty Link
    prli_dv_log("EARLY INTERCEPT: Checking potential Pretty Link: {$path}");
    
    // Query Pretty Links database
    global $wpdb;
    $table_name = $wpdb->prefix . 'prli_links';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
        prli_dv_log("EARLY INTERCEPT: Pretty Links table not found");
        return;
    }
    
    // Look for matching slug
    $link = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE slug = %s AND link_status = 'enabled'",
        $path
    ));
    
    if (!$link) {
        prli_dv_log("EARLY INTERCEPT: No Pretty Link found for: {$path}");
        return;
    }
    
    prli_dv_log("EARLY INTERCEPT: Found Pretty Link ID {$link->id} for slug: {$path}");
    
    // Check for software configuration
    $software = get_post_meta($link->id, '_prli_dv_software', true);
    if (!$software && !empty($link->link_cpt_id)) {
        $software = get_post_meta($link->link_cpt_id, '_prli_dv_software', true);
        prli_dv_log("EARLY INTERCEPT: Using WordPress post meta for ID {$link->link_cpt_id}");
    }
    
    if (!$software) {
        prli_dv_log("EARLY INTERCEPT: No software configured for link {$link->id}");
        return;
    }
    
    prli_dv_log("EARLY INTERCEPT: Found software: {$software}");
    
    // Process the redirect
    prli_dv_handle_pretty_link_redirect($link, $software);
}

function prli_dv_handle_pretty_link_redirect($link, $software) {
    prli_dv_log("REDIRECT: Processing link ID {$link->id} with software {$software}");
    
    $target_url = $link->url;
    prli_dv_log("REDIRECT: Target URL: {$target_url}");
    
    $map = prli_dv_get_software_map();
    if (!isset($map[$software])) {
        prli_dv_log("REDIRECT: Unknown software mapping: {$software}");
        return;
    }
    
    $click_id = prli_dv_generate_click_id();
    $query_string = str_replace('{clickid}', $click_id, $map[$software]);
    
    // Add to URL
    $glue = (parse_url($target_url, PHP_URL_QUERY) === null) ? '?' : '&';
    $final_url = $target_url . $glue . $query_string;
    
    prli_dv_log("REDIRECT: Final URL: {$final_url}");
    
    // Perform the redirect
    status_header(302);
    header("Location: {$final_url}");
    prli_dv_log("REDIRECT: Redirect sent");
    exit;
}

// Fallback: Standard Pretty Links filter approach
add_filter('prli_redirect_url', function($target_url, $link) {
    try {
        prli_dv_log("FILTER: prli_redirect_url called with URL: {$target_url}");
        
        if (!is_object($link)) {
            return $target_url;
        }
        
        $link_id = isset($link->id) ? $link->id : null;
        if (!$link_id) {
            return $target_url;
        }
        
        // Check for software setting
        $software = get_post_meta($link_id, '_prli_dv_software', true);
        if (!$software && isset($link->link_cpt_id)) {
            $software = get_post_meta($link->link_cpt_id, '_prli_dv_software', true);
        }
        
        if (!$software) {
            prli_dv_log("FILTER: No software for link {$link_id}");
            return $target_url;
        }
        
        prli_dv_log("FILTER: Found software {$software} for link {$link_id}");
        
        $map = prli_dv_get_software_map();
        if (!isset($map[$software])) {
            return $target_url;
        }
        
        $click_id = prli_dv_generate_click_id();
        $query_string = str_replace('{clickid}', $click_id, $map[$software]);
        $glue = (parse_url($target_url, PHP_URL_QUERY) === null) ? '?' : '&';
        $final_url = $target_url . $glue . $query_string;
        
        prli_dv_log("FILTER: Modified to: {$final_url}");
        
        return $final_url;
        
    } catch (Exception $e) {
        prli_dv_log("FILTER ERROR: " . $e->getMessage());
        return $target_url;
    }
}, 10, 2);

// Admin interface - simple version
add_action('add_meta_boxes', function() {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'pretty-link') {
        add_meta_box('prli_dv_software', 'Program Software', function($post) {
            wp_nonce_field('prli_dv_save_meta', 'prli_dv_nonce');
            
            $software_map = prli_dv_get_software_map();
            $selected = get_post_meta($post->ID, '_prli_dv_software', true);
            
            echo '<select name="prli_dv_software" style="width:100%;">';
            echo '<option value="">-- Select Software --</option>';
            foreach ($software_map as $slug => $template) {
                $display_name = ucfirst(str_replace(['_', '-'], ' ', $slug));
                printf(
                    '<option value="%s" %s>%s</option>', 
                    esc_attr($slug), 
                    selected($slug, $selected, false), 
                    esc_html($display_name)
                );
            }
            echo '</select>';
            echo '<p><small>Select affiliate software for click ID tracking.</small></p>';
            echo '<p><small>Post ID: ' . $post->ID . ' | Selected: ' . ($selected ?: 'none') . '</small></p>';
        }, 'pretty-link', 'side', 'high');
    }
});

// Save metabox
add_action('save_post', function($post_id) {
    if (!isset($_POST['prli_dv_nonce']) || !wp_verify_nonce($_POST['prli_dv_nonce'], 'prli_dv_save_meta')) {
        return;
    }
    
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    if (get_post_type($post_id) !== 'pretty-link') {
        return;
    }
    
    if (isset($_POST['prli_dv_software'])) {
        $software = sanitize_text_field($_POST['prli_dv_software']);
        update_post_meta($post_id, '_prli_dv_software', $software);
        prli_dv_log("ADMIN: Saved software '{$software}' for post ID: {$post_id}");
    }
});

// Debug page
add_action('admin_menu', function() {
    if (current_user_can('manage_options')) {
        add_options_page('PL Debug', 'PL Debug', 'manage_options', 'prli-dv-debug', function() {
            echo '<div class="wrap">';
            echo '<h1>Pretty Links DV Debug (v2.9 - Aggressive)</h1>';
            
            if (isset($_POST['clear_log'])) {
                $logfile = WP_CONTENT_DIR . '/dv-test.log';
                if (file_exists($logfile)) {
                    unlink($logfile);
                    echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
                }
            }
            
            echo '<form method="post"><button type="submit" name="clear_log" class="button">Clear Log</button></form><br>';
            
            echo '<h3>Quick Test</h3>';
            echo '<p><strong>NineCasino Test Link:</strong></p>';
            echo '<p><a href="https://dv.affiliation.services/goto/ninecasino" target="_blank">https://dv.affiliation.services/goto/ninecasino</a></p>';
            echo '<p>This should now redirect with a click ID added.</p>';
            
            // Show log
            $logfile = WP_CONTENT_DIR . '/dv-test.log';
            if (file_exists($logfile)) {
                $content = file_get_contents($logfile);
                $lines = explode("\n", trim($content));
                $recent_lines = array_slice($lines, -30); // Last 30 lines
                
                echo '<h3>Recent Log (Last 30 lines):</h3>';
                echo '<textarea style="width: 100%; height: 400px; font-family: monospace;" readonly>';
                echo esc_textarea(implode("\n", $recent_lines));
                echo '</textarea>';
            } else {
                echo '<p>No log file found.</p>';
            }
            
            echo '</div>';
        });
    }
});

// Log when plugin loads
prli_dv_log("Plugin v1.0 loaded");
