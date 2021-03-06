<?php

// This timeseries engine implements:
// Fixed Interval No Averaging

class PHPFina
{
    private $dir = "/var/lib/phpfina/";
    private $log;
    
    private $buffers = array();
    private $metadata_cache = array();
    
    private $filehandle = array();
    private $dpposition = array();
    
    /**
     * Constructor.
     *
     * @api
    */

    public function __construct($settings)
    {
        if (isset($settings['datadir'])) $this->dir = $settings['datadir'];
        
        $this->log = new EmonLogger(__FILE__);
    }

    /**
     * Create feed
     *
     * @param integer $feedid The id of the feed to be created
    */
    public function create($id,$options)
    {
        $interval = (int) $options['interval'];
        if ($interval<5) $interval = 5;
        
        // Check to ensure we dont overwrite an existing feed
        if (!$meta = $this->get_meta($id))
        {
            // Set initial feed meta data
            $meta = new stdClass();
            $meta->npoints = 0;
            $meta->interval = $interval;
            $meta->start_time = 0;
            
            // Save meta data
            $this->create_meta($id,$meta);
            
            if (!$fh = @fopen($this->dir.$id.".dat", 'c+')){
                $this->log->warn("PHPFina:create could not create data file id=$id");
                return false;
            }
            fclose($fh);
        }
        
        $feedname = "$id.meta";
        if (file_exists($this->dir.$feedname)) {
            return true;
        } else {
            $this->log->warn("PHPFina:create failed to create feed id=$id");
            return false;
        }
    }
    
    //private function checkpermissions()
    //{
    //    $uid = fileowner( $this->dir );
    //    $uinfo = posix_getpwuid( $uid ); 
    //    
    //    if ($uinfo['name']=="www-data") return true; else return false;
    //}


    /**
     * Adds a data point to the feed
     *
     * @param integer $feedid The id of the feed to add to
     * @param integer $time The unix timestamp of the data point, in seconds
     * @param float $value The value of the data point
    */
    public function prepare($id,$timestamp,$value)
    {   
        $id = (int) $id;
        $timestamp = (int) $timestamp;
        $value = (float) $value;
        
        $filename = "".$id;
        
        $now = time();
        $start = $now-(3600*24*365*5); // 5 years in past
        $end = $now+(3600*48);         // 48 hours in future
        
        if ($timestamp<$start || $timestamp>$end) {
            $this->log->warn("PHPFina:post timestamp out of range");
            return false;
        }
        
        // If meta data file does not exist then exit
        if (!$meta = $this->get_meta($id)) {
            return false;
        }
        
        $meta->npoints = $this->get_npoints($id);
        
        // Calculate interval that this datapoint belongs too
        $timestamp = floor($timestamp / $meta->interval) * $meta->interval;
        
        // If this is a new feed (npoints == 0) then set the start time to the current datapoint
        if ($meta->npoints == 0 && $meta->start_time==0) {
            $meta->start_time = $timestamp;
            $this->create_meta($id,$meta);
        }

        if ($timestamp < $meta->start_time) {
            $this->log->warn("PHPFina:post timestamp older than feed start time id=$id");
            return false; // in the past
        }
        
        

        $pos = floor(($timestamp - $meta->start_time) / $meta->interval);
        $last_pos = $meta->npoints - 1;
        
        if ($pos>$last_pos) {
            $npadding = ($pos - $last_pos)-1;
            
            if (!isset($this->buffers[$filename])) {
                $this->buffers[$filename] = "";    
            }
            
            if ($npadding>0) {
                for ($n=0; $n<$npadding; $n++)
                {
                    $this->buffers[$filename] .= pack("f",NAN);
                }
            }
            
            $this->buffers[$filename] .= pack("f",$value);
        }
        
        return $value;
    }
    
