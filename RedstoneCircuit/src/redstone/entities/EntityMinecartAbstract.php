<?php

namespace redstone\entities;

use pocketmine\block\ActivatorRail;
use pocketmine\block\BaseRail;
use pocketmine\block\Block;
use pocketmine\block\BlockIds;

use pocketmine\entity\Human;
use pocketmine\entity\Living;

use pocketmine\event\entity\EntityDamageEvent;

use pocketmine\level\Level;
use pocketmine\level\Location;

use pocketmine\math\Vector3;

use pocketmine\nbt\tag\CompoundTag;


use redstone\Main;

use redstone\utils\RedstoneUtils;

abstract class EntityMinecartAbstract extends EntityVehicle {

    private static $matrix = [
        [[0, 0, -1], [0, 0, 1]],
        [[-1, 0, 0], [1, 0, 0]],
        [[-1, -1, 0], [1, 0, 0]],
        [[-1, 0, 0], [1, -1, 0]],
        [[0, 0, -1], [0, -1, 1]],
        [[0, -1, -1], [0, 0, 1]],
        [[0, 0, 1], [1, 0, 0]],
        [[0, 0, 1], [-1, 0, 0]],
        [[0, 0, -1], [-1, 0, 0]],
        [[0, 0, -1], [1, 0, 0]]
    ];

    public $width = 0.7;
    public $height = 0.98;

    protected $currentSpeed = 0;
    protected $blockInside;
    protected $slowWhenEmpty = true;
    protected $deraliedX = 0.5;
    protected $deraliedY = 0.5;
    protected $deraliedZ = 0.5;
    protected $flyingX = 0.95;
    protected $flyingY = 0.95;
    protected $flyingZ = 0.95;
    protected $maxSpeed = 0.4;

    public function __construct(Level $level, CompoundTag $nbt) {
        parent::__construct($level, $nbt);
        
        $this->setMaxHealth(40);
        $this->setHealth(40);

        $this->drag = 0.1;

        $this->baseOffset = 0.35;
    }
    
    protected function initEntity() : void {
        parent::initEntity();

        $this->setRollingAmplitude(0);
        $this->setRollingDirection(1);

        if ($this->namedtag->hasTag("CustomDisplayTile", ByteTag::class) && $this->namedtag->getByte("CustomDisplayTile") == 0) {
            return;
        }
        
        $display = 0;
        if ($this->namedtag->hasTag("CustomDisplayTile", IntTag::class)) {
            $display = $this->namedtag->getInt("CustomDisplayTile");
        } else {
            $display = $this->blockInside == null ? 0 : $this->blockInside->getId() | $this->blockInside->getDamage() << 16;
        }

        if ($display == 0) {
            $this->getDataPropertyManager()->setByte(self::DATA_MINECART_HAS_DISPLAY, 0);
            return;
        }
        $this->getDataPropertyManager()->setInt(self::DATA_MINECART_DISPLAY_BLOCK, $display);

        $offset = 6;
        if ($this->namedtag->hasTag("CustomDisplayTile", IntTag::class)) {
            $offset = $this->namedtag->getInt("CustomDisplayTile");
        }
        $this->getDataPropertyManager()->setInt(self::DATA_MINECART_DISPLAY_OFFSET, $display);
        
        $this->getDataPropertyManager()->setByte(self::DATA_MINECART_HAS_DISPLAY, 1);
    }

    public function attack(EntityDamageEvent $source) : void {
        $source->setBaseDamage($source->getBaseDamage() * 15);
        parent::attack($source);
        
        if ($this->isAlive()) {
            $this->performHurtAnimation();
        }
    }

    public function kill() : void {
        parent::kill();
        foreach ($this->getDrops() as $item) {
            $this->getLevel()->dropItem($this, $item);
        }
    }

    public abstract function getDrops() : array;

