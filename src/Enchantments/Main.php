<?php

namespace Enchantments;

use pocketmine\entity\Arrow;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityInventoryChangeEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase implements Listener
{
    public function onEnable()
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function calculateEndDamage($damage, $reduction)
    {
        return $damage - $reduction;
    }

    public function calculateDamage($type, $material, $sharpness)
    {
        $type = strtoupper($type);
        $damage = swordDamages::DAMAGE_VALUES;
        $damage = $damage[$type];
        $material = strtoupper($material);
        $plus = swordDamages::MATERIAL_VALUES;
        $plus = $plus[$material];

        if ($damage > 1)
            $damage += $plus;
        $damage /= 2;
        $damage += .625 * $sharpness;

        return $damage;
    }

    public function onArrowShoot(EntityInventoryChangeEvent $event)
    {
        $player = $event->getEntity();
        if ($player instanceof Player) {
            if ($event->getOldItem()->getId() === Item::ARROW) {
                if ($player->getInventory()->getItemInHand()->getId() === Item::BOW) {
                    $infinty = $player->getInventory()->getItemInHand()->getEnchantment(22);
                    if ($infinty !== null) {
                        $event->setCancelled(true);
                    }
                }
            }
        }
    }

    public function onArrowHit(ProjectileHitEvent $event)
    {
        $arrow = $event->getEntity();
        if ($arrow instanceof Arrow) {
            $player = $arrow->shootingEntity;
            if ($player instanceof Player) {
                if ($player->getInventory()->getItemInHand()->getId() === Item::BOW) {
                    $flame = $player->getInventory()->getItemInHand()->getEnchantment(21);
                    if ($flame !== null) {
                        foreach ($arrow->getLevel()->getEntities() as $entity) {
                            if ($entity->distance($arrow) < 1.0) {
                                $time = $this->calculateFireAspect($flame->getLevel());
                                $entity->setOnFire($time);
                            }
                        }
                    }
                    $infinty = $player->getInventory()->getItemInHand()->getEnchantment(22);
                    if ($infinty !== null) {
                        $arrow->despawnFromAll();
                    }
                }
            }
        }
    }

    public function onArrow(EntityDamageEvent $event)
    {
        $player = $event->getEntity();
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if ($damager instanceof Arrow) {
                $shooter = $damager->shootingEntity;
                if ($shooter instanceof Player) {
                    $itemInHand = $shooter->getInventory()->getItemInHand();
                    $knockBack = $itemInHand->getEnchantment(20);
                    $knockBack2 = $itemInHand->getEnchantment(12);
                    if (($knockBack !== null) || ($knockBack2 !== null)) {
                        if ($player instanceof Player) {
                            $this->calculateKnockBack($player, $knockBack->getLevel(), $shooter);
                        }
                    }
                }
            }
        }
    }

    public function calculateArmorReduction($materials, $protections)
    {
        $material_head = $materials[0];
        $protection_head = $protections[0];
        $material_chest = $materials[1];
        $protection_chest = $protections[1];
        $material_leggings = $materials[2];
        $protection_leggings = $protections[2];
        $material_boots = $materials[3];
        $protection_boots = $protections[3];

        $def_head = armorReductions::MATERIAL_VALUES_BOOTS;
        $def_head = $def_head[$material_head];
        $def_chest = armorReductions::MATERIAL_VALUES_CHEST;
        $def_chest = $def_chest[$material_chest];
        $def_leggings = armorReductions::MATERIAL_VALUES_LEGGINGS;
        $def_leggings = $def_leggings[$material_leggings];
        $def_boots = armorReductions::MATERIAL_VALUES_BOOTS;
        $def_boots = $def_boots[$material_boots];

        $defensePoints = $def_head + $def_chest + $def_leggings + $def_boots;

        $epf = 0;
        if ($protection_head > 0) $epf += floor((6 + $protection_head * $protection_head) / 4);
        if ($protection_chest > 0) $epf += floor((6 + $protection_chest * $protection_chest) / 4);
        if ($protection_leggings > 0) $epf += floor((6 + $protection_leggings * $protection_leggings) / 4);
        if ($protection_boots > 0) $epf += floor((6 + $protection_boots * $protection_boots) / 4);

        $epf = min(ceil(min($epf, 25) * .75), 20);

        $reduction = 1 - (1 - .04 * $defensePoints) * (1 - .04 * $epf);

        return $reduction;
    }

    public function onDamage(EntityDamageEvent $event)
    {
        $player = $event->getEntity();
        if ($player instanceof Player) {
            if ($event instanceof EntityDamageByEntityEvent) {
                $damager = $event->getDamager();
                if ($damager instanceof Player) {
                    $itemInHand = $damager->getInventory()->getItemInHand();
                    $head = $player->getInventory()->getHelmet();
                    $chest = $player->getInventory()->getChestplate();
                    $leggings = $player->getInventory()->getLeggings();
                    $boots = $player->getInventory()->getBoots();

                    $itemInHand_type = $this->getWeaponType($itemInHand->getId());
                    $itemInHand_material = $this->getWeaponMaterial($itemInHand->getId());
                    $sharpness = 0;
                    foreach ($itemInHand->getEnchantments() as $enchantment) {
                        if ($enchantment->getId() === 9) {
                            $sharpness += $enchantment->getLevel();
                        }
                    }

                    $damage = $this->calculateDamage($itemInHand_type, $itemInHand_material, $sharpness);
                    $materials = array(
                        $this->getArmorMaterial($player->getInventory()->getHelmet()->getId()),
                        $this->getArmorMaterial($player->getInventory()->getChestplate()->getId()),
                        $this->getArmorMaterial($player->getInventory()->getLeggings()->getId()),
                        $this->getArmorMaterial($player->getInventory()->getBoots()->getId()),
                    );
                    $prot_head = 0;
                    $prot_chest = 0;
                    $prot_leggings = 0;
                    $prot_boots = 0;
                    foreach ($player->getInventory()->getHelmet()->getEnchantments() as $enchantment) {
                        if ($enchantment->getId() === 0) {
                            $prot_head += $enchantment->getLevel();
                        }
                    }
                    foreach ($player->getInventory()->getChestplate()->getEnchantments() as $enchantment) {
                        if ($enchantment->getId() === 0) {
                            $prot_chest += $enchantment->getLevel();
                        }
                    }
                    foreach ($player->getInventory()->getLeggings()->getEnchantments() as $enchantment) {
                        if ($enchantment->getId() === 0) {
                            $prot_leggings += $enchantment->getLevel();
                        }
                    }
                    foreach ($player->getInventory()->getBoots()->getEnchantments() as $enchantment) {
                        if ($enchantment->getId() === 0) {
                            $prot_boots += $enchantment->getLevel();
                        }
                    }
                    $protections = array(
                        $prot_head,
                        $prot_chest,
                        $prot_leggings,
                        $prot_boots
                    );
                    $reduction = $this->calculateArmorReduction($materials, $protections);

                    $endDamage = $this->calculateEndDamage($damage, $reduction);
                    $event->setDamage($endDamage);
                    $fireAspect = $itemInHand->getEnchantment(13);
                    if ($fireAspect !== null) {
                        $f_a = $this->calculateFireAspect($fireAspect->getLevel());
                        if ($f_a) {
                            $player->setOnFire($f_a);
                        }
                    }
                    $knockBack = $itemInHand->getEnchantment(12);
                    if ($knockBack !== null) {
                        if ($player instanceof Player) {
                            $this->calculateKnockBack($player, $knockBack->getLevel(), $damager);
                        }
                    }
                }
            }
        }
    }

    public function calculateFireAspect($level)
    {
        $bool = false;
        switch ($level) {
            case 1:
                $rand = mt_rand(1, 3);
                if ($rand === 1) $bool = true;
                break;
            case 2:
                $rand = mt_rand(1, 2);
                if ($rand === 1) $bool = true;
                break;
            default:
                $bool = true;
                break;
        }
        if ($bool) {
            $time = (($level * 2) + 1.5);
            return $time;
        } else {
            return false;
        }
    }

    public function calculateKnockBack(Player $player, $level, Player $damager)
    {
        switch ($level) {
            case 1:
                $level = $level + 0.5;
                break;
        }
        if ($damager->getDirection() == 0) {
            $player->knockBack($player, 0, 1, 0, $level);
        } elseif ($damager->getDirection() == 1) {
            $player->knockBack($player, 0, 0, 1, $level);
        } elseif ($damager->getDirection() == 2) {
            $player->knockBack($player, 0, -1, 0, $level);
        } elseif ($damager->getDirection() == 3) {
            $player->knockBack($player, 0, 0, -1, $level);
        }
    }

    public function getWeaponMaterial($id)
    {
        $wood = array(268, 269, 270, 271);
        $gold = array(283, 284, 285, 286);
        $stone = array(272, 273, 274, 275);
        $iron = array(267, 256, 257, 258);
        $diamond = array(276, 277, 278, 279);
        if (in_array($id, $wood)) {
            return "WOOD";
        } elseif (in_array($id, $gold)) {
            return "GOLD";
        } elseif (in_array($id, $stone)) {
            return "STONE";
        } elseif (in_array($id, $iron)) {
            return "IRON";
        } elseif (in_array($id, $diamond)) {
            return "DIAMOND";
        }
        return "WOOD";
    }

    public function getWeaponType($id)
    {
        $swords = array(267, 268, 272, 283, 276);
        $axes = array(258, 271, 275, 279, 286);
        $pickaxes = array(257, 270, 274, 278, 285);
        $shovels = array(256, 269, 273, 277, 284);
        if (in_array($id, $swords)) {
            return "SWORD";
        } elseif (in_array($id, $axes)) {
            return "AXE";
        } elseif (in_array($id, $pickaxes)) {
            return "PICKAXE";
        } elseif (in_array($id, $shovels)) {
            return "SHOVEL";
        }
        return "OTHER";
    }

    public function getArmorMaterial($id)
    {
        $leather = array(298, 299, 300, 301);
        $chain = array(302, 303, 304, 305);
        $iron = array(306, 307, 308, 309);
        $diamond = array(310, 311, 312, 313);
        $gold = array(314, 315, 316, 317);
        if (in_array($id, $leather)) {
            return "LEATHER";
        } elseif (in_array($id, $chain)) {
            return "CHAIN";
        } elseif (in_array($id, $iron)) {
            return "IRON";
        } elseif (in_array($id, $diamond)) {
            return "DIAMOND";
        } elseif (in_array($id, $gold)) {
            return "GOLD";
        }
        return "LEATHER";
    }

    public function getArmorType($id)
    {
        $head = array(298, 302, 306, 310, 314);
        $chest = array(299, 303, 307, 311, 315);
        $leggings = array(300, 304, 304, 312, 316);
        $boots = array(301, 305, 309, 313, 317);
        if (in_array($id, $head)) {
            return "HEAD";
        } elseif (in_array($id, $chest)) {
            return "CHEST";
        } elseif (in_array($id, $leggings)) {
            return "LEGGINGS";
        } elseif (in_array($id, $boots)) {
            return "BOOTS";
        }
        return "NONE";
    }
}