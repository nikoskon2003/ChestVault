<?php
/**
 ** CONFIG:config.yml
 **/

namespace VaultChest;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\utils\Config;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\Player;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\DoubleChestInventory;
use pocketmine\inventory\ChestInventory;
use pocketmine\tile\Tile;
use pocketmine\tile\Chest;
use pocketmine\math\Vector3;


use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;

use VaultChest\mc;

// OPEN
//- PlayerInteractEvent;
//- InventoryOpenEvent;

//PUT IN CHEST|GET FROM CHEST
//- InventoryTransactionEvent;
//- EntityInventoryChangeEvent;

// CLOSE
//- InventoryCloseEvent;


class Main extends PluginBase implements Listener
{
    protected $chests;    // Array with active chests
    protected $base_block;
    protected $dbm;

    protected static function iName(Player $player)
    {
        return strtolower($player->getName());
    }

    protected static function chestId($obj)
    {
        if ($obj instanceof ChestInventory) $obj = $obj->getHolder();
        if ($obj instanceof Chest) $obj = $obj->getBlock();
        return implode(":", [$obj->getLevel()->getName(), (int)$obj->getX(), (int)$obj->getY(), (int)$obj->getZ()]);
    }

    public function onDisable()
    {
        if ($this->dbm !== null) $this->dbm->close();
        $this->dbm = null;
    }

    public function onEnable()
    {
        if (!is_dir($this->getDataFolder())) mkdir($this->getDataFolder());
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->dbm = null;
        if (!is_dir($this->getDataFolder())) mkdir($this->getDataFolder());
        mc::plugin_init($this, $this->getFile());
        $defaults = [
            "version" => $this->getDescription()->getVersion(),
            "# settings" => "Configuration settings",
            "settings" => [
                "# global" => "If true all worlds share the same VaultChest. Changing this value may cause item loss!",
                "global" => false,
                "# particles" => "Decorate VaultChest...",
                "particles" => true,
                "# p-ticks" => "Particle ticks",
                "p-ticks" => 20,
                "# base-block" => "Block to use for the base",
                "base-block" => "BEDROCK",
            ]
        ];
        $cf = (new Config($this->getDataFolder() . "config.yml", Config::YAML, $defaults))->getAll();
        $this->dbm = new YamlMgr($this, $cf);

        $bl = Item::fromString($cf["settings"]["base-block"]);
        if ($bl->getBlock()->getId() == Item::AIR) {
            $this->getLogger()->warning(mc::_("Invalid base-block %1%", $cf["settings"]["base-block"]));
            $this->base_block = Block::BEDROCK;
        } else {
            $this->base_block = $bl->getBlock()->getId();
        }

        $this->chests = [];
        if ($cf["settings"]["particles"]) {
            $this->getScheduler()->scheduleRepeatingTask(
                new ParticleTask($this),
                $cf["settings"]["p-ticks"]);
        }
    }

    private function saveInventory(Player $player, Inventory $inv)
    {
        return $this->dbm->saveInventory($player, $inv);
    }

    private function loadInventory(Player $player, Inventory $inv)
    {
        return $this->dbm->loadInventory($player, $inv);
    }

    private function lockChest(Player $player, $obj)
    {
        $cid = self::chestId($obj);
        if (isset($this->chests[$cid])) return false;
        $this->chests[$cid] = self::iName($player);
        return true;
    }

    private function unlockChest(Player $player, $obj)
    {
        $cid = self::chestId($obj);
        if (!isset($this->chests[$cid])) return false;
        if ($this->chests[$cid] != self::iName($player)) return false;
        unset($this->chests[$cid]);
        return true;
    }

    public function isVChest(Inventory $inv)
    {
        if ($inv instanceof DoubleChestInventory) return false;
        if (!($inv instanceof ChestInventory)) return false;
        $tile = $inv->getHolder();
        if (!($tile instanceof Chest)) return false;
        $bl = $tile->getBlock();
        if ($bl->getId() != Block::CHEST) return false;
        if ($bl->getSide(Vector3::SIDE_DOWN)->getId() != $this->base_block) return false;
        return true;
    }

    public function onBlockPlaceEvent(BlockPlaceEvent $ev)
    {
        if ($ev->isCancelled()) return;
        $bl = $ev->getBlock();
        if ($bl->getId() != Block::CHEST || $bl->getSide(Vector3::SIDE_DOWN)->getId() != $this->base_block) return;
        $ev->getPlayer()->sendTip(mc::_("Placed a VaultChest"));
    }

    public function onBlockBreakEvent(BlockBreakEvent $ev)
    {
        if ($ev->isCancelled()) return;
        $bl = $ev->getBlock();
        if ($bl->getId() == $this->base_block) $bl = $bl->getSide(Vector3::SIDE_UP);
        if ($bl->getId() == Block::CHEST) {
            $tile = $bl->getLevel()->getTile($bl);
            if ($tile == null) return;
            if (!($tile instanceof Chest)) return;
            $inv = $tile->getInventory();
            if (!$this->isVChest($inv)) return;
            $cid = self::chestId($inv);
            if (!isset($this->chests[$cid])) return;
            $ev->getPlayer()->sendTip(mc::_("That VaultChest is in use!"));
            $ev->setCancelled();
            return;
        }
    }

    public function onPlayerQuitEvent(PlayerQuitEvent $ev)
    {
        $pn = self::iName($ev->getPlayer());
        foreach (array_keys($this->chests) as $cid) {
            if ($this->chests[$cid] == $pn) {
                unset($this->chests[$cid]);
                list($level, $x, $y, $z) = explode(":", $cid);
                $level = $this->getServer()->getLevelByName($level);
                if ($level == null) continue;
                $tile = $level->getTile(new Vector3($x, $y, $z));
                if ($tile == null) continue;
                if (!($tile instanceof Chest)) continue;
                $inv = $tile->getInventory();
                if (!$this->isVChest($inv)) continue;
                // QUITING WHILE VAULTCHEST IS OPEN!
                $this->saveInventory($ev->getPlayer(), $inv);
            }
        }
    }

    public function onInventoryCloseEvent(InventoryCloseEvent $ev)
    {
        $player = $ev->getPlayer();
        $inv = $ev->getInventory();
        if (!$this->isVChest($inv)) return;
        if ($this->unlockChest($player, $inv)) {
            $player->sendTip(mc::_("Closing VaultChest!"));
            $this->saveInventory($player, $inv);
        }
    }

    public function onInventoryOpenEvent(InventoryOpenEvent $ev)
    {
        if ($ev->isCancelled()) return;
        $player = $ev->getPlayer();
        $inv = $ev->getInventory();
        if (!$this->isVChest($inv)) return;
        if (!$this->lockChest($player, $inv)) {
            $player->sendTip(mc::_("That VaultChest is in use!"));
            $ev->setCancelled();
            return;
        }
        $player->sendTip(mc::_("Opening VaultChest!"));
        $this->loadInventory($player, $inv);
    }

}
