<?php

/**
 * This class is used by the CPE Core InputPoller
 * We use it to listen to SQS input messages from clients
 **/

namespace SA\CpeSdk\Sqs;

// SA Cpe SDK
use SA\CpeSdk;

class CpeSqsListener
{
    private $debug;
    private $sqs;
    private $cpeLogger;
    
    public function __construct($debug, $cpeLogger = null)
    {
        $this->debug = $debug;

        // Create AWS SDK instance
        $this->sqs = new \Aws\Sqs\SqsClient([
                'region'  => getenv("AWS_DEFAULT_REGION"),
                'version' => 'latest'
            ]);

        // Logger
        if (!$cpeLogger)
            $this->cpeLogger = new CpeSdk\CpeLogger();
        else
            $this->cpeLogger = $cpeLogger;
    }
    
    /**
     * LISTEN to OUTPUT SQS queue
     * Listen to the CPE stack for output messages
     */
    
    // Poll one message at a time from the provided SQS queue
    public function receive_message($queue, $timeout)
    {
        if ($this->debug)
            $this->cpeLogger->log_out(
                "DEBUG", 
                "[CPE SDK] ".basename(__FILE__),
                "Polling from '$queue' ..."
            );
            
        // Poll from SQS to check for new message 
        $result = $this->sqs->receiveMessage(array(
                'QueueUrl'        => $queue,
                'WaitTimeSeconds' => $timeout,
            ));
        
        // Get the message if any and return it to the caller
        if (($messages = $result->get('Messages')) &&
            count($messages))
        {
            if ($this->debug)
                $this->cpeLogger->log_out(
                    "DEBUG", 
                    "[CPE SDK] ".basename(__FILE__),
                    "New messages recieved in queue: '$queue'"
                );
            
            return $messages[0];
        }

        return false;
    }

    // Delete a message from SQS queue
    // Call it after reading a message
    public function delete_message($queue, $msg)
    {
        $this->sqs->deleteMessage(array(
                'QueueUrl'        => $queue,
                'ReceiptHandle'   => $msg['ReceiptHandle']));
    }
}
