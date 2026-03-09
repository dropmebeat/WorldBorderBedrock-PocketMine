<?php

declare(strict_types=1);

namespace icrafts\worldborderbedrock;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use function is_array;
use function is_string;
use function strtolower;
use function trim;

final class WorldBorderBedrock extends PluginBase implements Listener
{
    private Config $borderConfig;

    /** @var array<string, int> */
    private array $warnCooldown = [];

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->borderConfig = new Config(
            $this->getDataFolder() . "borders.yml",
            Config::YAML,
            ["worlds" => []],
        );
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args,
    ): bool {
        if (strtolower($command->getName()) !== "wb") {
            return false;
        }
        if (!$sender instanceof Player) {
            $this->msgRaw($sender, "player_only");
            return true;
        }
        if (!$sender->hasPermission("wb.use")) {
            $this->msg($sender, "no_permission");
            return true;
        }

        $sub = strtolower(trim((string) ($args[0] ?? "info")));
        return match ($sub) {
            "set" => $this->cmdSet($sender, $args),
            "center" => $this->cmdCenter($sender, $args),
            "clear" => $this->cmdClear($sender),
            "info" => $this->cmdInfo($sender),
            default => $this->cmdInfo($sender),
        };
    }

    public function onMove(PlayerMoveEvent $event): void
    {
        $player = $event->getPlayer();
        if ($player->hasPermission("wb.bypass")) {
            return;
        }
        $to = $event->getTo();
        if (!$to instanceof Position) {
            return;
        }
        if ($this->isInsideBorder($to)) {
            return;
        }

        $event->setTo($event->getFrom());
        $name = strtolower($player->getName());
        $tick = $this->getServer()->getTick();
        $last = $this->warnCooldown[$name] ?? 0;
        if ($tick - $last > 20) {
            $this->warnCooldown[$name] = $tick;
            $this->msg($player, "outside");
        }
    }

    public function onPlace(BlockPlaceEvent $event): void
    {
        $player = $event->getPlayer();
        if ($player->hasPermission("wb.bypass")) {
            return;
        }
        foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $_]) {
            $pos = new Position($x, $y, $z, $player->getWorld());
            if (!$this->isInsideBorder($pos)) {
                $event->cancel();
                $this->msg($player, "outside");
                return;
            }
        }
    }

    public function onBreak(BlockBreakEvent $event): void
    {
        $player = $event->getPlayer();
        if ($player->hasPermission("wb.bypass")) {
            return;
        }
        if (!$this->isInsideBorder($event->getBlock()->getPosition())) {
            $event->cancel();
            $this->msg($player, "outside");
        }
    }

    private function cmdSet(Player $player, array $args): bool
    {
        if (!$player->hasPermission("wb.admin")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $radius = max(16, (int) ($args[1] ?? 0));
        if ($radius <= 0) {
            return $this->cmdInfo($player);
        }
        $world = $player->getWorld()->getFolderName();
        $cfg = $this->getWorldBorder($world);
        if ($cfg === null) {
            $p = $player->getPosition();
            $cfg = ["center" => ["x" => $p->getX(), "z" => $p->getZ()], "radius" => $radius];
        } else {
            $cfg["radius"] = $radius;
        }
        $this->setWorldBorder($world, $cfg);
        $this->msg($player, "set_ok", ["{radius}" => (string) $radius]);
        return true;
    }

    private function cmdCenter(Player $player, array $args): bool
    {
        if (!$player->hasPermission("wb.admin")) {
            $this->msg($player, "no_permission");
            return true;
        }
        if (!isset($args[1], $args[2])) {
            return $this->cmdInfo($player);
        }
        $x = (float) $args[1];
        $z = (float) $args[2];
        $world = $player->getWorld()->getFolderName();
        $cfg = $this->getWorldBorder($world);
        if ($cfg === null) {
            $cfg = ["center" => ["x" => $x, "z" => $z], "radius" => 256];
        } else {
            $cfg["center"] = ["x" => $x, "z" => $z];
        }
        $this->setWorldBorder($world, $cfg);
        $this->msg($player, "center_ok", ["{x}" => (string) $x, "{z}" => (string) $z]);
        return true;
    }

    private function cmdClear(Player $player): bool
    {
        if (!$player->hasPermission("wb.admin")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $world = $player->getWorld()->getFolderName();
        $all = $this->getAllBorders();
        unset($all[$world]);
        $this->borderConfig->set("worlds", $all);
        $this->borderConfig->save();
        $this->msg($player, "clear_ok");
        return true;
    }

    private function cmdInfo(Player $player): bool
    {
        $world = $player->getWorld()->getFolderName();
        $cfg = $this->getWorldBorder($world);
        if ($cfg === null) {
            $this->msg($player, "info_none", ["{world}" => $world]);
            return true;
        }
        $center = $cfg["center"] ?? ["x" => 0, "z" => 0];
        $radius = (int) ($cfg["radius"] ?? 0);
        $this->msg($player, "info_line", [
            "{world}" => $world,
            "{x}" => (string) ($center["x"] ?? 0),
            "{z}" => (string) ($center["z"] ?? 0),
            "{radius}" => (string) $radius,
        ]);
        return true;
    }

    private function isInsideBorder(Position $position): bool
    {
        $world = $position->getWorld()->getFolderName();
        $cfg = $this->getWorldBorder($world);
        if ($cfg === null) {
            return true;
        }
        $center = $cfg["center"] ?? [];
        $radius = (float) ($cfg["radius"] ?? 0);
        if (!is_array($center) || $radius <= 0) {
            return true;
        }

        $dx = $position->getX() - (float) ($center["x"] ?? 0);
        $dz = $position->getZ() - (float) ($center["z"] ?? 0);
        return ($dx * $dx + $dz * $dz) <= ($radius * $radius);
    }

    /**
     * @return array<string, mixed>
     */
    private function getAllBorders(): array
    {
        $raw = $this->borderConfig->get("worlds", []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getWorldBorder(string $world): ?array
    {
        $all = $this->getAllBorders();
        $cfg = $all[$world] ?? null;
        return is_array($cfg) ? $cfg : null;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setWorldBorder(string $world, array $cfg): void
    {
        $all = $this->getAllBorders();
        $all[$world] = $cfg;
        $this->borderConfig->set("worlds", $all);
        $this->borderConfig->save();
    }

    /**
     * @param array<string, string> $replacements
     */
    private function msg(Player $player, string $key, array $replacements = []): void
    {
        $prefix = (string) $this->getConfig()->getNested("messages.prefix", "");
        $text = (string) $this->getConfig()->getNested("messages." . $key, $key);
        foreach ($replacements as $from => $to) {
            $text = str_replace($from, $to, $text);
        }
        $player->sendMessage(TextFormat::colorize($prefix . $text));
    }

    private function msgRaw(CommandSender $sender, string $key): void
    {
        $prefix = (string) $this->getConfig()->getNested("messages.prefix", "");
        $text = (string) $this->getConfig()->getNested("messages." . $key, $key);
        $sender->sendMessage(TextFormat::colorize($prefix . $text));
    }
}
