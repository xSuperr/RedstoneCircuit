<?php

namespace redstone\entities\utils;

class RidingManager {

    private $riding = [];

    public function getRiding(Entity $rider) : ?Entity {
        if (array_key_exists($rider->getId(), $this->riding)) {
            return $this->riding[$rider->getId()];
        }
        return null;
    }

    public function setRiding(Entity $rider, Entity $vehicle) : void {
        $this->riding[$rider->getId()] = $vehicle;
    }

    public function removeRiding(Entity $rider) : void {
        unset($this->riding[$rider->getId()]);
    }
}