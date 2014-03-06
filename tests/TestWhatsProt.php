<?php
require_once '../src/php/whatsprot.class.php';

/**
 * Used to expose protected methods to the testing framework.
 */
class TestWhatsProt extends WhatsProtocol {
    public function processInboundDataNode(ProtocolNode $node) {
        parent::processInboundDataNode($node);
    }
}
