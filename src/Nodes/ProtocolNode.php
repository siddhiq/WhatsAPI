<?php

  namespace WhatsApi\Nodes;



  /**
   * Class ProtocolNode
   *
   * @package WhatsApi\Nodes
   */
  class ProtocolNode
  {


    /**
     * Tag of node
     *
     * @var string
     */
    private $tag;


    /**
     * Array of attributes of node
     *
     * @var array
     */
    private $attributeHash;


    /**
     * Array of childs node
     *
     * @var ProtocolNode[]
     */
    private $children;


    /**
     * Data of node
     *
     * @var string
     */
    private $data;


    /**
     * Flag for indicate if call is from command line
     *
     * @var null|bool
     */
    private static $cli = null;



    /**
     * Constructor of Protocol Node
     *
     * @param string $tag
     * @param array $attributeHash
     * @param ProtocolNode[] $children
     * @param string $data
     */
    public function __construct($tag, $attributeHash, $children, $data)
    {
      $this->tag           = $tag;
      $this->attributeHash = $attributeHash;
      $this->children      = $children;
      $this->data          = $data;
    }



    /**
     * Check if call is from command line
     *
     * @return bool
     */
    private static function isCli()
    {
      if (self::$cli === null)
      {
        //initial setter
        if (php_sapi_name() == "cli")
        {
          self::$cli = true;
        }
        else
        {
          self::$cli = false;
        }
      }

      return self::$cli;
    }



    /**
     * Getter for data property
     *
     * @return string
     */
    public function getData()
    {
      return $this->data;
    }



    /**
     * Getter for tag property
     *
     * @return string
     */
    public function getTag()
    {
      return $this->tag;
    }



    /**
     * Getter for attributes property
     *
     * @return string[]
     */
    public function getAttributes()
    {
      return $this->attributeHash;
    }



    /**
     * Getter for children property
     *
     * @return ProtocolNode[]
     */
    public function getChildren()
    {
      return $this->children;
    }



    /**
     * Get the string of node
     *
     * @param string $indent
     * @param bool   $isChild
     *
     * @return string
     */
    public function nodeString($indent = "", $isChild = false)
    {
      //formatters
      $lt = "<";
      $gt = ">";
      $nl = "\n";

      if (!self::isCli())
      {
        $lt     = "&lt;";
        $gt     = "&gt;";
        $nl     = "<br />";
        $indent = str_replace(" ", "&nbsp;", $indent);
      }

      $ret = $indent . $lt . $this->tag;

      if ($this->attributeHash != null)
      {
        foreach ($this->attributeHash as $key => $value)
        {
          $ret .= " " . $key . "=\"" . $value . "\"";
        }
      }

      $ret .= $gt;

      if (strlen($this->data) > 0)
      {
        if (strlen($this->data) <= 1024)
        {
          //message
          $ret .= $this->data;
        }
        else
        {
          //raw data
          $ret .= " " . strlen($this->data) . " byte data";
        }
      }

      if ($this->children)
      {
        $ret .= $nl;
        $foo = array();

        foreach ($this->children as $child)
        {
          $foo[] = $child->nodeString($indent . "  ", true);
        }

        $ret .= implode($nl, $foo);
        $ret .= $nl . $indent;
      }

      $ret .= $lt . "/" . $this->tag . $gt;

      if (!$isChild)
      {
        $ret .= $nl;

        if (!self::isCli())
        {
          $ret .= $nl;
        }
      }

      return $ret;
    }



    /**
     * Get attribute from attributes of node
     *
     * @param string $attribute
     *
     * @return string
     */
    public function getAttribute($attribute)
    {
      $ret = "";

      if (isset($this->attributeHash[$attribute]))
      {
        $ret = $this->attributeHash[$attribute];
      }

      return $ret;
    }



    /**
     * Check if node contains id
     *
     * @param string $needle
     *
     * @return boolean
     */
    public function nodeIdContains($needle)
    {
      return (strpos($this->getAttribute("id"), $needle) !== false);
    }



    /**
     * Get child of node
     *
     * @param string|int $tag
     *
     * @return ProtocolNode
     */
    public function getChild($tag)
    {
      $ret = null;
      if ($this->children)
      {
        if (is_int($tag))
        {
          if (isset($this->children[$tag]))
          {
            return $this->children[$tag];
          }
          else
          {
            return null;
          }
        }

        foreach ($this->children as $child)
        {
          if (strcmp($child->tag, $tag) == 0)
          {
            return $child;
          }

          $ret = $child->getChild($tag);

          if ($ret)
          {
            return $ret;
          }
        }
      }

      return null;
    }



    /**
     * Check if child has a child node
     *
     * @param string|int $tag
     *
     * @return bool
     */
    public function hasChild($tag)
    {
      return $this->getChild($tag) == null ? false : true;
    }



    /**
     * Refresh time of node
     *
     * @param int $offset
     */
    public function refreshTimes($offset = 0)
    {
      if (isset($this->attributeHash['id']))
      {
        $id                        = $this->attributeHash['id'];
        $parts                     = explode('-', $id);
        $parts[0]                  = time() + $offset;
        $this->attributeHash['id'] = implode('-', $parts);
      }

      if (isset($this->attributeHash['t']))
      {
        $this->attributeHash['t'] = time();
      }
    }
  }
 
 