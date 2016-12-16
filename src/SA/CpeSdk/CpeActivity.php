<?php

/**
 * This Class must be used to create your own activities.
 * Extend it, and call `do_activity` with your callback as parameter to process incoming activities
 * Call activity_failed, activity_completed, activity_heartbeat
 * to notify Sfn of the status of your activity
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
    public $cpeSfnHandler;    // Class that contains the Sfn client
    public $client;           // Interface to the client application

    public $input;            // Input JSON for this activity
    public $token;            // Sfn token for this activity
    public $arn;              // The ARN of the activity
    public $name;             // The Name of the activity
    public $logKey;           // Composition for identifying logging infomation

    const HEARTBEAT_FAILED    = "HEARTBEAT_FAILED";
    const NO_ACTIVITY_NAME    = "NO_ACTIVITY_NAME";
    const NO_ACTIVITY_ARN     = "NO_ACTIVITY_ARN";
    const NO_INPUT            = "NO_INPUT";
    const INPUT_INVALID       = "INPUT_INVALID";

    public function __construct($clientClassPath = null, $params, $debug, $cpeLogger)
    {
        $this->debug            = $debug;
        $this->params           = $params;
        // Setup the logger
        $this->cpeLogger        = $cpeLogger;

        // For maniplating AWS State Function
        $this->cpeSfnHandler    = new \SA\CpeSdk\Sfn\CpeSfnHandler();

        // Set default timeout to 65 as the Sfn long poll timeout is 60
        ini_set('default_socket_timeout', 65);

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

        // Register the client application interface
        if (file_exists($clientClassPath) && is_readable($clientClassPath)) {
            $className = pathinfo($clientClassPath)['filename'];
            $this->cpeLogger->logOut("INFO",
                                     basename(__FILE__),
                                     "Instantiate Client Interface '$className' from '$clientClassPath'");
            
            require_once($clientClassPath);
            // Instanciate Client class, which should have the same name than the filename
            $this->client = new $className($this->cpeLogger);
        }
    }

    /*
     * This method must be implemented by the Activity which inherit this class
     * This is the entry point for you logic
     */
    abstract protected function process($task);

    /**
     * This must be called fro your activity to start listening for task
     * Perform Sfn long polling and call user callback function when receiving new activity
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
                if ($this->debug)
                    $this->cpeLogger->logOut("DEBUG", basename(__FILE__),
                                             "Polling for '$this->name' activity...");

                // Perform Sfn long polling and wait for new tasks to process
                $task = $this->cpeSfnHandler->sfn->getActivityTask($context);

            } catch (\Exception $e) {
                $this->cpeLogger->logOut("ERROR", basename(__FILE__),
                                         "Sfn getActivityTask Failed! " . $e->getMessage(),
                                         $this->logKey);

                // Notify the client if any
                if ($this->client)
                    $this->client->onException($context, $e);
            }
            
            try {
                // Do we have a new activity?
                if (isset($task['taskToken']) && $task['taskToken'] != '') {

                    $this->cpeLogger->logOut("INFO", basename(__FILE__),
                                             "\033[1mNew activity '$this->name' triggered!\033[0m Token: ".$task['taskToken'].".\nSee the Log file for this token: ".$this->cpeLogger->logPath);

                    // Set the logKey so a new log file will be created just for this Execution
                    $this->logKey = substr($task['taskToken'],
                                           strlen($task['taskToken'])-16,
                                           strlen($task['taskToken']) - (strlen($task['taskToken']) - 16));
                    $this->logKey = preg_replace('/[\\\\\/\%\[\]\.\(\)-\/]/s', "_", $this->logKey);
                    $this->token  = $task['taskToken'];
                    if ($this->client)
                        $this->client->logKey = $this->logKey;
                    
                    // Validate the JSON input and set `$this->input`
                    $this->doInputValidation($task['input']);

                    // Notify the client if any
                    if ($this->client)
                        $this->client->onStart($task);

                    // Call the user callback function with the activity input for processing
                    $result = $this->process($task);

                    // Execution successful. We mark it as such and return the output to Sfn
                    $this->activitySuccess($result);
                }
            } catch (\Exception $e) {
                // Notify Sfn that the activity has failed
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
     * Notifies Sfn that the activity has failed
     *
     * Return false ONLY if Sfn call failed.
     * True otherwise, even of your client interface failed as the Sfn work is successful.
     */
    public function activityFail($error = "", $cause = "")
    {
        $context = [
            'error' => $error,
            'cause' => $cause,
            'taskToken' => $this->token
        ];
                                                 
        try {
            $this->cpeLogger->logOut("ERROR", basename(__FILE__),
                                     "\033[1m[$error]\033[0m $cause",
                                     $this->logKey);

            $this->cpeSfnHandler->sfn->sendTaskFailure($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onFail($this->token, $error, $cause);

        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", basename(__FILE__),
                                     "Unable to send 'Task Failure' to Sfn! " . $e->getMessage(),
                                     $this->logKey);

            // Notify the client if any
            if ($this->client)
                $this->client->onException($context, $e);

            return false;
        }

        return true;
    }

    /**
     * Activity Success and completed
     * Notifies Sfn that the activity has succeeded
     * Call this from your activity logic once the process is successful.
     * If return false then the call to Sfn sndTaskSuccess failed
     * Try again or throw an exception to mark the Activity as failed
     */
    public function activitySuccess($output = '{}')
    {
        $context = [
            'output'    => $output,
            'taskToken' => $this->token
        ];

        try {
            $this->cpeLogger->logOut("INFO", basename(__FILE__),
                                     "\033[1mNotify Sfn that activity has completed!\033[0m",
                                     $this->logKey);

            $this->cpeSfnHandler->sfn->sendTaskSuccess($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onSuccess($this->token, $output);

        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", basename(__FILE__),
                                     "Unable to send 'Task success' to Sfn! " . $e->getMessage(),
                                     $this->logKey);

            // Notify the client if any
            if ($this->client)
                $this->client->onException($context, $e);

            return false;
        }

        return true;
    }

    /**
     * Send heartbeat to Sfn to keep the task alive.
     * Call this from your activity logic. If return false then the heartbeat was not sent
     * Try again or throw an exception to mark the Activity as failed
     */
    public function activityHeartbeat($data = null)
    {
        $context = [
            'taskToken' => $this->token
        ];

        try {
            $this->cpeLogger->logOut("INFO", basename(__FILE__),
                                     "\033[1mSending heartbeat to Sfn ...\033[0m",
                                     $this->logKey);

            $this->cpeSfnHandler->sfn->sendTaskHeartbeat($context);

            // Notify the client if any
            if ($this->client)
                $this->client->onHeartbeat($this->token, $data);

        } catch (\Exception $e) {
            $this->cpeLogger->logOut("ERROR", basename(__FILE__),
                                     "Unable to send 'Task Heartbeat' to Sfn! " . $e->getMessage(),
                                     $this->logKey);

            // Notify the client if any
            if ($this->client)
                $this->client->onException($context, $e);

            return false;
        }

        return true;
    }
}
