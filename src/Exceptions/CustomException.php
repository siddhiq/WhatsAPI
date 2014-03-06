<?php

  namespace WhatsApi\Exceptions;



  abstract class CustomException extends \Exception implements IException
  {


    protected $message = 'Unknown exception'; // Exception message
    protected $code = 0; // User-defined exception code
    protected $file; // Source filename of exception
    protected $line; // Source line of exception
    private $string; // Unknown
    private $trace; // Unknown
    public function __construct($message = null, $code = 0)
    {
      if (!$message)
      {
        throw new $this('Unknown ' . get_class($this));
      }
      parent::__construct($message, $code);
    }



    public function __toString()
    {
      return get_class($this) . " '{$this->message}' in {$this->file}({$this->line})\n"
      . "{$this->getTraceAsString()}";
    }
  }
 
 