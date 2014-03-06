<?php

  namespace WhatsApi;



  use WhatsApi\Common\Cryptography;
  use WhatsApi\Common\KeyStream;
  use WhatsApi\Common\Media;
  use WhatsApi\Common\MediaUploader;
  use WhatsApi\Common\Token;
  use WhatsApi\Events\WhatsAppEvent;
  use WhatsApi\Nodes\BinTreeNodeReader;
  use WhatsApi\Nodes\BinTreeNodeWriter;
  use WhatsApi\Nodes\ProtocolNode;



  /**
   * Class WhatsProtocol
   *
   * Main WhatsAPI class
   *
   * @package WhatsApi
   */
  class WhatsProtocol
  {


    /**
     * Constant declarations.
     */
    const CONNECTED_STATUS = 'connected'; // Describes the connection status with the WhatsApp server.
    const DISCONNECTED_STATUS = 'disconnected'; // Describes the connection status with the WhatsApp server.
    const MEDIA_FOLDER = 'media'; // The relative folder to store received media files
    const PICTURES_FOLDER = 'pictures'; // The relative folder to store picture files
    const PORT = 443; // The port of the WhatsApp server.
    const TIMEOUT_SEC = 2; // The timeout for the connection with the WhatsApp servers.
    const TIMEOUT_USEC = 0; //
    const WHATSAPP_CHECK_HOST = 'v.whatsapp.net/v2/exist'; // The check credentials host.
    const WHATSAPP_GROUP_SERVER = 'g.us'; // The Group server hostname
    const WHATSAPP_HOST = 'c.whatsapp.net'; // The hostname of the WhatsApp server.
    const WHATSAPP_REGISTER_HOST = 'v.whatsapp.net/v2/register'; // The register code host.
    const WHATSAPP_REQUEST_HOST = 'v.whatsapp.net/v2/code'; // The request code host.
    const WHATSAPP_SERVER = 's.whatsapp.net'; // The hostname used to login/send messages.
    const WHATSAPP_UPLOAD_HOST = 'https://mms.whatsapp.net/client/iphone/upload.php'; // The upload host.
    const WHATSAPP_DEVICE = 'Android'; // The device name.
    const WHATSAPP_VER = '2.11.151'; // The WhatsApp version.
    const WHATSAPP_USER_AGENT = 'WhatsApp/2.11.151 Android/4.3 Device/GalaxyS3'; // User agent used in request/registration code.


    /**
     * Property declarations.
     */
    protected $accountInfo; // The AccountInfo object.
    protected $challengeData; //
    protected $debug; // Determines whether debug mode is on or off.
    protected $event; // An instance of the WhatsAppEvent class.
    protected $groupList = array(); // An array with all the groups a user belongs in.
    protected $identity; // The Device Identity token. Obtained during registration with this API or using Missvenom to sniff from your phone.
    protected $inputKey; // Instances of the KeyStream class.
    protected $outputKey; // Instances of the KeyStream class.
    protected $groupId = false; // Id of the group created.
    protected $lastId = false; // Id to the last message sent.
    protected $loginStatus; // Holds the login status.
    protected $mediaFileInfo = array(); // Media File Information
    protected $mediaQueue = array(); // Queue for media message nodes
    protected $messageCounter = 1; // Message counter for auto-id.
    protected $messageQueue = array(); // Queue for received messages.
    protected $name; // The user name.
    protected $newMsgBind = false; //
    protected $outQueue = array(); // Queue for outgoing messages.
    protected $password; // The user password.
    protected $phoneNumber; // The user phone number including the country code without '+' or '00'.
    protected $reader; // An instance of the BinaryTreeNodeReader class.
    protected $serverReceivedId; // Confirm that the *server* has received your command.
    protected $socket; // A socket to connect to the WhatsApp network.
    protected $writer; // An instance of the BinaryTreeNodeWriter class.



    /**
     * Default class constructor.
     *
     * @param string $number
     *   The user phone number including the country code without '+' or '00'.
     * @param string $identity
     *   The Device Identity token. Obtained during registration with this API
     *   or using Missvenom to sniff from your phone.
     * @param string $nickname
     *   The user name.
     * @param        $debug
     *   Debug on or off, false by default.
     */
    public function __construct($number, $identity, $nickname, $debug = false)
    {
      $this->writer      = new BinTreeNodeWriter();
      $this->reader      = new BinTreeNodeReader();
      $this->debug       = $debug;
      $this->phoneNumber = $number;

      if (!$this->checkIdentity($identity))
      {
        //compute sha identity hash
        $this->identity = $this->buildIdentity($identity);
      }
      else
      {
        //use provided identity hash
        $this->identity = $identity;
      }

      $this->name        = $nickname;
      $this->loginStatus = static::DISCONNECTED_STATUS;
    }



    /**
     * Add message to the outgoing queue.
     *
     * @param ProtocolNode $node
     */
    public function addMsgOutQueue($node)
    {
      $this->outQueue[] = $node;
    }



    /**
     * Check if account credentials are valid.
     *
     * WARNING: WhatsApp now changes your password everytime you use this.
     * Make sure you update your config file if the output informs about
     * a password change.
     *
     * @return object
     *   An object with server response.
     *   - status: Account status.
     *   - login: Phone number with country code.
     *   - pw: Account password.
     *   - type: Type of account.
     *   - expiration: Expiration date in UNIX TimeStamp.
     *   - kind: Kind of account.
     *   - price: Formatted price of account.
     *   - cost: Decimal amount of account.
     *   - currency: Currency price of account.
     *   - price_expiration: Price expiration in UNIX TimeStamp.
     *
     * @throws \Exception
     */
    public function checkCredentials()
    {
      if (!$phone = $this->dissectPhone())
      {
        throw new \Exception('The prived phone number is not valid.');
      }

      // Build the url.
      $host  = 'https://' . static::WHATSAPP_CHECK_HOST;
      $query = array(
        'cc' => $phone['cc'],
        'in' => $phone['phone'],
        'id' => $this->identity,
        'c'  => 'cookie',
      );

      $response = $this->getResponse($host, $query);

      if ($response->status != 'ok')
      {
        $this->eventManager()->fireCredentialsBad($this->phoneNumber, $response->status, $response->reason);

        if ($this->debug)
        {
          print_r($query);
          print_r($response);
        }

        throw new \Exception('There was a problem trying to request the code.');
      }
      else
      {
        $this->eventManager()->fireCredentialsGood(
          $this->phoneNumber,
          $response->login,
          $response->pw,
          $response->type,
          $response->expiration,
          $response->kind,
          $response->price,
          $response->cost,
          $response->currency,
          $response->price_expiration
        );
      }

      return $response;
    }



    /**
     * Register account on WhatsApp using the provided code.
     *
     * @param integer $code
     *   Numeric code value provided on requestCode().
     *
     * @return object
     *   An object with server response.
     *   - status: Account status.
     *   - login: Phone number with country code.
     *   - pw: Account password.
     *   - type: Type of account.
     *   - expiration: Expiration date in UNIX TimeStamp.
     *   - kind: Kind of account.
     *   - price: Formatted price of account.
     *   - cost: Decimal amount of account.
     *   - currency: Currency price of account.
     *   - price_expiration: Price expiration in UNIX TimeStamp.
     *
     * @throws \Exception
     */
    public function codeRegister($code)
    {
      if (!$phone = $this->dissectPhone())
      {
        throw new \Exception('The prived phone number is not valid.');
      }

      // Build the url.
      $host  = 'https://' . static::WHATSAPP_REGISTER_HOST;
      $query = array(
        'cc'   => $phone['cc'],
        'in'   => $phone['phone'],
        'id'   => $this->identity,
        'code' => $code,
        'c'    => 'cookie',
      );

      $response = $this->getResponse($host, $query);

      if ($response->status != 'ok')
      {
        $this->eventManager()->fireCodeRegisterFailed(
          $this->phoneNumber,
          $response->status,
          $response->reason,
          $response->retry_after
        );

        if ($this->debug)
        {
          print_r($query);
          print_r($response);
        }

        throw new \Exception('An error occurred registering the registration code from WhatsApp.');
      }
      else
      {
        $this->eventManager()->fireCodeRegister(
          $this->phoneNumber,
          $response->login,
          $response->pw,
          $response->type,
          $response->expiration,
          $response->kind,
          $response->price,
          $response->cost,
          $response->currency,
          $response->price_expiration
        );
      }

      return $response;
    }



    /**
     * Request a registration code from WhatsApp.
     *
     * @param string $method
     *   Accepts only 'sms' or 'voice' as a value.
     * @param string $countryCode
     *   ISO Country Code, 2 Digit.
     * @param string $langCode
     *   ISO 639-1 Language Code: two-letter codes.
     *
     * @return object
     *   An object with server response.
     *   - status: Status of the request (sent/fail).
     *   - length: Registration code lenght.
     *   - method: Used method.
     *   - reason: Reason of the status (e.g. too_recent/missing_param/bad_param).
     *   - param: The missing_param/bad_param.
     *   - retry_after: Waiting time before requesting a new code.
     *
     * @throws \Exception
     */
    public function codeRequest($method = 'sms', $countryCode = null, $langCode = null)
    {
      if (!$phone = $this->dissectPhone())
      {
        throw new \Exception('The provided phone number is not valid.');
      }

      if ($countryCode == null && $phone['ISO3166'] != '')
      {
        $countryCode = $phone['ISO3166'];
      }

      if ($countryCode == null)
      {
        $countryCode = 'US';
      }

      if ($langCode == null && $phone['ISO639'] != '')
      {
        $langCode = $phone['ISO639'];
      }

      if ($langCode == null)
      {
        $langCode = 'en';
      }

      // Build the token.
      $token = Token::generateRequestToken($phone['country'], $phone['phone']);

      // Build the url.
      $host  = 'https://' . static::WHATSAPP_REQUEST_HOST;

      $query = array(
        'cc'     => $phone['cc'],
        'in'     => $phone['phone'],
        'to'     => $this->phoneNumber,
        'lg'     => $langCode,
        'lc'     => $countryCode,
        'method' => $method,
        'mcc'    => $phone['mcc'],
        'mnc'    => '001',
        'token'  => urlencode($token),
        'id'     => $this->identity,
      );

      if ($this->debug)
      {
        print_r($query);
      }

      $response = $this->getResponse($host, $query);

      if ($this->debug)
      {
        print_r($response);
      }

      if ($response->status == 'ok')
      {
        $this->eventManager()->fireCodeRegister(
          $this->phoneNumber,
          $response->login,
          $response->pw,
          $response->type,
          $response->expiration,
          $response->kind,
          $response->price,
          $response->cost,
          $response->currency,
          $response->price_expiration
        );
      }
      else if ($response->status != 'sent')
      {
        if (isset($response->reason) && $response->reason == "too_recent")
        {
          $this->eventManager()->fireCodeRequestFailedTooRecent(
            $this->phoneNumber,
            $method,
            $response->reason,
            $response->retry_after
          );

          $minutes = round($response->retry_after / 60);

          throw new \Exception("Code already sent. Retry after $minutes minutes.");
        }
        else
        {
          $this->eventManager()->fireCodeRequestFailed(
            $this->phoneNumber,
            $method,
            $response->reason,
            $response->param
          );

          throw new \Exception('There was a problem trying to request the code.');
        }
      }
      else
      {
        $this->eventManager()->fireCodeRequest(
          $this->phoneNumber,
          $method,
          $response->length
        );
      }

      return $response;
    }



    /**
     * Connect (create a socket) to the WhatsApp network.
     *
     */
    public function connect()
    {
      $socket = fsockopen(static::WHATSAPP_HOST, static::PORT);

      if ($socket !== false)
      {
        stream_set_timeout($socket, static::TIMEOUT_SEC, static::TIMEOUT_USEC);
        $this->socket = $socket;

        $this->eventManager()->fireConnect(
          $this->phoneNumber,
          $this->socket
        );
      }
      else
      {
        if ($this->debug)
        {
          print_r("Firing onConnectError\n");
        }

        $this->eventManager()->fireConnectError(
          $this->phoneNumber,
          $this->socket
        );
      }
    }



    /**
     * Disconnect from the WhatsApp network.
     *
     */
    public function disconnect()
    {
      if (is_resource($this->socket))
      {
        fclose($this->socket);
        $this->eventManager()->fireDisconnect(
          $this->phoneNumber,
          $this->socket
        );
      }
    }



    /**
     * Gets a new micro event dispatcher.
     *
     * @return WhatsAppEvent The event manager.
     */
    public function eventManager()
    {
      if (!is_object($this->event))
      {
        $this->event = new WhatsAppEvent();
      }

      return $this->event;
    }



    /**
     * Drain the message queue for application processing.
     *
     * @return ProtocolNode[]
     *   Return the message queue list.
     */
    public function getMessages()
    {
      $ret                = $this->messageQueue;
      $this->messageQueue = array();

      return $ret;
    }



    /**
     * Log into the Whatsapp server.
     *
     * ###Warning### using this method will generate a new password
     * from the WhatsApp servers each time.
     *
     * If you know your password and wish to use it without generating
     * a new password - use the loginWithPassword() method instead.
     */
    public function login()
    {
      $this->accountInfo = (array)$this->checkCredentials();

      if ($this->accountInfo['status'] == 'ok')
      {
        if ($this->debug)
        {
          print_r("New password received: " . $this->accountInfo['pw'] . "\n");
        }

        $this->password = $this->accountInfo['pw'];
      }

      $this->doLogin();
    }



    /**
     * Login to the Whatsapp server with your password
     *
     * If you already know your password you can log into the Whatsapp server
     * using this method.
     *
     * @param string $password Your whatsapp password. You must already know this!
     */
    public function loginWithPassword($password)
    {
      $this->password = $password;
      $challengeData  = @file_get_contents("nextChallenge.dat");

      if ($challengeData)
      {
        $this->challengeData = $challengeData;
      }

      $this->doLogin();
    }



    /**
     * Pull from the socket, and place incoming messages in the message queue.
     *
     */
    public function pollMessages()
    {
      $stanza = $this->readStanza();

      while ($stanza)
      {
        $this->processInboundData($stanza);
        $stanza = $this->readStanza();
      }
    }



    /**
     * Send the active status. User will show up as "Online" (as long as socket is connected).
     *
     */
    public function sendActiveStatus()
    {
      $messageNode = new ProtocolNode("presence", array("type" => "active"), null, "");
      $this->sendNode($messageNode);
    }



    /**
     * Send a Broadcast Message with audio.
     *
     * The receiptiant MUST have your number (synced) and in their contact list
     * otherwise the message will not deliver to that person.
     *
     * Approx 20 (unverified) is the maximum number of targets
     *
     * @param  array  $targets       An array of numbers to send to.
     * @param  string $path          URL or local path to the audio file to send
     * @param  bool   $storeURLmedia Keep a copy of the audio file on your server
     */
    public function sendBroadcastAudio($targets, $path, $storeURLmedia = false)
    {
      if (!is_array($targets))
      {
        $targets = array($targets);
      }

      $this->sendMessageAudio($targets, $path, $storeURLmedia);
    }



    /**
     * Send a Broadcast Message with an image.
     *
     * The receiptiant MUST have your number (synced) and in their contact list
     * otherwise the message will not deliver to that person.
     *
     * Approx 20 (unverified) is the maximum number of targets
     *
     * @param  array  $targets       An array of numbers to send to.
     * @param  string $path          URL or local path to the image file to send
     * @param  bool   $storeURLmedia Keep a copy of the audio file on your server
     */
    public function sendBroadcastImage($targets, $path, $storeURLmedia = false)
    {
      if (!is_array($targets))
      {
        $targets = array($targets);
      }

      $this->sendMessageImage($targets, $path, $storeURLmedia);
    }



    /**
     * Send a Broadcast Message with location data.
     *
     * The receiptiant MUST have your number (synced) and in their contact list
     * otherwise the message will not deliver to that person.
     *
     * If no name is supplied , receiver will see large sized google map
     * thumbnail of entered Lat/Long but NO name/url for location.
     *
     * With name supplied, a combined map thumbnail/name box is displayed
     * Approx 20 (unverified) is the maximum number of targets
     *
     * @param  array  $targets An array of numbers to send to.
     * @param  float  $long    The longitude of the location eg 54.31652
     * @param  float  $lat     The latitude if the location eg -6.833496
     * @param  string $name    (Optional) A name to describe the location
     * @param  string $url     (Optional) A URL to link location to web resource
     */
    public function sendBroadcastLocation($targets, $long, $lat, $name = null, $url = null)
    {
      if (!is_array($targets))
      {
        $targets = array($targets);
      }

      $this->sendMessageLocation($targets, $long, $lat, $name, $url);
    }



    /**
     * Send a Broadcast Message
     *
     * The receiptiant MUST have your number (synced) and in their contact list
     * otherwise the message will not deliver to that person.
     *
     * Approx 20 (unverified) is the maximum number of targets
     *
     * @param  array  $targets An array of numbers to send to.
     * @param  string $message Your message
     */
    public function sendBroadcastMessage($targets, $message)
    {
      $bodyNode = new ProtocolNode("body", null, null, $message);
      $this->sendBroadcast($targets, $bodyNode, "chat");
    }



    /**
     * Send a Broadcast Message with a video.
     *
     * The receiptiant MUST have your number (synced) and in their contact list
     * otherwise the message will not deliver to that person.
     *
     * Approx 20 (unverified) is the maximum number of targets
     *
     * @param  array  $targets       An array of numbers to send to.
     * @param  string $path          URL or local path to the video file to send
     * @param  bool   $storeURLmedia Keep a copy of the audio file on your server
     */
    public function sendBroadcastVideo($targets, $path, $storeURLmedia = false)
    {
      if (!is_array($targets))
      {
        $targets = array($targets);
      }

      $this->sendMessageVideo($targets, $path, $storeURLmedia);
    }



    /**
     * Clears the "dirty" status on your account
     *
     * @param  array $categories
     */
    protected function sendClearDirty($categories)
    {
      $msgId = $this->createMsgId("cleardirty");
      $catnodes = array();

      foreach ($categories as $category)
      {
        $catnode    = new ProtocolNode("clean", array("type" => $category), null, null);
        $catnodes[] = $catnode;
      }

      $node = new ProtocolNode("iq", array(
        "id"    => $msgId,
        "type"  => "set",
        "to"    => "s.whatsapp.net",
        "xmlns" => "urn:xmpp:whatsapp:dirty"
      ), $catnodes, null);

      $this->sendNode($node);
    }



    /**
     * Send client configuration
     *
     */
    public function sendClientConfig()
    {
      $phone = $this->dissectPhone();

      $attr             = array();
      $attr["xmlns"]    = "urn:xmpp:whatsapp:push";
      $attr["platform"] = "none";
      $attr["lc"]       = $phone["ISO3166"];
      $attr["lg"]       = $phone["ISO639"];
      $child            = new ProtocolNode("config", $attr, null, "");
      $node             = new ProtocolNode("iq", array("id" => $this->createMsgId("config"), "type" => "set", "to" => static::WHATSAPP_SERVER), array($child), null);

      $this->sendNode($node);
    }



    /**
     * Send a request to return client configuration
     *
     */
    public function sendGetClientConfig()
    {
      $msgId = $this->createMsgId("sendconfig");

      $child = new ProtocolNode("config", array("xmlns" => "urn:xmpp:whatsapp:push", "sound" => 'sound'), null, null);
      $node  = new ProtocolNode("iq", array(
        "id"   => $msgId,
        "type" => "set",
        "to"   => static::WHATSAPP_SERVER
      ), array($child), null);

      $this->sendNode($node);
      $this->waitForServer($msgId);
    }



    /**
     * Send a request to return a list of groups user is currently participating in.
     *
     * To capture this list you will need to bind the "onGetGroups" event.
     */
    public function sendGetGroups()
    {
      $this->sendGetGroupsFiltered("participating");
    }



    /**
     * Send a request to get information about a specific group
     *
     * @param string $gjid The specific group id
     */
    public function sendGetGroupsInfo($gjid)
    {
      $msgId = $this->createMsgId("getgroupinfo");

      $child = new ProtocolNode("query", array("xmlns" => "w:g"), null, null);
      $node  = new ProtocolNode("iq", array(
        "id"   => $msgId,
        "type" => "get",
        "to"   => $this->getJID($gjid)
      ), array($child), null);

      $this->sendNode($node);
      $this->waitForServer($msgId);
    }



    /**
     * Send a request to return a list of groups user has started in.
     *
     * To capture this list you will need to bind the "onGetGroups" event.
     */
    public function sendGetGroupsOwning()
    {
      $this->sendGetGroupsFiltered("owning");
    }



    /**
     * Send a request to return a list of people participating in a specific
     * group.
     *
     * @param string $gjid The specific group id
     */
    public function sendGetGroupsParticipants($gjid)
    {
      $msgId = $this->createMsgId("getgroupparticipants");

      $child = new ProtocolNode("list", array(
        "xmlns" => "w:g"
      ), null, null);
      $node  = new ProtocolNode("iq", array(
        "id"   => $msgId,
        "type" => "get",
        "to"   => $this->getJID($gjid)
      ), array($child), null);

      $this->sendNode($node);
      $this->waitForServer($msgId);
    }



    /**
     * Send a request to get a list of people you have currently blocked
     */
    public function sendGetPrivacyBlockedList()
    {
      $msgId  = $this->createMsgId("getprivacy");

      $child  = new ProtocolNode("list", array(
        "name" => "default"
      ), null, null);
      $child2 = new ProtocolNode("query", array(
        "xmlns" => "jabber:iq:privacy"
      ), array($child), null);
      $node   = new ProtocolNode("iq", array(
        "id"   => $msgId,
        "type" => "get"
      ), array($child2), null);

      $this->sendNode($node);
      $this->waitForServer($msgId);
    }



    /**
     * Get profile picture of specified user
     *
     * @param string $number
     *  Number or JID of user
     *
     * @param bool   $large
     *  Request large picture
     */
    public function sendGetProfilePicture($number, $large = false)
    {
      $hash          = array();
      $hash["xmlns"] = "w:profile:picture";
      $hash["type"]  = "image";

      if (!$large)
      {
        $hash["type"] = "preview";
      }

      $picture = new ProtocolNode("picture", $hash, null, null);

      $hash         = array();
      $hash["id"]   = $this->createMsgId("getpicture");
      $hash["type"] = "get";
      $hash["to"]   = $this->getJID($number);

      $node         = new ProtocolNode("iq", $hash, array($picture), null);

      $this->sendNode($node);
      $this->waitForServer($hash["id"]);
    }



    /**
     * Request to retrieve the last online time of specific user.
     *
     * @param string $to
     *  Number or JID of user
     */
    public function sendGetRequestLastSeen($to)
    {
      $queryNode = new ProtocolNode("query", null, null, null);

      $messageHash          = array();
      $messageHash["to"]    = $this->getJID($to);
      $messageHash["type"]  = "get";
      $messageHash["id"]    = $this->createMsgId("lastseen");
      $messageHash["xmlns"] = "jabber:iq:last";

      $messageNode = new ProtocolNode("iq", $messageHash, array($queryNode), "");

      $this->sendNode($messageNode);
      $this->waitForServer($messageHash["id"]);
    }



    /**
     * Send a request to get the current server properties
     */
    public function sendGetServerProperties()
    {
      $child = new ProtocolNode("props", array(
        "xmlns" => "w"
      ), null, null);
      $node  = new ProtocolNode("iq", array(
        "id"   => $this->createMsgId("getproperties"),
        "type" => "get",
        "to"   => "s.whatsapp.net"
      ), array($child), null);

      $this->sendNode($node);
    }



    /**
     * Get the current status message of a specific user.
     *
     * @deprecated Use ContactSyncV2 to get status
     *
     * @param  string $jid The user JID
     */
    public function sendGetStatus($jid)
    {
      $parts = explode("@", $jid);
      $to    = $parts[0] . "@s.us";
      $child = new ProtocolNode("action", array("type" => "get"), null, null);
      $node  = new ProtocolNode("message", array(
        "to"   => $to,
        "type" => "action",
        "id"   => $this->createMsgId("message")
      ), array($child), null);

      $this->sendNode($node);
    }



    /**
     * Create a group chat.
     *
     * @param string $subject
     *   The group Subject
     * @param array  $participants
     *   An array with the participants numbers.
     *
     * @return string
     *   The group ID.
     */
    public function sendGroupsChatCreate($subject, $participants = array())
    {
      $groupHash            = array();
      $groupHash["xmlns"]   = "w:g";
      $groupHash["action"]  = "create";
      $groupHash["subject"] = $subject;
      $group                = new ProtocolNode("group", $groupHash, null, "");
      $setHash         = array();
      $setHash["id"]   = $this->createMsgId("creategroup");
      $setHash["type"] = "set";
      $setHash["to"]   = static::WHATSAPP_GROUP_SERVER;
      $groupNode       = new ProtocolNode("iq", $setHash, array($group), "");

      $this->sendNode($groupNode);
      $this->waitForServer($setHash["id"]);

      $groupId = $this->groupId;

      if (count($participants) > 0)
      {
        $this->sendGroupsParticipantsAdd($groupId, $participants);
      }

      return $groupId;
    }



    /**
     * End or delete a group chat
     *
     * @param  string $gjid The group ID
     */
    public function sendGroupsChatEnd($gjid)
    {
      $gjid  = $this->getJID($gjid);
      $msgID = $this->createMsgId("endgroup");

      $groupData       = array();
      $groupData['id'] = $gjid;
      $groupNode       = new ProtocolNode('group', $groupData, null, null);
      $leaveData           = array();
      $leaveData["xmlns"]  = "w:g";
      $leaveData["action"] = "delete";
      $leaveNode           = new ProtocolNode("leave", $leaveData, array($groupNode), null);
      $iqData         = array();
      $iqData["id"]   = $msgID;
      $iqData["type"] = "set";
      $iqData["to"]   = static::WHATSAPP_GROUP_SERVER;
      $iqNode         = new ProtocolNode("iq", $iqData, array($leaveNode), null);

      $this->sendNode($iqNode);
      $this->waitForServer($msgID);
    }



    /**
     * Leave a group chat
     *
     * @param  array $gjids An array of group IDs
     */
    public function sendGroupsLeave($gjids)
    {
      if (!is_array($gjids))
      {
        $gjids = array($this->getJID($gjids));
      }

      $nodes = array();

      foreach ($gjids as $gjid)
      {
        $nodes[] = new ProtocolNode("group", array("id" => $this->getJID($gjid)), null, null);
      }

      $leave        = new ProtocolNode("leave", array("xmlns" => "w:g", 'action' => 'delete'), $nodes, null);
      $hash         = array();
      $hash["id"]   = $this->createMsgId("leavegroups");
      $hash["to"]   = static::WHATSAPP_GROUP_SERVER;
      $hash["type"] = "set";
      $node         = new ProtocolNode("iq", $hash, array($leave), null);

      $this->sendNode($node);
      $this->waitForServer($hash["id"]);
    }



    /**
     * Add participant(s) to a group.
     *
     * @param string $groupId
     *   The group ID.
     * @param array  $participants
     *   An array with the participants numbers to add
     */
    public function sendGroupsParticipantsAdd($groupId, $participants)
    {
      if (!is_array($participants))
      {
        $participants = array($participants);
      }

      $this->sendGroupsChangeParticipants($groupId, $participants, 'add');
    }



    /**
     * Remove participant(s) from a group.
     *
     * @param string $groupId
     *   The group ID.
     * @param array  $participants
     *   An array with the participants numbers to remove
     */
    public function sendGroupsParticipantsRemove($groupId, $participants)
    {
      if (!is_array($participants))
      {
        $participants = array($participants);
      }

      $this->sendGroupsChangeParticipants($groupId, $participants, 'remove');
    }



    /**
     * Send a text message to the user/group.
     *
     * @param        $to
     *   The recipient.
     * @param string $txt
     *   The text message.
     * @param        $id
     *
     * @return string
     */
    public function sendMessage($to, $txt, $id = null)
    {
      $txt      = $this->parseMessageForEmojis($txt);
      $bodyNode = new ProtocolNode("body", null, null, $txt);

      return $this->sendMessageNode($to, $bodyNode, $id);
    }



    /**
     * Send audio to the user/group.
     *
     * @param        $to
     *                              The recipient.
     * @param string $filepath
     *                              The url/uri to the audio file.
     * @param  bool  $storeURLmedia Keep copy of file
     *
     * @return bool
     */
    public function sendMessageAudio($to, $filepath, $storeURLmedia = false)
    {
      $allowedExtensions = array('3gp', 'caf', 'wav', 'mp3', 'wma', 'ogg', 'aif', 'aac', 'm4a');
      $size              = 10 * 1024 * 1024; // Easy way to set maximum file size for this media type.

      return $this->sendCheckAndSendMedia($filepath, $size, $to, 'audio', $allowedExtensions, $storeURLmedia);
    }



    /**
     * Send the composing message status. When typing a message.
     *
     * @param string $to
     *   The recipient to send status to.
     */
    public function sendMessageComposing($to)
    {
      $comphash          = array();
      $comphash['xmlns'] = 'http://jabber.org/protocol/chatstates';
      $compose           = new ProtocolNode("composing", $comphash, null, "");
      $messageHash         = array();
      $messageHash["to"]   = $this->getJID($to);
      $messageHash["type"] = "chat";
      $messageHash["id"]   = $this->createMsgId("composing");
      $messageHash["t"]    = time();

      $messageNode = new ProtocolNode("message", $messageHash, array($compose), "");

      $this->sendNode($messageNode);
    }



    /**
     * Send an image file to group/user
     *
     * @param  string $to
     *                               Recipient number
     * @param  string $filepath
     *                               The url/uri to the image file.
     * @param  bool   $storeURLmedia Keep copy of file
     *
     * @return bool
     */
    public function sendMessageImage($to, $filepath, $storeURLmedia = false)
    {
      $allowedExtensions = array('jpg', 'jpeg', 'gif', 'png');
      $size              = 5 * 1024 * 1024; // Easy way to set maximum file size for this media type.

      return $this->sendCheckAndSendMedia($filepath, $size, $to, 'image', $allowedExtensions, $storeURLmedia);
    }



    /**
     * Send a location to the user/group.
     *
     * If no name is supplied , receiver will see large sized google map
     * thumbnail of entered Lat/Long but NO name/url for location.
     *
     * With name supplied, a combined map thumbnail/name box is displayed
     *
     * @param array|string $to   The recipient(s) to send to.
     * @param  float       $long The longitude of the location eg 54.31652
     * @param  float       $lat  The latitude if the location eg -6.833496
     * @param string       $name (Optional)  The custom name you would like to give this location.
     * @param string       $url  (Optional) A URL to attach to the location.
     */
    public function sendMessageLocation($to, $long, $lat, $name = null, $url = null)
    {
      $mediaHash              = array();
      $mediaHash['xmlns']     = "urn:xmpp:whatsapp:mms";
      $mediaHash['type']      = "location";
      $mediaHash['latitude']  = $lat;
      $mediaHash['longitude'] = $long;
      $mediaHash['name']      = $name;
      $mediaHash['url']       = $url;

      $mediaNode = new ProtocolNode("media", $mediaHash, null, null);

      if (is_array($to))
      {
        $this->sendBroadcast($to, $mediaNode);
      }
      else
      {
        $this->sendMessageNode($to, $mediaNode);
      }
    }



    /**
     * Send the 'paused composing message' status.
     *
     * @param string $to
     *   The recipient number or ID.
     */
    public function sendMessagePaused($to)
    {
      $comphash          = array();
      $comphash['xmlns'] = 'http://jabber.org/protocol/chatstates';
      $compose           = new ProtocolNode("paused", $comphash, null, "");
      $messageHash         = array();
      $messageHash["to"]   = $this->getJID($to);
      $messageHash["type"] = "chat";
      $messageHash["id"]   = $this->createMsgId("paused");
      $messageHash["t"]    = time();

      $messageNode = new ProtocolNode("message", $messageHash, array($compose), "");

      $this->sendNode($messageNode);
    }



    /**
     * Send a video to the user/group.
     *
     * @param  string $to
     *                               The recipient to send.
     * @param string  $filepath
     *                               The url/uri to the MP4/MOV video.
     * @param  bool  $storeURLmedia
     *                               Keep a copy of media file.
     *
     * @return bool
     */
    public function sendMessageVideo($to, $filepath, $storeURLmedia = false)
    {
      $allowedExtensions = array('3gp', 'mp4', 'mov', 'avi');
      $size              = 20 * 1024 * 1024; // Easy way to set maximum file size for this media type.

      return $this->sendCheckAndSendMedia($filepath, $size, $to, 'video', $allowedExtensions, $storeURLmedia);
    }



    /**
     * Send the next message.
     */
    public function sendNextMessage()
    {
      if (count($this->outQueue) > 0)
      {
        $msgnode = array_shift($this->outQueue);
        $msgnode->refreshTimes();
        $this->lastId = $msgnode->getAttribute('id');

        $this->sendNode($msgnode);
      }
      else
      {
        $this->lastId = false;
      }
    }



    /**
     * Send the offline status. User will show up as "Offline".
     */
    public function sendOfflineStatus()
    {
      $messageNode = new ProtocolNode("presence", array("type" => "inactive"), null, "");
      $this->sendNode($messageNode);
    }



    /**
     * Send a pong to the whatsapp server. I'm alive!
     *
     * @param string $msgid
     *   The id of the message.
     */
    public function sendPong($msgid)
    {
      $messageHash         = array();
      $messageHash["to"]   = static::WHATSAPP_SERVER;
      $messageHash["id"]   = $msgid;
      $messageHash["type"] = "result";
      $messageNode = new ProtocolNode("iq", $messageHash, null, "");

      $this->sendNode($messageNode);

      $this->eventManager()->fireSendPong(
        $this->phoneNumber,
        $msgid
      );
    }



    /**
     * Send available status for start chat
     */
    public function sendAvailableForChat()
    {
      $presence         = array();
      $presence['name'] = $this->name;
      $node             = new ProtocolNode("presence", $presence, null, "");

      $this->sendNode($node);
    }



    /**
     * Send presence status.
     *
     * @param string $type
     *   The presence status.
     */
    public function sendPresence($type = "active")
    {
      $presence         = array();
      $presence['type'] = $type;
      $node             = new ProtocolNode("presence", $presence, null, "");

      $this->sendNode($node);

      $this->eventManager()->fireSendPresence(
        $this->phoneNumber,
        $presence['type'],
        $this->name
      );
    }



    /**
     * Send presence subscription, automatically receive presence updates as long as the socket is open.
     *
     * @param string $to
     *   Phone number.
     */
    public function sendPresenceSubscription($to)
    {
      $node = new ProtocolNode("presence", array("type" => "subscribe", "to" => $this->getJID($to)), null, "");
      $this->sendNode($node);
    }



    /**
     * Set the picture for the group
     *
     * @param  string $gjid The groupID
     * @param  string $path The URL/URI of the image to use
     */
    public function sendSetGroupPicture($gjid, $path)
    {
      $this->sendSetPicture($gjid, $path);
    }



    /**
     * Set the list of numbers you wish to block receiving from.
     *
     * @param array $blockedJids Array of numbers to block messages from.
     */
    public function sendSetPrivacyBlockedList($blockedJids = array())
    {
      if (!is_array($blockedJids))
      {
        $blockedJids = array($blockedJids);
      }

      $items = array();

      foreach ($blockedJids as $index => $jid)
      {
        $item    = new ProtocolNode("item", array(
          "type"   => "jid",
          "value"  => $this->getJID($jid),
          "action" => "deny",
          "order"  => $index + 1 //WhatsApp stream crashes on zero index
        ), null, null);
        $items[] = $item;
      }

      $child  = new ProtocolNode("list", array("name" => "default"), $items, null);
      $child2 = new ProtocolNode("query", array("xmlns" => "jabber:iq:privacy"), array($child), null);
      $node   = new ProtocolNode("iq", array(
        "id"   => $this->createMsgId("setprivacy"),
        "type" => "set"
      ), array($child2), null);

      $this->sendNode($node);
    }



    /**
     * Set your profile picture
     *
     * @param  string $path URL/URI of image
     */
    public function sendSetProfilePicture($path)
    {
      $this->sendSetPicture($this->phoneNumber, $path);
    }



    /**
     * Set the recovery token for your account to allow you to
     * retrieve your password at a later stage.
     *
     * @param  string $token A user generated token.
     */
    public function sendSetRecoveryToken($token)
    {
      $child = new ProtocolNode("pin", array("xmlns" => "w:ch:p"), null, $token);
      $node  = new ProtocolNode("iq", array(
        "id"   => $this->createMsgId("settoken"),
        "type" => "set",
        "to"   => "s.whatsapp.net"
      ), array($child), null);

      $this->sendNode($node);
    }



    /**
     * Update the user status.
     *
     * @param string $txt
     *   The text of the message status to send.
     */
    public function sendStatusUpdate($txt)
    {
      $bodyNode       = new ProtocolNode("body", null, null, $txt);
      $serverNode     = new ProtocolNode("server", null, null, "");
      $xHash          = array();
      $xHash["xmlns"] = "jabber:x:event";
      $xNode          = new ProtocolNode("x", $xHash, array($serverNode), "");
      $messageHash         = array();
      $messageHash["to"]   = 's.us';
      $messageHash["type"] = "chat";
      $messageHash["id"]   = $this->createMsgId("sendstatus");
      $messageNode = new ProtocolNode("message", $messageHash, array($xNode, $bodyNode), "");

      $this->sendNode($messageNode);

      $this->eventManager()->fireSendStatusUpdate(
        $this->phoneNumber,
        $txt
      );

      //listen for response
      $this->waitForServer($messageHash["id"]);
    }



    /**
     * Send a vCard to the user/group.
     *
     * @param $to
     *   The recipient to send.
     * @param $name
     *   The contact name.
     * @param $vCard
     *   The contact vCard to send.
     */
    public function sendVcard($to, $name, $vCard)
    {
      $vCardAttribs         = array();
      $vCardAttribs['name'] = $name;
      $vCardNode            = new ProtocolNode("vcard", $vCardAttribs, null, $vCard);
      $mediaAttribs         = array();
      $mediaAttribs["type"] = "vcard";

      $mediaNode = new ProtocolNode("media", $mediaAttribs, array($vCardNode), "");

      $this->sendMessageNode($to, $mediaNode);
    }



    /**
     * Sets the bind of the new message.
     */
    public function setNewMessageBind($bind)
    {
      $this->newMsgBind = $bind;
    }



    /**
     * Upload file to WhatsApp servers.
     *
     * @param $file
     *   The uri of the file.
     *
     * @return string|bool
     *   Return the remote url or false on failure.
     */
    public function uploadFile($file)
    {
      $data['file'] = "@" . $file;

      $ch           = curl_init();
      curl_setopt($ch, CURLOPT_HEADER, 0);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
      curl_setopt($ch, CURLOPT_URL, static::WHATSAPP_UPLOAD_HOST);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      $response = curl_exec($ch);
      curl_close($ch);

      $xml = simplexml_load_string($response);
      $url = strip_tags($xml->dict->string[3]->asXML());

      if (!empty($url))
      {
        $this->eventManager()->fireUploadFile(
          $this->phoneNumber,
          basename($file),
          $url
        );

        return $url;
      }
      else
      {
        $this->eventManager()->fireUploadFileFailed(
          $this->phoneNumber,
          basename($file)
        );

        return false;
      }
    }



    /**
     * Wait for message delivery notification.
     */
    public function waitForMessageReceipt()
    {
      $received = false;

      do
      {
        $this->pollMessages();
        $msgs = $this->getMessages();

        foreach ($msgs as $m)
        {
          // Process inbound messages.
          if ($m->getTag() == "message")
          {
            if ($m->getChild('received') != null && $m->getAttribute('retry') != null)
            {
              $received = true;
            }
            elseif ($m->getChild('received') != null && $m->getAttribute('retry') != null)
            {
              throw new \Exception('There was a problem trying to send the message, please retry.');
            }
          }
        }

      } while (!$received);
    }



    /**
     * Wait for Whatsapp server to acknowledge *it* has received message.
     *
     * @param  string $id The id of the node sent that we are awaiting acknowledgement of.
     */
    public function waitForServer($id)
    {
      $time                   = time();
      $this->serverReceivedId = false;

      do
      {
        $this->pollMessages();
      } while ($this->serverReceivedId !== $id && time() - $time < 5);
    }



    /**
     * Authenticate with the Whatsapp Server.
     *
     * @return String
     *   Returns binary string
     */
    protected function authenticate()
    {
      $keys            = KeyStream::generateKeys(base64_decode($this->password), $this->challengeData);
      $this->inputKey  = new KeyStream($keys[2], $keys[3]);
      $this->outputKey = new KeyStream($keys[0], $keys[1]);
      //$phone           = $this->dissectPhone();
      $array           = "\0\0\0\0" . $this->phoneNumber . $this->challengeData; // . time() . static::WHATSAPP_USER_AGENT . " MccMnc/" . str_pad($phone["mcc"], 3, "0", STR_PAD_LEFT) . "001";
      $response        = $this->outputKey->encodeMessage($array, 0, 4, strlen($array) - 4);

      return $response;
    }



    /**
     * Add the authentication nodes.
     *
     * @return ProtocolNode
     *   Return itself.
     */
    protected function createAuthNode()
    {
      $authHash              = array();
      $authHash["xmlns"]     = "urn:ietf:params:xml:ns:xmpp-sasl";
      $authHash["mechanism"] = "WAUTH-2";
      $authHash["user"]      = $this->phoneNumber;
      $data                  = $this->createAuthBlob();
      $node                  = new ProtocolNode("auth", $authHash, null, $data);

      return $node;
    }



    /**
     * Create authentication blob object
     *
     * @return null|string
     */
    protected function createAuthBlob()
    {
      if ($this->challengeData)
      {
        $key             = Cryptography::waPbkdf2('sha1', base64_decode($this->password), $this->challengeData, 16, 20, true);
        $this->inputKey  = new KeyStream($key[2], $key[3]);
        $this->outputKey = new KeyStream($key[0], $key[1]);
        $this->reader->setKey($this->inputKey);
        //$this->writer->setKey($this->outputKey);
        $phone               = $this->dissectPhone();
        $array               = "\0\0\0\0" . $this->phoneNumber . $this->challengeData . time() . static::WHATSAPP_USER_AGENT . " MccMnc/" . str_pad($phone["mcc"], 3, "0", STR_PAD_LEFT) . "001";
        $this->challengeData = null;

        return $this->outputKey->encodeMessage($array, 0, strlen($array), false);
      }

      return null;
    }



    /**
     * Add the auth response to protocoltreenode.
     *
     * @return ProtocolNode
     *   Return itself.
     */
    protected function createAuthResponseNode()
    {
      $resp              = $this->authenticate();
      $respHash          = array();
      $respHash["xmlns"] = "urn:ietf:params:xml:ns:xmpp-sasl";
      $node              = new ProtocolNode("response", $respHash, null, $resp);

      return $node;
    }



    /**
     * Add stream features.
     *
     * @return ProtocolNode
     *   Return itself.
     */
    protected function createFeaturesNode()
    {
      $parent = new ProtocolNode("stream:features", null, null, null);

      return $parent;
    }



    /**
     * Create a unique msg id.
     *
     * @param  string $prefix
     *
     * @return string
     *   A message id string.
     */
    protected function createMsgId($prefix)
    {
      $msgid = "$prefix-" . time() . '-' . $this->messageCounter;
      $this->messageCounter++;

      return $msgid;
    }



    /**
     * Print a message to the debug console.
     *
     * @param string $debugMsg
     *   The debug message.
     */
    protected function debugPrint($debugMsg)
    {
      if ($this->debug)
      {
        echo $debugMsg;
      }
    }



    /**
     * Dissect country code from phone number.
     *
     * @return array
     *   An associative array with country code and phone number.
     *   - country: The detected country name.
     *   - cc: The detected country code (phone prefix).
     *   - phone: The phone number.
     *   - ISO3166: 2-Letter country code
     *   - ISO639: 2-Letter language code
     *   Return false if country code is not found.
     */
    protected function dissectPhone()
    {
      if (($handle = fopen(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'countries.csv', 'rb')) !== false)
      {
        while (($data = fgetcsv($handle, 1000)) !== false)
        {
          if (strpos($this->phoneNumber, $data[1]) === 0)
          {
            // Return the first appearance.
            fclose($handle);
            $mcc = explode("|", $data[2]);
            $mcc = $mcc[0];

            //hook:
            //fix country code for North America
            if (substr($data[1], 0, 1) == "1")
            {
              $data[1] = "1";
            }

            $phone = array(
              'country' => $data[0],
              'cc'      => $data[1],
              'phone'   => substr($this->phoneNumber, strlen($data[1]), strlen($this->phoneNumber)),
              'mcc'     => $mcc,
              'ISO3166' => @$data[3],
              'ISO639'  => @$data[4]
            );

            $this->eventManager()->fireDissectPhone(
              $this->phoneNumber,
              $phone['country'],
              $phone['cc'],
              $phone['phone'],
              $phone['mcc'],
              $phone['ISO3166'],
              $phone['ISO639']
            );

            return $phone;
          }
        }
        fclose($handle);
      }

      $this->eventManager()->fireDissectPhoneFailed(
        $this->phoneNumber
      );

      return false;
    }



    /**
     * Send the nodes to the Whatsapp server to log in.
     *
     */
    protected function doLogin()
    {
      $this->writer->resetKey();
      $this->reader->resetKey();

      $resource = static::WHATSAPP_DEVICE . '-' . static::WHATSAPP_VER . '-' . static::PORT;
      $data     = $this->writer->startStream(static::WHATSAPP_SERVER, $resource);
      $feat     = $this->createFeaturesNode();
      $auth     = $this->createAuthNode();

      $this->sendData($data);
      $this->sendNode($feat);
      $this->sendNode($auth);

      $this->pollMessages();
      $cnt = 0;

      do
      {
        $this->pollMessages();
        if ($this->challengeData != null)
        {
          $data = $this->createAuthResponseNode();
          $this->sendNode($data);
          $this->reader->setKey($this->inputKey);
          $this->writer->setKey($this->outputKey);
          $this->pollMessages();
        }
      } while ($this->challengeData == null && ($cnt++ < 100) && (strcmp($this->loginStatus, static::DISCONNECTED_STATUS) == 0));

      $this->eventManager()->fireLogin(
        $this->phoneNumber
      );

      $this->sendAvailableForChat();
    }



    /**
     * Create an identity string
     *
     * @param  string $identity A user string
     *
     * @return string Correctly formatted identity
     */
    protected function buildIdentity($identity)
    {
      return strtolower(urlencode(sha1($identity, true)));
    }



    /**
     * Check if is a valid identity
     *
     * @param string $identity
     *
     * @return bool
     */
    protected function checkIdentity($identity)
    {
      return (strlen(urldecode($identity)) == 20);
    }



    public function sendSync(array $numbers, $mode = "full", $context = "registration", $index = 0, $last = true)
    {
      $users = array();

      foreach ($numbers as $number)
      { // number must start with '+' if international contact
        $users[] = new ProtocolNode("user", null, null, (substr($number, 0, 1) != '+') ? ('+' . $number) : ($number));
      }

      $node = new ProtocolNode("iq", array(
        "to"    => $this->getJID($this->phoneNumber),
        "type"  => "get",
        "id"    => $this->createMsgId("sendsync_"),
        "xmlns" => "urn:xmpp:whatsapp:sync"
      ), array(
        new ProtocolNode("sync", array(
          "mode"    => $mode,
          "context" => $context,
          "sid"     => "" . ((time() + 11644477200) * 10000000),
          "index"   => "" . $index,
          "last"    => $last ? "true" : "false"
        ), $users, null)
      ), null);

      $this->sendNode($node);
    }



    /**
     * Process number/jid and turn it into a JID if necessary
     *
     * @param string $number
     *  Number to process
     *
     * @return string
     */
    protected function getJID($number)
    {
      if (!stristr($number, '@'))
      {
        //check if group message
        if (stristr($number, '-'))
        {
          //to group
          $number .= "@" . static::WHATSAPP_GROUP_SERVER;
        }
        else
        {
          //to normal user
          $number .= "@" . static::WHATSAPP_SERVER;
        }
      }

      return $number;
    }



    /**
     * Retrieves media file and info from either a URL or localpath
     *
     * @param $filepath
     * The URL or path to the mediafile you wish to send
     * @param $maxsizebytes
     * The maximum size in bytes the media file can be. Default 1MB
     *
     * @return bool  false if file information can not be obtained.
     */
    protected function getMediaFile($filepath, $maxsizebytes = 1048576)
    {
      if (filter_var($filepath, FILTER_VALIDATE_URL) !== false)
      {
        $this->mediaFileInfo        = array();
        $this->mediaFileInfo['url'] = $filepath;
        //File is a URL. Create a curl connection but DON'T download the body content
        //because we want to see if file is too big.
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "$filepath");
        curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.11) Gecko/20071127 Firefox/2.0.0.11");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        if (curl_exec($curl) === false)
        {
          return false;
        }
        //While we're here, get mime type and filesize and extension
        $info                                 = curl_getinfo($curl);
        $this->mediaFileInfo['filesize']      = $info['download_content_length'];
        $this->mediaFileInfo['filemimetype']  = $info['content_type'];
        $this->mediaFileInfo['fileextension'] = pathinfo(parse_url($this->mediaFileInfo['url'], PHP_URL_PATH), PATHINFO_EXTENSION);
        //Only download file if it's not too big
        //TODO check what max file size whatsapp server accepts.
        if ($this->mediaFileInfo['filesize'] < $maxsizebytes)
        {
          //Create temp file in media folder. Media folder must be writable!
          $this->mediaFileInfo['filepath'] = tempnam(getcwd() . '/' . static::MEDIA_FOLDER, 'WHA');
          $fp                              = fopen($this->mediaFileInfo['filepath'], 'w');
          if ($fp)
          {
            curl_setopt($curl, CURLOPT_NOBODY, false);
            curl_setopt($curl, CURLOPT_BUFFERSIZE, 1024);
            curl_setopt($curl, CURLOPT_FILE, $fp);
            curl_exec($curl);
            fclose($fp);
          }
          else
          {
            unlink($this->mediaFileInfo['filepath']);
            curl_close($curl);

            return false;
          }
          //Success
          curl_close($curl);

          return true;
        }
        else
        {
          //File too big. Don't Download.
          curl_close($curl);

          return false;
        }
      }
      else if (file_exists($filepath))
      {
        //Local file
        $this->mediaFileInfo['filesize'] = filesize($filepath);
        if ($this->mediaFileInfo['filesize'] < $maxsizebytes)
        {
          $this->mediaFileInfo['filepath']      = $filepath;
          $this->mediaFileInfo['fileextension'] = pathinfo($filepath, PATHINFO_EXTENSION);
          //TODO
          //Get Mime type using finfo.
//                $finfo = new finfo_open(FILEINFO_MIME_TYPE);
//                $this->_mediafileinfo['filemimetype'] = finfo_file($finfo, $filepath);
//                finfo_close($finfo);
          //mime_content_type deprecated
          $this->mediaFileInfo['filemimetype'] = mime_content_type($filepath);

          return true;
        }
        else
        {
          //File too big
          return false;
        }
      }

      //Couldn't tell what file was, local or URL.
      return false;
    }



    /**
     * Get a decoded JSON response from Whatsapp server
     *
     * @param  string $host  The host URL
     * @param  array  $query A associative array of keys and values to send to server.
     *
     * @return object   NULL is returned if the json cannot be decoded or if the encoded data is deeper than the recursion limit
     */
    protected function getResponse($host, $query)
    {
      // Build the url.
      $url = $host . '?';
      foreach ($query as $key => $value)
      {
        $url .= $key . '=' . $value . '&';
      }
      $url = rtrim($url, '&');
      // Open connection.
      $ch = curl_init();
      // Configure the connection.
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HEADER, 0);
      curl_setopt($ch, CURLOPT_USERAGENT, static::WHATSAPP_USER_AGENT);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: text/json'));
      // This makes CURL accept any peer!
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      // Get the response.
      $response = curl_exec($ch);
      // Close the connection.
      curl_close($ch);

      return json_decode($response);
    }



    /**
     * Process the challenge.
     *
     *
     * @param ProtocolNode $node
     *   The node that contains the challenge.
     */
    protected function processChallenge($node)
    {
      $this->challengeData = $node->getData();
    }



    /**
     * Process inbound data.
     *
     * @param string $data
     *   The data to process.
     */
    protected function processInboundData($data)
    {
      $node = $this->reader->nextTree($data);

      if ($node != null)
      {
        $this->processInboundDataNode($node);
      }
    }



    /**
     * Will process the data from the server after it's been decrypted and parsed.
     *
     * This also provides a convenient method to use to unit test the event framework.
     *
     */
    protected function processInboundDataNode(ProtocolNode $node)
    {
      $this->debugPrint($node->nodeString("rx  ") . "\n");
      if ($node->getTag() == "challenge")
      {
        $this->processChallenge($node);
      }
      elseif ($node->getTag() == "success")
      {
        $this->loginStatus = static::CONNECTED_STATUS;
        $challengeData     = $node->getData();
        file_put_contents("nextChallenge.dat", $challengeData);
        $this->writer->setKey($this->outputKey);
      }
      elseif ($node->getTag() == "failure")
      {
        $this->eventManager()->fireLoginFailed(
          $this->phoneNumber,
          $node->getChild(0)->getTag()
        );
      }
      if ($node->getTag() == "message")
      {
        array_push($this->messageQueue, $node);
        //do not send received confirmation if sender is yourself
        if (strpos($node->getAttribute('from'), $this->phoneNumber . '@' . static::WHATSAPP_SERVER) === false
          &&
          (
            $node->hasChild("request")
            ||
            $node->hasChild("received")
          )
        )
        {
          $this->sendMessageReceived($node);
        }
        if ($node->hasChild('x') && $this->lastId == $node->getAttribute('id'))
        {
          $this->sendNextMessage();
        }
        if ($this->newMsgBind && $node->getChild('body'))
        {
          $this->newMsgBind->process($node);
        }
        if ($node->getChild('notify') != null && $node->getChild(0)->getAttribute('name') != '' && $node->getChild('body') != null)
        {
          $author = $node->getAttribute("author");
          if ($author == "")
          {
            //private chat message
            $this->eventManager()->fireGetMessage(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('type'),
              $node->getAttribute('t'),
              $node->getChild("notify")->getAttribute('name'),
              $node->getChild("body")->getData()
            );
          }
          else
          {
            //group chat message
            $this->eventManager()->fireGetGroupMessage(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $author,
              $node->getAttribute('id'),
              $node->getAttribute('type'),
              $node->getAttribute('t'),
              $node->getChild("notify")->getAttribute('name'),
              $node->getChild("body")->getData()
            );
          }
          $this->sendMessageReceived($node);
        }
        if ($node->hasChild('notification') && $node->getChild('notification')->getAttribute('type') == 'picture')
        {
          if ($node->getChild('notification')->hasChild('set'))
          {
            $this->eventManager()->fireProfilePictureChanged(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('t')
            );
          }
          else if ($node->getChild('notification')->hasChild('delete'))
          {
            $this->eventManager()->fireProfilePictureDeleted(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('t')
            );
          }
        }
        if ($node->getChild('notify') != null && $node->getChild(0)->getAttribute('name') != null && $node->getChild('media') != null)
        {
          if ($node->getChild(2)->getAttribute('type') == 'image')
          {
            $this->eventManager()->fireGetImage(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('type'),
              $node->getAttribute('t'),
              $node->getChild(0)->getAttribute('name'),
              $node->getChild(2)->getAttribute('size'),
              $node->getChild(2)->getAttribute('url'),
              $node->getChild(2)->getAttribute('file'),
              $node->getChild(2)->getAttribute('mimetype'),
              $node->getChild(2)->getAttribute('filehash'),
              $node->getChild(2)->getAttribute('width'),
              $node->getChild(2)->getAttribute('height'),
              $node->getChild(2)->getData()
            );
          }
          elseif ($node->getChild(2)->getAttribute('type') == 'video')
          {
            $this->eventManager()->fireGetVideo(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('type'),
              $node->getAttribute('t'),
              $node->getChild(0)->getAttribute('name'),
              $node->getChild(2)->getAttribute('url'),
              $node->getChild(2)->getAttribute('file'),
              $node->getChild(2)->getAttribute('size'),
              $node->getChild(2)->getAttribute('mimetype'),
              $node->getChild(2)->getAttribute('filehash'),
              $node->getChild(2)->getAttribute('duration'),
              $node->getChild(2)->getAttribute('vcodec'),
              $node->getChild(2)->getAttribute('acodec'),
              $node->getChild(2)->getData()
            );
          }
          elseif ($node->getChild(2)->getAttribute('type') == 'audio')
          {
            $this->eventManager()->fireGetAudio(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('type'),
              $node->getAttribute('t'),
              $node->getChild(0)->getAttribute('name'),
              $node->getChild(2)->getAttribute('size'),
              $node->getChild(2)->getAttribute('url'),
              $node->getChild(2)->getAttribute('file'),
              $node->getChild(2)->getAttribute('mimetype'),
              $node->getChild(2)->getAttribute('filehash'),
              $node->getChild(2)->getAttribute('duration'),
              $node->getChild(2)->getAttribute('acodec')
            );
          }
          elseif ($node->getChild(2)->getAttribute('type') == 'vcard')
          {
            $this->eventManager()->fireGetvCard(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('type'),
              $node->getAttribute('t'),
              $node->getChild(0)->getAttribute('name'),
              $node->getChild(2)->getChild(0)->getAttribute('name'),
              $node->getChild(2)->getChild(0)->getData()
            );
          }
          elseif ($node->getChild(2)->getAttribute('type') == 'location')
          {
            $url  = $node->getChild(2)->getAttribute('url');
            $name = $node->getChild(2)->getAttribute('name');
            $this->eventManager()->fireGetLocation(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getAttribute('type'),
              $node->getAttribute('t'),
              $node->getChild(0)->getAttribute('name'),
              $name,
              $node->getChild(2)->getAttribute('longitude'),
              $node->getChild(2)->getAttribute('latitude'),
              $url,
              $node->getChild(2)->getData()
            );
          }
          $this->sendMessageReceived($node);
        }
        if ($node->getChild('x') != null)
        {
          $this->serverReceivedId = $node->getAttribute('id');
          $this->eventManager()->fireMessageReceivedServer(
            $this->phoneNumber,
            $node->getAttribute('from'),
            $node->getAttribute('id'),
            $node->getAttribute('type'),
            $node->getAttribute('t')
          );
        }
        if ($node->getChild('received') != null)
        {
          $this->eventManager()->fireMessageReceivedClient(
            $this->phoneNumber,
            $node->getAttribute('from'),
            $node->getAttribute('id'),
            $node->getAttribute('type'),
            $node->getAttribute('t')
          );
        }
        if ($node->getAttribute('type') == "subject")
        {
          print_r($node);
          $reset_from   = explode('@', $node->getAttribute('from'));
          $reset_author = explode('@', $node->getAttribute('author'));
          $this->eventManager()->fireGetGroupsSubject(
            $this->phoneNumber,
            reset($reset_from),
            $node->getAttribute('t'),
            reset($reset_author),
            reset($reset_author),
            $node->getChild(0)->getAttribute('name'),
            $node->getChild(2)->getData()
          );
        }
      }
      if ($node->getTag() == "presence" && $node->getAttribute("status") == "dirty")
      {
        //clear dirty
        $categories = array();
        if (count($node->getChildren()) > 0)
          foreach ($node->getChildren() as $child)
          {
            if ($child->getTag() == "category")
            {
              $categories[] = $child->getAttribute("name");
            }
          }
        $this->sendClearDirty($categories);
      }
      if (strcmp($node->getTag(), "presence") == 0
        && strncmp($node->getAttribute('from'), $this->phoneNumber, strlen($this->phoneNumber)) != 0
        && strpos($node->getAttribute('from'), "-") == false
        && $node->getAttribute('type') != null
      )
      {
        $this->eventManager()->firePresence(
          $this->phoneNumber,
          $node->getAttribute('from'),
          $node->getAttribute('type')
        );
      }
      if ($node->getTag() == "presence"
        && strncmp($node->getAttribute('from'), $this->phoneNumber, strlen($this->phoneNumber)) != 0
        && strpos($node->getAttribute('from'), "-") !== false
        && $node->getAttribute('type') != null
      )
      {
        $groupId = self::parseJID($node->getAttribute('from'));
        if ($node->getAttribute('add') != null)
        {
          $this->eventManager()->fireGroupsParticipantsAdd(
            $this->phoneNumber,
            $groupId,
            self::parseJID($node->getAttribute('add'))
          );
        }
        elseif ($node->getAttribute('remove') != null)
        {
          $this->eventManager()->fireGroupsParticipantsRemove(
            $this->phoneNumber,
            $groupId,
            self::parseJID($node->getAttribute('remove')),
            self::parseJID($node->getAttribute('author'))
          );
        }
      }
      if ($node->getTag() == "iq"
        && $node->getAttribute('type') == "get"
        && $node->getChild(0)->getTag() == "ping"
      )
      {
        $this->eventManager()->firePing(
          $this->phoneNumber,
          $node->getAttribute('id')
        );
        $this->sendPong($node->getAttribute('id'));
      }
      if ($node->getTag() == "iq"
        && $node->getChild("sync") != null
      )
      {
        //sync result
        $sync        = $node->getChild('sync');
        $existing    = $sync->getChild("in");
        $nonexisting = $sync->getChild("out");
        //process existing first
        $existingUsers = array();
        if (!empty($existing))
        {
          foreach ($existing->getChildren() as $child)
          {
            $existingUsers[$child->getData()] = $child->getAttribute("jid");
          }
        }
        //now process failed numbers
        $failedNumbers = array();
        if (!empty($nonexisting))
        {
          foreach ($nonexisting->getChildren() as $child)
          {
            $failedNumbers[] = str_replace('+', '', $child->getData());
          }
        }
        $index = $sync->getAttribute("index");
        $this->eventManager()->fireGetSyncResult(
          $index,
          $sync->getAttribute("sync"),
          $existingUsers,
          $failedNumbers
        );
      }
      if ($node->getTag() == "receipt")
      {
        $this->eventManager()->fireGetReceipt(
          $node->getAttribute('from'),
          $node->getAttribute('id'),
          $node->getAttribute('offline'),
          $node->getAttribute('retry')
        );
      }
      if ($node->getTag() == "iq"
        && $node->getAttribute('type') == "result"
      )
      {
        $this->serverReceivedId = $node->getAttribute('id');
        if ($node->getChild(0) != null &&
          $node->getChild(0)->getTag() == "query"
        )
        {
          if ($node->getChild(0)->getAttribute('xmlns') == 'jabber:iq:privacy')
          {
            // ToDo: We need to get explicitly list out the children as arguments
            //       here.
            $this->eventManager()->fireGetPrivacyBlockedList(
              $this->phoneNumber,
              $node->getChild(0)->getChild(0)->getChildren()
            );
          }
          if ($node->getChild(0)->getAttribute('xmlns') == 'jabber:iq:last')
          {
            $this->eventManager()->fireGetRequestLastSeen(
              $this->phoneNumber,
              $node->getAttribute('from'),
              $node->getAttribute('id'),
              $node->getChild(0)->getAttribute('seconds')
            );
          }
          array_push($this->messageQueue, $node);
        }
        if ($node->getChild(0) != null && $node->getChild(0)->getTag() == "props")
        {
          //server properties
          $props = array();
          foreach ($node->getChild(0)->getChildren() as $child)
          {
            $props[$child->getAttribute("name")] = $child->getAttribute("value");
          }
          $this->eventManager()->fireGetServerProperties(
            $this->phoneNumber,
            $node->getChild(0)->getAttribute("version"),
            $props
          );
        }
        if ($node->getChild(0) != null && $node->getChild(0)->getTag() == "picture")
        {
          $this->eventManager()->fireGetProfilePicture(
            $this->phoneNumber,
            $node->getAttribute("from"),
            $node->getChild("picture")->getAttribute("type"),
            $node->getChild("picture")->getData()
          );
        }
        if ($node->getChild(0) != null && $node->getChild(0)->getTag() == "media")
        {
          $this->processUploadResponse($node);
        }
        if ($node->getChild(0) != null && $node->getChild(0)->getTag() == "duplicate")
        {
          $this->processUploadResponse($node);
        }
        if ($node->nodeIdContains("group"))
        {
          //There are multiple types of Group reponses. Also a valid group response can have NO children.
          //Events fired depend on text in the ID field.
          $groupList = array();
          if ($node->getChild(0) != null)
          {
            foreach ($node->getChildren() as $child)
            {
              $groupList[] = $child->getAttributes();
            }
          }
          if ($node->nodeIdContains('creategroup'))
          {
            $this->groupId = $node->getChild(0)->getAttribute('id');
            $this->eventManager()->fireGroupsChatCreate(
              $this->phoneNumber,
              $this->groupId
            );
          }
          if ($node->nodeIdContains('endgroup'))
          {
            $this->groupId = $node->getChild(0)->getChild(0)->getAttribute('id');
            $this->eventManager()->fireGroupsChatEnd(
              $this->phoneNumber,
              $this->groupId
            );
          }
          if ($node->nodeIdContains('getgroups'))
          {
            $this->eventManager()->fireGetGroups(
              $this->phoneNumber,
              $groupList
            );
          }
          if ($node->nodeIdContains('getgroupinfo'))
          {
            $this->eventManager()->fireGetGroupsInfo(
              $this->phoneNumber,
              $groupList
            );
          }
          if ($node->nodeIdContains('getgroupparticipants'))
          {
            $groupId = self::parseJID($node->getAttribute('from'));
            $this->eventManager()->fireGetGroupParticipants(
              $this->phoneNumber,
              $groupId,
              $groupList
            );
          }
        }
      }
      if ($node->getTag() == "iq" && $node->getAttribute('type') == "error")
      {
        $this->serverReceivedId = $node->getAttribute('id');
        $this->eventManager()->fireGetError(
          $this->phoneNumber,
          $node->getChild(0)
        );
      }
      $children = $node->getChild(0);
      if ($node->getTag() == "stream:error" && empty($children) == false && $node->getChild(0)->getTag() == "system-shutdown")
      {
        throw new \Exception('Error system-shutdown');
      }
      if ($node->getTag() == "notification")
      {
        //$name = $node->getAttribute("notify");
        $type = $node->getAttribute("type");
        switch ($type)
        {
          case "status":
            $this->eventManager()->fireGetStatus(
              $this->phoneNumber, //my number
              $node->getAttribute("from"), //sender
              $node->getChild(0)->getTag(), //type (set/remove)
              $node->getAttribute("id"), //message id
              $node->getAttribute("t"), //time
              $node->getChild(0)->getData()); //status message
            break;
          case "picture":
            //TODO
            break;
          default:
            throw new \Exception("Method $type not implemented");
        }
        $this->sendNotificationAck($node);
      }
      if ($node->getTag() == "ib")
      {
        foreach ($node->getChildren() as $child)
        {
          switch ($child->getTag())
          {
            case "dirty":
              $this->sendClearDirty(array($child->getAttribute("type")));
              break;
            case "offline":
              break;
            default:
              throw new \Exception("ib handler for " . $child->getTag() . " not implemented");
          }
        }
      }
      if ($node->getTag() == "ack")
      {
        ////on get ack
      }
    }



    /**
     * Send ack notification
     *
     * @param $node ProtocolNode
     */
    protected function sendNotificationAck($node)
    {
      $from        = $node->getAttribute("from");
      $to          = $node->getAttribute("to");
      $participant = $node->getAttribute("participant");
      $id          = $node->getAttribute("id");
      $type        = $node->getAttribute("type");

      $attributes = array();

      if ($to)
      {
        $attributes["from"] = $to;
      }
      if ($participant)
      {
        $attributes["participant"] = $participant;
      }

      $attributes["to"]    = $from;
      $attributes["class"] = "notification";
      $attributes["id"]    = $id;
      $attributes["type"]  = $type;
      $ack                 = new ProtocolNode("ack", $attributes, null, null);

      $this->sendNode($ack);
    }



    /**
     * Process and save media image
     *
     * @param ProtocolNode $node
     * ProtocolNode containing media
     */
    protected function processMediaImage($node)
    {
      $media = $node->getChild("media");
      if ($media != null)
      {
        $filename = $media->getAttribute("file");
        $url      = $media->getAttribute("url");
        //save thumbnail
        $data = $media->getData();
        $fp   = @fopen(static::MEDIA_FOLDER . "/thumb_" . $filename, "w");
        if ($fp)
        {
          fwrite($fp, $data);
          fclose($fp);
        }
        //download and save original
        $data = file_get_contents($url);
        $fp   = @fopen(static::MEDIA_FOLDER . "/" . $filename, "w");
        if ($fp)
        {
          fwrite($fp, $data);
          fclose($fp);
        }
      }
    }



    /**
     * Processes received picture node
     *
     * @param ProtocolNode $node
     *  ProtocolNode containing the picture
     */
    protected function processProfilePicture($node)
    {
      $pictureNode = $node->getChild("picture");
      if ($pictureNode != null)
      {
        $type = $pictureNode->getAttribute("type");
        $data = $pictureNode->getData();
        if ($type == "preview")
        {
          $filename = static::PICTURES_FOLDER . "/preview_" . $node->getAttribute("from") . ".jpg";
        }
        else
        {
          $filename = static::PICTURES_FOLDER . "/" . $node->getAttribute("from") . ".jpg";
        }
        $fp = @fopen($filename, "w");
        if ($fp)
        {
          fwrite($fp, $data);
          fclose($fp);
        }
      }
    }



    /**
     * If the media file was originally from a URL, this function either deletes it
     * or renames it depending on the user option.
     *
     * @param bool $storeURLmedia Save or delete the media file from local server
     */
    protected function processTempMediaFile($storeURLmedia)
    {
      if (isset($this->mediaFileInfo['url']))
      {
        if ($storeURLmedia)
        {
          if (is_file($this->mediaFileInfo['filepath']))
          {
            rename($this->mediaFileInfo['filepath'], $this->mediaFileInfo['filepath'] . $this->mediaFileInfo['fileextension']);
          }
        }
        else
        {
          if (is_file($this->mediaFileInfo['filepath']))
          {
            unlink($this->mediaFileInfo['filepath']);
          }
        }
      }
    }



    /**
     * Process media upload response
     *
     * @param ProtocolNode $node
     *  Message node
     *
     * @return bool
     */
    protected function processUploadResponse($node)
    {
      $id          = $node->getAttribute("id");
      $messageNode = @$this->mediaQueue[$id];
      if ($messageNode == null)
      {
        //message not found, can't send!
        $this->eventManager()->fireMediaUploadFailed(
          $this->phoneNumber,
          $id,
          $node,
          $messageNode,
          "Message node not found in queue"
        );

        return false;
      }
      $duplicate = $node->getChild("duplicate");
      if ($duplicate != null)
      {
        //file already on whatsapp servers
        $url      = $duplicate->getAttribute("url");
        $filesize = $duplicate->getAttribute("size");
//            $mimetype = $duplicate->getAttribute("mimetype");
//            $filehash = $duplicate->getAttribute("filehash");
        $filetype = $duplicate->getAttribute("type");
//            $width = $duplicate->getAttribute("width");
//            $height = $duplicate->getAttribute("height");
        $exploded = explode("/", $url);
        $filename = array_pop($exploded);
      }
      else
      {
        //upload new file
        $json = MediaUploader::pushFile($node, $messageNode, $this->mediaFileInfo, $this->phoneNumber);
        if (!$json)
        {
          //failed upload
          $this->eventManager()->fireMediaUploadFailed(
            $this->phoneNumber,
            $id,
            $node,
            $messageNode,
            "Failed to push file to server"
          );

          return false;
        }
        $url      = $json->url;
        $filesize = $json->size;
//            $mimetype = $json->mimetype;
//            $filehash = $json->filehash;
        $filetype = $json->type;
//            $width = $json->width;
//            $height = $json->height;
        $filename = $json->name;
      }
      $mediaAttribs          = array();
      $mediaAttribs["xmlns"] = "urn:xmpp:whatsapp:mms";
      $mediaAttribs["type"]  = $filetype;
      $mediaAttribs["url"]   = $url;
      $mediaAttribs["file"]  = $filename;
      $mediaAttribs["size"]  = $filesize;
      $filepath = $this->mediaQueue[$id]['filePath'];
      $to       = $this->mediaQueue[$id]['to'];
      switch ($filetype)
      {
        case "image":
          $icon = Media::createIcon($filepath);
          break;
        case "video":
          $icon = Media::createVideoIcon($filepath);
          break;
        default:
          $icon = '';
          break;
      }
      $mediaNode = new ProtocolNode("media", $mediaAttribs, null, $icon);
      if (is_array($to))
      {
        $this->sendBroadcast($to, $mediaNode);
      }
      else
      {
        $this->sendMessageNode($to, $mediaNode);
      }
      $this->eventManager()->fireMediaMessageSent(
        $this->phoneNumber,
        $to,
        $id,
        $filetype,
        $url,
        $filename,
        $filesize,
        $icon
      );

      return true;
    }



    /**
     * Read 1024 bytes from the whatsapp server.
     */
    protected function readStanza()
    {
      $buff = '';

      if ($this->socket != null)
      {
        $header = @fread($this->socket, 3); //read stanza header

        if (strlen($header) == 0)
        {
          //no data received
          return false;
        }

        if (strlen($header) != 3)
        {
          throw new \Exception("Failed to read stanza header");
        }

        $treeLength = 0;
        $treeLength = ord($header[1]) << 8;
        +$treeLength |= ord($header[2]) << 0;

        $buff = @fread($this->socket, $treeLength);

        if (strlen($buff) != $treeLength)
        {
          throw new \Exception("Tree length did not match received length");
        }
        elseif (@feof($this->socket))
        {
          $error = "socket EOF, closing socket...";
          fclose($this->socket);
          $this->socket = null;

          $this->eventManager()->fireClose(
            $this->phoneNumber,
            $error
          );
        }

        $buff = $header . $buff;
      }
      else
      {
        throw new \Exception("Socket closed");
      }

      return $buff;
    }



    /**
     * Checks that the media file to send is of allowable filetype and within size limits.
     *
     * @param string $filepath          The URL/URI to the media file
     * @param int    $maxSize           Maximim filesize allowed for media type
     * @param string $to                Recipient ID/number
     * @param string $type              media filetype. 'audio', 'video', 'image'
     * @param array  $allowedExtensions An array of allowable file types for the media file
     * @param bool   $storeURLmedia     Keep a copy of the media file
     *
     * @return bool
     */
    protected function sendCheckAndSendMedia($filepath, $maxSize, $to, $type, $allowedExtensions, $storeURLmedia)
    {
      if ($this->getMediaFile($filepath, $maxSize) == true)
      {
        if (in_array($this->mediaFileInfo['fileextension'], $allowedExtensions))
        {
          $b64hash = base64_encode(hash_file("sha256", $this->mediaFileInfo['filepath'], true));
          //request upload
          $this->sendRequestFileUpload($b64hash, $type, $this->mediaFileInfo['filesize'], $this->mediaFileInfo['filepath'], $to);
          $this->processTempMediaFile($storeURLmedia);

          return true;
        }
        else
        {
          //Not allowed file type.
          $this->processTempMediaFile($storeURLmedia);

          return false;
        }
      }
      else
      {
        //Didn't get media file details.
        return false;
      }
    }



    /**
     * Send a broadcast
     *
     * @param  array  $targets Array of numbers to send to
     * @param  ProtocolNode $node
     */
    protected function sendBroadcast($targets, $node)
    {
      if (!is_array($targets))
      {
        $targets = array($targets);
      }

      $serverNode     = new ProtocolNode("server", null, null, "");
      $xHash          = array();
      $xHash["xmlns"] = "jabber:x:event";
      $xNode          = new ProtocolNode("x", $xHash, array($serverNode), "");
      $toNodes = array();

      foreach ($targets as $target)
      {
        $jid       = $this->getJID($target);
        $hash      = array("jid" => $jid);
        $toNode    = new ProtocolNode("to", $hash, null, null);
        $toNodes[] = $toNode;
      }

      $broadcastNode = new ProtocolNode("broadcast", null, $toNodes, null);
      $messageHash         = array();
      $messageHash["to"]   = "broadcast";
      $messageHash["type"] = "chat";
      $messageHash["id"]   = $this->createMsgId("broadcast");
      $messageNode = new ProtocolNode("message", $messageHash, array($broadcastNode, $xNode, $node), null);

      if (!$this->lastId)
      {
        $this->lastId = $messageHash["id"];
        $this->sendNode($messageNode);
        //listen for response
        $this->waitForServer($messageHash["id"]);
      }
      else
      {
        $this->outQueue[] = $messageNode;
      }

      $this->eventManager()->fireSendMessage(
        $this->phoneNumber,
        $targets,
        $messageHash["id"],
        $node
      );
    }



    /**
     * Send data to the whatsapp server.
     *
     * @param string $data
     */
    protected function sendData($data)
    {
      if ($this->socket != null)
      {
        fwrite($this->socket, $data, strlen($data));
      }
    }



    /**
     * Send the getGroupList request to Whatsapp
     *
     * @param string $type Type of list of groups to retrieve. "owning" or "participating"
     */
    protected function sendGetGroupsFiltered($type)
    {
      $msgID = $this->createMsgId("getgroups");
      $child = new ProtocolNode("list", array(
        "xmlns" => "w:g",
        "type"  => $type
      ), null, null);
      $node  = new ProtocolNode("iq", array(
        "id"   => $msgID,
        "type" => "get",
        "to"   => "g.us"
      ), array($child), null);

      $this->sendNode($node);
      $this->waitForServer($msgID);
    }



    /**
     * Change participants of a group.
     *
     * @param string $groupId
     *   The group ID.
     * @param array  $participants
     *   An array with the participants.
     * @param string $tag
     *   The tag action. 'add' or 'remove'
     */
    protected function sendGroupsChangeParticipants($groupId, $participants, $tag)
    {
      $Participants = array();

      foreach ($participants as $participant)
      {
        $Participants[] = new ProtocolNode("participant", array("jid" => $this->getJID($participant)), null, "");
      }

      $childHash          = array();
      $childHash["xmlns"] = "w:g";
      $child              = new ProtocolNode($tag, $childHash, $Participants, "");
      $setHash         = array();
      $setHash["id"]   = $this->createMsgId("participants");
      $setHash["type"] = "set";
      $setHash["to"]   = $this->getJID($groupId);
      $node = new ProtocolNode("iq", $setHash, array($child), "");

      $this->sendNode($node);
      $this->waitForServer($setHash["id"]);
    }



    /**
     * Send node to the servers.
     *
     * @param string $to
     *               The recipient to send.
     * @param ProtocolNode $node
     *               The node that contains the message.
     * @param null $id
     *
     * @return null|string
     */
    protected function sendMessageNode($to, $node, $id = null)
    {
      $serverNode       = new ProtocolNode("server", null, null, "");
      $xHash            = array();
      $xHash["xmlns"]   = "jabber:x:event";
      $xNode            = new ProtocolNode("x", $xHash, array($serverNode), "");
      $notify           = array();
      $notify['xmlns']  = 'urn:xmpp:whatsapp';
      $notify['name']   = $this->name;
      $notnode          = new ProtocolNode("notify", $notify, null, "");
      $request          = array();
      $request['xmlns'] = "urn:xmpp:receipts";
      $reqnode          = new ProtocolNode("request", $request, null, "");
      $messageHash       = array();
      $messageHash["to"] = $this->getJID($to);

      if ($node->getTag() == "body")
      {
        $messageHash["type"] = "text";
      }
      else
      {
        $messageHash["type"] = "media";
      }

      $messageHash["id"] = ($id == null ? $this->createMsgId("message") : $id);
      $messageHash["t"]  = time();
      $messageNode = new ProtocolNode("message", $messageHash, array($xNode, $notnode, $reqnode, $node), "");

      if (!$this->lastId)
      {
        $this->lastId = $messageHash["id"];
        $this->sendNode($messageNode);
        //listen for response
        $this->waitForServer($messageHash["id"]);
      }
      else
      {
        $this->outQueue[] = $messageNode;
      }

      $this->eventManager()->fireSendMessage(
        $this->phoneNumber,
        $this->getJID($to),
        $messageHash["id"],
        $node
      );

      return $messageHash["id"];
    }



    /**
     * Tell the server we received the message.
     *
     * @param ProtocolNode $msg
     *   The ProtocolTreeNode that contains the message.
     */
    protected function sendMessageReceived($msg)
    {
      $requestNode  = $msg->getChild("request");
      $receivedNode = $msg->getChild("received");

      if ($requestNode != null || $receivedNode != null)
      {
        $receivedHash          = array();
        $receivedHash["xmlns"] = "urn:xmpp:receipts";
        $response = "received";

        if ($receivedNode != null)
        {
          $response = "ack";
        }

        $receivedNode = new ProtocolNode($response, $receivedHash, null, "");
        $messageHash         = array();
        $messageHash["to"]   = $msg->getAttribute("from");
        $messageHash["type"] = "chat";
        $messageHash["id"]   = $msg->getAttribute("id");
        $messageHash["t"]    = time();
        $messageNode         = new ProtocolNode("message", $messageHash, array($receivedNode), "");
        $this->sendNode($messageNode);

        $this->eventManager()->fireSendMessageReceived(
          $this->phoneNumber,
          $messageHash["t"],
          $msg->getAttribute("from")
        );
      }
    }



    /**
     * Send node to the WhatsApp server.
     *
     * @param ProtocolNode $node
     *
     * @param bool $encrypt
     */
    protected function sendNode($node, $encrypt = true)
    {
      $this->debugPrint($node->nodeString("tx  ") . "\n");
      $this->sendData($this->writer->write($node, $encrypt));
    }



    /**
     * Send request to upload file
     *
     * @param        $b64hash
     *  Base64 hash of file
     * @param string $type
     *  File type
     * @param        $size
     *  File size
     * @param string $filepath
     *  Path to image file
     * @param string $to
     *  Recipient
     */
    protected function sendRequestFileUpload($b64hash, $type, $size, $filepath, $to)
    {
      $hash          = array();
      $hash["xmlns"] = "w:m";
      $hash["hash"]  = $b64hash;
      $hash["type"]  = $type;
      $hash["size"]  = $size;
      $mediaNode     = new ProtocolNode("media", $hash, null, null);
      $hash         = array();
      $id           = $this->createMsgId("upload");
      $hash["id"]   = $id;
      $hash["to"]   = static::WHATSAPP_SERVER;
      $hash["type"] = "set";
      $node         = new ProtocolNode("iq", $hash, array($mediaNode), null);

      if (!is_array($to))
      {
        $to = $this->getJID($to);
      }

      //add to queue
      $this->mediaQueue[$id] = array("messageNode" => $node, "filePath" => $filepath, "to" => $to);
      $this->sendNode($node);
      $this->waitForServer($hash["id"]);
    }



    /**
     * Set your profile picture
     *
     * @param string $jid
     * @param string $filepath
     *  URL or localpath to image file
     */
    protected function sendSetPicture($jid, $filepath)
    {
      Media::preprocessProfilePicture($filepath);

      $fp = @fopen($filepath, "r");

      if ($fp)
      {
        $data = fread($fp, filesize($filepath));

        if ($data)
        {
          //this is where the fun starts
          $hash          = array();
          $hash["xmlns"] = "w:profile:picture";
          $picture       = new ProtocolNode("picture", $hash, null, $data);
          $icon  = Media::createIconGD($filepath, 96, true);
          $thumb = new ProtocolNode("picture", array("type" => "preview"), null, $icon);
          $hash         = array();
          $nodeID       = $this->createMsgId("setphoto");
          $hash["id"]   = $nodeID;
          $hash["to"]   = $this->getJID($jid);
          $hash["type"] = "set";
          $node         = new ProtocolNode("iq", $hash, array($picture, $thumb), null);

          $this->sendNode($node);
          $this->waitForServer($nodeID);
        }
      }
    }



    /**
     * Parse the message text for emojis
     *
     * This will look for special strings in the message text
     * that need to be replaced with a unicode character to show
     * the corresponding emoji.
     *
     * Emojis should be entered in the message text either as the
     * correct unicode character directly, or if this isn't possible,
     * by putting a placeholder of ##unicodeNumber## in the message text.
     * Include the surrounding ##
     * eg:
     * ##1f604## this will show the smiling face
     * ##1f1ec_1f1e7## this will show the UK flag.
     *
     * Notice that if 2 unicode characters are required they should be joined
     * with an underscore.
     *
     *
     * @param string $txt
     * The message to be parsed for emoji code.
     *
     * @return string
     */
    private function parseMessageForEmojis($txt)
    {
      $matches = null;
      preg_match_all('/##(.*?)##/', $txt, $matches, PREG_SET_ORDER);

      if (is_array($matches))
      {
        foreach ($matches as $emoji)
        {
          $txt = str_ireplace($emoji[0], $this->unichr((string)$emoji[1]), $txt);
        }
      }

      return $txt;
    }



    /**
     * Creates the correct unicode character from the unicode code point
     *
     * @param int $int
     *
     * @return string
     */
    private function unichr($int)
    {
      $string     = null;
      $multiChars = explode('_', $int);

      foreach ($multiChars as $char)
      {
        $string .= mb_convert_encoding('&#' . intval($char, 16) . ';', 'UTF-8', 'HTML-ENTITIES');
      }

      return $string;
    }



    /**
     * Parse a jabber id
     *
     * @param string $jid
     *
     * @return string
     */
    public static function parseJID($jid)
    {
      $parts = explode('@', $jid);
      $parts = reset($parts);

      return $parts;
    }
  }