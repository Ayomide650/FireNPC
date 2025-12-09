<?php

declare(strict_types=1);

namespace FireNPC;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\entity\Location;
use pocketmine\utils\Config;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;

class Main extends PluginBase implements Listener {

    private Config $npcs;
    private array $npcEntities = [];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        @mkdir($this->getDataFolder());
        $this->npcs = new Config($this->getDataFolder() . "npcs.yml", Config::YAML);
        
        $this->getLogger()->info(TF::GREEN . "FireNPC by Firekid846 enabled!");
        
        $this->getScheduler()->scheduleDelayedTask(new class($this) extends \pocketmine\scheduler\Task {
            private Main $plugin;
            
            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }
            
            public function onRun(): void {
                $this->plugin->loadAllNPCs();
            }
        }, 40);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        switch ($command->getName()) {
            case "firenpc":
                return $this->handleFireNPC($sender, $args);
        }

        return false;
    }

    private function handleFireNPC(Player $sender, array $args): bool {
        if (!$sender->hasPermission("firenpc.use")) {
            $sender->sendMessage(TF::RED . "You don't have permission!");
            return true;
        }

        if (count($args) < 1) {
            $this->sendHelp($sender);
            return true;
        }

        $action = strtolower($args[0]);

        switch ($action) {
            case "spawn":
                return $this->spawnNPC($sender, $args);
            
            case "remove":
                return $this->removeNPC($sender, $args);
            
            case "list":
                return $this->listNPCs($sender);
            
            case "setskin":
                return $this->setSkin($sender, $args);
            
            case "setname":
                return $this->setName($sender, $args);
            
            case "addcommand":
                return $this->addCommand($sender, $args);
            
            case "removecommand":
                return $this->removeCommand($sender, $args);
            
            case "commands":
                return $this->listCommands($sender, $args);
            
            case "tp":
                return $this->teleportToNPC($sender, $args);
            
            default:
                $this->sendHelp($sender);
                return true;
        }
    }

    private function spawnNPC(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc spawn <name>");
            return true;
        }

        $name = $args[1];
        
        if ($this->npcs->exists($name)) {
            $sender->sendMessage(TF::RED . "NPC with that name already exists!");
            return true;
        }

        $location = $sender->getLocation();
        
        $nbt = CompoundTag::create()
            ->setTag("Skin", CompoundTag::create()
                ->setString("Name", "Standard_Custom")
                ->setByteArray("Data", $sender->getSkin()->getSkinData())
                ->setByteArray("CapeData", $sender->getSkin()->getCapeData())
                ->setString("GeometryName", $sender->getSkin()->getGeometryName())
                ->setByteArray("GeometryData", $sender->getSkin()->getGeometryData())
            );

        $npc = new Human($location, $sender->getSkin(), $nbt);
        $npc->setNameTag(TF::colorize("&e" . $name));
        $npc->setNameTagAlwaysVisible(true);
        $npc->setNameTagVisible(true);
        $npc->spawnToAll();

        $this->npcEntities[$name] = $npc;

        $this->npcs->set($name, [
            "world" => $location->getWorld()->getFolderName(),
            "x" => $location->x,
            "y" => $location->y,
            "z" => $location->z,
            "yaw" => $location->yaw,
            "pitch" => $location->pitch,
            "nametag" => TF::colorize("&e" . $name),
            "skin" => base64_encode($sender->getSkin()->getSkinData()),
            "commands" => []
        ]);
        $this->npcs->save();

        $sender->sendMessage(TF::GREEN . "✓ NPC '$name' created!");
        $sender->sendMessage(TF::GRAY . "Use /firenpc setname $name <nametag> to customize");

        return true;
    }

    private function removeNPC(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc remove <name>");
            return true;
        }

        $name = $args[1];

        if (!$this->npcs->exists($name)) {
            $sender->sendMessage(TF::RED . "NPC not found!");
            return true;
        }

        if (isset($this->npcEntities[$name])) {
            $this->npcEntities[$name]->flagForDespawn();
            unset($this->npcEntities[$name]);
        }

        $this->npcs->remove($name);
        $this->npcs->save();

        $sender->sendMessage(TF::GREEN . "✓ NPC '$name' removed!");

        return true;
    }

    private function listNPCs(Player $sender): bool {
        $npcs = $this->npcs->getAll();

        if (empty($npcs)) {
            $sender->sendMessage(TF::YELLOW . "No NPCs found!");
            return true;
        }

        $sender->sendMessage(TF::GOLD . "━━━━━━━ NPCs ━━━━━━━");
        foreach (array_keys($npcs) as $name) {
            $sender->sendMessage(TF::YELLOW . "• " . TF::WHITE . $name);
        }
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function setSkin(Player $sender, array $args): bool {
        if (count($args) < 3) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc setskin <npc> <player>");
            return true;
        }

        $npcName = $args[1];
        $playerName = $args[2];

        if (!$this->npcs->exists($npcName)) {
            $sender->sendMessage(TF::RED . "NPC not found!");
            return true;
        }

        $target = $this->getServer()->getPlayerByPrefix($playerName);
        if ($target === null) {
            $sender->sendMessage(TF::RED . "Player not found! They must be online.");
            return true;
        }

        $data = $this->npcs->get($npcName);
        $data["skin"] = base64_encode($target->getSkin()->getSkinData());
        $this->npcs->set($npcName, $data);
        $this->npcs->save();

        if (isset($this->npcEntities[$npcName])) {
            $this->npcEntities[$npcName]->setSkin($target->getSkin());
            $this->npcEntities[$npcName]->sendSkin();
        }

        $sender->sendMessage(TF::GREEN . "✓ Set skin of '$npcName' to " . $target->getName() . "'s skin!");

        return true;
    }

    private function setName(Player $sender, array $args): bool {
        if (count($args) < 3) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc setname <npc> <nametag>");
            return true;
        }

        $npcName = $args[1];
        array_shift($args);
        array_shift($args);
        $nametag = implode(" ", $args);

        if (!$this->npcs->exists($npcName)) {
            $sender->sendMessage(TF::RED . "NPC not found!");
            return true;
        }

        $coloredName = str_replace("&", "§", $nametag);

        $data = $this->npcs->get($npcName);
        $data["nametag"] = $coloredName;
        $this->npcs->set($npcName, $data);
        $this->npcs->save();

        if (isset($this->npcEntities[$npcName])) {
            $this->npcEntities[$npcName]->setNameTag($coloredName);
        }

        $sender->sendMessage(TF::GREEN . "✓ Set nametag of '$npcName'!");
        $sender->sendMessage(TF::GRAY . "Preview: " . $coloredName);

        return true;
    }

    private function addCommand(Player $sender, array $args): bool {
        if (count($args) < 3) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc addcommand <npc> <command>");
            $sender->sendMessage(TF::GRAY . "Use {player} for clicking player's name");
            return true;
        }

        $npcName = $args[1];
        array_shift($args);
        array_shift($args);
        $command = implode(" ", $args);

        if (!$this->npcs->exists($npcName)) {
            $sender->sendMessage(TF::RED . "NPC not found!");
            return true;
        }

        $data = $this->npcs->get($npcName);
        if (!isset($data["commands"])) {
            $data["commands"] = [];
        }
        $data["commands"][] = $command;
        $this->npcs->set($npcName, $data);
        $this->npcs->save();

        $sender->sendMessage(TF::GREEN . "✓ Added command to '$npcName'!");
        $sender->sendMessage(TF::GRAY . "Command: " . $command);

        return true;
    }

    private function removeCommand(Player $sender, array $args): bool {
        if (count($args) < 3) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc removecommand <npc> <index>");
            return true;
        }

        $npcName = $args[1];
        $index = (int)$args[2] - 1;

        if (!$this->npcs->exists($npcName)) {
            $sender->sendMessage(TF::RED . "NPC not found!");
            return true;
        }

        $data = $this->npcs->get($npcName);
        if (!isset($data["commands"][$index])) {
            $sender->sendMessage(TF::RED . "Command index not found!");
            return true;
        }

        $removed = $data["commands"][$index];
        array_splice($data["commands"], $index, 1);
        $this->npcs->set($npcName, $data);
        $this->npcs->save();

        $sender->sendMessage(TF::GREEN . "✓ Removed command from '$npcName'!");
        $sender->sendMessage(TF::GRAY . "Removed: " . $removed);

        return true;
    }

    private function listCommands(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc commands <npc>");
            return true;
        }

        $npcName = $args[1];

        if (!$this->npcs->exists($npcName)) {
            $sender->sendMessage(TF::RED . "NPC not found!");
            return true;
        }

        $data = $this->npcs->get($npcName);
        $commands = $data["commands"] ?? [];

        if (empty($commands)) {
            $sender->sendMessage(TF::YELLOW . "No commands set for '$npcName'");
            return true;
        }

        $sender->sendMessage(TF::GOLD . "━━━━━━━ Commands for '$npcName' ━━━━━━━");
        $i = 1;
        foreach ($commands as $cmd) {
            $sender->sendMessage(TF::YELLOW . "$i. " . TF::WHITE . $cmd);
            $i++;
        }
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function teleportToNPC(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /firenpc tp <npc>");
            return true;
        }

        $npcName = $args[1];

        if (!$this->npcs->exists($npcName)) {
            $sender->sendMessage(TF::RED . "NPC not found!");
            return true;
        }

        $data = $this->npcs->get($npcName);
        $world = $this->getServer()->getWorldManager()->getWorldByName($data["world"]);

        if ($world === null) {
            $sender->sendMessage(TF::RED . "NPC's world not loaded!");
            return true;
        }

        $location = new Location($data["x"], $data["y"], $data["z"], $world, $data["yaw"], $data["pitch"]);
        $sender->teleport($location);
        $sender->sendMessage(TF::GREEN . "✓ Teleported to NPC '$npcName'!");

        return true;
    }

    public function onEntityDamage(EntityDamageByEntityEvent $event): void {
        $entity = $event->getEntity();
        $damager = $event->getDamager();

        if (!$damager instanceof Player) {
            return;
        }

        if (!$entity instanceof Human) {
            return;
        }

        $event->cancel();

        foreach ($this->npcEntities as $name => $npc) {
            if ($npc->getId() === $entity->getId()) {
                $data = $this->npcs->get($name);
                $commands = $data["commands"] ?? [];

                if (empty($commands)) {
                    $damager->sendMessage(TF::YELLOW . "This NPC has no actions set!");
                    return;
                }

                foreach ($commands as $cmd) {
                    $finalCmd = str_replace("{player}", $damager->getName(), $cmd);
                    $this->getServer()->dispatchCommand($damager, $finalCmd);
                }

                return;
            }
        }
    }

    private function loadAllNPCs(): void {
        $npcs = $this->npcs->getAll();

        foreach ($npcs as $name => $data) {
            $world = $this->getServer()->getWorldManager()->getWorldByName($data["world"]);
            
            if ($world === null) {
                $this->getLogger()->warning("Cannot load NPC '$name': World not loaded");
                continue;
            }

            try {
                $skinData = base64_decode($data["skin"]);
                $skin = new Skin("Standard_Custom", $skinData);
                
                $location = new Location($data["x"], $data["y"], $data["z"], $world, $data["yaw"], $data["pitch"]);
                
                $nbt = CompoundTag::create();
                
                $npc = new Human($location, $skin, $nbt);
                $npc->setNameTag($data["nametag"]);
                $npc->setNameTagAlwaysVisible(true);
                $npc->setNameTagVisible(true);
                $npc->spawnToAll();

                $this->npcEntities[$name] = $npc;
                
                $this->getLogger()->info("Loaded NPC: $name");
            } catch (\Exception $e) {
                $this->getLogger()->error("Failed to load NPC '$name': " . $e->getMessage());
            }
        }
    }

    private function sendHelp(Player $sender): void {
        $sender->sendMessage(TF::GOLD . "━━━━━━━ FireNPC Commands ━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "/firenpc spawn <name>" . TF::GRAY . " - Spawn NPC");
        $sender->sendMessage(TF::YELLOW . "/firenpc remove <name>" . TF::GRAY . " - Remove NPC");
        $sender->sendMessage(TF::YELLOW . "/firenpc list" . TF::GRAY . " - List all NPCs");
        $sender->sendMessage(TF::YELLOW . "/firenpc setskin <npc> <player>" . TF::GRAY . " - Set skin");
        $sender->sendMessage(TF::YELLOW . "/firenpc setname <npc> <text>" . TF::GRAY . " - Set nametag");
        $sender->sendMessage(TF::YELLOW . "/firenpc addcommand <npc> <cmd>" . TF::GRAY . " - Add command");
        $sender->sendMessage(TF::YELLOW . "/firenpc commands <npc>" . TF::GRAY . " - List commands");
        $sender->sendMessage(TF::YELLOW . "/firenpc removecommand <npc> <#>" . TF::GRAY . " - Remove cmd");
        $sender->sendMessage(TF::YELLOW . "/firenpc tp <npc>" . TF::GRAY . " - Teleport to NPC");
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    protected function onDisable(): void {
        foreach ($this->npcEntities as $npc) {
            $npc->flagForDespawn();
        }
        $this->npcs->save();
    }
}
