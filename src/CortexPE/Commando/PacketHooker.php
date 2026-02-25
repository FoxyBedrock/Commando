<?php

/***
 *    ___                                          _
 *   / __\___  _ __ ___  _ __ ___   __ _ _ __   __| | ___
 *  / /  / _ \| '_ ` _ \| '_ ` _ \ / _` | '_ \ / _` |/ _ \
 * / /__| (_) | | | | | | | | | | | (_| | | | | (_| | (_) |
 * \____/\___/|_| |_| |_|_| |_| |_|\__,_|_| |_|\__,_|\___/
 *
 * Commando - A Command Framework virion for PocketMine-MP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Written by @CortexPE <https://CortexPE.xyz>
 *
 */
declare(strict_types=1);

namespace CortexPE\Commando;

use CortexPE\Commando\store\SoftEnumStore;
use CortexPE\Commando\traits\IArgumentable;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\Server;

class PacketHooker implements Listener {

    public function onDataPacketReceive(DataPacketSendEvent $event) : void {
        $newPackets = [];
        $server = Server::getInstance();
        $commandMap = $server->getCommandMap();
        $enums = SoftEnumStore::getEnums();

        foreach ($event->getPackets() as $packet) {

            if (!$packet instanceof AvailableCommandsPacket) {
                $newPackets[] = $packet;
                continue;
            }

            // Désassemblage une seule fois
            $disassembled = AvailableCommandsPacketDisassembler::disassemble($packet);
            $baseCommandDataList = $disassembled->commandData;

            foreach ($event->getTargets() as $session) {
                $player = $session->getPlayer();

                // Copie profonde du tableau
                $commandDataList = [];
                foreach ($baseCommandDataList as $commandData) {
                    $commandDataList[] = clone $commandData;
                }

                foreach ($commandDataList as $commandData) {
                    $command = $commandMap->getCommand($commandData->getName());

                    if (!$command instanceof BaseCommand) {
                        continue;
                    }

                    foreach ($command->getConstraints() as $constraint) {
                        if (!$constraint->isVisibleTo($player)) {
                            continue 2; // skip cette commande
                        }
                    }

                    $commandData->overloads = self::generateOverloads($player, $command);
                }

                $newPackets[] = AvailableCommandsPacketAssembler::assemble(
                    $commandDataList,
                    [],
                    $enums
                );
            }
        }

        $event->setPackets($newPackets);
    }

    /**
	 * @param CommandSender $cs
	 * @param BaseCommand $command
	 *
	 * @return CommandOverload[][]
	 */
	private static function generateOverloads(CommandSender $cs, BaseCommand $command): array {
		$overloads = [];

		foreach($command->getSubCommands() as $label => $subCommand) {
			if(!$subCommand->testPermissionSilent($cs) || $subCommand->getName() !== $label){ // hide aliases
				continue;
			}
			foreach($subCommand->getConstraints() as $constraint){
				if(!$constraint->isVisibleTo($cs)){
					continue 2;
				}
			}

			$scParam = CommandParameter::enum(
				$label,
				new CommandHardEnum($label, [$label]),
				0,
				optional: false
			);
			$overloadList = self::generateOverloadList($subCommand);
			if(!empty($overloadList)){
				foreach($overloadList as $overload) {
					$overloads[] = new CommandOverload(false, [$scParam, ...$overload->getParameters()]);
				}
			} else {
				$overloads[] = new CommandOverload(false, [$scParam]);
			}
		}

		foreach(self::generateOverloadList($command) as $overload) {
			$overloads[] = $overload;
		}

		return $overloads;
	}

	/**
	 * @param IArgumentable $argumentable
	 *
	 * @return CommandOverload[]
	 */
	private static function generateOverloadList(IArgumentable $argumentable): array {
		$input = $argumentable->getArgumentList();
		$combinations = [];
		$outputLength = array_product(array_map("count", $input));
		$indexes = [];
		foreach($input as $k => $charList){
			$indexes[$k] = 0;
		}
		do {
			/** @var CommandParameter[] $set */
			$set = [];
			foreach($indexes as $k => $index){
				$param = $set[$k] = clone $input[$k][$index]->getNetworkParameterData();

				if(isset($param->enum) && $param->enum instanceof CommandHardEnum){

                    // WIP fix for enum names
                    $param->enum = new CommandHardEnum(
                        $input[$k][$index]->getTypeName(),
                        $param->enum->getValues()
                    );
				}
			}
			$combinations[] = new CommandOverload(false, $set);

			foreach($indexes as $k => $v){
				$indexes[$k]++;
				$lim = count($input[$k]);
				if($indexes[$k] >= $lim){
					$indexes[$k] = 0;
					continue;
				}
				break;
			}
		} while(count($combinations) !== $outputLength);

		return $combinations;
	}
}
