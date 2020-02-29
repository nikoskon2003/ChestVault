<?php

namespace VaultChest;

use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\inventory\Inventory;
use pocketmine\utils\Config;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;

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

        $yaml[$ln] = bin2hex(serialize($inv->getContents()));
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

        $inv->clearAll();
        if (!isset($yaml[$ln])) return false;

        if(is_array($yaml[$ln])) //compatibility with older versions
        {
            foreach ($yaml[$ln] as $slot => $t) {
                list($id, $dam, $cnt, $nm) = explode(":", $t['item']);
                $item = Item::get((int)$id, (int)$dam, (int)$cnt);
    
                if($item->getName() !== $nm)
                    $item->setCustomName($nm);
    
                if(!empty($t['ench'][0]))
                {
                    foreach ($t['ench'] as $enc)
                    {
                        $a = explode(":", $enc);
    
                        $enchantment = Enchantment::getEnchantment((int)$a[0]);
                        $enchantment = new EnchantmentInstance($enchantment);
                        $enchantment->setLevel((int)$a[1]);
    
                        $item->addEnchantment($enchantment);
                    }
                }
                $inv->setItem($slot, $item);
            }
        }
        else
        {
            $items = unserialize(hex2bin($yaml[$ln]));
            if($items === false) return false;
            $inv->setContents($items);
        }
        return true;
    }

    public function close()
    {
    }
}
