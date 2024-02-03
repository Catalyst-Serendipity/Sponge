<?php

/**
 * Copyright (c) 2024 Catalyst-Serendipity
 *      ______      __        __           __       _____                          ___       _ __       
 *     / ____/___ _/ /_____ _/ /_  _______/ /_     / ___/___  ________  ____  ____/ (_)___  (_) /___  __
 *    / /   / __ `/ __/ __ `/ / / / / ___/ __/_____\__ \/ _ \/ ___/ _ \/ __ \/ __  / / __ \/ / __/ / / /
 *   / /___/ /_/ / /_/ /_/ / / /_/ (__  ) /_/_____/__/ /  __/ /  /  __/ / / / /_/ / / /_/ / / /_/ /_/ / 
 *   \____/\__,_/\__/\__,_/_/\__, /____/\__/     /____/\___/_/   \___/_/ /_/\__,_/_/ .___/_/\__/\__, /  
 *                          /____/                                                /_/          /____/   
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  Catalyst Serendipity Team
 * @email   catalystserendipity@gmail.com
 * @link    https://github.com/Catalyst-Serendipity
 * 
 */

declare(strict_types=1);

namespace serendipity\sponge;

use pocketmine\block\Block;
use pocketmine\block\Sponge as SpongeBlock;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\hell\Nether;
use pocketmine\world\sound\FizzSound;
use serendipity\sponge\particle\EvaporationParticle;

class Sponge extends PluginBase implements Listener{

    public const DRY_BIOMES = [
        BiomeIds::DESERT,
        BiomeIds::DESERT_HILLS,
        BiomeIds::DESERT_MUTATED,
        BiomeIds::HELL, // should a hell biome be added ??
        BiomeIds::SAVANNA,
        BiomeIds::SAVANNA_MUTATED,
        BiomeIds::SAVANNA_PLATEAU,
        BiomeIds::SAVANNA_PLATEAU_MUTATED
    ]; //TODO: more dry biomes ??

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onBlockSpread(BlockSpreadEvent $event) : void{
        $source = $event->getSource();
        $block = $event->getBlock();
        $newState = $event->getNewState();
        $targetBlocks = [$source, $block, $newState];
        if($source instanceof Water){
            foreach($targetBlocks as $targetBlock){
                $this->findAndAbsorbWaterNearby($targetBlock);
            }
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event) : void{
        $blockAgainst = $event->getBlockAgainst();
        $sponge = null;
        foreach($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]){
            if($block instanceof SpongeBlock){
                $sponge = $block;
                break;
            }
        }
        /** @var SpongeBlock $sponge */
        if($sponge !== null){
            $underSponeBlock = $sponge->getPosition()->getWorld()->getBlock($sponge->getPosition()->down());
            $spongeBlockPosition = $sponge->getPosition();
            $world = $spongeBlockPosition->getWorld();
            $biomeId = $world->getBiomeId($spongeBlockPosition->getX(), $spongeBlockPosition->getY(), $spongeBlockPosition->getZ());
            if(!$sponge->isWet()){
                if($blockAgainst instanceof Water){
                    $this->absorbWater($sponge, $spongeBlockPosition->getX(), $spongeBlockPosition->getY(), $spongeBlockPosition->getZ());
                }elseif(!$underSponeBlock->isSolid()){
                    $this->absorbWater($sponge, $spongeBlockPosition->getX(), $spongeBlockPosition->getY(), $spongeBlockPosition->getZ());
                }
                foreach($this->getNearBlocks($sponge) as $block){
                    if($block instanceof Water){
                        $this->absorbWater($sponge, $spongeBlockPosition->getX(), $spongeBlockPosition->getY(), $spongeBlockPosition->getZ());
                    }
                }
            }else{
                $generatorNameByClass = GeneratorManager::getInstance()->getGeneratorName(Nether::class);
                $generatorNameByWorld = $world->getProvider()->getWorldData()->getGenerator();
                if($generatorNameByWorld === $generatorNameByClass || strpos($generatorNameByWorld, $generatorNameByClass) || in_array($biomeId, self::DRY_BIOMES)){
                    $sponge->setWet(false);
                    $world->addSound($spongeBlockPosition, new FizzSound());
                    $world->addParticle($spongeBlockPosition, new EvaporationParticle());
                }
            }
        }
    }

    public function findAndAbsorbWaterNearby(Block $targetBlock) : void{
        foreach($this->getNearBlocks($targetBlock) as $sponge){
            if($sponge instanceof SpongeBlock && !$sponge->isWet()){
                $spongeBlockPosition = $sponge->getPosition();
                $this->absorbWater($sponge, $spongeBlockPosition->getX(), $spongeBlockPosition->getY(), $spongeBlockPosition->getZ());
                break;
            }
        }
    }

    public function getNearBlocks(Block $block) : array{
        $blocks = [];
        $blockPosition = $block->getPosition();
        $world = $blockPosition->getWorld();
        $blocks[] = $world->getBlock($blockPosition->down());
        $blocks[] = $world->getBlock($blockPosition->up());
        $blocks[] = $world->getBlock($blockPosition->west());
        $blocks[] = $world->getBlock($blockPosition->east());
        $blocks[] = $world->getBlock($blockPosition->north());
        $blocks[] = $world->getBlock($blockPosition->south());
        return $blocks;
    }

    public function isWaterBlock(Block $block) : bool{
        return ($block instanceof Water) ? true : false;
    }

    public function absorbWater(Block $sponge, float|int $spongeX, float|int $spongeY, float|int $spongeZ) : void{
        /** @var SpongeBlock $sponge */
        $absorbedWaterCount = 0;
        $spongeBlockPosition = $sponge->getPosition();
        for($x = $spongeX -7; $x <= $spongeX + 7; $x++){
            for($y = $spongeY -7; $y <= $spongeY + 7; $y++){
                for($z = $spongeZ -7; $z <= $spongeZ + 7; $z++){
                    $targetBlock = $spongeBlockPosition->getWorld()->getBlockAt($x, $y, $z);
                    if($this->isWaterBlock($targetBlock) &&
                    (abs($x - $spongeX) + abs($y - $spongeY) + abs($z - $spongeZ)) <= 7){
                        $sponge->getPosition()->getWorld()->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
                        $absorbedWaterCount++;
                        if ($absorbedWaterCount >= 65) {
                            break 3;
                        }
                    }
                }
            }
        }
        if($absorbedWaterCount > 0){
            $sponge->setWet(true);
        }
        $spongeBlockPosition->getWorld()->setBlockAt($spongeBlockPosition->getX(), $spongeBlockPosition->getY(), $spongeBlockPosition->getZ(), $sponge);
    }
}