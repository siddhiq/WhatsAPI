<?php

  namespace WhatsApi\Common;



  /**
   * Class Cryptography
   *
   * Use this class for execute cryptography functions for passwords and more
   *
   * @package WhatsApi\Common
   */
  class Cryptography
  {


    /**
     * Password-Based Key Derivation Function 2
     *
     * @param string  $algorithm
     * @param string  $password
     * @param mixed   $salt
     * @param int     $count
     * @param int     $key_length
     * @param bool    $raw_output
     *
     * @return string
     */
    public static function waPbkdf2( $algorithm, $password, $salt, $count, $key_length, $raw_output = false )
    {
      $algorithm = strtolower($algorithm);

      if (!in_array($algorithm, hash_algos(), true))
      {
        die('PBKDF2 ERROR: Invalid hash algorithm.');
      }

      if ($count <= 0 || $key_length <= 0)
      {
        die('PBKDF2 ERROR: Invalid parameters.');
      }

      $hash_length = strlen(hash($algorithm, "", true));
      $block_count = ceil($key_length / $hash_length);
      $output = "";

      for ($i = 1; $i <= $block_count; $i++)
      {
        $last = $salt . pack("N", $i);
        $last = $xorsum = hash_hmac($algorithm, $last, $password, true);

        for ($j = 1; $j < $count; $j++)
        {
          $xorsum ^= ($last = hash_hmac($algorithm, $last, $password, true));
        }

        $output .= $xorsum;
      }

      if ($raw_output)
      {
        return substr($output, 0, $key_length);
      }
      else
      {
        return bin2hex(substr($output, 0, $key_length));
      }
    }



    /**
     * Convert string to hexadecimal
     *
     * @param string $string
     *
     * @return mixed
     */
    public static function str2hex( $string )
    {
      $hexstr = unpack('H*', $string);

      return array_shift($hexstr);
    }



    /**
     * Convert hexadecimal to string
     *
     * @param mixed $hexstr
     *
     * @return string
     */
    public static function hex2str( $hexstr )
    {
      $hexstr = str_replace(' ', '', $hexstr);
      $hexstr = str_replace('\x', '', $hexstr);
      $retstr = pack('H*', $hexstr);

      return $retstr;
    }



    /**
     * Print hexadecimal string
     *
     * @param $data
     * @param $name
     */
    public static function printHexStr( $data, $name )
    {
      $data = self::str2hex($data);
      $len  = strlen($data);

      print("Len: $len - $name\n");

      for ($i = 0; $i < $len; $i += 2)
      {
        if ((($i - 1) % 32) == 31)
        {
          print("\n");
        }

        printf(" %s%s", $data[$i], $data[$i + 1]);
      }

      print("\n");
    }
  }
 
 