    public function onUpdate(int $currentTick) : bool {
        $check = parent::onUpdate($currentTick);
        if (!$check) {
            return $check;
        }

        if (!$this->isAlive()) {
            return true;
        }

        $health = $this->getHealth();
        if ($health < 20) {
            $this->setHealth($health + 1);
        }

        $this->lastX = $this->x;
        $this->lastY = $this->y;
        $this->lastZ = $this->z;
        $this->motion->y -= 0.03999999910593033;

        $dx = floor($this->x);
        $dy = floor($this->y);
        $dz = floor($this->z);

        if ($this->getLevel()->getBlockAt($dx, $dy - 1, $dz) instanceof BaseRail) {
            $dy--;
        }

        $block = $this->getLevel()->getBlockAt($dx, $dy, $dz);
        if ($block instanceof BaseRail) {
            $this->processMovement($dx, $dy, $dz, $block);
            if ($block instanceof ActivatorRail) {
                $this->activate($dx, $dy, $dz, ($block->getDamage() & 0x08) != 0);
            }
        } else {
            $this->setFalling();
        }
        $this->checkBlockCollision();

        $this->pitch = 0;
        $diffX = $this->lastX - $this->x;
        $diffZ = $this->lastZ - $this->z;
        $yawToChange = $this->yaw;
        if ($diffX * $diffX + $diffZ * $diffZ > 0.001) {
            $yawToChange = atan2($diffZ, $diffX) * 180 / M_PI;
        }

        if ($yawToChange < 0) {
            $yawToChange *= -1;
        }

        $this->setRotation($yawToChange, $this->pitch);
        $from = new Location($this->lastX, $this->lastY, $this->lastZ, $this->lastYaw, $this->lastPitch, $this->level);
        $to = new Location($this->x, $this->y, $this->z, $this->yaw, $this->pitch, $this->level);

        // VehicleUpdateEvent

        $entities = $this->getLevel()->getNearbyEntities($this->getBoundingBox()->expandedCopy(0.2, 0, 0.2), $this);
        for ($i = 0; $i < count($entities); ++$i) {
            $entity = $entities[$i];
            if (!$this->isPassenger($entity) && $entity instanceof EntityMinecartAbstract) {
                //$entity->applyEntityCollision($this);
            }
        }

        for ($i = 0; $i < count($this->passengers); ++$i) {
            $passenger = $this->passengers[$i];
            if ($passenger->isAlive()) {
                continue;
            }

            $riding = Main::getInstance()->getRiding();
            if ($riding->getRiding($passenger) == $this) {
                $riding->removeRiding($passenger);
            }

            $key = array_search($entity, $this->passengers);
            if ($key !== false) {
                unset($this->passengers[$key]);
            }
        }

        return true;
    }

