<?php

  namespace WhatsApi\Exceptions;



  /**
   * Class WhatsException
   *
   * Use this class for fire custom exceptions
   *
   * @package WhatsApi\Exceptions
   */
  abstract class WhatsException extends \Exception implements IWhatsException
  {


    /**
     * User-defined exception message
     *
     * @var string
     */
    protected $message = 'Unknown exception';


    /**
     * User-defined exception code
     *
     * @var int
     */
    protected $code = 0;


    /**
     * Source filename of exception
     *
     * @var string
     */
    protected $file;


    /**
     * Source line of exception
     *
     * @var string
     */
    protected $line;

    //TODO: make sure they are not necessary
    /**
     * Unknown
     *
     * @var string
     */
    private $string;


    /**
     * Unknown
     *
     * @var string
     */
    private $trace;



    /**
     * Exception constructor
     *
     * @param string|null $message
     * @param int  $code
     */
    public function __construct($message = null, $code = 0)
    {
      if (!$message)
      {
        throw new $this('Unknown ' . get_class($this));
      }

      parent::__construct($message, $code);
    }



    /**
     * Formater string for display exception
     *
     * @return string
     */
    public function __toString()
    {
      return get_class($this) . " '{$this->message}' in {$this->file}({$this->line})\n" . "{$this->getTraceAsString()}";
    }
  }
 
 