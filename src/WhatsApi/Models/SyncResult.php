<?php

  namespace WhatsApi\Models;



  /**
   * Class SyncResult
   *
   * @package WhatsApi\Models
   */
  class SyncResult 
  {


    /**
     * @var int
     */
    public $index;


    /**
     * @var string
     */
    public $syncId;


    /**
     * @var array
     */
    public $existing;


    /**
     * @var array
     */
    public $nonExisting;



    /**
     * Constructor of synchronization result
     *
     * @param $index
     * @param $syncId
     * @param $existing
     * @param $nonExisting
     */
    public function __construct( $index, $syncId, $existing, $nonExisting )
    {
      $this->index = $index;
      $this->syncId = $syncId;
      $this->existing = $existing;
      $this->nonExisting = $nonExisting;
    }
  }
 