    protected function processMovement(int $dx, int $dy, int $dz, Block $block) : void {
        $this->fallDistance = 0;
        $vector = $this->getNextRail($this->x, $this->y, $this->z);

        $this->y = $dy;
        $isPowered = false;
        $isSlowed = false;

        if ($block instanceof BlockPoweredRail) {
            // TODO
        }

        $meta = $block->getId() > 7 ? $block->getDamage() & 0x07 : $block->getDamage();
        switch ($meta) {
            case 4: //ascending north
                $this->motion->x -= 0.0078125;
                $this->y++;
                break;
            case 5: //ascending south
                $this->motion->x += 0.0078125;
                $this->y++;
                break;
            case 2: //ascending east
                $this->motion->z += 0.0078125;
                $this->y++;
                break;
            case 3: //ascending west
                $this->motion->z -= 0.0078125;
                $this->y++;
                break;
        }

        $facing = EntityMinecartAbstract::$matrix[$meta];
        $facing1 = $facing[1][0] - $facing[0][0];
        $facing2 = $facing[1][2] - $facing[0][2];
        $speedOnTurns = sqrt($facing1 * $facing1 + $facing2 * $facing2);
        $realFacing = $this->motion->x * $facing1 + $this->motion->z * $facing2;

        if ($realFacing < 0) {
            $facing1 *= -1;
            $facing2 *= -1;
        }

        $squareOfFame = sqrt($this->motion->x * $this->motion->x + $this->motion->z * $this->motion->z);
        if ($squareOfFame > 2) {
            $squareOfFame = 2;
        }

        $this->motion->x = $squareOfFame + $facing1 / $speedOnTurns;
        $this->motion->z = $squareOfFame + $facing2 / $speedOnTurns;

        $expectedSpeed = 0.0;
        $playerYawNeg = 0.0;
        $playerYawPos = 0.0;
        $motion = 0.0;

        if (0 < count($this->passengers)) {
            $linked = $this->passengers[0];
            if ($linked instanceof Living) {
                $expectedSpeed = $this->currentSpped;
                if ($expectedSpeed > 0) {
                    $playerYawNeg = -sin($linked->yaw * M_PI / 180);
                    $playerYawPos = cos($linked->yaw * M_PI / 180);
                    $motion = $this->motion->x * $this->motion->x + $this->motion->z * $this->motion->z;
                    if ($motion < -0.01) {
                        $this->motion->x += $playerYawNeg * 0.1;
                        $this->motion->z += $playerYawPos * 0.1;

                        $isSlowed = false;
                    }
                }
            }
        }

        if ($isSlowed) {
            $expectedSpeed = sqrt($this->motion->x * $this->motion->x + $this->motion->z * $this->motion->z);
            if ($expectedSpeed < 0.03) {
                $this->motion = new Vector3(0, 0, 0);
            } else {
                $this->motion = new Vector3(0.5, 0, 0.5);
            }
        }

        $playerYawNeg = $dx + 0.5 + $facing[0][0] * 0.5;
        $playerYawPos = $dz + 0.5 + $facing[0][2] * 0.5;
        $motion = $dx + 0.5 + $facing[1][0] * 0.5;
        $wallOfFame = $dz + 0.5 + $facing[1][2] * 0.5;

        $facing1 = $motion - $playerYawNeg;
        $facing2 = $wallOfFame - $playerYawPos;
        $motX = 0;
        $motZ = 0;

        if ($facing1 == 0) {
            $this->x = $dx + 0.5;
            $expectedSpeed = $this->z - $dz;
        } else if ($facing2 == 0) {
            $this->z = $dz + 0.5;
            $expectedSpeed = $this->x - $dx;
        } else {
            $motX = $this->x - $playerYawNeg;
            $motZ = $this->z = $playerYawPos;
            $expectedSpeed = ($motX * $facing1 + $motZ * $facing2) * 2;
        }

        $this->x = $playerYawNeg + $facing1 * $expectedSpeed;
        $this->z = $playerYawPos + $facing2 * $expectedSpeed;
        $this->setPosition(new Vector3($this->x, $this->y, $this->z));

        $motX = $this->motion->x;
        $motZ = $this->motion->z;
        if (count($this->passengers) == 0) {
            $motX *= 0.75;
            $motZ *= 0.75;
        }
        $motX = $motX < -$this->maxSpeed ? -$this->maxSpeed : ($motX > $this->maxSpeed ? $this->maxSpeed : $motX);
        $motZ = $motZ < -$this->maxSpeed ? -$this->maxSpeed : ($motZ > $this->maxSpeed ? $this->maxSpeed : $motZ);
        $this->move($motX, 0, $motZ);

        if ($facing[0][1] != 0 && floor($this->x) - $dx == $facing[0][0] && floor($this->z) - $dz == $facing[0][2]) {
            $this->setPosition(new Vector3($this->x, $this->y + $facing[0][1], $this->z));
        } else if ($facing[1][1] != 0 && floor($this->x) - $dx == $facing[1][0] && floor($this->z) - $dz == $facing[1][2]) {
            $this->setPosition(new Vector3($this->x, $this->y + $facing[1][1], $this->z));
        }

        $this->applyDrag();
        $vector1 = $this->getNextRail($this->x, $this->y, $this->z);
        if ($vector1 != null && $vector != null) {
            $d14 = ($vector->y - $vector1->y) * 0.05;
            $squareOfFame = sqrt($this->motion->x * $this->motion->x + $this->motion->z * $this->motion->z);
            if ($squareOfFame > 0) {
                $this->motion->x = $this->motion->x / $squareOfFame * ($squareOfFame + $d14);
                $this->motion->z = $this->motion->z / $squareOfFame * ($squareOfFame + $d14);
            }

            $this->setPosition(new Vector3($this->x, $vector1->y, $this->z));
        }

        $floorX = floor($this->x);
        $floorZ = floor($this->z);
        if ($floorX != $dx || $floorZ != $dz) {
            $squareOfFame = sqrt($this->motion->x * $this->motion->x + $this->motion->z * $this->motion->z);
            $this->motion->x = $squareOfFame * ($floorX - $dx);
            $this->motion->z = $squareOfFame * ($floorZ - $dz);
        }

        if ($isPowered) {
            $newMovie = sqrt($this->motion->x * $this->motion->x + $this->motion->z * $this->motion->z);
            if ($newMovie > 0.01) {
                $nextMovie = 0.06;
                $this->motion->x += $this->motion->x / $newMovie + $nextMovie;
                $this->motion->z += $this->motion->z / $newMovie + $nextMovie;
            } else if ($meta == 0) {
                if (RedstoneUtils::isNormalBlock($this->getLevel()->getBlockAt($dx - 1, $dy, $dz))) {
                    $this->motion->x = 0.02;
                } else if (RedstoneUtils::isNormalBlock($this->getLevel()->getBlockAt($dx + 1, $dy, $dz))) {
                    $this->motion->x = -0.02;
                }
            } else if ($meta == 1) {
                if (RedstoneUtils::isNormalBlock($this->getLevel()->getBlockAt($dx, $dy, $dz - 1))) {
                    $this->motion->z = 0.02;
                } else if (RedstoneUtils::isNormalBlock($this->getLevel()->getBlockAt($dx, $dy, $dz + 1))) {
                    $this->motion->z = -0.02;
                }
            }
        }
    }

