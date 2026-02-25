<?php

namespace CortexPE\Commando;

use pocketmine\plugin\PluginBase;

class Commando extends PluginBase
{
    protected function onEnable() : void
    {
        $this->getLogger()->info("Commando plugin enabled!");
        $this->getServer()->getPluginManager()->registerEvents(new PacketHooker(), $this);
    }

    protected function onDisable() : void
    {
        $this->getLogger()->info("Commando plugin disabled!");
    }
}