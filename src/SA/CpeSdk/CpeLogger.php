<?php

namespace SA\CpeSdk;

use SA\CpeSdk;

/**
 * Allow formatted logging on STDOUT
 * Send logs to Syslog for log offloading
 */
class CpeLogger
{
    public $activityName;
    public $logPath;
    public $filePath;
    public $printout = true; // By default Will print ALL in STDOUT along with writing in a file. Can be disabled when using logOut()

    // Exception
    const LOG_TYPE_ERROR = "LOG_TYPE_ERROR";
    const OPENLOG_ERROR  = "OPENLOG_ERROR";
    const LOGFILE_ERROR  = "LOGFILE_ERROR";

    // Specify the path where to create the log files
    public function __construct($activityName, $logPath = null)
    {
        date_default_timezone_set('UTC');
        
        $this->activityName = $activityName;
        $this->logPath = "/var/tmp/logs/cpe/";
        
        if ($logPath)
            $this->logPath = $logPath;

        if (!file_exists($this->logPath))
            mkdir($this->logPath, 0755, true);

        $this->logOut(
            "INFO",
            basename(__FILE__),
            "Logging to: ".$this->logPath);
    }

    // Log message to syslog and log file. Will print
    public function logOut(
        $type,
        $source,
        $message,
        $logKey = null,
        $printOut = true)
    {
        $log = [
            "time"    => date("Y-m-d H:i:s", time()),
            "source"  => $source,
            "type"    => $type,
            "message" => $message
        ];

        if ($printOut)
            $this->printOut = $printOut;
    
        if ($logKey)
            $log["logKey"] = $logKey;

        // Set the log filaname based on the logkey If provided.
        // If not then it will log in a file that has the name of the PHP activity file
        $file = $this->activityName;
        if ($logKey)
            $file .= "-".$logKey;
        // Append progname to the path
        $this->filePath = $this->logPath . "/" . $file.".log";

        // Open Syslog. Use programe name as key
        if (!openlog (__FILE__, LOG_CONS|LOG_PID, LOG_LOCAL1))
            throw new CpeException("Unable to connect to Syslog!",
                OPENLOG_ERROR);
        
        // Change Syslog priority level
        switch ($type)
        {
        case "INFO":
            $priority = LOG_INFO;
            break;
        case "ERROR":
            $priority = LOG_ERR;
            break;
        case "FATAL":
            $priority = LOG_ALERT;
            break;
        case "WARNING":
            $priority = LOG_WARNING;
            break;
        case "DEBUG":
            $priority = LOG_DEBUG;
            break;
        default:
            throw new CpeException("Unknown log Type!", 
                LOG_TYPE_ERROR);
        }
        
        // Print log in file
        $this->printToFile($log);
        
        // Encode log message in JSON for better parsing
        $out = json_encode($log);
        // Send to syslog
        syslog($priority, $out);
    }

    // Write log in file
    private function printToFile($log)
    {
        if (!is_string($log['message']))
            $log['message'] = json_encode($log['message']);
        
        $toPrint = $log['time'] . " [" . $log['type'] . "] [" . $log['source'] . "] ";
        // If there is a workflow ID. We append it.
        if (isset($log['logKey']) && $log['logKey'])
            $toPrint .= "[".$log['logKey']."] ";
        $toPrint .= $log['message'] . "\n";

        if ($this->printout)
            print $toPrint;
        
        if (file_put_contents(
                $this->filePath,
                $toPrint,
                FILE_APPEND) === false) {
            throw new CpeException("Can't write into log file: $this->logPath", 
                LOGFILE_ERROR);
        }
    }
}