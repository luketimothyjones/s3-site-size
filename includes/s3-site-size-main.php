<?
/**
 * S3 Site Size core functions
 *
 * @link       https://aboundant.com
 * @since      1.0.0
 *
 * @package    S3_Site_Size
 * @subpackage S3_Site_Size/includes/
 *
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
**/


// Hit the floor if accessed directly
if (!defined('WPINC')) { die; }

// -----
/* S3 site size retrieval integration */
function s3ss_get_paginator($key_prefix, $delim, $client) {
    return $client->getPaginator('ListObjects', [
        "Bucket"    => get_site_option('s3ss_s3_bucket'),
        "Prefix"    => $key_prefix,
        "Delimiter" => $delim
    ]);
}

function s3ss_get_sum($key_prefix, $delim, $client) {
    $sum = 0;
    foreach (s3ss_get_paginator($key_prefix, $delim, $client) as $page) {
        foreach($page['Contents'] as $object) {
            $sum += $object['Size'];
        }
    }
    return $sum;
}

// ----
function s3ss_get_semaphore($site_id) {
    // Semaphore is set, update is already in progress
    if (get_site_option("s3ss_{$site_id}_update_semaphore")) {
        return false;
    }
    
    // No update in progress, set semaphore
    update_site_option("s3ss_{$site_id}_update_semaphore", true);
    return true;
}

function s3ss_release_semaphore($site_id) {
    delete_site_option("s3ss_{$site_id}_update_semaphore");
}

// ----
function s3ss_get_size_from_s3($site_id) {
    /**
     * Get the current site's size in bytes from the bucket and parent "directory."
     * --
     * Requires the AWS SDK to be installed on your server, see the settings page for details
    **/

    // Pull settings from database
    $aws_sdk_loc           = get_site_option('s3ss_aws_sdk_location');
    $aws_access_key        = get_site_option('s3ss_aws_access_key');
    $aws_access_secret     = get_site_option('s3ss_aws_access_secret');
    $s3_uploads_folder     = get_site_option('s3ss_s3_uploads_folder');
    $s3_child_sites_folder = get_site_option('s3ss_s3_child_sites_folder');

    // Ensure the paths are formatted correctly
    $aws_sdk_loc           = '/' . s3ss_clean_slashes($aws_sdk_loc) . '/';
    $s3_uploads_folder     = s3ss_clean_slashes($s3_uploads_folder) . '/';
    $child_sites_full_path = $s3_uploads_folder . s3ss_clean_slashes($s3_child_sites_folder) . '/';

    // -----
    // DEBUG :: Set both to false in live environments
    $staging_site         = false;  // Staging server name
    $ab_site_size_test_id = false;  // Force specific site ID when on the above domain
    // -----
    
    if ($staging_site && $ab_site_size_test_id && strpos($_SERVER['SERVER_NAME'], $staging_site) !== false) {
        $site_id = $ab_site_size_test_id;
    }

    // Don't needlessly repeat initialization
    if (!function_exists("Aws\constantly")) {
        $full_aws_path = $_SERVER['DOCUMENT_ROOT'] . $aws_sdk_loc . 'aws-autoloader.php';
        
        if (!file_exists($full_aws_path)) {
            return -1;
        } else {
            require $full_aws_path;
        }
    }
    
    // Authenticate with AWS
    $s3ss_client = (new \Aws\Sdk)->createMultiRegionS3([
        'version'     => 'latest',
        'credentials' => [
            'key'     => $aws_access_key,
            'secret'  => $aws_access_secret
        ]
    ]);
    
    try {
        // Pull in the size of the local upload directory
        $site_size = get_dirsize(wp_upload_dir()['basedir']);
        
        // If we're on the parent site, only get the top-level objects
        $prefix = ($site_id == "1") ? $s3_uploads_folder : $child_sites_full_path . $site_id . '/';
        
        // We're on a child site
        if ($site_id !== "1") {
            $site_size += s3ss_get_sum($prefix, '', $s3ss_client);
        
        // We're on the parent site, which is not contained in sites subdirectory
        } else {
             
            // Get paginator of "top-level directory" object
            foreach (s3ss_get_paginator($prefix, '/', $s3ss_client) as $top_level) {
                
                // Sum up the files in the root "folder"
                foreach ($top_level['Contents'] as $tl_files) {
                    $site_size += $tl_files['Size'];
                }
                
                // Iterate "folder" objects
                foreach ($top_level['CommonPrefixes'] as $com_pref) {
                    $folder = $com_pref['Prefix'];
                    
                    // Sum all file sizes in "subfolder", ignoring the "folder" containing child sites
                    if (strpos($folder, $child_sites_full_path) !== 0) {
                        $site_size += s3ss_get_sum($folder, '', $s3ss_client);
                    }
                }
            }
        }
        
        return $site_size;
    
    // Log exceptions in accordance with user settings
    } catch (Aws\S3\Exception\S3Exception $e) {
        s3ss_error_logger($e);
        
        // -3 means S3 failed!
        return -3;
    }
}

