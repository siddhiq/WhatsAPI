Available events and arguments
==============================
See Events/WhatsAppEventListener.php.

How to bind a callback to an event
==================================

# Create a WhatsAppEventListener class and implement the method you
#  would like to handle:
```php

class MyEventListener extends \WhatsApi\Events\WhatsAppEventListenerBase {
    function onGetMessage(
        $phone, // The user phone number including the country code.
        $from, // The sender JID.
        $msgid, // The message id.
        $type, // The message type.
        $time, // The unix time when send message notification.
        $name, // The sender name.
        $message // The message.
    ) {
        print( "onGetMessage(" . $phone . ", " . $from . ", " . $msgid . ", " . $type . ", " . $time . ", " . $name . ", " . $message . ")\n" );
    }
}
```
# Create an instance of WhastProt.
```php
$w = new \WhatsApi\WhatsProtocol($userPhone, $userIdentity, $userName, $debug);
```
# Add your event listener.
```php
w->eventManager()->addEventListener(new MyEventListener());
```
# Connect to WhatsApp servers.
```php
$w->connect();
```
# Login to WhatsApp
```php
$w->loginWithPassword($password);
```
[...]
