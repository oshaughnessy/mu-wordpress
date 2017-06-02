<?php

use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

class tcS3
{

    //declare variables
    public $aws;
    public $s3Client;
    public $uploads;
    public $options;
    public $uploadDir;
    public $pluginDir;
    public $networkActivated;

    public function __construct()
    {
        global $wpdb, $version;

        $this->pluginDir = @plugin_dir_url();
        $this->v = $version;

        //setup plugin on activation
        register_activation_hook(__FILE__, array($this, 'activate'));

        //if this plugin is being instantiated after a submission from the configs page, run the save function
        if (isset($_POST["tcS3_option_submit"])) {
            $this->save_s3_settings();
        }


        //setup options
        $this->networkActivated = $this->network_activation_check();
        $this->options = ($this->networkActivated) ? get_site_option("tcS3_options") : get_option("tcS3_options");
        $this->db = $wpdb;

        $this->accessKey = ($this->options["env_toggle"] == 1) ? getenv($this->options["access_key_variable"]) : $this->options["access_key"];
        $this->accessSecret = ($this->options["env_toggle"] == 1) ? getenv($this->options["access_secret_variable"]) : $this->options["access_secret"];


        $this->use_S3 = ($this->accessKey != "" && $this->accessSecret != "" && $this->options["bucket"] != "" && $this->options["bucket_path"] != "" && $this->options["bucket_region"] != "");

        if ($this->use_S3) {
            //set up aws
            $this->aws = new Aws\Sdk($this->build_aws_config());
            $this->s3Client = $this->aws->createS3();
            $this->uploads = wp_upload_dir();
        }
    }

