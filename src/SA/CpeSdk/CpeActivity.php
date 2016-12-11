<?php

/**
 * This Class must be used to create your own activities.
 * Extend it, and call `do_activity` with your callback as parameter to process incoming activities
 * Call activity_failed, activity_completed, activity_heartbeat 
 * to notify Snf of the status of your activity
 * 
 * Implement the \CpeSdk\CpeClientInterface and pass it to the constructor 
 * This allows you to interact with your application: Store data in your DB, or anything else
 * that you see fit to send to your app when something happens: start, fail, success, heartbeat
 * 
 * LOGS: By default logs go to /var/tmp/logs/cpe/. Each file will have the name of your activity name. 
 * The `process` logs are in seperate files under `${activity_name}-${taskToken}.log`
 */

namespace SA\CpeSdk;

abstract class CpeActivity
{
    public $params;           // Activity params coming from Activity script
    public $debug;            // Debug flag
    public $logPath;          // Debug flag
    
    public $cpeLogger;        // Logger
    public $client;           // Object that interface with the client application that initiate the activity
    
    public $input;            // Input JSON for this activity
    public $token;            // Snf token for this activity
    public $arn;              // The ARN of the activity
    public $name;             // The Name of the activity
    public $logKey;           // Composition for identifying logging infomation
    
    const HEARTBEAT_FAILED    = "HEARTBEAT_FAILED";
    const NO_ACTIVITY_NAME    = "NO_ACTIVITY_NAME";
    const NO_ACTIVITY_ARN     = "NO_ACTIVITY_ARN";
    const NO_INPUT            = "NO_INPUT";
    const INPUT_INVALID       = "INPUT_INVALID";

    public function __construct($client = null, $params, $debug, $cpeLogger) 
    {
        $this->debug            = $debug;
        $this->params           = $params;
        // Setup the logger
        $this->cpeLogger        = $cpeLogger;
          
        // For maniplating AWS State Function
        $this->cpeSfnHandler    = new \SA\CpeSdk\Sfn\CpeSfnHandler();    

        // Check if there is an activity name
        if (!isset($params["name"]) || !$params["name"])
            throw new \SA\CpeSdk\CpeException("Can't instantiate BasicActivity: 'name' is not provided or empty! Provide a name for your activity\n", 
			    self::NO_ACTIVITY_NAME);
        $this->name = $params["name"];
        
        // Check if there is an activity name
        if (!isset($params["arn"]) || !$params["arn"])
            throw new \SA\CpeSdk\CpeException("Can't instantiate BasicActivity: 'arn' is not provided or empty! Provide the ARN of your activity\n", 
			    self::NO_ACTIVITY_ARN);
        $this->arn = $params["arn"];
        
        // Set default timeout to 65 as the Snf long poll timeout is 60
        ini_set('default_socket_timeout', 65);
    }

    /*
     * This method must be implemented by the Activity which inherit this class
     * This is the entry point for you logic
     */
    abstract protected function process($task);
    
