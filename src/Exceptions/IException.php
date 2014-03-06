<?php

  namespace WhatsApi\Exceptions;



  interface IException
  {


    /* Overrideable methods inherited from Exception class */
    public function __construct($message = null, $code = 0);



    public function __toString(); // formated string for display
    /* Protected methods inherited from Exception class */
    public function getMessage(); // Exception message
    public function getCode(); // User-defined Exception code
    public function getFile(); // Source filename
    public function getLine(); // Source line
    public function getTrace(); // An array of the backtrace()
    public function getTraceAsString(); // Formated string of trace
  }
 