<?php
/**
 * HTTP Send File classes.
 *
 * @link      https://github.com/diversen/http-send-file/ HTTP send file GitHub project
 * @author    Dennis Iversen <dennis.iversen@gmail.com>
 */

namespace diversen;

/**
 * PHP lib which sends a file with support for (multiple) range requests and throttle.
 * @package diversen
 * @version 1.0.9
 */
class sendfile
{
    //public 
    /**
     * if false we set content disposition from file that will be sent
     * @var mixed $disposition
     */
    private $disposition = false;

    /**
     * throttle speed in secounds
     * @var float $sec
     */
    private $sec = 0.1;

    /**
     * bytes per $sec
     * @var int $bytes
     */
    private $bytes = 40960;

    /**
     * if contentType is false we try to guess it
     * @var mixed $type
     */
    private $type = false;

    /**
     * set content disposition
     * @param boolean|string $file_name
     */
    public function contentDisposition ($file_name = false) {
        $this->disposition = $file_name;
    }

    /**
     * set throttle speed
     * @param float $sec
     * @param int $bytes
     */
    public function throttle ($sec = 0.1, $bytes = 40960) {
        $this->sec = $sec;
        $this->bytes = $bytes;
    }

    /**
     * set content mime type if false we try to guess it
     * @param string $content_type
     */
    public function contentType ($content_type = null) {
        $this->type = $content_type;
    }

    /**
     * get name from path info
     * @param string $file
     * @return string
     */
    private function name ($file) {
        $info = pathinfo($file);
        return $info['basename'];
    }

    /**
     * Sets-up headers and starts transfering bytes
     *
     * @param string  $file_path
     * @param boolean $withDisposition
     * @throws Exception
     */
    public function send($file_path, $withDisposition=TRUE) {

        if (!is_readable($file_path)) {
            throw new \Exception('File not found or inaccessible!');
        }

        $size = filesize($file_path);
        $last_modified_time = filemtime($file_path);
        $etag = sha1(fileinode($file_path).$last_modified_time.$size);
        if (!$this->disposition) {
            $this->disposition = $this->name($file_path);
        }

        if (!$this->type) {
            $this->type = $this->getContentType($file_path);
        }

        $is_range = isset($_SERVER['HTTP_RANGE']);
        if($is_range && isset($_SERVER['HTTP_IF_RANGE'])){
            // verify if meanwhile the file is not changed
            $is_range = $_SERVER['HTTP_IF_RANGE'] == $etag;
        }

        // turn off output buffering to decrease cpu usage
        $this->cleanAll();

        // required for IE, otherwise Content-Disposition may be ignored
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
        }

        header('Content-Type: ' . $this->type);
        header('Content-Disposition: ' . ($withDisposition?"attachment":"inline") . '; filename="' . $this->disposition . '"');
        header('Accept-Ranges: bytes');
        header('Etag: ' . $etag);
        header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $last_modified_time) . ' GMT');

        // The three lines below basically make the
        // download non-cacheable 
        header("Cache-control: private");
        header('Pragma: private');
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

        // multipart-download and download resuming support
        if ($is_range) {
            list($a, $range) = explode("=", $_SERVER['HTTP_RANGE'], 2);
            list($range) = explode(",", $range, 2);
            list($range, $range_end) = explode("-", $range);
            $range = intval($range);
            if (!$range_end) {
                $range_end = $size - 1;
            } else {
                $range_end = intval($range_end);
            }

            $new_length = $range_end - $range + 1;
            header("HTTP/1.1 206 Partial Content");
            header("Content-Length: $new_length");
            header("Content-Range: bytes $range-$range_end/$size");
        } else {
            $new_length = $size;
            header("HTTP/1.1 200 OK");
            header("Content-Length: " . $size);
        }

        /* output the file itself */
        $chunksize = $this->bytes; //you may want to change this
        $bytes_send = 0;

        $file = @fopen($file_path, 'r');
        if ($file) {
            if ($is_range) {
                fseek($file, $range);
            }

            while (!feof($file) && (!connection_aborted()) && ($bytes_send < $new_length) ) {
                $buffer = fread($file, $chunksize);
                echo($buffer); //echo($buffer); // is also possible
                flush();
                usleep($this->sec * 1000000);
                $bytes_send += strlen($buffer);
            }
            fclose($file);
        } else {
            throw new \Exception('Error - can not open file.');
        }
        die();
    }

    /**
     * method for getting mime type of a file
     * @param string $path
     * @return string $mime_type
     */
    private function getContentType($path) {
        $result = false;
        if (is_file($path) === true) {
            if (function_exists('finfo_open') === true) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if (is_resource($finfo) === true) {
                    $result = finfo_file($finfo, $path);
                }
                finfo_close($finfo);
            } else if (function_exists('mime_content_type') === true) {
                $result = preg_replace('~^(.+);.*$~', '$1', mime_content_type($path));
            } else if (function_exists('exif_imagetype') === true) {
                $result = image_type_to_mime_type(exif_imagetype($path));
            }
        }
        return $result;
    }

    /**
     * clean all buffers
     */
    private function cleanAll() {
        while (ob_get_level()) {
            ob_end_clean();
        }
    }
}