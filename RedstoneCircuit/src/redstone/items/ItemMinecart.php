<?php

namespace redstone\items;

use pocketmine\Player;

use pocketmine\block\Block;

use pocketmine\entity\Entity;

use pocketmine\item\Minecart;

use pocketmine\math\Vector3;

class ItemMinecart extends Minecart {

	public function onActivate(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector) : bool {
		$nbt = Entity::createBaseNBT($blockReplace->add(0.5, 0.375, 0.5), null, 0, 0);

		if($this->hasCustomName()){
			$nbt->setString("CustomName", $this->getCustomName());
		}

		$entity = Entity::createEntity("Minecart", $player->getLevel(), $nbt);

		if($entity instanceof Entity){
			--$this->count;
			$entity->spawnToAll();
			return true;
		}

		return false;
	}
}