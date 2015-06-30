<?php

// Custom exception class for the Cloud Processing Engine
class CpeException extends \Exception
{
    public $ref;
    
    // Redefine constructor so 'message' isn't optional
    public function __construct(
        $message,
        $ref = "",
        $code = 0, \Exception $previous = null) {
        
        $this->ref = $ref;
        parent::__construct($message, $code, $previous);
    }
    
    // custom string representation of object
    public function __toString() {
        return __CLASS__ . ": [{$this->ref}]: {$this->message}\n";
    }
}
