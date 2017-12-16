# S3 Site Size

A WordPress plugin that allows multisite admins to host child-site data on AWS S3

----
### Functionality in brief  

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
            Get the size of the current site, don't display a spinner while waiting, and force the data to be current (also updates cache)
            
#### AJAX API

    Endpoint 's3ss_get_site_size'
    
    :: Returns ::
        JSON Object {
          allowed_bytes :: Amount of data the user is allowed to use in bytes
          used_bytes    :: Amount of data used by user in bytes
          used_percent  :: Percent of data used (allowed_bytes / used_bytes)
          used_readable :: Human-readable size  (highest size unit + two decimal places)
        }
    
    :: Example ::
        jQuery(document).ready( function() {
            jQuery.post(ajaxurl, {'action': 's3ss_get_site_size'},
                function(data) {
                    let space_used_elem = jQuery('#mydiv');
                    let json = JSON.parse(data);
                    let size_info = json.used_readable + ' (' + json.used_percent + '%) Space Used'; 
                    space_used_elem.innerHTML = size_info;
                }
            );
        });
