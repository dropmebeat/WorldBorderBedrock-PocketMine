# WorldBorder

**WorldBorder** is an essential utility for **PocketMine-MP** designed to limit the size of your game worlds. It prevents players from generating infinite terrain, which significantly reduces lag and keeps your server's disk usage under control.

## Features

*   **Custom Boundaries:** Set a specific radius for each world to define its playable area.
*   **Performance Optimization:** Prevents "runaway" world generation that causes TPS drops.
*   **Knockback Effect:** Smoothly pushes players back if they try to cross the border.
*   **Automated Trimming:** Built-in tools to trim or fill chunks within the border.
*   **Custom Messages:** Configurable alerts when a player hits the world limit.
*   **Multiple Shapes:** Supports both circular and rectangular borders.

## Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/wb set <radius>` | Sets a border for the current world | `worldborder.admin` |
| `/wb clear` | Removes the border from the current world | `worldborder.admin` |
| `/wb fill` | Automatically generates all chunks inside the border | `worldborder.admin` |
| `/wb trim` | Deletes chunks outside the border | `worldborder.admin` |

## Configuration

```yaml
# WorldBorder Settings
settings:
  # The message sent when a player hits the border
  border_message: "§cYou have reached the edge of this world!"
  # Knockback force when hitting the border
  knockback: 0.5
  # How often to check player positions (in ticks)
  check_delay: 20
