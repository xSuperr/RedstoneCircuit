<?php

namespace redstone\entities;

use pocketmine\item\Item;
use pocketmine\item\Minecart;

class EntityMinecart extends EntityMinecartAbstract {

    public const NETWORK_ID = self::MINECART;

    public function getName() : string {
        return "Minecart";
    }

    public function getDrops() : array {
        return [Item::get(Item::MINECART, 0, 1)];
    }
}