    // Save data in data buffers to disk
    // Writing data in larger blocks saves reduces disk write load as 
    // filesystems have a minimum IO size which are usually 512 bytes or more.
    public function save()
    {
        $byteswritten = 0;
        foreach ($this->buffers as $name=>$data)
        {
            // Auto-correction if something happens to the datafile, it gets partitally written to
            // this will correct the file size to always be an integer number of 4 bytes.
            clearstatcache($this->dir.$name.".dat");
            if (@filesize($this->dir.$name.".dat")%4 != 0) {
                $npoints = floor(filesize($this->dir.$name.".dat")/4.0);
                $fh = fopen($this->dir.$name.".dat","c");
                fseek($fh,$npoints*4.0);
                fwrite($fh,$data);
                fclose($fh);
                print "PHPFINA: FIXED DATAFILE WITH INCORRECT LENGHT\n";
            }
            else
            {
                $fh = fopen($this->dir.$name.".dat","ab");
                fwrite($fh,$data);
                fclose($fh);
            }
            
            $byteswritten += strlen($data);
        }
        
        // Reset buffers
        $this->buffers = array();
        
        return $byteswritten;
    }
    
    /**
     * Updates a data point in the feed
     *
     * @param integer $feedid The id of the feed to add to
     * @param integer $time The unix timestamp of the data point, in seconds
     * @param float $value The value of the data point
    */
    public function update($id,$timestamp,$value)
    {
        return $this->prepare($id,$timestamp,$value);
    }

    /**
     * Return the data for the given timerange
     *
     * @param integer $feedid The id of the feed to fetch from
     * @param integer $start The unix timestamp in ms of the start of the data range
     * @param integer $end The unix timestamp in ms of the end of the data range
     * @param integer $dp The number of data points to return (used by some engines)
    */
    public function get_data($feedid,$start,$end,$outinterval)
    {
        $feedid = intval($feedid);
        $start = intval($start/1000);
        $end = intval($end/1000);
        $outinterval= (int) $outinterval;
        
        // If meta data file does not exist then exit
        if (!$meta = $this->get_meta($feedid)) return false;
        $meta->npoints = $this->get_npoints($feedid);
        
        if ($outinterval<$meta->interval) $outinterval = $meta->interval;
        $dp = ceil(($end - $start) / $outinterval);
        $end = $start + ($dp * $outinterval);
        
        // $dpratio = $outinterval / $meta->interval;
        if ($dp<1) return false;

        // The number of datapoints in the query range:
        $dp_in_range = ($end - $start) / $meta->interval;

        // Divided by the number we need gives the number of datapoints to skip
        // i.e if we want 1000 datapoints out of 100,000 then we need to get one
        // datapoints every 100 datapoints.
        $skipsize = round($dp_in_range / $dp);
        if ($skipsize<1) $skipsize = 1;

        // Calculate the starting datapoint position in the timestore file
        if ($start>$meta->start_time){
            $startpos = ceil(($start - $meta->start_time) / $meta->interval);
        } else {
            $start = ceil($meta->start_time / $outinterval) * $outinterval;
            $startpos = ceil(($start - $meta->start_time) / $meta->interval);
        }

        $data = array();
        $time = 0; $i = 0;

        // The datapoints are selected within a loop that runs until we reach a
        // datapoint that is beyond the end of our query range
        $fh = fopen($this->dir.$feedid.".dat", 'rb');
        while($time<=$end)
        {
            // $position steps forward by skipsize every loop
            $pos = ($startpos + ($i * $skipsize));

            // Exit the loop if the position is beyond the end of the file
            if ($pos > $meta->npoints-1) break;

            // read from the file
            fseek($fh,$pos*4);
            $val = unpack("f",fread($fh,4));

            // calculate the datapoint time
            $time = $meta->start_time + $pos * $meta->interval;

            // add to the data array if its not a nan value
            if (!is_nan($val[1])) {
                $data[] = array($time*1000,$val[1]);
            } else {
                /*
                // Find non nan nearest within ten
                for ($x=1; $x<20; $x++)
                {
                    // +1, +2, +3..
                    fseek($fh,($pos+$x)*4);
                    $val = unpack("f",fread($fh,4));
                    if (!is_nan($val[1])) {
                        $data[] = array($time*1000,$val[1]);
                        break;
                    }

                    // -1, -2, -3..
                    fseek($fh,($pos-$x)*4);
                    $val = unpack("f",fread($fh,4));
                    if (!is_nan($val[1])) {
                        $data[] = array($time*1000,$val[1]);
                        break;
                    }
                }*/
                
            }

            $i++;
        }
        return $data;
    }

