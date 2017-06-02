<?php
/*
 * WP core class
 */

class tcS3_WP extends tcS3
{
    public function __construct()
    {
        parent::__construct();

        //setup admin menu
        if ($this->networkActivated) {
            add_action('network_admin_menu', array($this, 'add_network_plugin_page'));
        } else {
            add_action('admin_menu', array($this, 'add_plugin_page'));
        }

        add_action('admin_init', array($this, 'page_init'));
        add_action('admin_init', array($this, 'enqueue_admin_scripts'));

        if ($this->use_S3) {
            //send new uploads to S3
            add_action('add_attachment', array($this, 'create_on_S3')); //for non-images
            add_filter('wp_generate_attachment_metadata', array($this, 'create_image_on_S3'), 20, 5); //for images

            //send delete requests to S3
            add_action("delete_attachment", array($this, "delete_from_library"));

            //send crop/rotate/file changes to S3
            add_filter('wp_update_attachment_metadata', array($this, 'update_on_s3'), 20, 5);

            //add new column to media library
            add_filter('manage_media_columns', array($this, 'create_s3_media_column'));
            add_action('manage_media_custom_column', array($this, 'create_s3_media_column_content'), 10, 2);

            //add the push to S3 link to individual attachments
            add_filter('media_row_actions', array($this, 'push_single_to_S3'), 10, 2);

            //store the upload directory path
            preg_match("/\/wp-content(.+)$/", $this->uploads["basedir"], $matches);
            $this->uploadDir = $matches[1];

            //add the rewrite rule for the CDN lookup script and update the frontend to redirect to it
            add_action('init', array($this, 'add_images_rewrite'), 10, 0);
            add_action('template_redirect', array($this, 'load_image'));
            add_filter('wp_get_attachment_url', array($this, 'build_attachment_url'), 20, 1);
            add_filter('wp_get_attachment_image_src', array($this, 'get_attachment_image_src'), 20, 1);
            add_filter('wp_calculate_image_srcset', array($this, 'calculate_image_srcset'), 20, 1);

            //if super admin has flagged marking all uploads as uploaded and it has been done on this site yet, do it.
            if (get_site_option("tcS3_mark_all_attachments") == 1 && (get_option("tcS3_marked_all_attached") != 1 || get_option("tcS3_marked_all_attached") === false)) {
                add_action("init", array($this, "tcS3_mark_all_attached"));
            }

            //enable ajax requests
            add_action("wp_ajax_push_single", array($this, "tcS3_ajax_push_single"));
            add_action("wp_ajax_get_attachment_ids", array($this, "tcS3_ajax_get_attachment_ids"));
            add_action("wp_ajax_mark_all_synced", array($this, "tcS3_ajax_mark_all_synced"));
        } else {
            add_action('admin_head', array($this, "tcS3_dashAlert"));
        }
    }