    protected function getNextRail(float $dx, float $dy, float $dz) : Vector3 {
        $checkX = floor($dx);
        $checkY = floor($dy);
        $checkZ = floor($dz);

        if ($this->getLevel()->getBlockAt($checkX, $checkY - 1, $checkZ) instanceof BaseRail) {
            --$checkY;
        }

        $block = $this->getLevel()->getBlockAt($checkX, $checkY, $checkZ);
        if (!($block instanceof BaseRail)) {
            return new Vector3($dx, $dy, $dz);
        }

        $meta = $block->getId() > 7 ? $block->getDamage() & 0x07 : $block->getDamage();
        $facing = EntityMinecartAbstract::$matrix[$meta];

        $nextOne = $checkX + 0.5 + $facing[0][0] * 0.5;
        $nextTwo = $checkY + 0.5 + $facing[0][1] * 0.5;
        $nextThree = $checkZ + 0.5 + $facing[0][2] * 0.5;
        $nextFour = $checkX + 0.5 + $facing[1][0] * 0.5;
        $nextFive = $checkY + 0.5 + $facing[1][1] * 0.5;
        $nextSix = $checkZ + 0.5 + $facing[1][2] * 0.5;
        $nextSeven = $nextFour - $nextOne;
        $nextEight = ($nextFive - $nextTwo) * 2;
        $nextMax = $nextSix - $nextThree;

        $rail;
        if ($nextSeven == 0) {
            $rail = $dz - $checkZ;
        } else if ($nextMax == 0) {
            $rail = $dx - $checkX;
        } else {
            $rail = (((dx - $nextOne) * $nextSeven) + (($dz - $nextThree) * $nextMax)) * 2;
        }

        $dx = $nextOne + $nextSeven * $rail;
        $dy = $nextTwo + $nextEight * $rail;
        $dz = $nextThree + $nextMax * $rail;
        if ($nextEight < 0) {
            ++$dy;
        }

        if ($nextEight > 0) {
            $dy += 0.5;
        }

        return new Vector3($dx, $dy, $dz);
    }

    private function applyDrag() : void {
        if (0 < count($this->passengers) || !$this->slowWhenEmpty) {
            $this->motion = new Vector3(0.996999979019165, 0, 0.996999979019165);
        } else {
            $this->motion = new Vector3(0.9599999785423279, 0, 0.9599999785423279);
        }
    }

    protected function activate(int $x, int $y, int $z, bool $flag) : void {

    }

    private function setFalling() : void {
        $this->motion->x = $this->motion->x < -$this->maxSpeed ? -$this->maxSpeed : ($this->motion->x > $this->maxSpeed ? $this->maxSpeed : $this->motion->x);
        $this->motion->z = $this->motion->z < -$this->maxSpeed ? -$this->maxSpeed : ($this->motion->z > $this->maxSpeed ? $this->maxSpeed : $this->motion->z);

        //passenger update?

        if ($this->onGround) {
            $this->motion->x *= $this->deraliedX;
            $this->motion->y *= $this->deraliedY;
            $this->motion->z *= $this->deraliedZ;
        }
        $this->move($this->motion->x, $this->motion->y, $this->motion->z);

        if (!$this->onGround) {
            $this->motion->x *= $this->flyingX;
            $this->motion->y *= $this->flyingY;
            $this->motion->z *= $this->flyingZ;
        }
    }

    public function applyEntityCollision(Entity $entity) : void {
        $riding = Main::getInstance()->getRiding();
        if ($riding->getRiding($this) != $entity) {
            return;
        }

        if (!($entity instanceof Living) || $entity instanceof Human) {
            return;
        }

        //TODO
    }
}