// ----
function s3ss_update_cached_site_size($site_id) {
    /**
     * Updates the cached site size for $site_id
    **/
    
    global $wpdb;
    
    // Get the updated size and cache it in the table
    $size = s3ss_get_size_from_s3($site_id);
    $wpdb->update(S3SS_TABLE_NAME, ['site_size' => (int) $size], ['site_id' => (int) $site_id]);
    
    // Cache in memory as a transient
    s3ss_maybe_update_mem_cache($site_id, $size);
    
    return $size;
}

// ----
function s3ss_maybe_update_mem_cache($site_id, $size, $elapsed=0) {
    $mem_cache_duration = get_site_option('s3ss_mem_cache_duration');

    // Set transient for faster lookups
    $size_transient = 's3ss_site_size_cache_' . $site_id;
    
    $remaining = $elapsed - $mem_cache_duration;
    $duration  = ($remaining > 5) ? $remaining : 0;  // Is caching worth it?
    $duration  = s3ss_is_media()  ? 15 : $duration;  // Force 15s when accessing media page
    
    if ($duration) {
        set_transient($size_transient, $size, $duration);
        return true;
    }
    
    return false;
}

// ----
function s3ss_get_unexpired_site_size($site_id) {
     /**
     * Gets the size of $site_id, updating the value first if necessary.
    **/
    
    // Get the transient for this site
    $transient_name = 's3ss_site_size_cache_' . $site_id;
    $size_transient = get_transient($transient_name);
    
    // Something went wrong with the transient; delete it and try again
    if ($size_transient !== false && !is_numeric($size_transient)) {
        delete_transient($transient_name);
    
    // Found a valid cached transient
    } else if ($size_transient !== false) {
        return $size_transient;
    }

    // Get the site size data from the plugin's table
    global $wpdb;
    
    $result = $wpdb->get_row("
      SELECT site_size, UNIX_TIMESTAMP(last_update)
        FROM " . S3SS_TABLE_NAME . "
        WHERE site_id = {$site_id}"
    );
    
    // Site does not exist in the table or the value is expired, so let's update it
    $db_cache_duration = get_site_option('s3ss_db_cache_duration');
    
    $time_since = time() - $result['last_update'];
    if ($result === null || ($time_since > $db_cache_duration)) {
        
        // No other update was in progress
        if (s3ss_get_semaphore($site_id)) {
            $size = s3ss_update_cached_site_size($site_id);
            s3ss_release_semaphore($site_id);
            return $size;
        
        // There was already an update in progress, so this user gets old data.
        // Better luck next refresh!
        } else {
            return $result['site_size'];
        }
        
    // Unexpired cache found in table
    } else {
        // Transient must have expired, so let's refresh it
        s3ss_maybe_update_mem_cache($site_id, $result['site_size'], $time_since);
        return $result['site_size'];
    }
}

// ----
function s3ss_get_site_size($force_update=false) {
    /**
     * Gets the size of the current site.
     * --
     *   force_update (Boolean, default false)
     *     :: Forces the cached value to be updated before returning
    **/
    
    $site_id = get_blog_details(['domain' => $_SERVER['SERVER_NAME']])->blog_id;
    
    if ($force_update === true) {
        return s3ss_update_cached_site_size($site_id);
        
    } else {
        return s3ss_get_unexpired_site_size($site_id);
    }
}

