# S3 Site Size

A WordPress plugin that provides several methods for displaying the amount of storage space used by child sites hosted on AWS S3.


----
### Functionality in brief  


#### WordPress Core  

S3 Site Size works in part by hooking into WordPress's 'pre_get_space_used' filter. This allows other plugins to seamlessly pull the information from S3 (plus the site's local space usage).
    

#### Pro-Sites Integration  

If you have Pro-Sites installed on your network, S3 Site Size will automatically overwrite the Pro-Sites dashboard display.


#### Shortcodes  

    [s3ss_display_site_size]  
    
    Gets the current site's size via AJAX. What it does afterwards is customizable.
  
    :: Parameters ::  
        callback  
            - Name of a JavaScript function in AJAX callback format
            - Will be passed a JSON object as described in s3ss_get_site_size_ajax_handler
            - This overrides all other arguments
     
        selector  
            - Replaces selector.innerHTML with readable version of the site size
            - A span with the id "s3ss_size_elem" will be used if this is not specified
     
        spinner ['true'* | 'false']  
            - Replaces selector.innerHTML (or default span) with a spinner until the response is receieved
     
        force_update ['true' | 'false'*]  
            - Force cache to be updated before size is returned
    
    :: Example ::
        [s3ss_display_site_size spinner='false' force_update='true']  
            Get the size of the current site, don't display a spinner while waiting, and force the data to be current
            (also updates cache)
            
            
#### AJAX API

    Endpoint 's3ss_get_site_size'
    
    Gets the current site's size [requires 'upload_files' capability]
    
    :: Parameters ::
        force_update :: Forces the data to be updated before returning
    
    :: Returns ::
        JSON Object {
          allowed_bytes :: Amount of storage space the site is allowed to use in bytes
          used_bytes    :: Amount of storage space used by site in bytes
          used_percent  :: Percent of allowed storage space used (allowed_bytes / used_bytes)
          used_readable :: Human-readable size (applicable size unit to two decimal places)
        }
    
    :: Example ::
        jQuery(document).ready(function() {
            jQuery.post(ajaxurl, {action: 's3ss_get_site_size', force_update: true},
                function(data) {
                    let space_used_elem = jQuery('#mydiv');
                    let json = JSON.parse(data);
                    let size_info = json.used_readable + ' (' + json.used_percent + '%) Space Used'; 
                    space_used_elem.innerHTML = size_info;
                }
            );
        });
        
        
     Endpoint 's3ss_force_update'
     
     Forces the provided site's size to be updated [requires manage_network_options capability]
     
     :: Parameters ::
        site :: Either a blog ID (ex: 101) or a blog slug (ex: mysite). Note: the slug is not the site's FQDN!
        
     :: Returns ::
        Raw string :: site size in bytes. This will change soon to return the same JSON as s3ss_get_site_size.