    public function network_activation_check()
    {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }
        return (is_plugin_active_for_network("tcs3/tcS3.php")) ? true : false;
    }

    public function build_aws_config()
    {
        return array(
            'region' => $this->options["bucket_region"],
            "version" => "2006-03-01",
            "credentials" => array(
                'key' => $this->accessKey,
                'secret' => $this->accessSecret,
            )
        );
    }

    //upload a series of keys to S3
    public function push_to_s3($keys, $localdir = false, $remotedir = false)
    {
        set_time_limit(120);
        $errors = 0;

        if (!$localdir) {
            $localdir = $this->uploads["basedir"];
        }

        if (!$remotedir) {
            $remotedir = $this->uploadDir;
        }

        foreach ($keys as $key) {
            $localFile = $localdir . "/" . $key;
            $remoteFile = $this->sanitize_s3_path($this->options["bucket_path"] . $remotedir . "/" . $key);

            //error_log("Local: ".$localFile);
            //error_log("Remote: ".$remoteFile);

            //if the file doesn't exist, skip it
            if (!file_exists($localFile)) {
                continue;
            }

            $uploader = new MultipartUploader($this->s3Client, $localFile, [
                'bucket' => $this->options["bucket"],
                'key'    => $this->s3Client->encodeKey($remoteFile),
                'concurrency' => $this->options["concurrent_conn"],
                'part_size' => $this->options["min_part_size"] * 1024 * 1024,
                'acl' => 'public-read',
                'before_initiate' => function (\Aws\Command $command) {
                    // $command is a CreateMultipartUpload operation
                    $command['CacheControl'] = 'max-age=' . $this->options["s3_cache_time"];
                }
            ]);



            try {
                $result = $uploader->upload();
                error_log("Upload complete: {$result['ObjectURL']}");
            } catch (MultipartUploadException $e) {
                error_log($e->getMessage());
            }

            //on a successful upload where the settings call for the local file to be deleted right away, delete the local file
            if ($upload && $this->options["s3_delete_local"] == 1) {
                unlink($localFile);
            }
        }

        return ($errors == 0) ? true : false;
    }

    //function to delete object(s) from S3
    public function delete_from_S3($keys, $dir = false, $bucketPath = true)
    {
        if (!$dir) {
            $dir = $this->uploadDir;
        }

        foreach ($keys as $key) {
            if ($bucketPath) {
                $file = $this->sanitize_s3_path($this->options["bucket_path"] . "/" . $dir . "/" . $key);
            } else {
                $file = $this->sanitize_s3_path($dir . "/" . $key);
            }

            $file = preg_replace("/^\//", "", $file);

            if ($this->s3Client->doesObjectExist($this->options["bucket"], $file)) {
                $result = $this->s3Client->deleteObject(
                    array(
                        'Bucket' => $this->options["bucket"],
                        'Key' => $file
                    )
                );
            }
        }
    }

    public function sanitize_s3_path($path)
    {
        $search = array("/^\/+/", "/[\/]+/");
        $replace = array("", "/");
        return preg_replace($search, $replace, $path);
    }

    //find the subdirectory from the filename
    public function get_subdir_from_filename($filename)
    {
        preg_match("/([0-9]+\/[0-9]+)\/(.+)$/", $filename, $matches);
        return $matches[1];
    }

    //send output to log
    public function dump_to_log($mixed)
    {
        ob_start();
        var_dump($mixed);
        error_log(ob_get_contents());
        ob_end_clean();
    }

    public function detect_image_by_header($url)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true));

        $check = explode("\n", curl_exec($curl));
        curl_close($curl);

        $http_accepts = array(
            "HTTP/1.1 200 OK",
            "HTTP/1.1 201 Created",
            "HTTP/1.1 202 Accepted",
            "HTTP/1.1 203 Non-Authoritative Information",
            "HTTP/1.1 204 No Content",
            "HTTP/1.1 205 Reset Content",
            "HTTP/1.1 206 Partial Content",
            "HTTP/1.1 207 Multi-Status",
            "HTTP/1.1 208 Already Reported",
            "HTTP/1.1 226 IM Used",
            "HTTP/1.1 300 Multiple Choices",
            "HTTP/1.1 301 Moved Permanently",
            "HTTP/1.1 302 Found",
            "HTTP/1.1 303 See Other",
            "HTTP/1.1 304 Not Modified",
            "HTTP/1.1 305 Use Proxy",
            "HTTP/1.1 306 Switch Proxy",
            "HTTP/1.1 307 Temporary Redirect",
            "HTTP/1.1 308 Permanent Redirect"
            );


        if (in_array(trim($check[0]), $http_accepts)) {
            return true;
        } else {
            return false;
        }
    }

    //remember when an image is on S3
    public function tcS3_redirect_cache($key, $value = null, $action = "read")
    {
        $cacheDirectory = dirname(__FILE__) . "/cache/";
        $key = md5($key);
        $redirect_cache_time = $this->options["s3_redirect_cache_time"];
        $use_memcached = false;

        if (class_exists("Memcached")) { //use memcached when possible and configured
            $memcached = new Memcached();
            $memcacheHosts = $this->options["s3_redirect_cache_memcached"];
            $memcacheHosts = preg_split("/[,]+\s*/", $memcacheHosts);
            foreach ($memcacheHosts as $host) {
                $host = explode(":", $host);
                $servers[] = array($host[0], $host[1]);
            }
            $memcached->addServers($servers);

            if ($memcached->set("test", "1")) {
                $use_memcached = true; //if memcached is accessible
            }
        }


        if ($redirect_cache_time > 0) { //if caching is enabled
            switch ($action) {
                case "read":
                if ($use_memcached) {
                    return $memcached->get($key);
                }

                if (file_exists($cacheDirectory . $key) && (time() - filemtime($cacheDirectory . $key)) <= $redirect_cache_time) {
                    $url = file_get_contents($cacheDirectory . $key);
                    return $url;
                }

                return false;
                break;

                case "write":
                if ($use_memcached) {
                    $memcached->set($key, $value, $redirect_cache_time);
                } else {
                    file_put_contents($cacheDirectory . $key, $value);
                }
                break;
            }
        } else { //if caching is disabled
            return false;
        }
    }

    public function tcS3_redirect_to_image($url)
    {
        status_header(301);
        header("Location: " . $url);
        exit();
    }

    /****** wordpress extensions ***** */

    //function for creating new uploads on S3
    public function create_image_on_S3($file_data)
    {
        if (count($file_data) > 0) {
            $keys = $this->build_attachment_keys($file_data);
            if ($this->push_to_s3($keys)) {
                $this->mark_as_transferred($file_data);
            }
        }

        return $file_data;
    }

    public function build_attachment_url($url, $dir = false)
    {
        if (!$dir) {
            if ($this->networkActivated) {
                $dir = "/files";
            } else {
                $dir = $this->uploadDir;
            }
        }

        if (isset($this->options["tcS3_use_url"]) && $this->options["tcS3_use_url"] == 0) {
            return $url;
        }

        $match = "/" . preg_replace("/\//", "\/", $dir) . "\/(.+)$/";

        preg_match($match, $url, $matches);

        $protocol = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != "") ? "https" : "http";
        if ($this->options["tcS3_fallback"] == 1) {
            $url = get_site_url() . "/tcS3_media" . $this->uploadDir . '/' . $matches[1];
        } else {
            $url = $this->options["s3_url"] . $this->uploadDir . "/" . $matches[1];
        }

        return preg_replace("/([^:])\/\//", "$1/", $url);
    }

    public function array_to_options($arrays)
    {
        foreach ($arrays as $option) {
            $option = (object) $option;
            $options[] = "<option value = '{$option->value}' {$option->options}>{$option->display}</option>";
        }
        return implode("", $options);
    }

    public function save_s3_settings()
    {
        foreach ($_POST["tcS3_option"] as $key => $value) {
            if ($key == "bucket_path") {
                $options[$key] = "/" . trim($value, "/");
                continue;
            }

            if ($key == "s3_url" || $key == "local_url") {
                unset($hosts);
                $values = preg_split("/[,]+\s*/", $value);

                foreach ($values as $value) {
                    $protocol_check = preg_match("/^.*\/\//", $value, $matches);
                    if ($protocol_check == 0) { //if protocol wasn't included
                        $value = "//" . $value;
                    }
                    $hosts[] = rtrim(trim($value), "/") . "/";
                }
                $options[$key] = implode(",", $hosts);
                continue;
            }
            $options[$key] = trim(sanitize_text_field($value));
        }

        if ($this->network_activation_check()) {
            update_site_option("tcS3_options", $options);
        } else {
            update_option("tcS3_options", $options);
        }
    }
}