// ----
function s3ss_pre_get_site_size() {
    /**
     * Hook S3 into WordPress site size detection
     * --
     * Note: If you're running Pro-Sites, this function will return 0 on the dashboard.
     *       This is because Pro-Sites' size display is updated via AJAX after the page has loaded
     *       for performance reasons.
    **/
    
    // Skip if using Pro Sites, as this will be handled by AJAX later.
    if (!(s3ss_is_dashboard() && s3ss_has_prosites())) {
        return s3ss_get_site_size() / 1048576; // Convert to MB
    }
    
    return 1;

} add_filter('pre_get_space_used', 's3ss_pre_get_site_size');

// ----
function s3ss_get_site_size_ajax_handler() {
    /**
     * Handler for getting site size information via AJAX.
     * Call via AJAX action 's3ss_get_site_size'
     * --
     * Returns JSON object
     *   allowed_bytes :: Amount of data the user is allowed to use in bytes
     *   used_bytes    :: Amount of data used by user in bytes
     *   used_percent  :: Percent of data used (allowed_bytes / used_bytes)
     *   used_readable :: Human-readable size  (highest size unit + two decimal places)
    **/
    
    // If the current user doesn't have uploading permissions, they have no business here
    // This may need to be tweaked for Pro-Sites
    
    if (!current_user_can('upload_files')) {
        wp_die("You do not have permission to view this information.");
    }
    
    $force_update = $_POST['force_update'];
    $force_update = isset($force_update) && $force_update === 'true';
    $size         = s3ss_get_site_size($force_update);
    $allowed      = get_space_allowed();
    $formatted    = size_format($size, 2);
    $formatted    = ($formatted !== false) ? $formatted : $size;
    
    echo json_encode([
        'allowed_bytes' => $allowed,
        'used_bytes'    => $size,
        'used_percent'  => number_format((($size / 1048576) / $allowed) * 100),
        'used_readable' => $formatted
    ]);
    
    wp_die();
    
} add_action('wp_ajax_s3ss_get_site_size', 's3ss_get_site_size_ajax_handler');

// ----
function s3ss_force_update_ajax_handler() {
    
    // Die if user doesn't have network admin permissions
    if (!current_user_can('manage_network_options')) {
        wp_die("Access denied.");
    }
    
    // Force update current site if site data not set
    $this_site = get_blog_details()->domain;
    $site = isset($_POST['site']) ? $_POST['site'] : $this_site;
    
    // Exceeds length limit for FQDN
    if (strlen($site) > 255) {
        wp_die('Invalid site data');
    }
    
    // Is a blog ID
    if (is_numeric($site)) {
        $site_id = $site;
        
    // Is a domain slug
    } else {
        // Gracefully handle child sites
        if (strpos($this_site, $site) === false) {
            $blog_det= get_blog_details(['domain' => "{$site}.{$this_site}"]);
            
        } else {
            $blog_det = get_blog_details(['domain' => $site]);
        }
    }
    
    // Couldn't find the ID
    if ($blog_det === null) {
        status_header(400);
        wp_send_json_error();
        wp_die();
        
    } else {
        $site_id = $blog_det->blog_id;
    }
    
    // Everything went great
    $size = s3ss_update_cached_site_size($site_id);
    wp_die($size);
    
} add_action('wp_ajax_s3ss_force_update', 's3ss_force_update_ajax_handler');

