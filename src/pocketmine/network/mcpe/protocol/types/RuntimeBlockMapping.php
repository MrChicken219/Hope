<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

use pocketmine\block\BlockIds;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use function file_get_contents;
use function getmypid;
use function json_decode;
use function mt_rand;
use function mt_srand;
use function shuffle;
use const pocketmine\RESOURCE_PATH;

/**
 * @internal
 */
final class RuntimeBlockMapping {

    /** @var RuntimeBlockMapping[] $mappings */
    private static $mappings = [];

	/** @var int[] */
	private $legacyToRuntimeMap = [];

	/** @var int[] */
	private $runtimeToLegacyMap = [];

	/** @var mixed[] */
	private $bedrockKnownStates;

	/** @var string $serializedTable */
	private $serializedTable;

    /**
     * RuntimeBlockMapping constructor.
     * @param int $protocol
     * @param bool $buildNBT
     */
	public function __construct(int $protocol, bool $buildNBT) {
	    $files = $this->getResourceFiles($protocol);
        $legacyIdMap = json_decode(file_get_contents(array_shift($files)), true); // block ids
        $compressedTable = json_decode(file_get_contents(array_shift($files)), true); // required block states
        $decompressed = [];

        if($buildNBT) {
            $this->serializedTable = file_get_contents(RESOURCE_PATH . "vanilla/full_data_370.txt");
        }

        foreach($compressedTable as $prefix => $entries){
            foreach($entries as $shortStringId => $states){
                foreach($states as $state){
                    $name = "$prefix:$shortStringId";
                    $decompressed[] = [
                        "name" => $name,
                        "data" => $state,
                        "legacy_id" => $legacyIdMap[$name]
                    ];
                }
            }
        }

        $this->bedrockKnownStates = self::randomizeTable($decompressed);

        foreach($this->bedrockKnownStates as $k => $obj){
            if($obj["data"] > 15){
                //TODO: in 1.12 they started using data values bigger than 4 bits which we can't handle right now
                continue;
            }
            //this has to use the json offset to make sure the mapping is consistent with what we send over network, even though we aren't using all the entries
            self::registerMapping($k, $obj["legacy_id"], $obj["data"]);
        }

        if(!$buildNBT) {
            $this->serializedTable = StartGamePacket::serializeBlockTable($this->bedrockKnownStates);
        }
	}

	public static function init() : void{
		self::$mappings[ProtocolInfo::PROTOCOL_1_12] = new RuntimeBlockMapping(ProtocolInfo::PROTOCOL_1_12, false);
		self::$mappings[ProtocolInfo::PROTOCOL_1_13] = new RuntimeBlockMapping(ProtocolInfo::PROTOCOL_1_13, true);
	}

	/**
	 * Randomizes the order of the runtimeID table to prevent plugins relying on them.
	 * Plugins shouldn't use this stuff anyway, but plugin devs have an irritating habit of ignoring what they
	 * aren't supposed to do, so we have to deliberately break it to make them stop.
	 *
	 * @param array $table
	 *
	 * @return array
	 */
	private static function randomizeTable(array $table) : array{
		$postSeed = mt_rand(); //save a seed to set afterwards, to avoid poor quality randoms
		mt_srand(getmypid() ?: 0); //Use a seed which is the same on all threads. This isn't a secure seed, but we don't care.
		shuffle($table);
		mt_srand($postSeed); //restore a good quality seed that isn't dependent on PID
		return $table;
	}

    /**
     * @param int $id
     * @param int $meta
     * @param int $protocol
     *
     * @return int
     */
	public static function toStaticRuntimeId(int $id, int $meta = 0, int $protocol = ProtocolInfo::CURRENT_PROTOCOL) : int{
        $class = self::$mappings[self::getProtocol($protocol)];
		/*
		 * try id+meta first
		 * if not found, try id+0 (strip meta)
		 * if still not found, return update! block
		 */
		return $class->legacyToRuntimeMap[($id << 4) | $meta] ?? $class->legacyToRuntimeMap[$id << 4] ?? $class->legacyToRuntimeMap[BlockIds::INFO_UPDATE << 4];
	}

    /**
     * @param int $runtimeId
     * @param int $protocol
     * @return array
     */
	public static function fromStaticRuntimeId(int $runtimeId, int $protocol = ProtocolInfo::CURRENT_PROTOCOL) : array{
	    $class = self::$mappings[self::getProtocol($protocol)];
		$v = $class->runtimeToLegacyMap[$runtimeId];
		return [$v >> 4, $v & 0xf];
	}

	private function registerMapping(int $staticRuntimeId, int $legacyId, int $legacyMeta) : void{
		$this->legacyToRuntimeMap[($legacyId << 4) | $legacyMeta] = $staticRuntimeId;
		$this->runtimeToLegacyMap[$staticRuntimeId] = ($legacyId << 4) | $legacyMeta;
	}

    /**
     * @param int $protocol
     * @return array
     */
	public static function getBedrockKnownStates(int $protocol = ProtocolInfo::CURRENT_PROTOCOL): array {
        $class = self::$mappings[self::getProtocol($protocol)];
		return $class->bedrockKnownStates;
	}

    /**
     * @param int $protocol
     * @return string
     */
	public static function getSerializeBlockTable(int $protocol = ProtocolInfo::CURRENT_PROTOCOL) {
	    $class = self::$mappings[self::getProtocol($protocol)];

	    if($class->serializedTable === "") {
	        $class->serializedTable = StartGamePacket::serializeBlockTable(self::getBedrockKnownStates($protocol));
        }

	    return $class->serializedTable;
    }

    /**
     * @param int $protocol
     * @return int
     */
    private static function getProtocol(int $protocol) {
	    if($protocol >= ProtocolInfo::PROTOCOL_1_13) {
	        return ProtocolInfo::PROTOCOL_1_13;
        }
	    return ProtocolInfo::PROTOCOL_1_12;
    }

    /**
     * @param int $protocol
     * @return array
     */
	private function getResourceFiles(int $protocol): array {
	    if($protocol == ProtocolInfo::PROTOCOL_1_12) {
            return [
                \pocketmine\RESOURCE_PATH . "vanilla/block_id_map.json",
                \pocketmine\RESOURCE_PATH . "vanilla/required_block_states.json"
            ];
        }

        return [
            \pocketmine\RESOURCE_PATH . "vanilla/block_id_map_370.json",
            \pocketmine\RESOURCE_PATH . "vanilla/required_block_states_370.json",
            \pocketmine\RESOURCE_PATH . "vanilla/advanced_block_states_370.json"
        ];
    }
}

RuntimeBlockMapping::init();
