<?
/**
 * S3 Site Size settings page
 *
 * @link       https://aboundant.com
 * @since      1.0.0
 *
 * @package    S3_Site_Size
 * @subpackage S3_Site_Size/admin/partials
 *
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
**/


// Hit the floor if accessed directly
if (!defined('ABSPATH')) { exit; }

// ----
add_action('network_admin_menu', 's3ss_add_network_admin_menu');
function s3ss_add_network_admin_menu() { 
    add_submenu_page(
        'settings.php',
        'S3 Site Size Settings',
        'S3 Site Size',
        'manage_network_options', 
        's3-site-size-settings',
        's3ss_settings_page_callback'
    );

    add_settings_section('s3ss-settings', null, null, 's3-site-size-settings');
    
    $aws_link = 'https://docs.aws.amazon.com/aws-sdk-php/v3/guide/getting-started/installation.html#installing-via-zip';
    $fields = [
        ['s3ss_aws_sdk_location',      __( "Directory in which the <a href='{$aws_link}' target='_blank' rel='noopener noreferrer'>AWS SDK</a> is installed <br>(contains aws-autoloader.php)", 'wordpress' ), 'string'],
        ['s3ss_aws_access_key',        __( 'AWS access key with bucket read permissions <br>(best practice: IAM user)', 'wordpress' ), 'string'],
        ['s3ss_aws_access_secret',     __( 'AWS access secret for above access key', 'wordpress' ), 'string'],
        ['s3ss_s3_bucket',             __( 'Bucket in which the sites are stored', 'wordpress' ), 'string'],
        ['s3ss_s3_uploads_folder',     __( 'Path to parent site\'s uploads folder in S3', 'wordpress' ), 'string'],       
        ['s3ss_s3_child_sites_folder', __( 'Child sites folder relative to parent sites folder', 'wordpress' ), 'string'],
        ['s3ss_db_cache_duration',     __( 'Duration of cache in the database in seconds', 'wordpress' ), 'integer'],
        ['s3ss_mem_cache_duration',    __( 'Duration of cache in memory in seconds <br>(may not survive this long; forced to 15s on media page)', 'wordpress' ), 'integer']
    ];
    
    foreach ($fields as $f) {
        add_settings_field(
            $f[0],
            $f[1],
            's3ss_field_renderer',
            's3-site-size-settings',
            's3ss-settings',
            ['id' => $f[0]]
        );
        
        register_setting(
            's3-site-size-settings',   /* Settings page slug */
            $f[0],                     /* Option name */
            $f[2],                     /* Type */
            $f[1]                      /* Description */
        );
    }
}

// ----
function s3ss_field_renderer($arg) {    
    $id = $arg['id'];
    $value = get_site_option($id, '');
    
    $data = [
        's3ss_aws_access_key'           => ['password', $value !== '' ? '<data hidden>' : 'AWS access key',     ''],
        's3ss_aws_access_secret'        => ['password', $value !== '' ? '<data hidden>' : 'AWS access secret',  ''],
        's3ss_aws_sdk_location'         => ['text',     '/wp-content/aws-sdk/',                                 $value],
        's3ss_s3_bucket'                => ['text',     'mybucket',                                             $value],
        's3ss_s3_uploads_folder'        => ['text',     'somefolder/wp-content/uploads/',                       $value],
        's3ss_s3_child_sites_folder'    => ['text',     'sites/',                                               $value],
        's3ss_db_cache_duration'        => ['number',   '30',                                                   $value],
        's3ss_mem_cache_duration'       => ['number',   '15',                                                   $value]
    ][$id];

    $required = strpos($data[1], '<') === false ? 'required' : '';
    echo "<input type='{$data[0]}' name='{$id}' id='{$id}' placeholder='{$data[1]}' value='{$data[2]}' {$required}>";
}

// ----
function s3ss_settings_page_callback() { 
    if (isset($_GET['updated'])) { ?>
        <div id="message" class="updated notice is-dismissible"><p><?php _e('Settings saved.') ?></p></div>
    <? }
    ?>
    
    <form action='edit.php?action=s3ss_update_network_options' method='post'>
        <h1>S3 Site Size Configuration</h1><br>
        
        <?
        settings_fields('s3-site-size-settings');
        do_settings_sections('s3-site-size-settings');
        ?>
        
        <input type="text" id="s3ss-site" placeholder="Site ID or slug"> <input type="button" id="s3ss-force-refresh" value="Force Cache Refresh"> <img id="s3ss-fr-spinner" src="/wp-admin/images/wpspin_light-2x.gif" class="s3ss-hidden">

        <? submit_button(); ?>
    </form>
    
    <script>
        jQuery(document).ready(function() {
            jQuery('#s3ss-force-refresh').click(function() {
                jQuery('#s3ss-fr-spinner').removeClass('s3ss-hidden');
                jQuery('#s3ss-site').removeClass('s3ss-error');
                
                var site = jQuery('#s3ss-site')[0].value;
                
                var request = jQuery.ajax({
                    method:   "POST",
                    dataType: 'json',
                    url:       ajaxurl,
                    data:      {action: 's3ss_force_update', site: site}
                });
                
                request.done(function() {
                        jQuery('#s3ss-fr-spinner').addClass('s3ss-hidden');
                });
                
                request.fail(function() {
                        jQuery('#s3ss-fr-spinner').addClass('s3ss-hidden');
                        jQuery('#s3ss-site').addClass('s3ss-error');
                });
            });
            
            jQuery('#s3ss-site').focusin(function() {
                    jQuery('#s3ss-site').removeClass('s3ss-error');
            });
        });
    </script>
    <?
}


add_action('network_admin_edit_s3ss_update_network_options',  's3ss_update_network_options');
function s3ss_update_network_options() {
    /**
     * Handler for saving network options
     * ---
     * See the links below for more details
     * https://forum.alecaddd.com/d/79-settings-api-in-multisite-installation/2
     * https://vedovini.net/2015/10/using-the-wordpress-settings-api-with-network-admin-pages/
    **/
    
    // Make sure we are posting from our options page;
    // must add the '-options' postfix when we check the referer.
    check_admin_referer('s3-site-size-settings-options');
    
    // Make extra special sure they have permission
    if (!current_user_can('manage_network_options')) {
        wp_die("Access denied.");
    }
        
    // This is the list of registered options.
    global $new_whitelist_options;
    $options = $new_whitelist_options['s3-site-size-settings'];
    
    // Go through the posted data and save only our options
    foreach ($options as $option) {
        // If the key exists and it is not empty (disallow data erasure)
        if (isset($_POST[$option]) && $_POST[$option] !== '') {
            update_site_option($option, $_POST[$option]);
        }
    }
    
    // Redirect back to our options page.
    wp_redirect(add_query_arg([
                    'page' => 's3-site-size-settings',
                    'updated' => 'true'
                    ], network_admin_url('settings.php')
                )
    );
    exit;
}

?>
