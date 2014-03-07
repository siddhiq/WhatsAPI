<?php

  namespace WhatsApi\Exceptions;



  /**
   * Interface IWhatsException
   *
   * Use this interface for implements custom whatsapi exceptions
   *
   * @package WhatsApi\Exceptions
   */
  interface IWhatsException
  {


    /**
     * Overrideable inherited constructor from Exception class
     *
     * @param string|null $message
     * @param int  $code
     */
    public function __construct($message = null, $code = 0);



    /**
     * Overrideable inherited formatter display string from Exception class
     *
     * @return string
     */
    public function __toString();



    /**
     * Method inherited from Exception class for get exception message
     *
     * @return string
     */
    public function getMessage();



    /**
     * Method inherited from Exception class for get user-defined exception code
     *
     * @return int
     */
    public function getCode();



    /**
     * Method inherited from Exception class for get exception source filename
     *
     * @return string
     */
    public function getFile();



    /**
     * Method inherited from Exception class for get exception source line
     *
     * @return string
     */
    public function getLine();



    /**
     * Method inherited from Exception class for get exception backtrace array
     *
     * @return array
     */
    public function getTrace();



    /**
     * Method inherited from Exception class for get exception backtrace string
     *
     * @return string
     */
    public function getTraceAsString();
  }
 