    public function tcS3_dashAlert()
    {
        echo "<style type=\"text/css\">
		.alert {
			padding:1em;
			background-color:#ebfbff;
			border-bottom:1px solid #CCC;
			display:none;
			text-align: center;
			font-size: 1.5em;
		}
		</style>
		<script type=\"text/javascript\">
		\$j = jQuery;
		\$j().ready(function(){ //when page has fully loaded
		  \$j('h2').parent().prev().after('<div id=\"my-plugin-alert\" class=\"alert\">Oops! It looks like you\'ve activated tcS3 but it\'s not properly configured to upload to your bucket. <a href=\"" . admin_url("options-general.php?page=tcS3-admin") . "\">Please check your settings!</a></div>');
		  setTimeout(\"\$j('#my-plugin-alert').fadeIn('slow');clearTimeout();\",1000);
		});
		</script>";
    }

    public function get_attachment_image_src($image)
    {
        $image["src"] = $this->build_attachment_url($image[0]);
        return $image;
    }

    public function calculate_image_srcset($sources)
    {
        foreach ($sources as $key => $source) {
            $sources[$key]['url'] = $this->build_attachment_url($source['url']);
        }
        return $sources;
    }

    public function activate()
    {
        $options = array(
            "bucket" => "",
            "bucket_path" => "",
            "bucket_region" => "",
            "concurrent_conn" => 10,
            "min_part_size" => 5,
            "access_key" => "",
            "access_secret" => "",
            "delete_after_push" => 1,
            "s3_url" => "",
            "local_url" => "http://{$_SERVER["HTTP_HOST"]}/wp-content/",
            "tcS3_fallback" => 1,
            "s3_cache_time" => 172800,
            "s3_permalink_reset" => 0,
            "s3_redirect_cache_time" => 86400,
            "s3_redirect_cache_memcached" => "",
            "access_key_variable" => "",
            "access_secret_variable" => "",
            "env_toggle" => 0
            );

        if ($this->networkActivated) {
            add_site_option("tcS3_options", $options);
        } else {
            add_option("tcS3_options", $options);
        }
    }

    /**
     * Deactivate the plugin
     */
    public function deactivate()
    {
        // Do nothing
    }

    //build all of the keys associated with an attachment for upload
    public function build_attachment_keys($file_data)
    {
        $datePath = $this->get_subdir_from_filename($file_data["file"]);

        $keys[] = $file_data["file"];
        if (isset($file_data["sizes"])) {
            foreach ($file_data["sizes"] as $size => $data) {
                $keys[] = $datePath . "/" . $data["file"];
            }
        }
        return $keys;
    }

    //mark image as being on S3
    public function mark_as_transferred($file_data)
    {
        global $wpdb;
        $post_id = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_value = '{$file_data["file"]}' LIMIT 1");
        update_post_meta($post_id, "is_on_s3", 1);
    }

    //send image to browser
    public function load_image()
    {
        global $wp_query;

        if ($wp_query->get('file')) {
            $key = preg_replace("/[*]/", ".", rtrim($wp_query->get('file'), "/"));
            $s3URL = $this->options["s3_url"];
            $localURLs = preg_split("/[,]+\s*/", $this->options["local_url"]);

            if ($url = $this->tcS3_redirect_cache($key)) { //if image URL is in cache
                $this->tcS3_redirect_to_image($url);
            }

            if ($this->detect_image_by_header($s3URL . $key)) {// if image is on S3
                $url = $s3URL . $key;
                $this->tcS3_redirect_cache($key, $url, "write");
                $this->tcS3_redirect_to_image($url);
            }

            foreach ($localURLs as $localURL) {
                if ($this->detect_image_by_header($localURL . $key)) {// if image is on local
                    $url = $localURL . $key;
                    $this->tcS3_redirect_cache($key, $url, "write");
                    $this->tcS3_redirect_to_image($url);
                }
            }

            $wp_query->set_404();
            status_header(404);
        }
    }

    public function tcS3_mark_all_attached()
    {
        $ids = $this->get_all_attachments();
        foreach ($ids as $id) {
            update_post_meta($id, "is_on_s3", 1);
        }
        update_option("tcS3_marked_all_attached", 1);
    }

    public function require_login()
    {
        if (!is_user_logged_in()) {
            $error = array(
                "error" => array(
                    "message" => "You must be logged in!"
                    )
                );
            die(json_encode($error));
        }
    }

    public function image_key_from_path($path)
    {
        preg_match("/\/([0-9]+\/[0-9]+\/.+)$/", $path, $matches);
        return $matches[1];
    }

    public function create_on_S3($post_id)
    { //for posting non-images
        if (strpos(get_post_mime_type($post_id), "image/") === false) {
            $results["file"] = $this->image_key_from_path(get_attached_file($post_id));
            $keys = $this->build_attachment_keys($results);
            if ($this->push_to_s3($keys)) {
                $this->mark_as_transferred($results);
            }
        }
    }

    //send updated images to S3 after image editor is used
    public function update_on_s3($file_data, $post_id)
    {
        if (count($file_data) > 0) {
            $keys = $this->build_attachment_keys($file_data);
            if ($this->push_to_s3($keys)) {
                $this->mark_as_transferred($file_data);
            }
        }

        return $file_data;
    }

    //this function is called when you delete an element from the media library
    public function delete_from_library($post_id)
    {
        $file_data = wp_get_attachment_metadata($post_id);
        if ($file_data == "") { //if this is not an image
            $file_data["file"] = $this->image_key_from_path(get_attached_file($post_id));
        }
        $this->delete_from_S3($this->build_attachment_keys($file_data));
    }

    //set up the additional column in the media library to indicate whether an image is on S3 or not
    public function create_s3_media_column($defaults)
    {
        $defaults["s3"] = 'S3';
        return $defaults;
    }

    public function create_s3_media_column_content($column_name, $post_ID)
    {
        switch ($column_name) {
            case 's3':
            $is_on_s3 = get_post_meta($post_ID, 'is_on_s3', true);
            if ($is_on_s3 == 1) {
                $uploadedClasses = 'uploaded active';
                $notUploadedClasses = 'notuploaded';
            } else {
                $uploadedClasses = 'uploaded';
                $notUploadedClasses = 'notuploaded active';
            }

            echo "<img class='{$uploadedClasses}' src='" . plugin_dir_url(__FILE__) . "../img/s3-logo.png' />";
            echo "<img class='{$notUploadedClasses}' src='" . plugin_dir_url(__FILE__) . "../img/s3-logo-bw.png' />";

            break;
        }
    }

    public function push_single_to_S3($actions, $post)
    {
        $actions['push_to_s3'] = '<a class="push_single_to_S3" data-postID="' . $post->ID . '" title="' . esc_attr("Send this file to S3") . '">' . "Send this file to S3" . '</a>';
        return $actions;
    }

    public function get_all_attachments($skip_synced = true)
    {
        global $wpdb;

        $whereClause = ($skip_synced) ? "AND b.post_id IS NULL" : "";

        $attachments = $wpdb->get_results("
			SELECT ID
			FROM $wpdb->posts as a
			LEFT JOIN $wpdb->postmeta as b ON a.ID = b.post_id AND b.meta_key = 'is_on_s3'
			WHERE a.post_type = 'attachment'
			{$whereClause}
			GROUP BY ID
			ORDER BY ID DESC
			");

        foreach ($attachments as $id) {
            $ids[] = intval($id->ID);
        }

        return $ids;
    }

    public function add_images_rewrite()
    {
        global $wp_rewrite;

        add_rewrite_rule('^tcS3_media/(.+)$', 'index.php?file=$matches[1]', 'top');
        add_rewrite_tag('%file%', '([^&]+)');

        if (!get_option("tcS3_rewrite_flush")) {
            $wp_rewrite->flush_rules();
            update_option("tcS3_rewrite_flush", 1);
        }
    }

    /**** AJAX REQUESTS ******/
    public function tcS3_ajax_push_single()
    {
        $post_id = $_POST["postID"];
        $file_data = wp_get_attachment_metadata($post_id);
        if (count($file_data) > 0) {
            $file_data["file"] = get_attached_file($post_id);
        }

        $keys = $this->build_attachment_keys($file_data);

        if ($this->push_to_S3($keys)) {
            $results = array("success" => array("message" => "File successfully pushed to S3"));
            update_post_meta($post_id, "is_on_s3", 1);
        } else {
            $results = array("error" => array("message" => "Could not send to S3"));
        }

        echo json_encode($results);
        die;
    }

    public function tcS3_ajax_get_attachment_ids()
    {
        $full_sync = ($_POST["full_sync"] == 1) ? true : false;
        echo json_encode($this->get_all_attachments($full_sync));
        die;
    }

    public function tcS3_ajax_mark_all_synced()
    {
        update_site_option("tcS3_mark_all_attachments", 1);
        die;
    }

    /*     * *** admin interface **** */

    public function add_plugin_page()
    {
        add_options_page(
            'tcS3 Admin', 'tcS3 Admin configuration', 'manage_options', 'tcS3-admin', array($this, 'create_admin_page')
            );
    }

    public function add_network_plugin_page()
    {
        add_submenu_page(
            'settings.php', 'tcS3 Admin', 'tcS3 Admin configuration', 'manage_options', 'tcS3-admin', array($this, 'create_admin_page')
            );
    }

    public function enqueue_admin_scripts()
    {
        wp_enqueue_script("jquery");
        wp_enqueue_script('jquery-ui-progressbar');  // the progress bar
        wp_enqueue_script("tcS3", $this->pluginDir . "tcs3/js/tcS3.min.js", array("jquery"), $this->v, true);
        wp_enqueue_style("jquery-ui", "//ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/themes/smoothness/jquery-ui.css");
        wp_enqueue_style("tcS3", $this->pluginDir . "tcs3/css/tcS3.min.css", $this->v, true);
    }

    public function create_admin_page()
    {
        ?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2>tcS3 Settings</h2>
			<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
				<?php
                // This prints out all hidden setting fields
                settings_fields('tcS3_option_group');
        do_settings_sections('tcS3-setting-admin');
        submit_button(); ?>
			</form>
		</div>
		<?php

    }

    public function page_init()
    {
        register_setting(
                'tcS3_option_group', // Option group
                'tcS3-settings', // Option name
                array($this, 'sanitize') // Sanitize
                );

        //primary config
        add_settings_section(
                'tcS3-settings', // ID
                'tcS3 Configuration Parameters', // Title
                array($this, 'print_section_info'), // Callback
                'tcS3-setting-admin' // Page
                );

        add_settings_field(
                'aws_key', // ID
                'AWS Key', // Title
                array($this, 'aws_key_callback'), // Callback
                'tcS3-setting-admin', // Page
                'tcS3-settings' // Section
                );

        add_settings_field(
            'aws_secret', 'AWS Secret', array($this, 'aws_secret_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            'access_key_variable', 'AWS Access Key environment variable', array($this, 'aws_key_variable_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            'access_secret_variable', 'AWS Access Key Secret environment variable', array($this, 'aws_secret_variable_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            'env_toggle', 'Use environment variables', array($this, 'env_variable_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            's3_bucket', 'S3 Bucket', array($this, 's3_bucket_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            's3_bucket_path', 'S3 Bucket Path', array($this, 's3_bucket_path_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            's3_use_url', 'Override default WP URL', array($this, 's3_use_url_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            's3_url', 'Local URL', array($this, 'local_url_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            'local_url', 'S3 URL', array($this, 's3_url_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            'tcS3_fallback', 'Use fallback', array($this, 'tcS3_fallback_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );

        add_settings_field(
            's3_bucket_region', 'S3 Bucket Region', array($this, 's3_bucket_region_callback'), 'tcS3-setting-admin', 'tcS3-settings'
            );


        //advanced settings

        add_settings_section(
                'tcS3-advanced-settings', // ID
                'tcS3 Advanced Configuration', // Title
                array($this, 'print_advanced_section_warning'), // Callback
                'tcS3-setting-admin' // Page
                );

        add_settings_field(
            's3_concurrent', 'S3 Concurrent Connections', array($this, 's3_concurrent_callback'), 'tcS3-setting-admin', 'tcS3-advanced-settings'
            );

        add_settings_field(
            's3_min_part_size', 'S3 Minimum Part Size (MB)', array($this, 's3_min_part_size_callback'), 'tcS3-setting-admin', 'tcS3-advanced-settings'
            );

        add_settings_field(
            's3_delete_local', 'Delete local file after upload', array($this, 's3_delete_local_callback'), 'tcS3-setting-admin', 'tcS3-advanced-settings'
            );

        add_settings_field(
            's3_cache_time', 'Cache time for S3 objects', array($this, 's3_cache_time_callback'), 'tcS3-setting-admin', 'tcS3-advanced-settings'
            );

        add_settings_field(
            's3_redirect_cache_time', 'Cache time for S3 object redirects', array($this, 's3_redirect_cache_time_callback'), 'tcS3-setting-admin', 'tcS3-advanced-settings'
            );

        if (class_exists("Memcached")) {
            add_settings_field(
                's3_redirect_cache_memcached', 'Memcached host(s)', array($this, 's3_redirect_cache_memcached_callback'), 'tcS3-setting-admin', 'tcS3-advanced-settings'
                );
        }

        add_settings_section(
                'tcS3-migration', // ID
                'tcS3 Migration Tools', // Title
                array($this, 'migration_output'), // Callback
                'tcS3-setting-admin' // Page
                );
    }

    public function sanitize($input)
    {
        $new_input = array();
        if (isset($input['id_number'])) {
            $new_input['id_number'] = absint($input['id_number']);
        }

        if (isset($input['title'])) {
            $new_input['title'] = sanitize_text_field($input['title']);
        }

        return $new_input;
    }

    //section callbacks
    public function print_section_info()
    {
        echo "<div class='postbox'><div class='inside'>Running nginx? You'll need to drop a custom rule into your configuration for images to load properly. Copy and paste the code below into your nginx configuration for this site";
        echo '<pre>
		location ~*/tcS3_media/ {
			if (!-e $request_filename) {
				rewrite ^.*/tcS3_media/(.*)$ /index.php?file=$1 last;
			}
		}
		</pre>
		</div></div>';
    }

    public function print_advanced_section_warning()
    {
        echo "<div class='postbox'><div class='inside'>These are advanced settings and do not need to be altered for this plugin to do its job. Only change these values if you know what you're doing!</div></div>";
    }

    public function migration_output()
    {
        echo "
		<div class='migration'>
		<div class='progressbar'>
		<div class='progressbar-label'></div>
		</div>
		<input id='s3_sync' type='button' class='button sync' value='Sync' data-plugin-path='" . $this->pluginDir . "tcs3/' />
		<input id='tcS3_mark_all_attached' type='button' class='button sync' value='Mark all synced to S3' data-plugin-path='" . $this->pluginDir . "tcs3/' />
		</div>
		";
    }

    public function aws_key_callback()
    {
        $optionKey = 'access_key';
        $helperText = 'We recommend you create an AWS IAM user just for S3 and use its key. If your blog is every exploited, this method will prevent hackers from doing too much damage to your AWS account if they get their hands on your keys.';

        printf(
            '<input type="hidden" name="tcS3_option_submit" value="1" /><input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function aws_secret_callback()
    {
        $optionKey = 'access_secret';
        $helperText = 'We recommend you create an AWS IAM user just for S3 and use its secret. If your blog is every exploited, this method will prevent hackers from doing too much damage to your AWS account if they get their hands on your secrets.';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function aws_key_variable_callback()
    {
        $optionKey = 'access_key_variable';
        $helperText = 'For security reasons, you may prefer to store your AWS key and secret in an environment variable instead of your DB. If that is your preference enter the name of the env variable for your key';

        printf(
            '<input type="hidden" name="tcS3_option_submit" value="1" /><input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function aws_secret_variable_callback()
    {
        $optionKey = 'access_secret_variable';
        $helperText = 'For security reasons, you may prefer to store your AWS key and secret in an environment variable instead of your DB. If that is your preference enter the name of the env variable for your secret';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_bucket_callback()
    {
        $optionKey = 'bucket';
        $helperText = 'The name of your S3 bucket';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_bucket_path_callback()
    {
        $optionKey = 'bucket_path';
        $helperText = 'The path within your S3 bucket that Wordpress should use as your "uploads" directory';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_url_callback()
    {
        $optionKey = 's3_url';
        $helperText = 'The URL (including http://) to your S3 bucket and directory where uploads are being stored (e.g. http://mybucket.s3.amazonaws.com/uploads)';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function local_url_callback()
    {
        $optionKey = 'local_url';
        $helperText = 'The URL or URLs (comma separated) and path to where your local uploads file (e.g. http://www.example.com/wp-content/uploads would be http://www.example.com/wp-content/). This will be used as a fallback should the sync process fail for any upload so that your images always display.';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_bucket_region_callback()
    {
        $regions = array(
            array("value" => "ap-northeast-1", "display" => "ap-northeast-1", "options" => ""),
            array("value" => "ap-southeast-1", "display" => "ap-southeast-1", "options" => ""),
            array("value" => "ap-southeast-2", "display" => "ap-southeast-2", "options" => ""),
            array("value" => "eu-west-1", "display" => "eu-west-1", "options" => ""),
            array("value" => "sa-east-1", "display" => "sa-east-1", "options" => ""),
            array("value" => "us-east-1", "display" => "us-east-1", "options" => ""),
            array("value" => "us-west-1", "display" => "us-west-1", "options" => ""),
            array("value" => "us-west-2", "display" => "us-west-2", "options" => "")
            );

        foreach ($regions as $key => $region) {
            if ($region["value"] == $this->options["bucket_region"]) {
                $regions[$key]["options"] = "selected";
                break;
            }
        }
        $optionKey = 'bucket_region';
        $helperText = 'What region is your S3 bucket in?';

        printf(
            '<select id="%s" name="tcS3_option[%s]" />%s</select><div><small>%s</small></div>', $optionKey, $optionKey, $this->array_to_options($regions), $helperText
            );
    }

    public function s3_concurrent_callback()
    {
        $optionKey = 'concurrent_conn';
        $helperText = 'How many concurrent connections should the server make on upload to S3? (Default: 10)';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_min_part_size_callback()
    {
        $optionKey = 'min_part_size';
        $helperText = 'What size chunks should Wordpress break the file up into on S3 upload (in MB)? (Default: 5)';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_delete_local_callback()
    {
        $optionKey = 's3_delete_local';
        $helperText = 'Should uploaded files be removed from your local server?';

        printf(
            '<input type="radio" id="%s" name="tcS3_option[%s]" value="1" %s /> Yes
			<br /><input type="radio" id="%s" name="tcS3_option[%s]" value="0" %s /> No
			<div><small>%s</small></div>', $optionKey, $optionKey, ($this->options[$optionKey] == 1) ? "checked" : "", $optionKey, $optionKey, ($this->options[$optionKey] == 0) ? "checked" : "", $helperText
            );
    }

    public function env_variable_callback()
    {
        $optionKey = 'env_toggle';
        $helperText = 'For security reasons, you may prefer to store your AWS key and secret in an environment variable instead of your DB. If that is your preference toggle this to \'yes\', otherwise the key and secret entered will be used';

        printf(
            '<input type="radio" id="%s" name="tcS3_option[%s]" value="1" %s /> Yes
			<br /><input type="radio" id="%s" name="tcS3_option[%s]" value="0" %s /> No
			<div><small>%s</small></div>', $optionKey, $optionKey, ($this->options[$optionKey] == 1) ? "checked" : "", $optionKey, $optionKey, ($this->options[$optionKey] == 0) ? "checked" : "", $helperText
            );
    }

    public function tcS3_fallback_callback()
    {
        $optionKey = 'tcS3_fallback';
        $helperText = 'If selected tcS3 will use a tcS3_media URL to allow the plugin to check for files locally if they\'re not available on S3. This option slows down load time';

        printf(
            '<input type="radio" id="%s" name="tcS3_option[%s]" value="1" %s /> Yes
			<br /><input type="radio" id="%s" name="tcS3_option[%s]" value="0" %s /> No
			<div><small>%s</small></div>', $optionKey, $optionKey, ($this->options[$optionKey] == 1) ? "checked" : "", $optionKey, $optionKey, ($this->options[$optionKey] == 0) ? "checked" : "", $helperText
            );
    }

    public function s3_use_url_callback()
    {
        $optionKey = 'tcS3_use_url';
        $helperText = 'By default tcS3 overrides WP default URLs. You can disable this if you so desire.';
        if (!isset($this->options[$optionKey])) {
            $this->options[$optionKey] = 1;
        }

        printf(
            '<input type="radio" id="%s" name="tcS3_option[%s]" value="1" %s /> Yes
			<br /><input type="radio" id="%s" name="tcS3_option[%s]" value="0" %s /> No
			<div><small>%s</small></div>', $optionKey, $optionKey, ($this->options[$optionKey] == 1) ? "checked" : "", $optionKey, $optionKey, ($this->options[$optionKey] == 0) ? "checked" : "", $helperText
            );
    }

    public function s3_cache_time_callback()
    {
        $optionKey = 's3_cache_time';
        $helperText = 'How long (in seconds) should the cache headers be set for on S3 objects? (This will help keep your S3 bill down and improve page load for returning visitors)';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_redirect_cache_time_callback()
    {
        $optionKey = 's3_redirect_cache_time';
        $helperText = 'How long should the redirect lookups be cached? This will improve response time in the file lookup. (Set this to 0 to disabled redirect lookup caching)';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function s3_redirect_cache_memcached_callback()
    {
        $optionKey = 's3_redirect_cache_memcached';
        $helperText = 'A comma separated list of memcached servers (in hostname:port) format. Leave this blank to not use memcached.';

        printf(
            '<input type="text" id="%s" name="tcS3_option[%s]" value="%s" /><div><small>%s</small></div>', $optionKey, $optionKey, isset($this->options[$optionKey]) ? esc_attr($this->options[$optionKey]) : '', $helperText
            );
    }

    public function tcS3_media_ads($view)
    {
        $this->dump_to_log($view);
    }
}