    /**
     * Get the last value from a feed
     *
     * @param integer $feedid The id of the feed
    */
    public function lastvalue($id)
    {
        $id = (int) $id;
        
        // If meta data file does not exist then exit
        if (!$meta = $this->get_meta($id)) return false;
        $meta->npoints = $this->get_npoints($id);
        
        if ($meta->npoints>0)
        {
            $fh = fopen($this->dir.$id.".dat", 'rb');
            $size = $meta->npoints*4;
            fseek($fh,$size-4);
             $d = fread($fh,4);
            fclose($fh);

            $val = unpack("f",$d);
            $time = date("Y-n-j H:i:s", $meta->start_time + $meta->interval * $meta->npoints);
            
            return array('time'=>$time, 'value'=>$val[1]);
        }
        else
        {
            return array('time'=>0, 'value'=>0);
        }
    }
    
    public function delete($id)
    {
        if (!$meta = $this->get_meta($id)) return false;
        unlink($this->dir.$id.".meta");
        unlink($this->dir.$id.".dat");
    }
    
    public function get_feed_size($id)
    {
        if (!$meta = $this->get_meta($id)) return false;
        return (16 + filesize($this->dir.$id.".dat"));
    }
    
    public function get_npoints($filename)
    {
        $bytesize = 0;
        
        if (file_exists($this->dir.$filename.".dat"))
            clearstatcache($this->dir.$filename.".dat");
            $bytesize += filesize($this->dir.$filename.".dat");
            
        if (isset($this->buffers[$filename]))
            $bytesize += strlen($this->buffers[$filename]);
            
        return floor($bytesize / 4.0);
    }   

    public function get_meta($filename)
    {
        // Load metadata from cache if it exists
        if (isset($this->metadata_cache[$filename])) 
        {
            return $this->metadata_cache[$filename];
        }
        elseif (file_exists($this->dir.$filename.".meta"))
        {
            // Open and read meta data file
            // The start_time and interval are saved as two consequative unsigned integers
            $meta = new stdClass();
            $metafile = fopen($this->dir.$filename.".meta", 'rb');

            $tmp = unpack("I",fread($metafile,4));
            $tmp = unpack("I",fread($metafile,4));
            $tmp = unpack("I",fread($metafile,4)); 
            $meta->interval = $tmp[1];
            $tmp = unpack("I",fread($metafile,4)); 
            $meta->start_time = $tmp[1];
            
            fclose($metafile);
            
            // Save to metadata_cache so that we dont need to open the file next time
            $this->metadata_cache[$filename] = $meta;
            
            return $meta;
        }
        else
        {
            return false;
        }
    }
    
    public function create_meta($filename,$meta)
    {
        $metafile = fopen($this->dir.$filename.".meta", 'wb');
        fwrite($metafile,pack("I",0));
        fwrite($metafile,pack("I",0)); 
        fwrite($metafile,pack("I",$meta->interval));
        fwrite($metafile,pack("I",$meta->start_time)); 
        fclose($metafile);
        
        // Save metadata to cache
        $this->metadata_cache[$filename] = $meta;
    }
    
    public function readnext($filename)
    {
        if (!isset($this->filehandle[$filename])) {
            $this->filehandle[$filename] = fopen($this->dir."$filename.dat", 'rb');
            $this->dpposition[$filename] = 0;
        }
        $fh = $this->filehandle[$filename];
        if (feof($fh)) return false;
        
        $meta = $this->get_meta($filename);
        
        $d = fread($fh,4);
        if (strlen($d)!=4) return false;
        
        $val = unpack("f",$d);
        $value = $val[1];
        
        $time = $meta->start_time + $this->dpposition[$filename] * $meta->interval;
        $this->dpposition[$filename] += 1;
        
        return array('time'=>$time, 'value'=>$value);
    }
}