// ---- 
function s3ss_display_site_size_shortcode($atts) {
    /** 
     * Gets the current site's size via AJAX.
     * What it does afterwards is customizable -- read the parameters below for details.
     * ----
     * callback
     *   :: Name of a JavaScript function in AJAX callback format
     *   :: Will be passed a JSON object as described in s3ss_get_site_size_ajax_handler
     *   :: This overrides all other arguments
     *
     * selector
     *   :: Replaces selector.innerHTML with readable version of the site size
     *   :: A span with the id "s3ss_size_elem" will be used if this is not specified
     *
     * spinner ['true' (default) / 'false']
     *   :: Replaces selector.innerHTML (or default span) with a spinner until the response is receieved
     *
     * force_update ['true' / 'false' (default)]
     *   :: Force cache to be updated before size is returned
     * ----
     *
     * Registered as [s3ss_display_site_size]
    **/

    // NOP while in the admin area to stop shortcodes from freezing pages
    if (strpos($_SERVER['REQUEST_URI'], '/wp-admin/') === 0) { return ''; }
    
    $atts = shortcode_atts(
        array('callback' => '', 'selector' => '', 'spinner' => 'true', 'force_update' => ''), 
        $atts, 's3ss_display_site_size'
    );
    
    $callback = $atts['callback'];
    $selector = $atts['selector'];
    $spinner = ($atts['spinner'] === 'true') ? 'true' : 'false';
    $force_update = ($atts['force_update'] === 'true') ? 'true' : 'false';
    
    $output = '';
    
    // Add default span if no callback or selector was provided
    if ($callback === '' && $selector === '') {
        $output .= '<span id="s3ss_size_elem"></span>';
        $selector = '#s3ss_size_elem';
    }
    
    $output .= '<script type="text/javascript">';
    $ajax_common = "jQuery(document).ready(function() {
                        jQuery.post('" . admin_url('admin-ajax.php') . "', 
                            {action: 's3ss_get_site_size',
                             force_update: '{$force_update}' },
                        success: ";

    // If a callback was given, hook that function and skip everything else
    if ($callback !== '') {
        $output .= $ajax_common . $callback;
    
    } else {
        $output .= "var s3ss_size_elem = document.querySelector('{$selector}');";
            
        // Uses built-in WordPress spinner
        if ($spinner !== 'false') {
            $output .= 's3ss_size_elem.innerHTML = \'<img src="/wp-admin/images/wpspin_light-2x.gif" style="height: 1.2em;">\';';
        }
        
        $output .= $ajax_common . "function(d) { s3ss_size_elem.innerHTML = JSON.parse(d).used_readable; }";
    }
    
    $output .= ");}); </script>";
        
    return $output;
    
} add_shortcode('s3ss_display_site_size', 's3ss_display_site_size_shortcode'); 

// ----
function s3ss_prosites_site_size_js() {
    /**
     * Inserts Javascript into admin footer to replace Pro-Sites' dashboard space usage display
    **/
    
    // Add the Pro-Sites override JavaScript only if the site actually uses Pro-Sites
    if (!(s3ss_is_dashboard() && s3ss_has_prosites())) { return; }
    
    ?>
    <script type="text/javascript"> 
        // Overrides Pro-Sites' default site size getting behavior
        var space_used_elem = document.querySelector('div.mu-storage a.musublink');
        
        // Ensure element exists
        if (space_used_elem !== null) {
            var sreader = ' <span class="screen-reader-text">(Manage Uploads)</span>';
            var spinner = '<img src="/wp-admin/images/wpspin_light-2x.gif" style="padding: 3px 0 0 2px; height: 1.2em;">';
             
            space_used_elem.innerHTML = spinner + sreader;
            
            jQuery(document).ready( function() {
                jQuery.post(ajaxurl, {'action': 's3ss_get_site_size'},
                    function(data) {
                        var json = JSON.parse(data);
                        var size_info = json.used_readable + ' (' + json.used_percent + '%) Space Used'; 
                        space_used_elem.innerHTML = size_info + sreader;
                    }
                );
            });
        }
    </script>
    <?
    
} add_action('admin_footer', 's3ss_prosites_site_size_js');

/* ---------------- */
/* Helper functions */
function s3ss_is_dashboard() {
    return $_SERVER['REQUEST_URI'] == '/wp-admin/index.php' || $_SERVER['REQUEST_URI'] == '/wp-admin/';
}

function s3ss_is_media() {
    return strpos($_SERVER['REQUEST_URI'], '/wp-admin/upload.php') === 0;
}

function s3ss_clean_slashes($str) {
    // Removes leading and trailing slashes
    $new = substr($str, 0);
    $new = ($new[0] == '/') ? substr($new, 1) : $new;
    return (substr($new, -1) == '/') ? substr($new, 0, -1) : $new;
}

function s3ss_has_prosites() {
    $prosites = 'pro-sites/pro-sites.php';
    $network_active = get_site_option('active_sitewide_plugins'); 
    return is_plugin_active($prosites) || in_array($prosites, $network_active);
}

function s3ss_error_logger($e) {
    if (gettype($e) === 'object') {
        $error = "S3 Site Size encountered AWS exception: {$e->getMessage()} on blog {$site_id}\n";
    } else {
        $error = "S3 Site Size encountered an error: {$e}";
    }
    
    // This may break our AJAX calls; disabled for now
    // if (WP_DEBUG === true)      { echo $error; }
    
    if (WP_DEBUG_LOG === true ) { error_log($error); }
}

?>
