<?php

namespace SA\CpeSdk;

use SA\CpeSdk;

/**
 * Allow formatted logging on STDOUT
 * Send logs to Syslog for log offloading
 */
class CpeLogger
{
    public $logPath;
    public $printout;

    // Exception
    const LOG_TYPE_ERROR = "LOG_TYPE_ERROR";
    const OPENLOG_ERROR  = "OPENLOG_ERROR";

    // Specify the path where to create the log files
    public function __construct(
        $logPath = null,
        $suffix = null, 
        $printout = false)
    {
        global $argv;
        
        $this->printout = $printout;
        $this->logPath = "/var/tmp/logs/cpe/";
                
        if ($logPath)
            $this->logPath = $logPath;

        if (!file_exists($this->logPath))
            mkdir($this->logPath, 0755, true);

        $file = basename($argv[0]);
        if ($suffix)
            $file .= "-".$suffix;
        // Append progname to the path
        $this->logPath .= "/".$file.".log";

        $this->log_out(
            "INFO",
            "[CPE SDK] ".basename(__FILE__),
            "Logging to: ".$this->logPath);
    }

    // Log message to syslog and log file
    public function log_out(
        $type,
        $source,
        $message,
        $workflowId = null)
    {
        $log = [
            "time"    => date("Y-m-d H:i:s", time()),
            "source"  => $source,
            "type"    => $type,
            "message" => $message
        ];
    
        if ($workflowId)
            $log["workflowId"] = $workflowId;

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
        $this->print_to_file($log, $workflowId);
        
        // Encode log message in JSON for better parsing
        $out = json_encode($log);
        // Send to syslog
        syslog($priority, $out);
    }

    // Write log in file
    private function print_to_file($log, $workflowId)
    {
        if (!is_string($log['message']))
            $log['message'] = json_encode($log['message']);
        
        $toPrint = $log['time'] . " [" . $log['type'] . "] [" . $log['source'] . "] ";
        // If there is a workflow ID. We append it.
        if ($workflowId)
            $toPrint .= "[$workflowId] ";
        $toPrint .= $log['message'] . "\n";

        if ($this->printout)
            print $toPrint;
        
        if (file_put_contents(
                $this->logPath,
                $toPrint,
                FILE_APPEND) === false)
            print "ERROR: Can't write into log file!\n";
    }
}