    /**
     * This must be called fro your activity to start listening for task
     * Perform Snf long polling and call user callback function when receiving new activity
     * 
     */ 
    public function doActivity()
    {
        $context = [
            'activityArn' => $this->arn, 
            'workerName'  => $this->name
        ];
        
        $this->cpeLogger->logOut("INFO", basename(__FILE__),
            "Starting '$this->name' activity tasks polling");
                
        do {
            
            try {
                
                $this->cpeLogger->logOut("DEBUG", basename(__FILE__),
                    "Polling for '$this->name' activity...");
                // Perform Snf long polling and wait for new tasks to process
                $task = $this->cpeSfnHandler->snf->getActivityTask($context);
                
            } catch (\Exception $e) {
                $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                    "Snf getActivityTask Failed! " . $e->getMessage(),
                    $this->logKey);
                
                // Notify the client if any
                if ($this->client)
                    $this->client->onException($context, $e);
            }
            
            try {
                // Do we have a new activity?
                if (isset($task['taskToken']) && $task['taskToken'] != '') {

                    $this->cpeLogger->logOut("INFO", basename(__FILE__),
                        "New activity '$this->name' triggered! Token: ".$task['taskToken'].".\nSee the Log file for this token: $this->cpeLogger->logPath using the token");
        
                    // Set the logKey so a new log file will be created just for this Execution
                    $this->logKey = $task['taskToken'];
                    $this->token  = $task['taskToken'];
                    
                    // Validate the JSON input and set `$this->input`
                    $this->doInputValidation($task['input']);
                    
                    // Notify the client if any
                    if ($this->client)
                        $this->client->onStart($task);
                    
                    // Call the user callback function with the activity input for processing
                    $result = $this->process($task);

                    // Execution successful. We mark it as such and return the output to Snf
                    $this->activitySuccess($result);
                }
            } catch (\Exception $e) {
                // Notify Snf that the activity has failed
                $this->activityFail($this->name."Exception", $e->getMessage());
            }
            
        } while (42);  
    }

    /**
     * Perform JSON input validation
     * Decode JSON to Associative array
     */
    private function doInputValidation($input)
    {
        // Check JSON input
        if (!($this->input = json_decode($input)))
            throw new \SA\CpeSdk\CpeException("JSON input is invalid!", 
			    self::INPUT_INVALID);
    }
    
    /**
     * Activity failed 
     * Called by parent Activity when something went wrong
     * Notifies Snf that the activity has failed
     *
     * Return false ONLY if Snf call failed. 
     * True otherwise, even of your client interface failed as the Snf work is successful.
     */
    public function activityFail($error = "", $cause = "")
    {
        $context = [
            'cause' => $cause,
            'error' => $error,
            'taskToken' => $this->token
        ];
        
        try {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__),
                "[$error] $cause",
                $this->token);

            $this->SnfHandler->sendTaskFailure($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onFail($this->token, $error, $cause);
            
        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                "Unable to send 'Task Failure' to Snf! " . $e->getMessage(),
                $this->token);
            
            // Notify the client if any
            if ($this->client)
                $this->client->onException($context, $e);
            
            return false;
        }

        return true;
    }

    /**
     * Activity Success and completed
     * Notifies Snf that the activity has succeeded 
     * Call this from your activity logic once the process is successful. 
     * If return false then the call to Snf sndTaskSuccess failed
     * Try again or throw an exception to mark the Activity as failed
     */
    public function activitySuccess($output = '')
    {
        $context = [
            'output'    => $output,
            'taskToken' => $this->token
        ];
        
        try {
            $this->cpeLogger->logOut("INFO", "[CPE SDK] " . basename(__FILE__),
                "Notify Snf that activity has completed !",
                $this->token);
            
            $this->SnfHandler->sendTaskSuccess($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onSuccess($this->token, $output);
            
        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                "Unable to send 'Task success' to Snf! " . $e->getMessage(),
                $this->token);
            
            // Notify the client if any
            if ($this->client)
                $this->client->onException($context, $e);

            return false;
        }

        return true;
    }
    
    /**
     * Send heartbeat to Snf to keep the task alive.
     * Call this from your activity logic. If return false then the heartbeat was not sent
     * Try again or throw an exception to mark the Activity as failed
     */
    public function activityHeartbeat($data = null)
    {
        $context = [
            'taskToken' => $this->token
        ];
        
        try {
            $this->cpeLogger->logOut("INFO", "[CPE SDK] " . basename(__FILE__), 
                "Sending heartbeat to Snf ...",
                $this->token);

            $client->sendTaskHeartbeat($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onHeartbeat($this->token, $data);
            
        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                "Unable to send 'Task Heartbeat' to Snf! " . $e->getMessage(),
                $this->token);
            
            // Notify the client if any
            if ($this->client)
                $this->client->onException($context, $e);

            return false;
        }

        return true;
    }
}
