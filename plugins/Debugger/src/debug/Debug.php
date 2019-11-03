<?php

declare(strict_types=1);

namespace debug;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\plugin\PluginBase;

/**
 * Class Debug
 * @package debug
 */
class Debug extends PluginBase implements Listener {

    public $sent = [];

    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onSend(DataPacketSendEvent $event) {
        $pk = $event->getPacket();
        if($pk instanceof BatchPacket) {
            foreach ($pk->getPackets() as $packet) {
                $this->log(get_class(PacketPool::getPacket($packet)));
            }
            return;
        }
        $class = get_class($event->getPacket());
        $this->log($class);
    }

    public function log($string) {
        if(!in_array($string, $this->sent)) {
            $this->sent[] = $string;
            var_dump($string);
        }
    }
}