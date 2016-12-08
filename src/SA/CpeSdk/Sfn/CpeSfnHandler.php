<?php

namespace SA\CpeSdk\Swf;

// SA Cpe SDK
use SA\CpeSdk;

/**
 * Create the AWS Sfn Client
 * Check for AWS environment variables
 */
class CpeSfnHandler
{
    public $sfn;

    public function __construct()
    {
        # Check if preper env vars are setup
        if (!($region = getenv("AWS_DEFAULT_REGION")))
            throw new CpeSdk\CpeException("Set 'AWS_DEFAULT_REGION' environment variable!");

        // SWF client
        $this->snf = new \Aws\Sfn\SfnClient([
                'region'  => $region,
                'version' => 'latest'
            ]);
    }
}