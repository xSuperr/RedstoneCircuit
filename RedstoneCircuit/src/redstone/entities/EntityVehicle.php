<?php

namespace redstone\entities;

use pocketmine\Player;
use pocketmine\Server;

use pocketmine\entity\Entity;
use pocketmine\entity\Rideable;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

use pocketmine\math\Vector3;

use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;

use pocketmine\network\mcpe\protocol\type\EntityLink;


use redstone\Main;

class EntityVehicle extends Entity implements Rideable {

    public function getRollingAmplitude() : int {
        return $this->getDataPropertyManager()->getInt(Entity::DATA_HURT_TIME);
    }

    public function setRollingAmplitude(int $time) : void {
        $this->getDataPropertyManager()->setInt(Entity::DATA_HURT_TIME, $time);
        echo "RollingAmplitude " . $time . "\n";
    }

    public function getRollingDirection() : int {
        return $this->getDataPropertyManager()->getInt(Entity::DATA_HURT_DIRECTION);
    }

    public function setRollingDirection(int $direction) : void {
        $this->getDataPropertyManager()->setInt(Entity::DATA_HURT_DIRECTION, $direction);
        echo "RollingDirection " . $direction . "\n";
    }

    public function getDamage() : int {
        return $this->getDataPropertyManager()->getInt(Entity::DATA_HEALTH);
    }

    public function setDamage(int $damage) : void {
        $this->getDataPropertyManager()->setInt(Entity::DATA_HEALTH, $damage);
    }
    
    public function onUpdate(int $currentTick) : bool {
		if($this->closed){
			return false;
        }

        $rolling = $this->getRollingAmplitude();
        if ($rolling > 0) {
            $this->setRollingAmplitude($rolling - 1);
        }

        if ($this->y < -16) {
            $this->kill();
        }

        if(!$this->isAlive()){
            if($this->onDeathUpdate($currentTick)){
                $this->flagForDespawn();
            }

            return true;
        }

		$changedProperties = $this->propertyManager->getDirty();
		if(!empty($changedProperties)){
			$this->sendData($this->hasSpawned, $changedProperties);
			$this->propertyManager->clearDirtyProperties();
		}

        $this->updateMovement();
        return true;
    }

    protected $rollingDirection = true;

    protected function performHurtAnimation() : bool {
        $this->setRollingAmplitude(9);
        $this->setRollingDirection($this->rollingDirection ? 1 : -1);
        $this->rollingDirection = !$this->rollingDirection;

        return true;
    }

    public function attack(EntityDamageEvent $source) : void {
        // TODO : VehicleDamageEvent

        $instantKill = false;

        if ($source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            $instantKill = $damager instanceof Player && $damager->isCreative();
        }

        if ($instantKill || $this->getHealth() - $source->getFinalDamage() < 1) {
            // TODO : VehicleDestroyEvent
        }

        if ($instantKill) {
            $source->setBaseDamage(1000);
        }

        parent::attack($source);
    }

    protected $passengers = [];

    public function getPassenger() : ?Entity {
        if (count($this->passengers) == 0) {
            return null;
        }
        return $this->passengers[0];
    }

    public function getPassengers() : array {
        return $this->passengers;
    }

    public function isPassenger(Entity $entity) : bool {
        return in_array($entity, $this->passengers);
    }

    protected $riding = null;

    public function mountEntity(Entity $entity, int $type = EntityLink::TYPE_RIDER) : void {
        $riding = Main::getInstance()->getRiding();
        if ($riding->getRiding($entity) != null) {
            $this->dismountEntity($entity);
            return;
        }

        if ($this->isPassenger($entity)) {
            return;
        }

        //TODO : EntityVehicleEnterEvent

        $pk = new SetActorLinkPacket();
        $pk->link = new EntityLink($entity->getId(), $this->getId(), $type);
        Server::getInstance()->broadcastPacket($this->getViewers(), $pk);

        $riding->setRiding($entity, $this);
        $entity->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_RIDING, true);
        $this->passengers[] = $entity;

        $this->setSeatPosition($this->getMountedOffset(), $entity);
        $this->updatePassengerPosition($entity);
    }

    public function dismountEntity(Entity $entity) : void {
        //TODO : EntityVehicleExisEvent

        $pk = new SetActorLinkPacket();
        $pk->link = new EntityLink($entity->getId(), $this->getId(), EntityLink::TYPE_REMOVE);
        Server::getInstance()->broadcastPacket($this->getViewers(), $pk);

        $riding = Main::getInstance()->getRiding();
        $riding->removeRiding($entity);
        $entity->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_RIDING, false);
        $key = array_search($entity, $this->passengers);
        if ($key !== false) {
            unset($this->passengers[$key]);
        }

        $this->setSeatPosition(new Vector3(), $entity);
        $this->updatePassengerPosition($entity);
    }

    public function getSeatPosition($entity = null) : Vector3 {
        if ($entity == null) {
            $entity = $this;
        }

        return $this->getDataPropertyManager()->getVector3(Entity::DATA_RIDER_SEAT_POSITION);
    }

    public function setSeatPosition(Vector3 $pos, $entity = null) : void {
        if ($entity == null) {
            $entity = $this;
        }

        $entity->getDataPropertyManager()->setVector3(Entity::DATA_RIDER_SEAT_POSITION, new Vector3());
    }

    public function updatePassengerPosition(Entity $entity) : void {
        $entity->setPosition($this->add($this->getSeatPosition($entity)));
    }

    public function getMountedOffset() : Vector3 {
        return new Vector3(0, $this->height * 0.75);
    }

    protected function sendSpawnPacket(Player $player) : void {
		$pk = new AddActorPacket();
		$pk->entityRuntimeId = $this->getId();
		$pk->type = static::NETWORK_ID;
		$pk->position = $this->asVector3();
		$pk->motion = $this->getMotion();
		$pk->yaw = $this->yaw;
		$pk->headYaw = $this->yaw; //TODO
		$pk->pitch = $this->pitch;
		$pk->attributes = $this->attributeMap->getAll();
        $pk->metadata = $this->propertyManager->getAll();
        
        for ($i = 0; $i < count($this->passengers); ++$i) {
            $entity = $this->passengers[$i];
            $pk->link[] = new EntityLink($this->getId(), $entity->getId(), $i == 0 ? EntityLink::TYPE_RIDER : EntityLink::TYPE_PASSENGER, false);
        }

		$player->dataPacket($pk);
	}
}