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
 */

namespace SA\CpeSdk;

use SA\CpeSdk;

class CpeActivity
{
    public $params;           // Activity params coming from Activity script
    public $debug;            // Debug flag
    
    public $cpeLogger;        // Logger
    public $client;           // Object that interface with the client application that initiate the activity
    
    public $input;            // Input JSON for this activity
    public $token;            // Snf token for this activity
    public $activityLogKey;   // Create a key workflowId:activityId to put in logs
    
    const HEARTBEAT_FAILED    = "HEARTBEAT_FAILED";
    const NO_ACTIVITY_NAME    = "NO_ACTIVITY_NAME";
    const NO_ACTIVITY_ARN     = "NO_ACTIVITY_ARN";
    const NO_INPUT            = "NO_INPUT";
    const INPUT_INVALID       = "INPUT_INVALID";

    public function __construct(\CpeSdk\CpeClientInterface $client = null,
        $params,
        $debug,
        $cpeLogger = null)
    {
        $this->debug            = $debug;
        $this->params           = $params;
          
        // For maniplating AWS State Function
        $this->cpeSfnHandler    = new \CpeSdk\Sfn\CpeSfnHandler();    

        // Check if there is an activity name
        if (!isset($params["name"]) || !$params["name"])
            throw new \CpeSdk\CpeException("Can't instantiate BasicActivity: 'name' is not provided or empty! Provide a name for your activity\n", 
			    self::NO_ACTIVITY_NAME);
        
        // Check if there is an activity name
        if (!isset($params["arn"]) || !$params["arn"])
            throw new \CpeSdk\CpeException("Can't instantiate BasicActivity: 'arn' is not provided or empty! Provide the ARN of your activity\n", 
			    self::NO_ACTIVITY_ARN);

        // Setup the logger
        if (!$cpeLogger)
            $this->cpeLogger = new \CpeSdk\CpeLogger(null, $params["name"], $debug); 
        else
            $this->cpeLogger = $cpeLogger;

        // Set default timeout to 65 as the Snf long poll timeout is 60
        ini_set('default_socket_timeout', 65);
    }

    /**
     * Perform JSON input validation
     * Decode JSON to Associative array
     */
    private function doInputValidation($input)
    {
        // Check JSON input
        if (!($this->input = json_decode($input)))
            throw new \CpeSdk\CpeException("JSON input is invalid!", 
			    self::INPUT_INVALID);
    }
    
    /**
     * Perform Snf long polling and call user callback function when receiving new activity
     */ 
    public function doActivity($arn, $name, $callback)
    {
        do {
            $context = [
                'activityArn' => $arn, 
                'workerName'  => $name,
            ];
            
            try {
                // Perform Snf long polling and wait for new tasks to process
                $task = $client->getActivityTask($context);
            } catch (\Exception $e) {
                $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                    "Snf getActivityTask Failed! " . $e->getMessage(),
                    $arn . " - " .$name);
                
                // Notify the client if any
                if ($this->client)
                    $this->client->onException($context, $e);
            }
            
            try {
                // Do we have a new activity?
                if (isset($task['taskToken']) && $task['taskToken'] != '') {

                    // Validate the JSON input and set `$this->input`
                    $this->doInputValidation($task['input']);
                    
                    // Notify the client if any
                    if ($this->client)
                        $this->client->onStart($task);
                    
                    // Call the user callback function with the activity input for processing
                    $callback($task);
                }
            } catch (\Exception $e) {
                // Notify Snf that the activity has failed
                $this->activityFailed($task['taskToken'], "activityException", $e->getMessage());
            }
            
        } while (42);  
    }

    /**
     * Activity failed 
     * Called by parent Activity when something went wrong
     * Notifies Snf that the activity has failed
     *
     * Return false ONLY if Snf call failed. 
     * True otherwise, even of your client interface failed as the Snf work is successful.
     */
    public function activityFail($taskToken, $error = "", $cause = "")
    {
        $context = [
            'cause' => $cause,
            'error' => $error,
            'taskToken' => $taskToken
        ];
        
        try {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__),
                "[$error] $cause",
                $taskToken);

            $this->SnfHandler->sendTaskFailure($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onFail($taskToken, $error, $cause);
            
        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                "Unable to send 'Task Failure' to Snf! " . $e->getMessage(),
                $taskToken);
            
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
    public function activitySuccess($taskToken, $output = '')
    {
        $context = [
            'output'    => $output,
            'taskToken' => $taskToken
        ];
        
        try {
            $this->cpeLogger->logOut("INFO", "[CPE SDK] " . basename(__FILE__),
                "Notify Snf that activity has completed !",
                $taskToken);
            
            $this->SnfHandler->sendTaskSuccess($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onSuccess($taskToken, $output);
            
        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                "Unable to send 'Task success' to Snf! " . $e->getMessage(),
                $taskToken);
            
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
    public function activityHeartbeat($taskToken, $data = null)
    {
        $context = [
            'taskToken' => $taskToken
        ];
        
        try {
            $this->cpeLogger->logOut("INFO", "[CPE SDK] " . basename(__FILE__), 
                "Sending heartbeat to Snf ...",
                $taskToken);

            $client->sendTaskHeartbeat($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onHeartbeat($taskToken, $data);
            
        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", "[CPE SDK] " . basename(__FILE__), 
                "Unable to send 'Task Heartbeat' to Snf! " . $e->getMessage(),
                $taskToken);
            
            // Notify the client if any
            if ($this->client)
                $this->client->onException($context, $e);

            return false;
        }

        return true;
    }
}
