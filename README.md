# Toxic Stage

Toxic Stage is a browser-based survival game where players fight waves of zombies, find weapons in crates, and try to improve their score. It includes a solo mode, co-op multiplayer, player accounts, and administration tools.

## Features

- Zombie waves, bosses, toxic gas, and crates containing weapons or medkits.
- Weapons are unlocked during a round; weapon progress resets on death or when the round restarts.
- Peer-to-peer multiplayer using PeerJS: one player hosts a room and others join.
- Keyboard, mouse, and touch controls; key bindings can be customized in-game.
- Player accounts with ADMIN and ROOT roles.
- Administration panel, shared changelog, and maintenance mode.

## Requirements

- A modern browser with WebGL support.
- PHP 7.4 or newer for the account, changelog, and maintenance endpoints.
- Apache with `mod_rewrite` and `.htaccess` support for the complete hosting setup.
- HTTPS for PeerJS multiplayer and public deployment.
- Access to the Three.js and PeerJS CDNs and to public PeerJS signaling.

## Run locally

The solo mode can be launched by opening `index.html` in a browser. Accounts, the shared changelog, and maintenance mode require a PHP server. Rewrite rules in `.htaccess` require Apache and are not handled by PHP's built-in development server.

For multiplayer, the host must remain in the game. All players must use the same project version and room code.

## Deploy on Apache

1. Place the project files in an Apache-served web directory and make sure PHP is enabled.
2. Enable `mod_rewrite` and allow Apache to apply `.htaccess` rules (`AllowOverride`).
3. Configure HTTPS.
4. Set `ACCOUNT_SETUP_KEY` in the PHP environment. It must be a random secret at least 24 characters long. Do not put it in source code or commit it to the repository.
5. Open the game, create the first account, and provide the configured setup key. The first account created with the valid key receives the ROOT role.
6. Verify that account and maintenance data directories are not publicly accessible over HTTP. Do not publish these directories or their contents.

The backend creates data directories when needed. Maintenance mode routes requests for the site root and `index.html` through `maintenance-gate.php`. When enabled, it serves [503.html](./503.html) with HTTP status 503. ROOT accounts can bypass the gate and change the maintenance state.

## Controls

Default desktop controls:

- `W`, `A`, `S`, `D` — move
- Mouse — look around
- Left mouse button — fire
- `Space` — jump
- `Shift` — sprint
- `Ctrl` — crouch
- `1`–`6` — select a weapon slot; locked weapons cannot be selected
- `Enter` — chat
- `Tab` — scoreboard
- `Esc` — pause

Key bindings can be changed in the game menu. On touch devices, on-screen controls are available.

## ROOT console

The console is available after signing in with a ROOT account. Supported commands:

| Command | Action |
| --- | --- |
| `pomoc` | Show the command list |
| `status` | Show maintenance and game status |
| `konserwacja on` / `konserwacja off` | Enable or disable maintenance mode |
| `ulecz` | Heal the player or players in a hosted room |
| `restart` | Restart the round |
| `trudność 0.5–3` | Set zombie difficulty |
| `ogłoszenie <tekst>` | Send an announcement in a hosted room |

Commands affecting multiplayer are executed by the host. Changing maintenance mode requires the PHP backend to be running.

## Main files

| File | Description |
| --- | --- |
| [index.html](./index.html) | Game, interface, controls, and multiplayer logic |
| [accounts.php](./accounts.php) | Registration, login, and account operations |
| [changelog.php](./changelog.php) | Read and update the shared changelog |
| [maintenance.php](./maintenance.php) | Read and change maintenance state |
| [maintenance-gate.php](./maintenance-gate.php) | Access gate during maintenance |
| [.htaccess](./.htaccess) | Apache rules for the site root and maintenance data |
| [503.html](./503.html) | Maintenance page |

## Security and privacy

- Never commit `users.json`, account data, passwords, keys, or environment secrets to the repository.
- Make sure the web server blocks direct HTTP access to directories containing private data.
- Client-side code is delivered to the browser. Do not put secrets in it or rely on it as a trusted place to enforce game rules.
- Multiplayer uses public PeerJS signaling and peer-to-peer connections.

## Development

This project was built using AI-assisted, prompt-driven development ("vibe coding").

## License

This project is licensed under the GNU General Public License version 3 only (GPL-3.0-only). See [LICENSE](./LICENSE) for the full license text.
