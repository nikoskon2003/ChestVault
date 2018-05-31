<?php

namespace VaultChest;

use pocketmine\item\enchantment\Enchantment;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\inventory\Inventory;
use pocketmine\utils\Config;
use pocketmine\item\Item;

class YamlMgr implements DatabaseManager
{
    protected $isGlobal;
    protected $owner;

    public function __construct(PluginBase $owner, $cf)
    {
        $this->owner = $owner;
        $this->isGlobal = $cf["settings"]["global"];
    }

    protected function getDataFolder()
    {
        return $this->owner->getDataFolder();
    }

    public function saveInventory(Player $player, Inventory $inv)
    {
        $n = trim(strtolower($player->getName()));
        if ($n === "") return false;
        $d = substr($n, 0, 1);
        if (!is_dir($this->getDataFolder() . $d)) mkdir($this->getDataFolder() . $d);

        $path = $this->getDataFolder() . $d . "/" . $n . ".yml";
        $cfg = new Config($path, Config::YAML);
        $yaml = $cfg->getAll();
        if ($this->isGlobal)
            $ln = "*";
        else
            $ln = trim(strtolower($player->getLevel()->getName()));

        $yaml[$ln] = [];

        foreach ($inv->getContents() as $slot => &$item) {
            $name = $item->getName();
            if($item->hasCustomName())
            {
                $name = $item->getCustomName();
            }
            $ench = [];
            if($item->hasEnchantments())
            {
                foreach ($item->getEnchantments() as $enc)
                {
                    $id = $enc->getId();
                    $level = $enc->getLevel();
                    $enc = implode([(string)$id, (string)$level], ":");
                    array_push($ench, $enc);
                }
            }
            $yaml[$ln][$slot]['item'] = implode(":", [$item->getId(),
                $item->getDamage(),
                $item->getCount(), $name]);
            $yaml[$ln][$slot]['ench'] = $ench;
        }
        $inv->clearAll();
        $cfg->setAll($yaml);
        $cfg->save();
        return true;

    }

    public function loadInventory(Player $player, Inventory $inv)
    {
        $n = trim(strtolower($player->getName()));
        if ($n === "") return false;
        $d = substr($n, 0, 1);
        $path = $this->getDataFolder() . $d . "/" . $n . ".yml";
        if (!is_file($path)) return false;

        $cfg = new Config($path, Config::YAML);
        $yaml = $cfg->getAll();
        if ($this->isGlobal)
            $ln = "*";
        else
            $ln = trim(strtolower($player->getLevel()->getName()));

        if (!isset($yaml[$ln])) return false;

        $inv->clearAll();
        foreach ($yaml[$ln] as $slot => $t) {
            list($id, $dam, $cnt, $nm) = explode(":", $t['item']);
            $item = Item::get((int)$id, (int)$dam, (int)$cnt)->setCustomName($nm);
            if(!empty($t['ench'][0]))
            {
                foreach ($t['ench'] as $enc)
                {
                    $a = explode(":", $enc);
                    $item->addEnchantment(Enchantment::getEnchantment((int)$a[0])->setLevel((int)$a[1]));
                }
            }
            $inv->setItem($slot, $item);
        }
        return true;
    }

    public function close()
    {
    }
}
